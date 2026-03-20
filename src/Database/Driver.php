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

use Cake\Core\App;
use Cake\Core\Exception\Cake_Exception;
use Cake\Core\Retry\Command_Retry;
use Cake\Database\Exception\Database_Exception;
use Cake\Database\Exception\Missing_Connection_Exception;
use Cake\Database\Exception\Query_Exception;
use Cake\Database\Expression\Comparison_Expression;
use Cake\Database\Expression\Identifier_Expression;
use Cake\Database\Expression\Query_Expression;
use Cake\Database\Log\Logged_Query;
use Cake\Database\Log\Query_Logger;
use Cake\Database\Query\Delete_Query;
use Cake\Database\Query\Insert_Query;
use Cake\Database\Query\Select_Query;
use Cake\Database\Query\Update_Query;
use Cake\Database\Retry\Error_Code_Wait_Strategy;
use Cake\Database\Schema\Schema_Dialect;
use Cake\Database\Schema\Table_Schema;
use Cake\Database\Schema\Table_Schema_Interface;
use Cake\Database\Statement\Statement;
use InvalidArgumentException;
use PDO;
use PDOException;
use Psr\Log\Logger_Aware_Interface;
use Psr\Log\Logger_Aware_Trait;
use Psr\Log\Logger_Interface;
use Stringable;
/**
 * Represents a database driver containing all specificities for
 * a database engine including its SQL dialect.
 */
