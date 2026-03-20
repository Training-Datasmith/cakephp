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
namespace Cake\Database\Driver;

use Cake\Database\Driver;
use Cake\Database\Driver_Feature_Enum;
use Cake\Database\Expression\Function_Expression;
use Cake\Database\Expression\Order_By_Expression;
use Cake\Database\Expression\Order_Clause_Expression;
use Cake\Database\Expression\Tuple_Comparison;
use Cake\Database\Expression\Unary_Expression;
use Cake\Database\Expression_Interface;
use Cake\Database\Query;
use Cake\Database\Query\Select_Query;
use Cake\Database\Query_Compiler;
use Cake\Database\Schema\Schema_Dialect;
use Cake\Database\Schema\Sqlserver_Schema_Dialect;
use Cake\Database\Sqlserver_Compiler;
use Cake\Database\Statement\Sqlserver_Statement;
use Cake\Database\Statement_Interface;
use InvalidArgumentException;
use PDO;
/**
 * SQLServer driver.
 */
class Sqlserver extends Driver
{
    use Tuple_Comparison_Translator_Trait;
    /**
     * @inheritDoc
     */
    protected const MAX_ALIAS_LENGTH = 128;
    /**
     * @inheritDoc
     */
    protected const RETRY_ERROR_CODES = [40613];
    /**
     * @inheritDoc
     */
    protected const STATEMENT_CLASS = Sqlserver_Statement::class;
    /**
     * Base configuration settings for Sqlserver driver
     *
     * @var array<string, mixed>
     */
    protected array $_base_config = [
        'host' => 'localhost\SQLEXPRESS',
        'username' => '',
        'password' => '',
        'database' => 'cake',
        'port' => '',
        // PDO::SQLSRV_ENCODING_UTF8
        'encoding' => 65001,
        'flags' => [],
        'init' => [],
        'settings' => [],
        'attributes' => [],
        'app' => null,
        'connectionPooling' => null,
        'failoverPartner' => null,
        'loginTimeout' => null,
        'multiSubnetFailover' => null,
        'encrypt' => null,
        'trustServerCertificate' => null,
        'accessToken' => null,
        'authentication' => null,
    ];
    /**
     * String used to start a database identifier quoting to make it safe
     */
    protected string $_start_quote = '[';
    /**
     * String used to end a database identifier quoting to make it safe
     */
    protected string $_end_quote = ']';
    /**
     * Establishes a connection to the database server.
     *
     * Please note that the PDO::ATTR_PERSISTENT attribute is not supported by
     * the SQL Server PHP PDO drivers.  As a result you cannot use the
     * persistent config option when connecting to a SQL Server  (for more
     * information see: https://github.com/Microsoft/msphpsql/issues/65).
     *
     * @throws \InvalidArgumentException if an unsupported setting is in the driver config
     */
    public function connect(): void
    {
        if ($this->pdo !== null) {
            return;
        }
        $config = $this->_config;
        if (isset($config['persistent']) && $config['persistent']) {
            throw new InvalidArgumentException('Config setting "persistent" cannot be set to true, ' . 'as the Sqlserver PDO driver does not support PDO::ATTR_PERSISTENT');
        }
        $config['flags'] += [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
        if (!empty($config['encoding'])) {
            $config['flags'][PDO::SQLSRV_ATTR_ENCODING] = $config['encoding'];
        }
        $port = '';
        if ($config['port']) {
            $port = ',' . $config['port'];
        }
        $dsn = "sqlsrv:Server={$config['host']}{$port};Database={$config['database']};MultipleActiveResultSets=false";
        if ($config['app'] !== null) {
            $dsn .= ";APP={$config['app']}";
        }
        if ($config['connectionPooling'] !== null) {
            $dsn .= ";ConnectionPooling={$config['connectionPooling']}";
        }
        if ($config['failoverPartner'] !== null) {
            $dsn .= ";Failover_Partner={$config['failoverPartner']}";
        }
        if ($config['loginTimeout'] !== null) {
            $dsn .= ";LoginTimeout={$config['loginTimeout']}";
        }
        if ($config['multiSubnetFailover'] !== null) {
            $dsn .= ";MultiSubnetFailover={$config['multiSubnetFailover']}";
        }
        if ($config['encrypt'] !== null) {
            $dsn .= ";Encrypt={$config['encrypt']}";
        }
        if ($config['trustServerCertificate'] !== null) {
            $dsn .= ";TrustServerCertificate={$config['trustServerCertificate']}";
        }
        if ($config['accessToken'] !== null) {
            $dsn .= ";AccessToken={$config['accessToken']}";
        }
        if ($config['authentication'] !== null) {
            $dsn .= ";Authentication={$config['authentication']}";
        }
        $this->pdo = $this->create_pdo($dsn, $config);
        if (!empty($config['init'])) {
            foreach ((array) $config['init'] as $command) {
                $this->pdo->exec($command);
            }
        }
        if (!empty($config['settings']) && is_array($config['settings'])) {
            foreach ($config['settings'] as $key => $value) {
                $this->pdo->exec("SET {$key} {$value}");
            }
        }
        if (!empty($config['attributes']) && is_array($config['attributes'])) {
            foreach ($config['attributes'] as $key => $value) {
                $this->pdo->set_attribute($key, $value);
            }
        }
    }
    /**
     * Returns whether PHP is able to use this driver for connecting to database
     *
     * @return bool true if it is valid to use this driver
     */
    public function enabled(): bool
    {
        return in_array('sqlsrv', PDO::get_available_drivers(), true);
    }
    /**
     * @inheritDoc
     */
    public function prepare(Query|string $query): Statement_Interface
    {
        $options = [PDO::ATTR_CURSOR => PDO::CURSOR_SCROLL, PDO::SQLSRV_ATTR_CURSOR_SCROLL_TYPE => PDO::SQLSRV_CURSOR_BUFFERED];
        $sql = $query;
        if ($query instanceof Query) {
            $sql = $query->sql();
            if (count($query->get_value_binder()->bindings()) > 2100) {
                throw new InvalidArgumentException('Exceeded maximum number of parameters (2100) for prepared statements in Sql Server. ' . 'This is probably due to a very large WHERE IN () clause which generates a parameter ' . 'for each value in the array. ' . 'If using an Association, try changing the `strategy` from select to subquery.');
            }
            if ($query instanceof Select_Query && !$query->is_buffered_results_enabled()) {
                $options = [];
            }
        }
        /** @var string $sql */
        $statement = $this->get_pdo()->prepare($sql, $options);
        return new (static::STATEMENT_CLASS)($statement, $this, $this->get_result_set_decorators($query));
    }
    /**
     * @inheritDoc
     */
    public function save_point_sql($name): string
    {
        return 'SAVE TRANSACTION t' . $name;
    }
    /**
     * @inheritDoc
     */
    public function release_save_point_sql($name): string
    {
        // SQLServer has no release save point operation.
        return '';
    }
    /**
     * @inheritDoc
     */
    public function rollback_save_point_sql($name): string
    {
        return 'ROLLBACK TRANSACTION t' . $name;
    }
    /**
     * @inheritDoc
     */
    public function disable_foreign_key_sql(): string
    {
        return 'EXEC sp_MSforeachtable "ALTER TABLE ? NOCHECK CONSTRAINT all"';
    }
    /**
     * @inheritDoc
     */
    public function enable_foreign_key_sql(): string
    {
        return 'EXEC sp_MSforeachtable "ALTER TABLE ? WITH CHECK CHECK CONSTRAINT all"';
    }
    /**
     * @inheritDoc
     */
    public function supports(Driver_Feature_Enum $feature): bool
    {
        return match ($feature) {
            Driver_Feature_Enum::CTE, Driver_Feature_Enum::DISABLE_CONSTRAINT_WITHOUT_TRANSACTION, Driver_Feature_Enum::SAVEPOINT, Driver_Feature_Enum::TRUNCATE_WITH_CONSTRAINTS, Driver_Feature_Enum::WINDOW => true,
            Driver_Feature_Enum::INTERSECT => true,
            Driver_Feature_Enum::INTERSECT_ALL => false,
            Driver_Feature_Enum::JSON => false,
            Driver_Feature_Enum::SET_OPERATIONS_ORDER_BY => false,
            Driver_Feature_Enum::OPTIMIZER_HINT_COMMENT => false,
            Driver_Feature_Enum::CHECK_CONSTRAINTS => false,
        };
    }
    /**
     * @inheritDoc
     */
    public function schema_dialect(): Schema_Dialect
    {
        return $this->_schema_dialect ??= new Sqlserver_Schema_Dialect($this);
    }
    /**
     * {@inheritDoc}
     *
     * @return \Cake\Database\SqlserverCompiler
     */
    public function new_compiler(): Query_Compiler
    {
        return new Sqlserver_Compiler();
    }
    /**
     * @inheritDoc
     */
    protected function _select_query_translator(Select_Query $query): Select_Query
    {
        $limit = $query->clause('limit');
        $offset = $query->clause('offset');
        if ($limit && $offset === null) {
            $query->modifier(['_auto_top_' => sprintf('TOP %d', $limit)]);
        }
        if ($offset !== null && !$query->clause('order')) {
            $query->order_by($query->expr()->add('(SELECT NULL)'));
        }
        if ($this->version() < 11 && $offset !== null) {
            return $this->_paging_subquery($query, $limit, $offset);
        }
        return $this->_transform_distinct($query);
    }
    /**
     * Generate a paging subquery for older versions of SQLserver.
     *
     * Prior to SQLServer 2012 there was no equivalent to LIMIT OFFSET, so a subquery must
     * be used.
     *
     * @param \Cake\Database\Query\SelectQuery<mixed> $original The query to wrap in a subquery.
     * @param int|null $limit The number of rows to fetch.
     * @param int|null $offset The number of rows to offset.
     * @return \Cake\Database\Query\SelectQuery<mixed> Modified query object.
     */
    protected function _paging_subquery(Select_Query $original, ?int $limit, ?int $offset): Select_Query
    {
        $field = '_cake_paging_._cake_page_rownum_';
        /** @var \Cake\Database\Expression\OrderByExpression $originalOrder */
        $original_order = $original->clause('order');
        if ($original_order) {
            // SQL server does not support column aliases in OVER clauses.  But
            // the only practical way to specify the use of calculated columns
            // is with their alias.  So substitute the select SQL in place of
            // any column aliases for those entries in the order clause.
            $select = $original->clause('select');
            $order = new Order_By_Expression();
            $original_order->iterate_parts(function ($direction, $order_by) use ($select, $order) {
                $key = $order_by;
                if (isset($select[$order_by]) && $select[$order_by] instanceof Expression_Interface) {
                    $order->add(new Order_Clause_Expression($select[$order_by], $direction));
                } else {
                    $order->add([$key => $direction]);
                }
                // Leave original order clause unchanged.
                return $order_by;
            });
        } else {
            $order = new Order_By_Expression('(SELECT NULL)');
        }
        $query = clone $original;
        $query->select(['_cake_page_rownum_' => new Unary_Expression('ROW_NUMBER() OVER', $order)])->limit(null)->offset(null)->order_by([], true);
        $outer = $query->get_connection()->select_query();
        $outer->select('*')->from(['_cake_paging_' => $query]);
        if ($offset) {
            $outer->where(["{$field} > " . $offset]);
        }
        if ($limit) {
            $value = (int) $offset + $limit;
            $outer->where(["{$field} <= {$value}"]);
        }
        // Decorate the original query as that is what the
        // end developer will be calling execute() on originally.
        $original->decorate_results(function (array $row): array {
            if (isset($row['_cake_page_rownum_'])) {
                unset($row['_cake_page_rownum_']);
            }
            return $row;
        });
        return $outer;
    }
    /**
     * @inheritDoc
     */
    protected function _transform_distinct(Select_Query $query): Select_Query
    {
        if (!is_array($query->clause('distinct'))) {
            return $query;
        }
        $original = $query;
        $query = clone $original;
        $distinct = $query->clause('distinct');
        $query->distinct(false);
        $order = new Order_By_Expression($distinct);
        $query->select(function (Query $q) use ($distinct, $order): array {
            $over = $q->expr('ROW_NUMBER() OVER')->add('(PARTITION BY')->add($q->expr()->add($distinct)->set_conjunction(','))->add($order)->add(')')->set_conjunction(' ');
            return ['_cake_distinct_pivot_' => $over];
        })->limit(null)->offset(null)->order_by([], true);
        $outer = new Select_Query($query->get_connection());
        $outer->select('*')->from(['_cake_distinct_' => $query])->where(['_cake_distinct_pivot_' => 1]);
        // Decorate the original query as that is what the
        // end developer will be calling execute() on originally.
        $original->decorate_results(function (array $row): array {
            if (isset($row['_cake_distinct_pivot_'])) {
                unset($row['_cake_distinct_pivot_']);
            }
            return $row;
        });
        return $outer;
    }
    /**
     * @inheritDoc
     */
    protected function _expression_translators(): array
    {
        return [Function_Expression::class => '_transformFunctionExpression', Tuple_Comparison::class => '_transformTupleComparison'];
    }
    /**
     * Receives a FunctionExpression and changes it so that it conforms to this
     * SQL dialect.
     *
     * @param \Cake\Database\Expression\FunctionExpression $expression The function expression to convert to TSQL.
     */
    protected function _transform_function_expression(Function_Expression $expression): void
    {
        switch ($expression->get_name()) {
            case 'CONCAT':
                // CONCAT function is expressed as exp1 + exp2
                $expression->set_name('')->set_conjunction(' +');
                break;
            case 'DATEDIFF':
                $has_day = false;
                $visitor = function ($value) use (&$has_day) {
                    if ($value === 'day') {
                        $has_day = true;
                    }
                    return $value;
                };
                $expression->iterate_parts($visitor);
                if (!$has_day) {
                    $expression->add(['day' => 'literal'], [], true);
                }
                break;
            case 'CURRENT_DATE':
                $time = new Function_Expression('GETUTCDATE');
                $expression->set_name('CONVERT')->add(['date' => 'literal', $time]);
                break;
            case 'CURRENT_TIME':
                $time = new Function_Expression('GETUTCDATE');
                $expression->set_name('CONVERT')->add(['time' => 'literal', $time]);
                break;
            case 'NOW':
                $expression->set_name('GETUTCDATE');
                break;
            case 'EXTRACT':
                $expression->set_name('DATEPART')->set_conjunction(' ,');
                break;
            case 'DATE_ADD':
                $params = [];
                $visitor = function ($p, $key) use (&$params) {
                    if ($key === 0) {
                        $params[2] = $p;
                    } else {
                        $value_unit = explode(' ', $p);
                        $params[0] = rtrim($value_unit[1], 's');
                        $params[1] = $value_unit[0];
                    }
                    return $p;
                };
                $manipulator = function ($p, $key) use (&$params) {
                    return $params[$key];
                };
                $expression->set_name('DATEADD')->set_conjunction(',')->iterate_parts($visitor)->iterate_parts($manipulator)->add([$params[2] => 'literal']);
                break;
            case 'DAYOFWEEK':
                $expression->set_name('DATEPART')->set_conjunction(' ')->add(['weekday, ' => 'literal'], [], true);
                break;
            case 'SUBSTR':
                $expression->set_name('SUBSTRING');
                if (count($expression) < 4) {
                    $params = [];
                    $expression->iterate_parts(function ($p) use (&$params) {
                        return $params[] = $p;
                    })->add([new Function_Expression('LEN', [$params[0]]), ['string']]);
                }
                break;
        }
    }
}