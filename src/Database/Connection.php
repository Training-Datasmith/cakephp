<?php

declare (strict_types=1);
/**
* CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
* Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
*
* Licensed under The MIT License
* For full copyright and license information, please see the LICENSE.txt
* Redistributions of files must retain the above copyright notice.
*
* @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
* @link          https://cakephp.org CakePHP(tm) Project
* @since         3.0.0
* @license       https://opensource.org/licenses/mit-license.php MIT License
*/
namespace Cake\Database;

use Cake\Cache\Cache;
use Cake\Core\App;
use function Cake\Core\env;
use Cake\Core\Exception\Cake_Exception;
use Cake\Core\Retry\Command_Retry;
use Cake\Database\Exception\Missing_Driver_Exception;
use Cake\Database\Exception\Missing_Extension_Exception;
use Cake\Database\Exception\Nested_Transaction_Rollback_Exception;
use Cake\Database\Query\Delete_Query;
use Cake\Database\Query\Insert_Query;
use Cake\Database\Query\Query_Factory;
use Cake\Database\Query\Select_Query;
use Cake\Database\Query\Update_Query;
use Cake\Database\Retry\Reconnect_Strategy;
use Cake\Database\Schema\Cached_Collection;
use Cake\Database\Schema\Collection as SchemaCollection;
use Cake\Database\Schema\Collection_Interface as SchemaCollectionInterface;
use Cake\Datasource\Connection_Interface;
use Cake\Log\Log;
use Closure;
use Psr\Simple_Cache\Cache_Interface;
use Throwable;
/**
 * Represents a connection with a database server.
 */