abstract class Driver implements Logger_Aware_Interface
{
    use Logger_Aware_Trait;
    /**
     * @var int|null Maximum alias length or null if no limit
     */
    protected const MAX_ALIAS_LENGTH = null;
    /**
     * @var array<int>  DB-specific error codes that allow connect retry
     */
    protected const RETRY_ERROR_CODES = [];
    /**
     * @var class-string<\Cake\Database\Statement\Statement>
     */
    protected const STATEMENT_CLASS = Statement::class;
    /**
     * Instance of PDO.
     */
    protected ?PDO $pdo = null;
    /**
     * Configuration data.
     *
     * @var array<string, mixed>
     */
    protected array $_config = [];
    /**
     * Base configuration that is merged into the user
     * supplied configuration data.
     *
     * @var array<string, mixed>
     */
    protected array $_base_config = [];
    /**
     * Indicates whether the driver is doing automatic identifier quoting
     * for all queries
     */
    protected bool $_auto_quoting = false;
    /**
     * String used to start a database identifier quoting to make it safe
     */
    protected string $_start_quote = '';
    /**
     * String used to end a database identifier quoting to make it safe
     */
    protected string $_end_quote = '';
    /**
     * Identifier quoter
     */
    protected ?Identifier_Quoter $quoter = null;
    /**
     * The server version
     */
    protected ?string $_version = null;
    /**
     * Whether to log queries generated during this connection.
     */
    protected bool $log_queries = false;
    /**
     * The last number of connection retry attempts.
     */
    protected int $connect_retries = 0;
    /**
     * The schema dialect for this driver
     */
    protected Schema_Dialect $_schema_dialect;
    /**
     * Constructor
     *
     * @param array<string, mixed> $config The configuration for the driver.
     * @throws \InvalidArgumentException
     */
    public function __construct(array $config = [])
    {
        if (empty($config['username']) && !empty($config['login'])) {
            throw new InvalidArgumentException('Please pass "username" instead of "login" for connecting to the database');
        }
        $config += $this->_base_config + ['log' => false];
        $this->_config = $config;
        if (!empty($config['quoteIdentifiers'])) {
            $this->enable_auto_quoting();
        }
        if ($config['log'] !== false) {
            $this->log_queries = true;
            $this->logger = $this->create_logger($config['log'] === true ? null : $config['log']);
        }
    }
    /**
     * Get the configuration data used to create the driver.
     *
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return $this->_config;
    }
    /**
     * Establishes a connection to the database server
     *
     * @param string $dsn A Driver-specific PDO-DSN
     * @param array<string, mixed> $config configuration to be used for creating connection
     */
    protected function create_pdo(string $dsn, array $config): PDO
    {
        $action = fn(): PDO => new PDO($dsn, $config['username'] ?: null, $config['password'] ?: null, $config['flags']);
        $retry = new Command_Retry(new Error_Code_Wait_Strategy(static::RETRY_ERROR_CODES, 5), 4);
        try {
            return $retry->run($action);
        } catch (PDOException $e) {
            throw new Missing_Connection_Exception(['driver' => App::short_name(static::class, 'Database/Driver'), 'reason' => $e->get_message()], null, $e);
        } finally {
            $this->connect_retries = $retry->get_retries();
        }
    }
    /**
     * Establishes a connection to the database server.
     *
     * @throws \Cake\Database\Exception\MissingConnectionException If database connection could not be established.
     */
    abstract public function connect(): void;
    /**
     * Disconnects from database server.
     */
    public function disconnect(): void
    {
        $this->pdo = null;
        $this->_version = null;
    }
    /**
     * Returns connected server version.
     */
    public function version(): string
    {
        return $this->_version ??= (string) $this->get_pdo()->get_attribute(PDO::ATTR_SERVER_VERSION);
    }
    /**
     * Get the PDO connection instance.
     */
    protected function get_pdo(): PDO
    {
        if ($this->pdo === null) {
            $this->connect();
        }
        assert($this->pdo !== null);
        return $this->pdo;
    }
    /**
     * Execute the SQL query using the internal PDO instance.
     *
     * @param string $sql SQL query.
     * @return int|false Number of affected rows or false on failure
     * @throws \Cake\Database\Exception\QueryException On database error
     */
    public function exec(string $sql): int|false
    {
        try {
            return $this->get_pdo()->exec($sql);
        } catch (PDOException $e) {
            $logged_query = new Logged_Query();
            $logged_query->set_context(['query' => $sql, 'driver' => $this]);
            throw new Query_Exception($logged_query, $e);
        }
    }
    /**
     * Returns whether php is able to use this driver for connecting to database.
     *
     * @return bool True if it is valid to use this driver.
     */
    abstract public function enabled(): bool;
    /**
     * Executes a query using $params for interpolating values and $types as a hint for each
     * those params.
     *
     * @param string $sql SQL to be executed and interpolated with $params
     * @param array $params List or associative array of params to be interpolated in $sql as values.
     * @param array $types List or associative array of types to be used for casting values in query.
     * @return \Cake\Database\StatementInterface Executed statement
     */
    public function execute(string $sql, array $params = [], array $types = []): Statement_Interface
    {
        $statement = $this->prepare($sql);
        if ($params) {
            $statement->bind($params, $types);
        }
        $this->execute_statement($statement);
        return $statement;
    }
    /**
     * Executes the provided query after compiling it for the specific driver
     * dialect and returns the executed Statement object.
     *
     * @param \Cake\Database\Query $query The query to be executed.
     * @return \Cake\Database\StatementInterface Executed statement
     */
    public function run(Query $query): Statement_Interface
    {
        $statement = $this->prepare($query);
        $query->get_value_binder()->attach_to($statement);
        $this->execute_statement($statement);
        return $statement;
    }
    /**
     * Execute the statement and log the query string.
     *
     * @param \Cake\Database\StatementInterface $statement Statement to execute.
     * @param array|null $params List of values to be bound to query.
     */
    protected function execute_statement(Statement_Interface $statement, ?array $params = null): void
    {
        if ($this->logger === null) {
            try {
                $statement->execute($params);
            } catch (PDOException $e) {
                throw $this->create_query_exception($e, $statement, $params);
            }
            return;
        }
        $exception = null;
        $took = 0.0;
        try {
            $start = microtime(true);
            $statement->execute($params);
            $took = (float) number_format((microtime(true) - $start) * 1000, 1);
        } catch (PDOException $e) {
            $exception = $e;
        }
        $log_context = ['driver' => $this, 'error' => $exception, 'params' => $params ?? $statement->get_bound_params()];
        if (!$exception) {
            $log_context['numRows'] = $statement->row_count();
            $log_context['took'] = $took;
        }
        $this->log($statement->query_string(), $log_context);
        if ($exception) {
            throw $this->create_query_exception($exception, $statement, $params);
        }
    }
    /**
     * Create a QueryException from a PDOException
     */
    protected function create_query_exception(PDOException $exception, Statement_Interface $statement, ?array $params = null): Query_Exception
    {
        $logged_query = new Logged_Query();
        $logged_query->set_context(['query' => $statement->query_string(), 'driver' => $this, 'params' => $params ?? $statement->get_bound_params()]);
        return new Query_Exception($logged_query, $exception);
    }
    /**
     * Prepares a sql statement to be executed.
     *
     * @param \Cake\Database\Query|string $query The query to turn into a prepared statement.
     */
    public function prepare(Query|string $query): Statement_Interface
    {
        try {
            $statement = $this->get_pdo()->prepare($query instanceof Query ? $query->sql() : $query);
        } catch (PDOException $e) {
            throw new Query_Exception($query instanceof Query ? $query->sql() : $query, $e);
        }
        return new (static::STATEMENT_CLASS)($statement, $this, $this->get_result_set_decorators($query));
    }
    /**
     * Returns the decorators to be applied to the result set incase of a SelectQuery.
     *
     * @param \Cake\Database\Query|string $query The query to be decorated.
     * @return array<\Closure>
     */
    protected function get_result_set_decorators(Query|string $query): array
    {
        if ($query instanceof Select_Query) {
            $decorators = $query->get_result_decorators();
            if ($query->is_results_casting_enabled()) {
                $type_converter = new Field_Type_Converter($query->get_select_type_map(), $this);
                array_unshift($decorators, $type_converter(...));
            }
            return $decorators;
        }
        return [];
    }
    /**
     * Starts a transaction.
     *
     * @return bool True on success, false otherwise.
     */
    public function begin_transaction(): bool
    {
        if ($this->get_pdo()->in_transaction()) {
            return true;
        }
        $this->log('BEGIN');
        return $this->get_pdo()->begin_transaction();
    }
    /**
     * Commits a transaction.
     *
     * @return bool True on success, false otherwise.
     */
    public function commit_transaction(): bool
    {
        if (!$this->get_pdo()->in_transaction()) {
            return false;
        }
        $this->log('COMMIT');
        return $this->get_pdo()->commit();
    }
    /**
     * Rollbacks a transaction.
     *
     * @return bool True on success, false otherwise.
     */
    public function rollback_transaction(): bool
    {
        if (!$this->get_pdo()->in_transaction()) {
            return false;
        }
        $this->log('ROLLBACK');
        return $this->get_pdo()->roll_back();
    }
    /**
     * Returns whether a transaction is active for connection.
     */
    public function in_transaction(): bool
    {
        return $this->get_pdo()->in_transaction();
    }
    /**
     * Returns a SQL snippet for creating a new transaction savepoint
     *
     * @param string|int $name save point name
     */
    public function save_point_sql(string|int $name): string
    {
        return 'SAVEPOINT LEVEL' . $name;
    }
    /**
     * Returns a SQL snippet for releasing a previously created save point
     *
     * @param string|int $name save point name
     */
    public function release_save_point_sql(string|int $name): string
    {
        return 'RELEASE SAVEPOINT LEVEL' . $name;
    }
    /**
     * Returns a SQL snippet for rollbacking a previously created save point
     *
     * @param string|int $name save point name
     */
    public function rollback_save_point_sql(string|int $name): string
    {
        return 'ROLLBACK TO SAVEPOINT LEVEL' . $name;
    }
    /**
     * Get the SQL for disabling foreign keys.
     */
    abstract public function disable_foreign_key_sql(): string;
    /**
     * Get the SQL for enabling foreign keys.
     */
    abstract public function enable_foreign_key_sql(): string;
    /**
     * Transform the query to accommodate any specificities of the SQL dialect in use.
     *
     * It will also quote the identifiers if auto quoting is enabled.
     *
     * @param \Cake\Database\Query $query Query to transform.
     */
    protected function transform_query(Query $query): Query
    {
        if ($this->is_auto_quoting_enabled()) {
            $query = $this->quoter()->quote($query);
        }
        $query = match (true) {
            $query instanceof Select_Query => $this->_select_query_translator($query),
            $query instanceof Insert_Query => $this->_insert_query_translator($query),
            $query instanceof Update_Query => $this->_update_query_translator($query),
            $query instanceof Delete_Query => $this->_delete_query_translator($query),
            default => throw new InvalidArgumentException(sprintf('Instance of SelectQuery, UpdateQuery, InsertQuery, DeleteQuery expected. Found `%s` instead.', get_debug_type($query))),
        };
        $translators = $this->_expression_translators();
        if (!$translators) {
            return $query;
        }
        $query->traverse_expressions(function ($expression) use ($translators, $query): void {
            foreach ($translators as $class => $method) {
                if ($expression instanceof $class) {
                    $this->{$method}($expression, $query);
                }
            }
        });
        return $query;
    }
    /**
     * Returns an associative array of methods that will transform Expression
     * objects to conform with the specific SQL dialect. Keys are class names
     * and values a method in this class.
     *
     * @return array<class-string, string>
     */
    protected function _expression_translators(): array
    {
        return [];
    }
    /**
     * Apply translation steps to select queries.
     *
     * @param \Cake\Database\Query\SelectQuery<mixed> $query The query to translate
     * @return \Cake\Database\Query\SelectQuery<mixed> The modified query
     */
    protected function _select_query_translator(Select_Query $query): Select_Query
    {
        return $this->_transform_distinct($query);
    }
    /**
     * Returns the passed query after rewriting the DISTINCT clause, so that drivers
     * that do not support the "ON" part can provide the actual way it should be done
     *
     * @param \Cake\Database\Query\SelectQuery<mixed> $query The query to be transformed
     * @return \Cake\Database\Query\SelectQuery<mixed>
     */
    protected function _transform_distinct(Select_Query $query): Select_Query
    {
        if (is_array($query->clause('distinct'))) {
            $query->group_by($query->clause('distinct'), true);
            $query->distinct(false);
        }
        return $query;
    }
    /**
     * Apply translation steps to delete queries.
     *
     * Chops out aliases on delete query conditions as most database dialects do not
     * support aliases in delete queries. This also removes aliases
     * in table names as they frequently don't work either.
     *
     * We are intentionally not supporting deletes with joins as they have even poorer support.
     *
     * @param \Cake\Database\Query\DeleteQuery $query The query to translate
     * @return \Cake\Database\Query\DeleteQuery The modified query
     */
    protected function _delete_query_translator(Delete_Query $query): Delete_Query
    {
        $had_alias = false;
        $tables = [];
        foreach ($query->clause('from') as $alias => $table) {
            if (is_string($alias)) {
                $had_alias = true;
            }
            $tables[] = $table;
        }
        if ($had_alias) {
            $query->from($tables, true);
        }
        if (!$had_alias) {
            return $query;
        }
        return $this->_remove_aliases_from_conditions($query);
    }
    /**
     * Apply translation steps to update queries.
     *
     * Chops out aliases on update query conditions as not all database dialects do support
     * aliases in update queries.
     *
     * Just like for delete queries, joins are currently not supported for update queries.
     *
     * @param \Cake\Database\Query\UpdateQuery $query The query to translate
     * @return \Cake\Database\Query\UpdateQuery The modified query
     */
    protected function _update_query_translator(Update_Query $query): Update_Query
    {
        return $this->_remove_aliases_from_conditions($query);
    }
    /**
     * Removes aliases from the `WHERE` clause of a query.
     *
     * @param \Cake\Database\Query\UpdateQuery|\Cake\Database\Query\DeleteQuery $query The query to process.
     * @return \Cake\Database\Query\UpdateQuery|\Cake\Database\Query\DeleteQuery The modified query.
     * @throws \Cake\Database\Exception\DatabaseException In case the processed query contains any joins, as removing
     *  aliases from the conditions can break references to the joined tables.
     * @template T of \Cake\Database\Query\UpdateQuery|\Cake\Database\Query\DeleteQuery
     * @phpstan-param T $query
     * @phpstan-return T
     */
    protected function _remove_aliases_from_conditions(Update_Query|Delete_Query $query): Update_Query|Delete_Query
    {
        if ($query->clause('join')) {
            throw new Database_Exception('Aliases are being removed from conditions for UPDATE/DELETE queries, ' . 'this can break references to joined tables.');
        }
        $conditions = $query->clause('where');
        assert($conditions === null || $conditions instanceof Expression_Interface);
        if ($conditions) {
            $conditions->traverse(function ($expression) {
                if ($expression instanceof Comparison_Expression) {
                    $field = $expression->get_field();
                    if (is_string($field) && str_contains($field, '.')) {
                        [, $unaliased_field] = explode('.', $field, 2);
                        $expression->set_field($unaliased_field);
                    }
                    return $expression;
                }
                if ($expression instanceof Identifier_Expression) {
                    $identifier = $expression->get_identifier();
                    if (str_contains($identifier, '.')) {
                        [, $unaliased_identifier] = explode('.', $identifier, 2);
                        $expression->set_identifier($unaliased_identifier);
                    }
                    return $expression;
                }
                return $expression;
            });
        }
        return $query;
    }
    /**
     * Apply translation steps to insert queries.
     *
     * @param \Cake\Database\Query\InsertQuery $query The query to translate
     * @return \Cake\Database\Query\InsertQuery The modified query
     */
    protected function _insert_query_translator(Insert_Query $query): Insert_Query
    {
        return $query;
    }
    /**
     * Get the schema dialect.
     *
     * Used by {@link \Cake\Database\Schema} package to reflect schema and
     * generate schema.
     *
     * If all the tables that use this Driver specify their
     * own schemas, then this may return null.
     */
    abstract public function schema_dialect(): Schema_Dialect;
    /**
     * Quotes a database identifier (a column name, table name, etc..) to
     * be used safely in queries without the risk of using reserved words
     *
     * @param string $identifier The identifier to quote.
     */
    public function quote_identifier(string $identifier): string
    {
        return $this->quoter()->quote_identifier($identifier);
    }
    /**
     * Quotes a database value.
     *
     * This makes values safe for concatenation in SQL queries.
     *
     * Using this method **is not** recommended. You should use `execute()`
     * instead, as it uses prepared statements which are safer than
     * string concatenation.
     *
     * This method should only be used for queries that do not support placeholders.
     *
     * @param string $value The value to quote.
     */
    public function quote(string $value): string
    {
        return $this->get_pdo()->quote($value);
    }
    /**
     * Get identifier quoter instance.
     */
    public function quoter(): Identifier_Quoter
    {
        return $this->quoter ??= new Identifier_Quoter($this->_start_quote, $this->_end_quote);
    }
    /**
     * Escapes values for use in schema definitions.
     *
     * @param mixed $value The value to escape.
     * @return string String for use in schema definitions.
     */
    public function schema_value(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if ($value === false) {
            return 'FALSE';
        }
        if ($value === true) {
            return 'TRUE';
        }
        if (is_float($value)) {
            return str_replace(',', '.', (string) $value);
        }
        if (is_int($value) || $value === '0' || is_numeric($value) && !str_contains($value, ',') && !str_starts_with($value, '0') && !str_contains($value, 'e')) {
            return (string) $value;
        }
        if ($value instanceof Query_Expression) {
            return $value->sql(new Value_Binder());
        }
        return $this->get_pdo()->quote((string) $value, PDO::PARAM_STR);
    }
    /**
     * Returns the schema name that's being used.
     */
    public function schema(): string
    {
        return $this->_config['schema'];
    }
    /**
     * Returns last id generated for a table or sequence in database.
     *
     * @param string|null $table table name or sequence to get last insert value from.
     */
    public function last_insert_id(?string $table = null): string
    {
        return (string) $this->get_pdo()->last_insert_id($table);
    }
    /**
     * Checks whether the driver is connected.
     */
    public function is_connected(): bool
    {
        if ($this->pdo === null) {
            return false;
        }
        try {
            return (bool) $this->pdo->query('SELECT 1');
        } catch (PDOException) {
            return false;
        }
    }
    /**
     * Sets whether this driver should automatically quote identifiers
     * in queries.
     *
     * @param bool $enable Whether to enable auto quoting
     * @return $this
     */
    public function enable_auto_quoting(bool $enable = true)
    {
        $this->_auto_quoting = $enable;
        return $this;
    }
    /**
     * Disable auto quoting of identifiers in queries.
     *
     * @return $this
     */
    public function disable_auto_quoting()
    {
        $this->_auto_quoting = false;
        return $this;
    }
    /**
     * Returns whether this driver should automatically quote identifiers
     * in queries.
     */
    public function is_auto_quoting_enabled(): bool
    {
        return $this->_auto_quoting;
    }
    /**
     * Returns whether the driver supports the feature.
     *
     * Should return false for unknown features.
     *
     * @param \Cake\Database\DriverFeatureEnum $feature Driver feature
     */
    abstract public function supports(Driver_Feature_Enum $feature): bool;
    /**
     * Transforms the passed query to this Driver's dialect and returns an instance
     * of the transformed query and the full compiled SQL string.
     *
     * @param \Cake\Database\Query $query The query to compile.
     * @param \Cake\Database\ValueBinder $binder The value binder to use.
     * @return string The compiled SQL.
     */
    public function compile_query(Query $query, Value_Binder $binder): string
    {
        $processor = $this->new_compiler();
        $query = $this->transform_query($query);
        return $processor->compile($query, $binder);
    }
    public function new_compiler(): Query_Compiler
    {
        return new Query_Compiler();
    }
    /**
     * Constructs new TableSchema.
     *
     * @param string $table The table name.
     * @param array<string, mixed> $columns The list of columns for the schema.
     */
    public function new_table_schema(string $table, array $columns = []): Table_Schema_Interface
    {
        /** @var class-string<\Cake\Database\Schema\TableSchemaInterface> $className */
        $class_name = $this->_config['tableSchema'] ?? Table_Schema::class;
        return new $class_name($table, $columns);
    }
    /**
     * Returns the maximum alias length allowed.
     *
     * This can be different from the maximum identifier length for columns.
     *
     * @return int|null Maximum alias length or null if no limit
     */
    public function get_max_alias_length(): ?int
    {
        return static::MAX_ALIAS_LENGTH;
    }
    /**
     * Get the logger instance.
     */
    public function get_logger(): ?Logger_Interface
    {
        return $this->logger;
    }
    /**
     * Create logger instance.
     *
     * @param string|null $className Logger's class name
     */
    protected function create_logger(?string $class_name): Logger_Interface
    {
        $class_name ??= Query_Logger::class;
        /** @var class-string<\Psr\Log\LoggerInterface>|null $className */
        $class_name = App::class_name($class_name, 'Cake/Log', 'Log');
        if ($class_name === null) {
            throw new Cake_Exception('For logging you must either set the `log` config to a FQCN which implements Psr\Log\LoggerInterface' . ' or require the cakephp/log package in your composer config.');
        }
        return new $class_name();
    }
    /**
     * Logs a message or query using the configured logger object.
     *
     * @param \Stringable|string $message Message string or query.
     * @param array $context Logging context.
     * @return bool True if message was logged.
     */
    public function log(Stringable|string $message, array $context = []): bool
    {
        if ($this->logger === null || !$this->log_queries) {
            return false;
        }
        $context['query'] = $message;
        $logged_query = new Logged_Query();
        $logged_query->set_context($context);
        $this->logger->debug((string) $logged_query, ['query' => $logged_query]);
        return true;
    }
    /**
     * Returns the connection role this driver performs.
     */
    public function get_role(): string
    {
        return $this->_config['_role'] ?? Connection::ROLE_WRITE;
    }
    /**
     * Enable query logging.
     *
     * @return $this
     */
    public function enable_query_logging()
    {
        $this->log_queries = true;
        return $this;
    }
    /**
     * Disable query logging.
     *
     * @return $this
     */
    public function disable_query_logging()
    {
        $this->log_queries = false;
        return $this;
    }
    /**
     * @inheritDoc
     */
    public function set_logger(Logger_Interface $logger): void
    {
        $this->logger = $logger;
        $this->enable_query_logging();
    }
    /**
     * Destructor
     */
    public function __destruct()
    {
        $this->pdo = null;
    }
    /**
     * Returns an array that can be used to describe the internal state of this
     * object.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['connected' => $this->pdo !== null, 'role' => $this->get_role()];
    }
}