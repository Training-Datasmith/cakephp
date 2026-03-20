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
use Cake\Database\Query;
use Cake\Database\Query\Select_Query;
use Cake\Database\Schema\Mysql_Schema_Dialect;
use Cake\Database\Schema\Schema_Dialect;
use Cake\Database\Statement_Interface;
use PDO;
use Pdo\Mysql as PdoMysql;
/**
 * MySQL Driver
 */
class Mysql extends Driver
{
    /**
     * @inheritDoc
     */
    protected const MAX_ALIAS_LENGTH = 256;
    /**
     * Server type MySQL
     *
     * @var string
     */
    protected const SERVER_TYPE_MYSQL = 'mysql';
    /**
     * Server type MariaDB
     *
     * @var string
     */
    protected const SERVER_TYPE_MARIADB = 'mariadb';
    /**
     * Base configuration settings for MySQL driver
     *
     * @var array<string, mixed>
     */
    protected array $_base_config = ['persistent' => true, 'host' => 'localhost', 'username' => 'root', 'password' => '', 'database' => 'cake', 'port' => '3306', 'flags' => [], 'encoding' => 'utf8mb4', 'timezone' => null, 'init' => []];
    /**
     * String used to start a database identifier quoting to make it safe
     */
    protected string $_start_quote = '`';
    /**
     * String used to end a database identifier quoting to make it safe
     */
    protected string $_end_quote = '`';
    /**
     * Server type.
     *
     * If the underlying server is MariaDB, its value will get set to `'mariadb'`
     * after `version()` method is called.
     */
    protected string $server_type = self::SERVER_TYPE_MYSQL;
    /**
     * Mapping of feature to db server version for feature availability checks.
     *
     * @var array<string, array<string, string>>
     */
    protected array $feature_versions = ['mysql' => ['json' => '5.7.0', 'cte' => '8.0.0', 'window' => '8.0.0', 'intersect' => '8.0.31', 'intersect-all' => '8.0.31', 'check-constraints' => '8.0.16'], 'mariadb' => ['json' => '10.2.7', 'cte' => '10.2.1', 'window' => '10.2.0', 'intersect' => '10.3.0', 'intersect-all' => '10.5.0', 'check-constraints' => '10.2.1']];
    /**
     * @inheritDoc
     */
    public function connect(): void
    {
        if ($this->pdo !== null) {
            return;
        }
        $config = $this->_config;
        if ($config['timezone'] === 'UTC') {
            $config['timezone'] = '+0:00';
        }
        if (!empty($config['timezone'])) {
            $config['init'][] = sprintf("SET time_zone = '%s'", $config['timezone']);
        }
        $config['flags'] += [PDO::ATTR_PERSISTENT => $config['persistent'], $this->attr_use_buffered_query_id() => true, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
        if (!empty($config['ssl_key']) && !empty($config['ssl_cert'])) {
            $config['flags'][$this->attr_ssl_key_id()] = $config['ssl_key'];
            $config['flags'][$this->attr_ssl_cert_id()] = $config['ssl_cert'];
        }
        if (!empty($config['ssl_ca'])) {
            $config['flags'][$this->attr_ssl_ca_id()] = $config['ssl_ca'];
        }
        if (empty($config['unix_socket'])) {
            $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']}";
        } else {
            $dsn = "mysql:unix_socket={$config['unix_socket']};dbname={$config['database']}";
        }
        if (!empty($config['encoding'])) {
            $dsn .= ";charset={$config['encoding']}";
        }
        $this->pdo = $this->create_pdo($dsn, $config);
        if (!empty($config['init'])) {
            foreach ((array) $config['init'] as $command) {
                $this->pdo->exec($command);
            }
        }
    }
    /**
     * @inheritDoc
     */
    public function run(Query $query): Statement_Interface
    {
        $statement = $this->prepare($query);
        $query->get_value_binder()->attach_to($statement);
        if ($query instanceof Select_Query) {
            try {
                $this->get_pdo()->set_attribute($this->attr_use_buffered_query_id(), $query->is_buffered_results_enabled());
                $this->execute_statement($statement);
            } finally {
                $this->get_pdo()->set_attribute($this->attr_use_buffered_query_id(), true);
            }
        } else {
            $this->execute_statement($statement);
        }
        return $statement;
    }
    /**
     * Returns whether php is able to use this driver for connecting to database
     *
     * @return bool true if it is valid to use this driver
     */
    public function enabled(): bool
    {
        return in_array('mysql', PDO::get_available_drivers(), true);
    }
    /**
     * @inheritDoc
     */
    public function schema_dialect(): Schema_Dialect
    {
        return $this->_schema_dialect ?? $this->_schema_dialect = new Mysql_Schema_Dialect($this);
    }
    /**
     * @inheritDoc
     */
    public function schema(): string
    {
        return $this->_config['database'];
    }
    /**
     * Get the SQL for disabling foreign keys.
     */
    public function disable_foreign_key_sql(): string
    {
        return 'SET foreign_key_checks = 0';
    }
    /**
     * @inheritDoc
     */
    public function enable_foreign_key_sql(): string
    {
        return 'SET foreign_key_checks = 1';
    }
    /**
     * @inheritDoc
     */
    public function supports(Driver_Feature_Enum $feature): bool
    {
        $version_compare = fn() => version_compare($this->version(), $this->feature_versions[$this->server_type][$feature->value], '>=');
        return match ($feature) {
            Driver_Feature_Enum::DISABLE_CONSTRAINT_WITHOUT_TRANSACTION, Driver_Feature_Enum::SAVEPOINT => true,
            Driver_Feature_Enum::TRUNCATE_WITH_CONSTRAINTS => false,
            Driver_Feature_Enum::CTE, Driver_Feature_Enum::JSON, Driver_Feature_Enum::WINDOW => $version_compare(),
            Driver_Feature_Enum::INTERSECT => $version_compare(),
            Driver_Feature_Enum::INTERSECT_ALL => $version_compare(),
            Driver_Feature_Enum::CHECK_CONSTRAINTS => $version_compare(),
            Driver_Feature_Enum::SET_OPERATIONS_ORDER_BY => true,
            Driver_Feature_Enum::OPTIMIZER_HINT_COMMENT => true,
        };
    }
    /**
     * Returns true if the connected server is MariaDB.
     */
    public function is_mariadb(): bool
    {
        $this->version();
        return $this->server_type === static::SERVER_TYPE_MARIADB;
    }
    /**
     * Returns connected server version.
     */
    public function version(): string
    {
        if ($this->_version === null) {
            $this->_version = (string) $this->get_pdo()->get_attribute(PDO::ATTR_SERVER_VERSION);
            if (str_contains($this->_version, 'MariaDB')) {
                $this->server_type = static::SERVER_TYPE_MARIADB;
                preg_match('/^(?:5\.5\.5-)?(\d+\.\d+\.\d+.*-MariaDB[^:]*)/', $this->_version, $matches);
                $this->_version = $matches[1];
            }
        }
        return $this->_version;
    }
    /**
     * Get PDO ATTR_SSL_KEY id.
     */
    private function attr_ssl_key_id(): int
    {
        return PHP_VERSION_ID < 80400 ? PDO::MYSQL_ATTR_SSL_KEY : Pdo_Mysql::ATTR_SSL_KEY;
    }
    /**
     * Get PDO ATTR_SSL_CERT id.
     */
    private function attr_ssl_cert_id(): int
    {
        return PHP_VERSION_ID < 80400 ? PDO::MYSQL_ATTR_SSL_CERT : Pdo_Mysql::ATTR_SSL_CERT;
    }
    /**
     * Get PDO ATTR_SSL_CA id.
     */
    private function attr_ssl_ca_id(): int
    {
        return PHP_VERSION_ID < 80400 ? PDO::MYSQL_ATTR_SSL_CA : Pdo_Mysql::ATTR_SSL_CA;
    }
    /**
     * Get PDO ATTR_USE_BUFFERED_QUERY id.
     */
    private function attr_use_buffered_query_id(): int
    {
        return PHP_VERSION_ID < 80400 ? PDO::MYSQL_ATTR_USE_BUFFERED_QUERY : Pdo_Mysql::ATTR_USE_BUFFERED_QUERY;
    }
}