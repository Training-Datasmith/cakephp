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
namespace Cake\Database\Schema;

use Cake\Database\Driver\Mysql;
use Cake\Database\Driver_Feature_Enum;
use Cake\Database\Exception\Database_Exception;
use PDOException;
/**
 * Schema generation/reflection features for MySQL
 *
 * @internal
 */
class Mysql_Schema_Dialect extends Schema_Dialect
{
    /**
     * Generate the SQL to list the tables and views.
     *
     * @param array<string, mixed> $config The connection configuration to use for
     *    getting tables from.
     * @return array<mixed> An array of (sql, params) to execute.
     */
    public function list_tables_sql(array $config): array
    {
        return ['SHOW FULL TABLES FROM ' . $this->_driver->quote_identifier($config['database']) . " WHERE TABLE_TYPE IN ('BASE TABLE', 'VIEW')", []];
    }
    /**
     * Generate the SQL to list the tables, excluding all views.
     *
     * @param array<string, mixed> $config The connection configuration to use for
     *    getting tables from.
     * @return array<mixed> An array of (sql, params) to execute.
     */
    public function list_tables_without_views_sql(array $config): array
    {
        return ['SHOW FULL TABLES FROM ' . $this->_driver->quote_identifier($config['database']) . ' WHERE TABLE_TYPE = "BASE TABLE"', []];
    }
    /**
     * @inheritDoc
     */
    public function describe_column_sql(string $table_name, array $config): array
    {
        $sql = $this->describe_column_query($table_name);
        return [$sql, []];
    }
    /**
     * Helper method for creating SQL to describe columns in a table.
     *
     * @param string $tableName The table to describe.
     * @return string SQL to reflect columns
     */
    private function describe_column_query(string $table_name): string
    {
        return 'SHOW FULL COLUMNS FROM ' . $this->_driver->quote_identifier($table_name);
    }
    /**
     * Split a table name into a tuple of database, table
     * If the table does not have a database name included, the connection
     * database will be used.
     *
     * @param string $tableName The table name to split
     * @return array<string> A tuple of [database, tablename]
     */
    private function split_table_name(string $table_name): array
    {
        $config = $this->_driver->config();
        $db = $config['database'];
        if (str_contains($table_name, '.')) {
            return explode('.', $table_name);
        }
        return [$db, $table_name];
    }
    /**
     * Get a list of column metadata as a array
     *
     * Each item in the array will contain the following:
     *
     * - name : the name of the column.
     * - type : the abstract type of the column.
     * - length : the length of the column.
     * - default : the default value of the column or null.
     * - null : boolean indicating whether the column can be null.
     * - comment : the column comment or null.
     *
     * The following keys will be set as required:
     *
     * - autoIncrement : set for columns that are an integer primary key.
     * - onUpdate : set for datetime/timestamp columns with `ON UPDATE` clauses.
     *
     * @param string $tableName The name of the table to describe columns on.
     */
    public function describe_columns(string $table_name): array
    {
        $sql = $this->describe_column_query($table_name);
        try {
            $rows = $this->_driver->execute($sql)->fetch_all('assoc');
        } catch (PDOException $e) {
            throw new Database_Exception("Could not describe columns on `{$table_name}`", null, $e);
        }
        $geometry_columns = [];
        if (array_intersect(array_column($rows, 'Type'), Table_Schema_Interface::GEOSPATIAL_TYPES)) {
            $geometry_columns = $this->describe_geometry_columns($table_name);
        }
        $columns = [];
        foreach ($rows as $row) {
            $field = $this->_convert_column($row['Type']);
            $default = $this->parse_default($field['type'], $row);
            $field += ['name' => $row['Field'], 'null' => $row['Null'] === 'YES', 'default' => $default, 'collate' => $row['Collation'], 'comment' => $row['Comment'], 'length' => null];
            $extra = trim($row['Extra'] ?? '');
            if ($extra === 'auto_increment') {
                $field['autoIncrement'] = true;
            }
            // Depending on the MySQL Version the extra column can contain/start with DEFAULT_GENERATED as well
            if (str_ends_with($extra, 'on update CURRENT_TIMESTAMP') || str_ends_with($extra, 'on update current_timestamp()')) {
                $field['onUpdate'] = 'CURRENT_TIMESTAMP';
            }
            $srid = $geometry_columns[$field['name']]['srid'] ?? null;
            if ($srid !== null) {
                $field['srid'] = $srid;
            }
            $columns[] = $field;
        }
        return $columns;
    }
    /**
     * Describes geometry-specific column information.
     *
     * @param string $table The table name.
     * @return array<string, array{name: string, srid: int}> The column information.
     */
    private function describe_geometry_columns(string $table): array
    {
        /** @var \Cake\Database\Driver\Mysql $driver */
        $driver = $this->_driver;
        if (!$driver->is_maria_db() && version_compare($driver->version(), '8.0.1', '>=')) {
            $sql = <<<SQL
            SELECT
                COLUMN_NAME AS name,
                SRS_ID AS srid
            FROM information_schema.ST_GEOMETRY_COLUMNS
            WHERE TABLE_NAME = ? AND TABLE_SCHEMA = ?
            SQL;
        } else {
            return [];
        }
        $schema = $driver->config()['database'];
        $columns = $this->_driver->execute($sql, [$table, $schema])->fetch_all('assoc');
        return array_combine(array_column($columns, 'name'), $columns);
    }
    /**
     * Parse the default value if required.
     *
     * @param string $type The type of column
     * @param array $row a Row of schema reflection data
     * @return ?string The default value of a column.
     */
    protected function parse_default(string $type, array $row): ?string
    {
        $default = $row['Default'];
        if (is_string($default) && in_array($type, array_merge(Table_Schema::GEOSPATIAL_TYPES, [Table_Schema::TYPE_BINARY, Table_Schema::TYPE_JSON, Table_Schema::TYPE_TEXT]))) {
            // The default that comes back from MySQL for these types prefixes the collation type and
            // surrounds the value with escaped single quotes, for example "_utf8mbf4\'abc\'", and so
            // this converts that then down to the default value of "abc" to correspond to what the user
            // would have specified in a migration.
            $default = (string) preg_replace("/^_(?:[a-zA-Z0-9]+?)\\\\'(.*)\\\\'\$/", '\1', $default);
            // If the default is wrapped in a function, and has a collation marker on it, strip
            // the collation marker out
            $default = (string) preg_replace("/^(?<prefix>[a-zA-Z0-9_]*\\()(?<collation>_[a-zA-Z0-9]+)\\\\'(?<args>.*)\\\\'\\)\$/", "\\1'\\3')", $default);
        }
        if ($this->_driver instanceof Mysql && $this->_driver->is_maria_db() && $default === 'current_timestamp()') {
            return 'CURRENT_TIMESTAMP';
        }
        return $default;
    }
    /**
     * @inheritDoc
     */
    public function describe_index_sql(string $table_name, array $config): array
    {
        $sql = $this->describe_index_query($table_name);
        return [$sql, []];
    }
    /**
     * Helper method for creating SQL to reflect indexes in a table.
     *
     * @param string $tableName The table to get indexes from.
     * @return string SQL to reflect indexes
     */
    private function describe_index_query(string $table_name): string
    {
        return 'SHOW INDEXES FROM ' . $this->_driver->quote_identifier($table_name);
    }
    /**
     * @inheritDoc
     */
    public function describe_indexes(string $table_name): array
    {
        $sql = $this->describe_index_query($table_name);
        $statement = $this->_driver->execute($sql);
        $indexes = [];
        foreach ($statement->fetch_all('assoc') as $row) {
            $name = $row['Key_name'];
            $type = null;
            if ($name === 'PRIMARY') {
                $name = Table_Schema::CONSTRAINT_PRIMARY;
                $type = Table_Schema::CONSTRAINT_PRIMARY;
            }
            if ($row['Index_type'] === 'FULLTEXT') {
                $type = Table_Schema::INDEX_FULLTEXT;
            } elseif ((int) $row['Non_unique'] === 0 && $type !== Table_Schema::CONSTRAINT_PRIMARY) {
                $type = Table_Schema::CONSTRAINT_UNIQUE;
            } elseif ($type !== Table_Schema::CONSTRAINT_PRIMARY) {
                $type = Table_Schema::INDEX_INDEX;
            }
            if (!isset($indexes[$name])) {
                $indexes[$name] = ['name' => $name, 'type' => $type, 'columns' => [], 'length' => []];
            }
            // conditional indexes can have null columns
            if ($row['Column_name'] !== null) {
                $indexes[$name]['columns'][] = $row['Column_name'];
            }
            if (!empty($row['Sub_part'])) {
                $indexes[$name]['length'][$row['Column_name']] = $row['Sub_part'];
            }
        }
        return array_values($indexes);
    }
    /**
     * @inheritDoc
     */
    public function describe_options_sql(string $table_name, array $config): array
    {
        return ['SHOW TABLE STATUS WHERE Name = ?', [$table_name]];
    }
    /**
     * @inheritDoc
     */
    public function convert_options_description(Table_Schema $schema, array $row): void
    {
        $schema->set_options(['engine' => $row['Engine'], 'collation' => $row['Collation']]);
    }
    /**
     * @inheritDoc
     */
    public function describe_options(string $table_name): array
    {
        [, $name] = $this->split_table_name($table_name);
        $sql = 'SHOW TABLE STATUS WHERE Name = ?';
        $statement = $this->_driver->execute($sql, [$name]);
        $row = $statement->fetch('assoc');
        return ['engine' => $row['Engine'], 'collation' => $row['Collation']];
    }
    /**
     * Convert a MySQL column type into an abstract type.
     *
     * The returned type will be a type that Cake\Database\TypeFactory can handle.
     *
     * @param string $column The column type + length
     * @return array<string, mixed> Array of column information.
     * @throws \Cake\Database\Exception\DatabaseException When column type cannot be parsed.
     */
    protected function _convert_column(string $column): array
    {
        preg_match('/([a-z]+)(?:\(([0-9,]+)\))?\s*([a-z]+)?/i', $column, $matches);
        if (!$matches) {
            throw new Database_Exception(sprintf('Unable to parse column type from `%s`', $column));
        }
        $col = strtolower($matches[1]);
        $length = null;
        $precision = null;
        $scale = null;
        if (isset($matches[2]) && strlen($matches[2])) {
            $length = $matches[2];
            if (str_contains($matches[2], ',')) {
                [$length, $precision] = explode(',', $length);
            }
            $length = (int) $length;
            $precision = (int) $precision;
        }
        $type = $this->_apply_type_specific_column_conversion($col, compact('length', 'precision', 'scale'));
        if ($type !== null) {
            return $type;
        }
        if (in_array($col, ['date', 'time', 'year'])) {
            return ['type' => $col, 'length' => null];
        }
        if (in_array($col, ['datetime', 'timestamp'])) {
            $type_name = $col;
            if ($length > 0) {
                $type_name = $col . 'fractional';
            }
            return ['type' => $type_name, 'length' => null, 'precision' => $length];
        }
        if ($col === 'tinyint' && $length === 1 || $col === 'boolean') {
            return ['type' => Table_Schema_Interface::TYPE_BOOLEAN, 'length' => null];
        }
        if ($col === 'bit') {
            return ['type' => Table_Schema_Interface::TYPE_BIT, 'length' => $length];
        }
        $unsigned = isset($matches[3]) && strtolower($matches[3]) === 'unsigned';
        if (str_contains($col, 'bigint')) {
            return ['type' => Table_Schema_Interface::TYPE_BIGINTEGER, 'length' => null, 'unsigned' => $unsigned];
        }
        if ($col === 'tinyint') {
            return ['type' => Table_Schema_Interface::TYPE_TINYINTEGER, 'length' => null, 'unsigned' => $unsigned];
        }
        if ($col === 'smallint') {
            return ['type' => Table_Schema_Interface::TYPE_SMALLINTEGER, 'length' => null, 'unsigned' => $unsigned];
        }
        if (in_array($col, ['int', 'integer', 'mediumint'])) {
            return ['type' => Table_Schema_Interface::TYPE_INTEGER, 'length' => null, 'unsigned' => $unsigned];
        }
        if ($col === 'char' && $length === 36) {
            return ['type' => Table_Schema_Interface::TYPE_UUID, 'length' => null];
        }
        if ($col === 'char') {
            return ['type' => Table_Schema_Interface::TYPE_CHAR, 'length' => $length];
        }
        if (str_contains($col, 'char')) {
            return ['type' => Table_Schema_Interface::TYPE_STRING, 'length' => $length];
        }
        if (str_contains($col, 'text')) {
            $length_name = substr($col, 0, -4);
            $length = Table_Schema::$column_lengths[$length_name] ?? null;
            return ['type' => Table_Schema_Interface::TYPE_TEXT, 'length' => $length];
        }
        if ($col === 'binary' && $length === 16) {
            return ['type' => Table_Schema_Interface::TYPE_BINARY_UUID, 'length' => null];
        }
        if ($col === 'uuid') {
            return ['type' => Table_Schema_Interface::TYPE_NATIVE_UUID, 'length' => null];
        }
        if (str_contains($col, 'blob') || in_array($col, ['binary', 'varbinary'])) {
            $length_name = substr($col, 0, -4);
            $length = Table_Schema::$column_lengths[$length_name] ?? $length;
            $result = ['type' => Table_Schema_Interface::TYPE_BINARY, 'length' => $length];
            if ($col === 'binary') {
                $result['fixed'] = true;
            }
            return $result;
        }
        if (str_contains($col, 'float') || str_contains($col, 'double')) {
            return ['type' => Table_Schema_Interface::TYPE_FLOAT, 'length' => $length, 'precision' => $precision, 'unsigned' => $unsigned];
        }
        if (str_contains($col, 'decimal')) {
            return ['type' => Table_Schema_Interface::TYPE_DECIMAL, 'length' => $length, 'precision' => $precision, 'unsigned' => $unsigned];
        }
        if (str_contains($col, 'json')) {
            return ['type' => Table_Schema_Interface::TYPE_JSON, 'length' => null];
        }
        if (in_array($col, Table_Schema_Interface::GEOSPATIAL_TYPES)) {
            // TODO how can srid be preserved? It doesn't come back
            // in the output of show full columns from ...
            return ['type' => $col, 'length' => null];
        }
        return ['type' => Table_Schema_Interface::TYPE_STRING, 'length' => null];
    }
    /**
     * @inheritDoc
     */
    public function convert_column_description(Table_Schema $schema, array $row): void
    {
        $field = $this->_convert_column($row['Type']);
        $default = $this->parse_default($field['type'], $row);
        $field += ['null' => $row['Null'] === 'YES', 'default' => $default, 'collate' => $row['Collation'], 'comment' => $row['Comment']];
        if (isset($row['Extra']) && $row['Extra'] === 'auto_increment') {
            $field['autoIncrement'] = true;
        }
        $schema->add_column($row['Field'], $field);
    }
    /**
     * @inheritDoc
     */
    public function convert_index_description(Table_Schema $schema, array $row): void
    {
        $type = null;
        $columns = [];
        $length = [];
        $name = $row['Key_name'];
        if ($name === 'PRIMARY') {
            $name = Table_Schema::CONSTRAINT_PRIMARY;
            $type = Table_Schema::CONSTRAINT_PRIMARY;
        }
        if (!empty($row['Column_name'])) {
            $columns[] = $row['Column_name'];
        }
        if ($row['Index_type'] === 'FULLTEXT') {
            $type = Table_Schema::INDEX_FULLTEXT;
        } elseif ((int) $row['Non_unique'] === 0 && $type !== 'primary') {
            $type = Table_Schema::CONSTRAINT_UNIQUE;
        } elseif ($type !== 'primary') {
            $type = Table_Schema::INDEX_INDEX;
        }
        if (!empty($row['Sub_part'])) {
            $length[$row['Column_name']] = $row['Sub_part'];
        }
        $is_index = $type === Table_Schema::INDEX_INDEX || $type === Table_Schema::INDEX_FULLTEXT;
        if ($is_index) {
            $existing = $schema->get_index($name);
        } else {
            $existing = $schema->get_constraint($name);
        }
        // MySQL multi column indexes come back as multiple rows.
        if ($existing) {
            $columns = array_merge($existing['columns'], $columns);
            $length = array_merge($existing['length'], $length);
        }
        if ($is_index) {
            $schema->add_index($name, ['type' => $type, 'columns' => $columns, 'length' => $length]);
        } else {
            $schema->add_constraint($name, ['type' => $type, 'columns' => $columns, 'length' => $length]);
        }
    }
    /**
     * @inheritDoc
     */
    public function describe_foreign_key_sql(string $table_name, array $config): array
    {
        $sql = 'SELECT * FROM information_schema.key_column_usage AS kcu
            INNER JOIN information_schema.referential_constraints AS rc
            ON (
                kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
                AND kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
            )
            WHERE kcu.TABLE_SCHEMA = ? AND kcu.TABLE_NAME = ? AND rc.TABLE_NAME = ?
            ORDER BY kcu.ORDINAL_POSITION ASC';
        return [$sql, [$config['database'], $table_name, $table_name]];
    }
    /**
     * @inheritDoc
     */
    public function convert_foreign_key_description(Table_Schema $schema, array $row): void
    {
        $data = ['type' => Table_Schema::CONSTRAINT_FOREIGN, 'columns' => [$row['COLUMN_NAME']], 'references' => [$row['REFERENCED_TABLE_NAME'], $row['REFERENCED_COLUMN_NAME']], 'update' => $this->_convert_on_clause($row['UPDATE_RULE']), 'delete' => $this->_convert_on_clause($row['DELETE_RULE'])];
        $name = $row['CONSTRAINT_NAME'];
        $schema->add_constraint($name, $data);
    }
    /**
     * @inheritDoc
     */
    public function describe_foreign_keys(string $table_name): array
    {
        [$database, $name] = $this->split_table_name($table_name);
        $sql = 'SELECT * FROM information_schema.key_column_usage AS kcu
            INNER JOIN information_schema.referential_constraints AS rc
            ON (
                kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
                AND kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
            )
            WHERE kcu.TABLE_SCHEMA = ? AND kcu.TABLE_NAME = ? AND rc.TABLE_NAME = ?
            ORDER BY kcu.ORDINAL_POSITION ASC';
        $statement = $this->_driver->execute($sql, [$database, $name, $name]);
        $keys = [];
        foreach ($statement->fetch_all('assoc') as $row) {
            $name = $row['CONSTRAINT_NAME'];
            if (!isset($keys[$name])) {
                $keys[$name] = ['name' => $name, 'type' => Table_Schema::CONSTRAINT_FOREIGN, 'columns' => [], 'references' => [$row['REFERENCED_TABLE_NAME'], []], 'update' => $this->_convert_on_clause($row['UPDATE_RULE'] ?? ''), 'delete' => $this->_convert_on_clause($row['DELETE_RULE'] ?? ''), 'length' => []];
            }
            // Add the columns incrementally
            $keys[$name]['columns'][] = $row['COLUMN_NAME'];
            $keys[$name]['references'][1][] = $row['REFERENCED_COLUMN_NAME'];
        }
        foreach ($keys as $id => $key) {
            if (count($key['references'][1]) === 1) {
                $keys[$id]['references'][1] = $key['references'][1][0];
            }
        }
        return array_values($keys);
    }
    /**
     * @inheritDoc
     */
    public function describe_check_constraints(string $table_name): array
    {
        if (!$this->_driver->supports(Driver_Feature_Enum::CHECK_CONSTRAINTS)) {
            return [];
        }
        [$schema, $name] = $this->split_tablename($table_name);
        $sql = <<<SQL
                SELECT
                cc.CONSTRAINT_NAME AS name,
                cc.CHECK_CLAUSE AS expression
                FROM INFORMATION_SCHEMA.CHECK_CONSTRAINTS AS cc
                INNER JOIN INFORMATION_SCHEMA.TABLE_CONSTRAINTS AS tc ON (
                    tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA
                    AND tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME
                )
                WHERE tc.CONSTRAINT_SCHEMA = ? AND tc.TABLE_NAME = ? AND tc.CONSTRAINT_TYPE = 'CHECK'
        SQL;
        $constraints = [];
        $statement = $this->_driver->execute($sql, [$schema, $name]);
        foreach ($statement->fetch_all('assoc') as $row) {
            $constraints[] = ['name' => $row['name'], 'type' => Table_Schema::CONSTRAINT_CHECK, 'expression' => $row['expression']];
        }
        return $constraints;
    }
    /**
     * @inheritDoc
     */
    public function truncate_table_sql(Table_Schema $schema): array
    {
        return [sprintf('TRUNCATE TABLE `%s`', $schema->name())];
    }
    /**
     * @inheritDoc
     */
    public function create_table_sql(Table_Schema $schema, array $columns, array $constraints, array $indexes): array
    {
        $content = implode(",\n", array_merge($columns, $constraints, $indexes));
        $temporary = $schema->is_temporary() ? ' TEMPORARY ' : ' ';
        $content = sprintf("CREATE%sTABLE `%s` (\n%s\n)", $temporary, $schema->name(), $content);
        $options = $schema->get_options();
        if (isset($options['engine'])) {
            $content .= sprintf(' ENGINE=%s', $options['engine']);
        }
        if (isset($options['charset'])) {
            $content .= sprintf(' DEFAULT CHARSET=%s', $options['charset']);
        }
        if (isset($options['collate'])) {
            $content .= sprintf(' COLLATE=%s', $options['collate']);
        }
        return [$content];
    }
    /**
     * Create a SQL snippet for a column based on the array shape
     * that `describeColumns()` creates.
     *
     * @param array $column The column metadata
     * @return string Generated SQL fragment for a column
     */
    public function column_definition_sql(array $column): string
    {
        $name = $column['name'];
        $column += ['length' => null];
        $out = $this->_driver->quote_identifier($name);
        $native_json = $this->_driver->supports(Driver_Feature_Enum::JSON);
        $type_map = [Table_Schema_Interface::TYPE_TINYINTEGER => ' TINYINT', Table_Schema_Interface::TYPE_SMALLINTEGER => ' SMALLINT', Table_Schema_Interface::TYPE_INTEGER => ' INTEGER', Table_Schema_Interface::TYPE_BIGINTEGER => ' BIGINT', Table_Schema_Interface::TYPE_BINARY_UUID => ' BINARY(16)', Table_Schema_Interface::TYPE_BOOLEAN => ' BOOLEAN', Table_Schema_Interface::TYPE_FLOAT => ' FLOAT', Table_Schema_Interface::TYPE_DECIMAL => ' DECIMAL', Table_Schema_Interface::TYPE_DATE => ' DATE', Table_Schema_Interface::TYPE_TIME => ' TIME', Table_Schema_Interface::TYPE_DATETIME => ' DATETIME', Table_Schema_Interface::TYPE_DATETIME_FRACTIONAL => ' DATETIME', Table_Schema_Interface::TYPE_TIMESTAMP => ' TIMESTAMP', Table_Schema_Interface::TYPE_TIMESTAMP_FRACTIONAL => ' TIMESTAMP', Table_Schema_Interface::TYPE_TIMESTAMP_TIMEZONE => ' TIMESTAMP', Table_Schema_Interface::TYPE_CHAR => ' CHAR', Table_Schema_Interface::TYPE_UUID => ' CHAR(36)', Table_Schema_Interface::TYPE_NATIVE_UUID => ' UUID', Table_Schema_Interface::TYPE_JSON => $native_json ? ' JSON' : ' LONGTEXT', Table_Schema_Interface::TYPE_GEOMETRY => ' GEOMETRY', Table_Schema_Interface::TYPE_POINT => ' POINT', Table_Schema_Interface::TYPE_LINESTRING => ' LINESTRING', Table_Schema_Interface::TYPE_POLYGON => ' POLYGON', Table_Schema_Interface::TYPE_BIT => ' BIT'];
        $special_map = ['string' => true, 'text' => true, 'char' => true, 'binary' => true];
        if (isset($type_map[$column['type']])) {
            $out .= $type_map[$column['type']];
        }
        if (isset($special_map[$column['type']])) {
            switch ($column['type']) {
                case Table_Schema_Interface::TYPE_STRING:
                    $out .= ' VARCHAR';
                    if (!isset($column['length'])) {
                        $column['length'] = 255;
                    }
                    break;
                case Table_Schema_Interface::TYPE_TEXT:
                    $is_known_length = in_array($column['length'], Table_Schema::$column_lengths);
                    if (empty($column['length']) || !$is_known_length) {
                        $out .= ' TEXT';
                        break;
                    }
                    $length = array_search($column['length'], Table_Schema::$column_lengths);
                    assert(is_string($length));
                    $out .= ' ' . strtoupper($length) . 'TEXT';
                    break;
                case Table_Schema_Interface::TYPE_BINARY:
                    $is_known_length = in_array($column['length'], Table_Schema::$column_lengths);
                    if ($is_known_length) {
                        $length = array_search($column['length'], Table_Schema::$column_lengths);
                        assert(is_string($length));
                        unset($column['length']);
                        $out .= ' ' . strtoupper($length) . 'BLOB';
                        break;
                    }
                    if (empty($column['length'])) {
                        $out .= ' BLOB';
                        break;
                    }
                    if (!empty($column['fixed'])) {
                        $out .= ' BINARY';
                    } else {
                        $out .= ' VARBINARY';
                    }
                    break;
            }
        }
        $has_length = [Table_Schema_Interface::TYPE_INTEGER, Table_Schema_Interface::TYPE_CHAR, Table_Schema_Interface::TYPE_SMALLINTEGER, Table_Schema_Interface::TYPE_TINYINTEGER, Table_Schema_Interface::TYPE_STRING, Table_Schema_Interface::TYPE_BINARY, Table_Schema_Interface::TYPE_BIT];
        if (!isset($type_map[$column['type']]) && !isset($special_map[$column['type']])) {
            $out .= ' ' . strtoupper((string) $column['type']);
            $has_length[] = $column['type'];
        }
        if (in_array($column['type'], $has_length, true) && isset($column['length'])) {
            $out .= '(' . $column['length'] . ')';
        }
        $length_and_precision_types = [Table_Schema_Interface::TYPE_FLOAT, Table_Schema_Interface::TYPE_DECIMAL];
        if (in_array($column['type'], $length_and_precision_types, true) && isset($column['length'])) {
            if (isset($column['precision'])) {
                $out .= '(' . (int) $column['length'] . ',' . (int) $column['precision'] . ')';
            } else {
                $out .= '(' . (int) $column['length'] . ')';
            }
        }
        $precision_types = [Table_Schema_Interface::TYPE_DATETIME_FRACTIONAL, Table_Schema_Interface::TYPE_TIMESTAMP_FRACTIONAL];
        if (in_array($column['type'], $precision_types, true) && isset($column['precision'])) {
            $out .= '(' . (int) $column['precision'] . ')';
        }
        $has_unsigned = [Table_Schema_Interface::TYPE_TINYINTEGER, Table_Schema_Interface::TYPE_SMALLINTEGER, Table_Schema_Interface::TYPE_INTEGER, Table_Schema_Interface::TYPE_BIGINTEGER, Table_Schema_Interface::TYPE_FLOAT, Table_Schema_Interface::TYPE_DECIMAL];
        if (in_array($column['type'], $has_unsigned, true) && isset($column['unsigned']) && $column['unsigned'] === true) {
            $out .= ' UNSIGNED';
        }
        $has_collate = [Table_Schema_Interface::TYPE_TEXT, Table_Schema_Interface::TYPE_CHAR, Table_Schema_Interface::TYPE_STRING, Table_Schema_Interface::TYPE_UUID];
        if (in_array($column['type'], $has_collate, true) && isset($column['collate']) && $column['collate'] !== '') {
            $out .= ' COLLATE ' . $column['collate'];
        }
        if (isset($column['null']) && $column['null'] === false) {
            $out .= ' NOT NULL';
        }
        if (isset($column['autoIncrement']) && $column['autoIncrement']) {
            $out .= ' AUTO_INCREMENT';
            unset($column['default']);
        }
        $timestamp_types = [Table_Schema_Interface::TYPE_TIMESTAMP, Table_Schema_Interface::TYPE_TIMESTAMP_FRACTIONAL, Table_Schema_Interface::TYPE_TIMESTAMP_TIMEZONE];
        if (isset($column['null']) && $column['null'] === true && in_array($column['type'], $timestamp_types, true)) {
            $out .= ' NULL';
            unset($column['default']);
        }
        if (isset($column['srid']) && in_array($column['type'], Table_Schema_Interface::GEOSPATIAL_TYPES)) {
            $out .= " SRID {$column['srid']}";
        }
        $default_expression_types = array_merge(Table_Schema_Interface::GEOSPATIAL_TYPES, [Table_Schema_Interface::TYPE_BINARY, Table_Schema_Interface::TYPE_TEXT, Table_Schema_Interface::TYPE_JSON]);
        if (in_array($column['type'], $default_expression_types) && isset($column['default'])) {
            // Geospatial, blob and text types need to be wrapped in () to create an expression.
            $out .= ' DEFAULT (' . $this->_driver->schema_value($column['default']) . ')';
            unset($column['default']);
        }
        $date_time_types = [Table_Schema_Interface::TYPE_DATETIME, Table_Schema_Interface::TYPE_DATETIME_FRACTIONAL, Table_Schema_Interface::TYPE_TIMESTAMP, Table_Schema_Interface::TYPE_TIMESTAMP_FRACTIONAL, Table_Schema_Interface::TYPE_TIMESTAMP_TIMEZONE];
        if (isset($column['default']) && in_array($column['type'], $date_time_types) && is_string($column['default']) && str_contains(strtolower($column['default']), 'current_timestamp')) {
            $out .= ' DEFAULT CURRENT_TIMESTAMP';
            if (isset($column['precision'])) {
                $out .= '(' . $column['precision'] . ')';
            }
            unset($column['default']);
        }
        if (isset($column['default'])) {
            $out .= ' DEFAULT ' . $this->_driver->schema_value($column['default']);
            unset($column['default']);
        }
        if (isset($column['comment']) && $column['comment'] !== '') {
            // Always quote comments as strings to prevent SQL syntax errors with numeric comments
            // See: https://github.com/cakephp/migrations/issues/889
            $out .= ' COMMENT ' . $this->_driver->quote((string) $column['comment']);
        }
        if (isset($column['onUpdate']) && $column['onUpdate'] !== '') {
            $out .= ' ON UPDATE ' . $column['onUpdate'];
        }
        return $out;
    }
    /**
     * @inheritDoc
     */
    public function column_sql(Table_Schema $schema, string $name): string
    {
        $data = $schema->get_column($name);
        assert($data !== null);
        // TODO deprecate Type defined schema mappings?
        $sql = $this->_get_type_specific_column_sql($data['type'], $schema, $name);
        if ($sql !== null) {
            return $sql;
        }
        $data['name'] = $name;
        $auto_increment_types = [Table_Schema_Interface::TYPE_TINYINTEGER, Table_Schema_Interface::TYPE_SMALLINTEGER, Table_Schema_Interface::TYPE_INTEGER, Table_Schema_Interface::TYPE_BIGINTEGER];
        if (in_array($data['type'], $auto_increment_types, true) && $schema->get_primary_key() === [$name] && $name === 'id') {
            $data['autoIncrement'] = true;
        }
        return $this->column_definition_sql($data);
    }
    /**
     * @inheritDoc
     */
    public function constraint_sql(Table_Schema $schema, string $name): string
    {
        $data = $schema->get_constraint($name);
        assert($data !== null);
        if ($data['type'] === Table_Schema::CONSTRAINT_PRIMARY) {
            $columns = array_map($this->_driver->quote_identifier(...), $data['columns']);
            return sprintf('PRIMARY KEY (%s)', implode(', ', $columns));
        }
        $out = '';
        if ($data['type'] === Table_Schema::CONSTRAINT_UNIQUE) {
            $out = 'UNIQUE KEY ';
        } elseif ($data['type'] === Table_Schema::CONSTRAINT_FOREIGN) {
            $out = 'CONSTRAINT ';
        } elseif ($data['type'] === Table_Schema::CONSTRAINT_CHECK) {
            return 'CONSTRAINT ' . $this->_driver->quote_identifier($name) . ' CHECK (' . $data['expression'] . ')';
        }
        $out .= $this->_driver->quote_identifier($name);
        return $this->_key_sql($out, $data);
    }
    /**
     * @inheritDoc
     */
    public function add_constraint_sql(Table_Schema $schema): array
    {
        $sql_pattern = 'ALTER TABLE %s ADD %s;';
        $sql = [];
        foreach ($schema->constraints() as $name) {
            $constraint = $schema->get_constraint($name);
            assert($constraint !== null);
            if ($constraint['type'] === Table_Schema::CONSTRAINT_FOREIGN) {
                $table_name = $this->_driver->quote_identifier($schema->name());
                $sql[] = sprintf($sql_pattern, $table_name, $this->constraint_sql($schema, $name));
            }
        }
        return $sql;
    }
    /**
     * @inheritDoc
     */
    public function drop_constraint_sql(Table_Schema $schema): array
    {
        $sql_pattern = 'ALTER TABLE %s DROP FOREIGN KEY %s;';
        $sql = [];
        foreach ($schema->constraints() as $name) {
            $constraint = $schema->get_constraint($name);
            assert($constraint !== null);
            if ($constraint['type'] === Table_Schema::CONSTRAINT_FOREIGN) {
                $table_name = $this->_driver->quote_identifier($schema->name());
                $constraint_name = $this->_driver->quote_identifier($name);
                $sql[] = sprintf($sql_pattern, $table_name, $constraint_name);
            }
        }
        return $sql;
    }
    /**
     * @inheritDoc
     */
    public function index_sql(Table_Schema $schema, string $name): string
    {
        $data = $schema->get_index($name);
        assert($data !== null);
        $out = '';
        if ($data['type'] === Table_Schema::INDEX_INDEX) {
            $out = 'KEY ';
        }
        if ($data['type'] === Table_Schema::INDEX_FULLTEXT) {
            $out = 'FULLTEXT KEY ';
        }
        $out .= $this->_driver->quote_identifier($name);
        return $this->_key_sql($out, $data);
    }
    /**
     * Helper method for generating key SQL snippets.
     *
     * @param string $prefix The key prefix
     * @param array $data Key data.
     */
    protected function _key_sql(string $prefix, array $data): string
    {
        $columns = array_map($this->_driver->quote_identifier(...), $data['columns']);
        foreach ($data['columns'] as $i => $column) {
            if (isset($data['length'][$column])) {
                $columns[$i] .= sprintf('(%d)', $data['length'][$column]);
            }
        }
        if ($data['type'] === Table_Schema::CONSTRAINT_FOREIGN) {
            return $prefix . sprintf(' FOREIGN KEY (%s) REFERENCES %s (%s) ON UPDATE %s ON DELETE %s', implode(', ', $columns), $this->_driver->quote_identifier($data['references'][0]), $this->_convert_constraint_columns($data['references'][1]), $this->_foreign_on_clause($data['update']), $this->_foreign_on_clause($data['delete']));
        }
        return $prefix . ' (' . implode(', ', $columns) . ')';
    }
}