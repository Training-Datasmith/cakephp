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
use Cake\Database\Expression\Identifier_Expression;
use Cake\Database\Expression\String_Expression;
use Cake\Database\Postgres_Compiler;
use Cake\Database\Query\Insert_Query;
use Cake\Database\Query\Select_Query;
use Cake\Database\Query_Compiler;
use Cake\Database\Schema\Postgres_Schema_Dialect;
use Cake\Database\Schema\Schema_Dialect;
use PDO;
/**
 * Class Postgres
 */
class Postgres extends Driver
{
    /**
     * @inheritDoc
     */
    protected const MAX_ALIAS_LENGTH = 63;
    /**
     * Base configuration settings for Postgres driver
     *
     * @var array<string, mixed>
     */
    protected array $_base_config = ['persistent' => true, 'host' => 'localhost', 'username' => 'root', 'password' => '', 'database' => 'cake', 'schema' => 'public', 'port' => 5432, 'encoding' => 'utf8', 'timezone' => null, 'flags' => [], 'init' => [], 'ssl_key' => null, 'ssl_cert' => null, 'ssl_ca' => null, 'ssl' => false, 'ssl_mode' => null];
    /**
     * String used to start a database identifier quoting to make it safe
     */
    protected string $_start_quote = '"';
    /**
     * String used to end a database identifier quoting to make it safe
     */
    protected string $_end_quote = '"';
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
        if (empty($config['unix_socket'])) {
            $dsn = "pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}";
        } else {
            $dsn = "pgsql:dbname={$config['database']}";
        }
        if ($this->_config['ssl']) {
            if ($this->_config['ssl_mode']) {
                $dsn .= ';sslmode=' . $this->_config['ssl_mode'];
            } else {
                $dsn .= ';sslmode=allow';
            }
            if ($this->_config['ssl_key']) {
                $dsn .= ';sslkey=' . $this->_config['ssl_key'];
            }
            if ($this->_config['ssl_cert']) {
                $dsn .= ';sslcert=' . $this->_config['ssl_cert'];
            }
            if ($this->_config['ssl_ca']) {
                $dsn .= ';sslrootcert=' . $this->_config['ssl_ca'];
            }
        }
        $this->pdo = $this->create_pdo($dsn, $config);
        if (!empty($config['encoding'])) {
            $this->set_encoding($config['encoding']);
        }
        if (!empty($config['schema'])) {
            $this->set_schema($config['schema']);
        }
        if (!empty($config['timezone'])) {
            $config['init'][] = sprintf('SET timezone = %s', $this->get_pdo()->quote($config['timezone']));
        }
        foreach ($config['init'] as $command) {
            /** @phpstan-ignore-next-line */
            $this->pdo->exec($command);
        }
    }
    /**
     * Returns whether php is able to use this driver for connecting to database
     *
     * @return bool true if it is valid to use this driver
     */
    public function enabled(): bool
    {
        return in_array('pgsql', PDO::get_available_drivers(), true);
    }
    /**
     * @inheritDoc
     */
    public function schema_dialect(): Schema_Dialect
    {
        return $this->_schema_dialect ?? $this->_schema_dialect = new Postgres_Schema_Dialect($this);
    }
    /**
     * Sets connection encoding
     *
     * @param string $encoding The encoding to use.
     */
    public function set_encoding(string $encoding): void
    {
        $pdo = $this->get_pdo();
        $pdo->exec('SET NAMES ' . $pdo->quote($encoding));
    }
    /**
     * Sets connection default schema, if any relation defined in a query is not fully qualified
     * postgres will fallback to looking the relation into defined default schema
     *
     * @param string $schema The schema names to set `search_path` to.
     */
    public function set_schema(string $schema): void
    {
        $pdo = $this->get_pdo();
        $pdo->exec('SET search_path TO ' . $pdo->quote($schema));
    }
    /**
     * Get the SQL for disabling foreign keys.
     */
    public function disable_foreign_key_sql(): string
    {
        return 'SET CONSTRAINTS ALL DEFERRED';
    }
    /**
     * @inheritDoc
     */
    public function enable_foreign_key_sql(): string
    {
        return 'SET CONSTRAINTS ALL IMMEDIATE';
    }
    /**
     * @inheritDoc
     */
    public function supports(Driver_Feature_Enum $feature): bool
    {
        return match ($feature) {
            Driver_Feature_Enum::CTE, Driver_Feature_Enum::JSON, Driver_Feature_Enum::SAVEPOINT, Driver_Feature_Enum::TRUNCATE_WITH_CONSTRAINTS, Driver_Feature_Enum::WINDOW => true,
            Driver_Feature_Enum::INTERSECT => true,
            Driver_Feature_Enum::INTERSECT_ALL => true,
            Driver_Feature_Enum::SET_OPERATIONS_ORDER_BY => true,
            Driver_Feature_Enum::DISABLE_CONSTRAINT_WITHOUT_TRANSACTION => false,
            Driver_Feature_Enum::OPTIMIZER_HINT_COMMENT => true,
            Driver_Feature_Enum::CHECK_CONSTRAINTS => true,
        };
    }
    /**
     * @inheritDoc
     */
    protected function _transform_distinct(Select_Query $query): Select_Query
    {
        return $query;
    }
    /**
     * @inheritDoc
     */
    protected function _insert_query_translator(Insert_Query $query): Insert_Query
    {
        if (!$query->clause('epilog')) {
            $query->epilog('RETURNING *');
        }
        return $query;
    }
    /**
     * @inheritDoc
     */
    protected function _expression_translators(): array
    {
        return [Identifier_Expression::class => '_transformIdentifierExpression', Function_Expression::class => '_transformFunctionExpression', String_Expression::class => '_transformStringExpression'];
    }
    /**
     * Changes identifier expression into postgresql format.
     *
     * @param \Cake\Database\Expression\IdentifierExpression $expression The expression to transform.
     */
    protected function _transform_identifier_expression(Identifier_Expression $expression): void
    {
        $collation = $expression->get_collation();
        if ($collation) {
            // use trim() to work around expression being transformed multiple times
            $expression->set_collation('"' . trim($collation, '"') . '"');
        }
    }
    /**
     * Receives a FunctionExpression and changes it so that it conforms to this
     * SQL dialect.
     *
     * @param \Cake\Database\Expression\FunctionExpression $expression The function expression to convert
     *   to postgres SQL.
     */
    protected function _transform_function_expression(Function_Expression $expression): void
    {
        switch ($expression->get_name()) {
            case 'CONCAT':
                // CONCAT function is expressed as exp1 || exp2
                $expression->set_name('')->set_conjunction(' ||');
                break;
            case 'DATEDIFF':
                $expression->set_name('')->set_conjunction('-')->iterate_parts(function ($p): \Cake\Database\Expression\Function_Expression {
                    if (is_string($p)) {
                        $p = ['value' => [$p => 'literal'], 'type' => null];
                    } else {
                        $p['value'] = [$p['value']];
                    }
                    return new Function_Expression('DATE', $p['value'], [$p['type']]);
                });
                break;
            case 'CURRENT_DATE':
                $time = new Function_Expression('LOCALTIMESTAMP', [' 0 ' => 'literal']);
                $expression->set_name('CAST')->set_conjunction(' AS ')->add([$time, 'date' => 'literal']);
                break;
            case 'CURRENT_TIME':
                $time = new Function_Expression('LOCALTIMESTAMP', [' 0 ' => 'literal']);
                $expression->set_name('CAST')->set_conjunction(' AS ')->add([$time, 'time' => 'literal']);
                break;
            case 'NOW':
                $expression->set_name('LOCALTIMESTAMP')->add([' 0 ' => 'literal']);
                break;
            case 'RAND':
                $expression->set_name('RANDOM');
                break;
            case 'DATE_ADD':
                $expression->set_name('')->set_conjunction(' + INTERVAL')->iterate_parts(function (string $p, $key): string {
                    if ($key === 1) {
                        return sprintf("'%s'", $p);
                    }
                    return $p;
                });
                break;
            case 'DAYOFWEEK':
                $expression->set_name('EXTRACT')->set_conjunction(' ')->add(['DOW FROM' => 'literal'], [], true)->add([') + (1' => 'literal']);
                // Postgres starts on index 0 but Sunday should be 1
                break;
            case 'JSON_VALUE':
                $expression->set_name('JSONB_PATH_QUERY')->iterate_parts(function ($p, $key) {
                    if ($key === 0) {
                        $p = sprintf('%s::jsonb', $p);
                    } elseif ($key === 1) {
                        $p = sprintf("'%s'::jsonpath", $this->quote_identifier($p['value']));
                    }
                    return $p;
                });
                break;
        }
    }
    /**
     * Changes string expression into postgresql format.
     *
     * @param \Cake\Database\Expression\StringExpression $expression The string expression to transform.
     */
    protected function _transform_string_expression(String_Expression $expression): void
    {
        // use trim() to work around expression being transformed multiple times
        $expression->set_collation('"' . trim($expression->get_collation(), '"') . '"');
    }
    /**
     * {@inheritDoc}
     *
     * @return \Cake\Database\PostgresCompiler
     */
    public function new_compiler(): Query_Compiler
    {
        return new Postgres_Compiler();
    }
}