class Connection implements Connection_Interface
{
    protected Driver $read_driver;
    protected Driver $write_driver;
    /**
     * Contains how many nested transactions have been started.
     */
    protected int $_transaction_level = 0;
    /**
     * Whether a transaction is active in this connection.
     */
    protected bool $_transaction_started = false;
    /**
     * Whether this connection can and should use savepoints for nested
     * transactions.
     */
    protected bool $_use_save_points = false;
    /**
     * Cacher object instance.
     */
    protected ?Cache_Interface $cacher = null;
    /**
     * The schema collection object
     */
    protected ?Schema_Collection_Interface $_schema_collection = null;
    /**
     * NestedTransactionRollbackException object instance, will be stored if
     * the rollback method is called in some nested transaction.
     */
    protected ?Nested_Transaction_Rollback_Exception $nested_transaction_rollback_exception = null;
    protected Query_Factory $query_factory;
    /**
     * Constructor.
     *
     * ### Available options:
     *
     * - `driver` Sort name or FQCN for driver.
     * - `log` Boolean indicating whether to use query logging.
     * - `name` Connection name.
     * - `cacheMetaData` Boolean indicating whether metadata (datasource schemas) should be cached.
     *    If set to a string it will be used as the name of cache config to use.
     * - `cacheKeyPrefix` Custom prefix to use when generation cache keys. Defaults to connection name.
     *
     * @param array<string, mixed> $_config Configuration array.
     * @throws \Cake\Database\Exception\MissingDriverException when the driver class cannot be found
     * @throws \Cake\Database\Exception\MissingExtensionException when the database extension is not enabled
     */
    public function __construct(
        /**
         * Contains the configuration params for this connection.
         */
        protected array $_config
    )
    {
        [self::ROLE_READ => $this->read_driver, self::ROLE_WRITE => $this->write_driver] = $this->create_drivers($this->_config);
    }
    /**
     * Creates read and write drivers.
     *
     * @param array<string, mixed> $config Connection config
     * @return array<string, \Cake\Database\Driver>
     * @phpstan-return array{read: \Cake\Database\Driver, write: \Cake\Database\Driver}
     */
    protected function create_drivers(array $config): array
    {
        $driver = $config['driver'] ?? '';
        if (!is_string($driver)) {
            assert($driver instanceof Driver);
            if (!$driver->enabled()) {
                throw new Missing_Extension_Exception(['driver' => $driver::class, 'name' => $this->config_name()]);
            }
            // Legacy support for setting instance instead of driver class
            return [self::ROLE_READ => $driver, self::ROLE_WRITE => $driver];
        }
        /** @var class-string<\Cake\Database\Driver>|null $driverClass */
        $driver_class = App::class_name($driver, 'Database/Driver');
        if ($driver_class === null) {
            throw new Missing_Driver_Exception(['driver' => $driver, 'connection' => $this->config_name()]);
        }
        $shared_config = array_diff_key($config, array_flip(['className', 'driver', 'cacheMetaData', 'cacheKeyPrefix', 'read', 'write']));
        $write_config = ($config['write'] ?? []) + $shared_config;
        $read_config = ($config['read'] ?? []) + $shared_config;
        if (array_key_exists('write', $config) || array_key_exists('read', $config)) {
            $read_driver = new $driver_class(['_role' => self::ROLE_READ] + $read_config);
            $write_driver = new $driver_class(['_role' => self::ROLE_WRITE] + $write_config);
        } else {
            $read_driver = new $driver_class(['_role' => self::ROLE_WRITE] + $write_config);
            $write_driver = $read_driver;
        }
        if (!$write_driver->enabled()) {
            throw new Missing_Extension_Exception(['driver' => $write_driver::class, 'name' => $this->config_name()]);
        }
        return [self::ROLE_READ => $read_driver, self::ROLE_WRITE => $write_driver];
    }
    /**
     * Destructor
     *
     * Disconnects the driver to release the connection.
     */
    public function __destruct()
    {
        if ($this->_transaction_started && class_exists(Log::class)) {
            $message = 'The connection is going to be closed but there is an active transaction.';
            $request_url = env('REQUEST_URI');
            if ($request_url) {
                $message .= "\nRequest URL: " . $request_url;
            }
            $client_ip = env('REMOTE_ADDR');
            if ($client_ip) {
                $message .= "\nClient IP: " . $client_ip;
            }
            Log::warning($message);
        }
    }
    /**
     * @inheritDoc
     */
    public function config(): array
    {
        return $this->_config;
    }
    /**
     * @inheritDoc
     */
    public function config_name(): string
    {
        return $this->_config['name'] ?? '';
    }
    /**
     * Returns the connection role: read or write.
     */
    public function role(): string
    {
        return preg_match('/:read$/', $this->config_name()) === 1 ? static::ROLE_READ : static::ROLE_WRITE;
    }
    /**
     * Get the retry wrapper object that is allows recovery from server disconnects
     * while performing certain database actions, such as executing a query.
     *
     * @return \Cake\Core\Retry\CommandRetry The retry wrapper
     */
    public function get_disconnect_retry(): Command_Retry
    {
        return new Command_Retry(new Reconnect_Strategy($this));
    }
    /**
     * Gets the role-specific driver instance.
     *
     * @param string $role Connection role ('read' or 'write')
     */
    public function get_driver(string $role = self::ROLE_WRITE): Driver
    {
        assert($role === self::ROLE_READ || $role === self::ROLE_WRITE);
        return $role === self::ROLE_READ ? $this->get_read_driver() : $this->get_write_driver();
    }
    /**
     * Gets the read-role driver instance.
     */
    public function get_read_driver(): Driver
    {
        return $this->read_driver;
    }
    /**
     * Gets the write-role driver instance.
     */
    public function get_write_driver(): Driver
    {
        return $this->write_driver;
    }
    /**
     * Executes a query using $params for interpolating values and $types as a hint for each
     * those params.
     *
     * @param string $sql SQL to be executed and interpolated with $params
     * @param array $params list or associative array of params to be interpolated in $sql as values
     * @param array $types list or associative array of types to be used for casting values in query
     * @return \Cake\Database\StatementInterface executed statement
     */
    public function execute(string $sql, array $params = [], array $types = []): Statement_Interface
    {
        return $this->get_disconnect_retry()->run(fn(): \Cake\Database\Statement_Interface => $this->get_write_driver()->execute($sql, $params, $types));
    }
    /**
     * Executes the provided query after compiling it for the specific driver
     * dialect and returns the executed Statement object.
     *
     * @param \Cake\Database\Query $query The query to be executed
     * @return \Cake\Database\StatementInterface executed statement
     */
    public function run(Query $query): Statement_Interface
    {
        return $this->get_disconnect_retry()->run(fn(): \Cake\Database\Statement_Interface => $this->get_driver($query->get_connection_role())->run($query));
    }
    /**
     * Get query factory instance.
     */
    public function query_factory(): Query_Factory
    {
        return $this->query_factory ??= new Query_Factory($this);
    }
    /**
     * Create a new SelectQuery instance for this connection.
     *
     * @param \Cake\Database\ExpressionInterface|\Closure|array|string|float|int $fields Fields/columns list for the query.
     * @param array|string $table The table or list of tables to query.
     * @param array<string, string> $types Associative array containing the types to be used for casting.
     * @return \Cake\Database\Query\SelectQuery<mixed>
     */
    public function select_query(Expression_Interface|Closure|array|string|float|int $fields = [], array|string $table = [], array $types = []): Select_Query
    {
        return $this->query_factory()->select($fields, $table, $types);
    }
    /**
     * Create a new InsertQuery instance for this connection.
     *
     * @param string|null $table The table to insert rows into.
     * @param array $values Associative array of column => value to be inserted.
     * @param array<int|string, string> $types Associative array containing the types to be used for casting.
     */
    public function insert_query(?string $table = null, array $values = [], array $types = []): Insert_Query
    {
        return $this->query_factory()->insert($table, $values, $types);
    }
    /**
     * Create a new UpdateQuery instance for this connection.
     *
     * @param \Cake\Database\ExpressionInterface|string|null $table The table to update rows of.
     * @param array $values Values to be updated.
     * @param array $conditions Conditions to be set for the update statement.
     * @param array<string, string> $types Associative array containing the types to be used for casting.
     */
    public function update_query(Expression_Interface|string|null $table = null, array $values = [], array $conditions = [], array $types = []): Update_Query
    {
        return $this->query_factory()->update($table, $values, $conditions, $types);
    }
    /**
     * Create a new DeleteQuery instance for this connection.
     *
     * @param string|null $table The table to delete rows from.
     * @param array $conditions Conditions to be set for the delete statement.
     * @param array<string, string> $types Associative array containing the types to be used for casting.
     */
    public function delete_query(?string $table = null, array $conditions = [], array $types = []): Delete_Query
    {
        return $this->query_factory()->delete($table, $conditions, $types);
    }
    /**
     * Sets a Schema\Collection object for this connection.
     *
     * @param \Cake\Database\Schema\CollectionInterface $collection The schema collection object
     * @return $this
     */
    public function set_schema_collection(Schema_Collection_Interface $collection): static
    {
        $this->_schema_collection = $collection;
        return $this;
    }
    /**
     * Gets a Schema\Collection object for this connection.
     */
    public function get_schema_collection(): Schema_Collection_Interface
    {
        if ($this->_schema_collection !== null) {
            return $this->_schema_collection;
        }
        if (!empty($this->_config['cacheMetadata'])) {
            return $this->_schema_collection = new Cached_Collection(new Schema_Collection($this), empty($this->_config['cacheKeyPrefix']) ? $this->config_name() : $this->_config['cacheKeyPrefix'], $this->get_cacher());
        }
        return $this->_schema_collection = new Schema_Collection($this);
    }
    /**
     * Executes an INSERT query on the specified table.
     *
     * @param string $table the table to insert values in
     * @param array $values values to be inserted
     * @param array<string, string> $types Array containing the types to be used for casting
     */
    public function insert(string $table, array $values, array $types = []): Statement_Interface
    {
        return $this->insert_query($table, $values, $types)->execute();
    }
    /**
     * Executes an UPDATE statement on the specified table.
     *
     * @param string $table the table to update rows from
     * @param array $values values to be updated
     * @param array $conditions conditions to be set for update statement
     * @param array<string, string> $types list of associative array containing the types to be used for casting
     */
    public function update(string $table, array $values, array $conditions = [], array $types = []): Statement_Interface
    {
        return $this->update_query($table, $values, $conditions, $types)->execute();
    }
    /**
     * Executes a DELETE statement on the specified table.
     *
     * @param string $table the table to delete rows from
     * @param array $conditions conditions to be set for delete statement
     * @param array<string, string> $types list of associative array containing the types to be used for casting
     */
    public function delete(string $table, array $conditions = [], array $types = []): Statement_Interface
    {
        return $this->delete_query($table, $conditions, $types)->execute();
    }
    /**
     * Starts a new transaction.
     */
    public function begin(): void
    {
        if (!$this->_transaction_started) {
            $this->get_disconnect_retry()->run(function (): void {
                $this->get_write_driver()->begin_transaction();
            });
            $this->_transaction_level = 0;
            $this->_transaction_started = true;
            $this->nested_transaction_rollback_exception = null;
            return;
        }
        $this->_transaction_level++;
        if ($this->is_save_points_enabled()) {
            $this->create_save_point((string) $this->_transaction_level);
        }
    }
    /**
     * Commits current transaction.
     *
     * @return bool true on success, false otherwise
     * @throws \Cake\Database\Exception\NestedTransactionRollbackException when a nested transaction was rolled back
     */
    public function commit(): bool
    {
        if (!$this->_transaction_started) {
            return false;
        }
        if ($this->_transaction_level === 0) {
            if ($this->was_nested_transaction_rolledback()) {
                $e = $this->nested_transaction_rollback_exception;
                assert($e !== null);
                $this->nested_transaction_rollback_exception = null;
                throw $e;
            }
            $this->_transaction_started = false;
            $this->nested_transaction_rollback_exception = null;
            return $this->get_write_driver()->commit_transaction();
        }
        if ($this->is_save_points_enabled()) {
            $this->release_save_point((string) $this->_transaction_level);
        }
        $this->_transaction_level--;
        return true;
    }
    /**
     * Rollback current transaction.
     *
     * @param bool|null $toBeginning Whether the transaction should be rolled back to the
     * beginning of it. Defaults to false if using savepoints, or true if not.
     */
    public function rollback(?bool $to_beginning = null): bool
    {
        if (!$this->_transaction_started) {
            return false;
        }
        $use_save_point = $this->is_save_points_enabled();
        $to_beginning ??= !$use_save_point;
        if ($this->_transaction_level === 0 || $to_beginning) {
            $this->_transaction_level = 0;
            $this->_transaction_started = false;
            $this->nested_transaction_rollback_exception = null;
            $this->get_write_driver()->rollback_transaction();
            return true;
        }
        $save_point = $this->_transaction_level--;
        if ($use_save_point) {
            $this->rollback_savepoint($save_point);
        } else {
            $this->nested_transaction_rollback_exception ??= new Nested_Transaction_Rollback_Exception();
        }
        return true;
    }
    /**
     * Enables/disables the usage of savepoints, enables only if the driver allows it.
     *
     * If you are trying to enable this feature, make sure you check
     * `isSavePointsEnabled()` to verify that savepoints were enabled successfully.
     *
     * @param bool $enable Whether save points should be used.
     * @return $this
     */
    public function enable_save_points(bool $enable = true): static
    {
        if ($enable === false) {
            $this->_use_save_points = false;
        } else {
            $this->_use_save_points = $this->get_write_driver()->supports(Driver_Feature_Enum::SAVEPOINT);
        }
        return $this;
    }
    /**
     * Disables the usage of savepoints.
     *
     * @return $this
     */
    public function disable_save_points(): static
    {
        $this->_use_save_points = false;
        return $this;
    }
    /**
     * Returns whether this connection is using savepoints for nested transactions
     *
     * @return bool true if enabled, false otherwise
     */
    public function is_save_points_enabled(): bool
    {
        return $this->_use_save_points;
    }
    /**
     * Creates a new save point for nested transactions.
     *
     * @param string|int $name Save point name or id
     */
    public function create_save_point(string|int $name): void
    {
        $this->execute($this->get_write_driver()->save_point_sql($name));
    }
    /**
     * Releases a save point by its name.
     *
     * @param string|int $name Save point name or id
     */
    public function release_save_point(string|int $name): void
    {
        $sql = $this->get_write_driver()->release_save_point_sql($name);
        if ($sql) {
            $this->execute($sql);
        }
    }
    /**
     * Rollback a save point by its name.
     *
     * @param string|int $name Save point name or id
     */
    public function rollback_savepoint(string|int $name): void
    {
        $this->execute($this->get_write_driver()->rollback_save_point_sql($name));
    }
    /**
     * Run driver specific SQL to disable foreign key checks.
     */
    public function disable_foreign_keys(): void
    {
        $this->get_disconnect_retry()->run(function (): void {
            $this->execute($this->get_write_driver()->disable_foreign_key_sql());
        });
    }
    /**
     * Run driver specific SQL to enable foreign key checks.
     */
    public function enable_foreign_keys(): void
    {
        $this->get_disconnect_retry()->run(function (): void {
            $this->execute($this->get_write_driver()->enable_foreign_key_sql());
        });
    }
    /**
     * Executes a callback inside a transaction, if any exception occurs
     * while executing the passed callback, the transaction will be rolled back
     * If the result of the callback is `false`, the transaction will
     * also be rolled back. Otherwise the transaction is committed after executing
     * the callback.
     *
     * The callback will receive the connection instance as its first argument.
     *
     * ### Example:
     *
     * ```
     * $connection->transactional(function ($connection) {
     *   $connection->deleteQuery('users')->execute();
     * });
     * ```
     *
     * @param \Closure $callback The callback to execute within a transaction.
     * @return mixed The return value of the callback.
     * @throws \Exception Will re-throw any exception raised in $callback after
     *   rolling back the transaction.
     */
    public function transactional(Closure $callback): mixed
    {
        $this->begin();
        try {
            $result = $callback($this);
        } catch (Throwable $e) {
            $this->rollback(false);
            throw $e;
        }
        if ($result === false) {
            $this->rollback(false);
            return false;
        }
        try {
            $this->commit();
        } catch (Nested_Transaction_Rollback_Exception $e) {
            $this->rollback(false);
            throw $e;
        }
        return $result;
    }
    /**
     * Returns whether some nested transaction has been already rolled back.
     */
    protected function was_nested_transaction_rolledback(): bool
    {
        return $this->nested_transaction_rollback_exception instanceof Nested_Transaction_Rollback_Exception;
    }
    /**
     * Run an operation with constraints disabled.
     *
     * Constraints should be re-enabled after the callback succeeds/fails.
     *
     * ### Example:
     *
     * ```
     * $connection->disableConstraints(function ($connection) {
     *   $connection->insertQuery('users')->execute();
     * });
     * ```
     *
     * @param \Closure $callback Callback to run with constraints disabled
     * @return mixed The return value of the callback.
     * @throws \Exception Will re-throw any exception raised in $callback after
     *   rolling back the transaction.
     */
    public function disable_constraints(Closure $callback): mixed
    {
        return $this->get_disconnect_retry()->run(function () use ($callback) {
            $this->disable_foreign_keys();
            try {
                $result = $callback($this);
            } finally {
                $this->enable_foreign_keys();
            }
            return $result;
        });
    }
    /**
     * Checks if a transaction is running.
     *
     * @return bool True if a transaction is running else false.
     */
    public function in_transaction(): bool
    {
        return $this->_transaction_started;
    }
    /**
     * Enables or disables metadata caching for this connection
     *
     * Changing this setting will not modify existing schema collections objects.
     *
     * @param string|bool $cache Either boolean false to disable metadata caching, or
     *   true to use `_cake_model_` or the name of the cache config to use.
     */
    public function cache_metadata(string|bool $cache): void
    {
        $this->_schema_collection = null;
        $this->_config['cacheMetadata'] = $cache;
        if (is_string($cache)) {
            $this->cacher = null;
        }
    }
    /**
     * @inheritDoc
     */
    public function set_cacher(Cache_Interface $cacher): static
    {
        $this->cacher = $cacher;
        return $this;
    }
    /**
     * @inheritDoc
     */
    public function get_cacher(): Cache_Interface
    {
        if ($this->cacher !== null) {
            return $this->cacher;
        }
        $config_name = $this->_config['cacheMetadata'] ?? '_cake_model_';
        if (!is_string($config_name)) {
            $config_name = '_cake_model_';
        }
        if (!class_exists(Cache::class)) {
            throw new Cake_Exception('To use caching you must either set a cacher using Connection::setCacher()' . ' or require the cakephp/cache package in your composer config.');
        }
        return $this->cacher = Cache::pool($config_name);
    }
    /**
     * Returns an array that can be used to describe the internal state of this
     * object.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        $secrets = ['password' => '*****', 'username' => '*****', 'host' => '*****', 'database' => '*****', 'port' => '*****'];
        $replace = array_intersect_key($secrets, $this->_config);
        $config = $replace + $this->_config;
        if (isset($config['read'])) {
            $config['read'] = array_intersect_key($secrets, $config['read']) + $config['read'];
        }
        if (isset($config['write'])) {
            $config['write'] = array_intersect_key($secrets, $config['write']) + $config['write'];
        }
        return ['config' => $config, 'readDriver' => $this->read_driver, 'writeDriver' => $this->write_driver, 'transactionLevel' => $this->_transaction_level, 'transactionStarted' => $this->_transaction_started, 'useSavePoints' => $this->_use_save_points];
    }
}