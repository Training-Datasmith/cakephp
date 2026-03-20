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
use Cake\Database\Expression\Tuple_Comparison;
use Cake\Database\Schema\Schema_Dialect;
use Cake\Database\Schema\Sqlite_Schema_Dialect;
use Cake\Database\Statement\Sqlite_Statement;
use InvalidArgumentException;
use PDO;
/**
 * Class Sqlite
 */
class Sqlite extends Driver
{
    use Tuple_Comparison_Translator_Trait;
    /**
     * @inheritDoc
     */
    protected const STATEMENT_CLASS = Sqlite_Statement::class;
    /**
     * Base configuration settings for Sqlite driver
     *
     * - `mask` The mask used for created database
     *
     * @var array<string, mixed>
     */
    protected array $_base_config = ['persistent' => false, 'username' => null, 'password' => null, 'database' => ':memory:', 'encoding' => 'utf8', 'mask' => 0644, 'cache' => null, 'mode' => null, 'flags' => [], 'init' => []];
    /**
     * Whether the connected server supports window functions.
     */
    protected ?bool $_supports_window_functions = null;
    /**
     * String used to start a database identifier quoting to make it safe
     */
    protected string $_start_quote = '"';
    /**
     * String used to end a database identifier quoting to make it safe
     */
    protected string $_end_quote = '"';
    /**
     * Mapping of date parts.
     *
     * @var array<string, string>
     */
    protected array $_date_parts = ['day' => 'd', 'hour' => 'H', 'month' => 'm', 'minute' => 'M', 'second' => 'S', 'week' => 'W', 'year' => 'Y'];
    /**
     * Mapping of feature to db server version for feature availability checks.
     *
     * @var array<string, string>
     */
    protected array $feature_versions = ['cte' => '3.8.3', 'window' => '3.28.0'];
    /**
     * @inheritDoc
     */
    public function connect(): void
    {
        if ($this->pdo !== null) {
            return;
        }
        $config = $this->_config;
        $config['flags'] += [PDO::ATTR_PERSISTENT => $config['persistent'], PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
        if (!is_string($config['database']) || $config['database'] === '') {
            $name = $config['name'] ?? 'unknown';
            throw new InvalidArgumentException("The `database` key for the `{$name}` SQLite connection needs to be a non-empty string.");
        }
        $chmod_file = false;
        if ($config['database'] !== ':memory:' && $config['mode'] !== 'memory') {
            $chmod_file = !file_exists($config['database']);
        }
        $params = [];
        if ($config['cache']) {
            $params[] = 'cache=' . $config['cache'];
        }
        if ($config['mode']) {
            $params[] = 'mode=' . $config['mode'];
        }
        if ($params) {
            $dsn = 'sqlite:file:' . $config['database'] . '?' . implode('&', $params);
        } else {
            $dsn = 'sqlite:' . $config['database'];
        }
        $this->pdo = $this->create_pdo($dsn, $config);
        if ($chmod_file) {
            // phpcs:disable
            @chmod($config['database'], $config['mask']);
            // phpcs:enable
        }
        if (!empty($config['init'])) {
            foreach ((array) $config['init'] as $command) {
                $this->pdo->exec($command);
            }
        }
    }
    /**
     * Returns whether php is able to use this driver for connecting to database
     *
     * @return bool true if it is valid to use this driver
     */
    public function enabled(): bool
    {
        return in_array('sqlite', PDO::get_available_drivers(), true);
    }
    /**
     * Get the SQL for disabling foreign keys.
     */
    public function disable_foreign_key_sql(): string
    {
        return 'PRAGMA foreign_keys = OFF';
    }
    /**
     * @inheritDoc
     */
    public function enable_foreign_key_sql(): string
    {
        return 'PRAGMA foreign_keys = ON';
    }
    /**
     * @inheritDoc
     */
    public function supports(Driver_Feature_Enum $feature): bool
    {
        return match ($feature) {
            Driver_Feature_Enum::DISABLE_CONSTRAINT_WITHOUT_TRANSACTION, Driver_Feature_Enum::SAVEPOINT, Driver_Feature_Enum::TRUNCATE_WITH_CONSTRAINTS => true,
            Driver_Feature_Enum::JSON => false,
            Driver_Feature_Enum::CTE, Driver_Feature_Enum::WINDOW => version_compare($this->version(), $this->feature_versions[$feature->value], '>='),
            Driver_Feature_Enum::INTERSECT => true,
            Driver_Feature_Enum::INTERSECT_ALL => false,
            Driver_Feature_Enum::SET_OPERATIONS_ORDER_BY => false,
            Driver_Feature_Enum::OPTIMIZER_HINT_COMMENT => false,
            Driver_Feature_Enum::CHECK_CONSTRAINTS => true,
        };
    }
    /**
     * @inheritDoc
     */
    public function schema_dialect(): Schema_Dialect
    {
        return $this->_schema_dialect ?? $this->_schema_dialect = new Sqlite_Schema_Dialect($this);
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
                // CONCAT function is expressed as exp1 || exp2
                $expression->set_name('')->set_conjunction(' ||');
                break;
            case 'DATEDIFF':
                $expression->set_name('ROUND')->set_conjunction('-')->iterate_parts(fn($p) => new Function_Expression('JULIANDAY', [$p['value']], [$p['type']]));
                break;
            case 'NOW':
                $expression->set_name('DATETIME')->add(["'now'" => 'literal']);
                break;
            case 'RAND':
                $expression->set_name('ABS')->add(['RANDOM() % 1' => 'literal'], [], true);
                break;
            case 'CURRENT_DATE':
                $expression->set_name('DATE')->add(["'now'" => 'literal']);
                break;
            case 'CURRENT_TIME':
                $expression->set_name('TIME')->add(["'now'" => 'literal']);
                break;
            case 'EXTRACT':
                $expression->set_name('STRFTIME')->set_conjunction(' ,')->iterate_parts(function ($p, $key) {
                    if ($key === 0) {
                        $value = rtrim(strtolower($p), 's');
                        if (isset($this->_date_parts[$value])) {
                            $p = ['value' => '%' . $this->_date_parts[$value], 'type' => null];
                        }
                    }
                    return $p;
                });
                break;
            case 'DATE_ADD':
                $expression->set_name('DATE')->set_conjunction(',')->iterate_parts(function ($p, $key) {
                    if ($key === 1) {
                        return ['value' => $p, 'type' => null];
                    }
                    return $p;
                });
                break;
            case 'DAYOFWEEK':
                $expression->set_name('STRFTIME')->set_conjunction(' ')->add(["'%w', " => 'literal'], [], true)->add([') + (1' => 'literal']);
                // Sqlite starts on index 0 but Sunday should be 1
                break;
            case 'JSON_VALUE':
                $expression->set_name('JSON_EXTRACT');
                break;
        }
    }
}