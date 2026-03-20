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

/**
 * Schema management/reflection features for SQLServer.
 *
 * @internal
 */
class Sqlserver_Schema_Dialect extends Schema_Dialect
{
    /**
     * @var string
     */
    public const DEFAULT_SCHEMA_NAME = 'dbo';
    /**
     * Generate the SQL to list the tables and views.
     *
     * @param array<string, mixed> $config The connection configuration to use for
     *    getting tables from.
     * @return array An array of (sql, params) to execute.
     */
    public function list_tables_sql(array $config): array
    {
        $sql = "SELECT TABLE_NAME\n            FROM INFORMATION_SCHEMA.TABLES\n            WHERE TABLE_SCHEMA = ?\n            AND (TABLE_TYPE = 'BASE TABLE' OR TABLE_TYPE = 'VIEW')\n            ORDER BY TABLE_NAME";
        $schema = $config['schema'] ?? static::DEFAULT_SCHEMA_NAME;
        return [$sql, [$schema]];
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
        $sql = "SELECT TABLE_NAME\n            FROM INFORMATION_SCHEMA.TABLES\n            WHERE TABLE_SCHEMA = ?\n            AND (TABLE_TYPE = 'BASE TABLE')\n            ORDER BY TABLE_NAME";
        $schema = $config['schema'] ?? static::DEFAULT_SCHEMA_NAME;
        return [$sql, [$schema]];
    }
    /**
     * @inheritDoc
     */
    public function describe_column_sql(string $table_name, array $config): array
    {
        $sql = $this->describe_column_query();
        $schema = $config['schema'] ?? static::DEFAULT_SCHEMA_NAME;
        return [$sql, [$table_name, $schema]];
    }
    /**
     * Helper method for creating SQL to describe columns in a table.
     *
     * @return string SQL to reflect columns
     */
    private function describe_column_query(): string
    {
        return 'SELECT DISTINCT
            AC.column_id AS [column_id],
            AC.name AS [name],
            TY.name AS [type],
            AC.max_length AS [char_length],
            AC.precision AS [precision],
            AC.scale AS [scale],
            AC.is_identity AS [autoincrement],
            AC.is_nullable AS [null],
            OBJECT_DEFINITION(AC.default_object_id) AS [default],
            AC.collation_name AS [collation_name],
            EP.[value] AS [comment]
            FROM sys.[objects] T
            INNER JOIN sys.[schemas] S ON S.[schema_id] = T.[schema_id]
            INNER JOIN sys.[all_columns] AC ON T.[object_id] = AC.[object_id]
            INNER JOIN sys.[types] TY ON TY.[user_type_id] = AC.[user_type_id]
            LEFT JOIN sys.[extended_properties] as EP
                ON T.[object_id] = EP.[major_id]
                AND AC.[column_id] = EP.[minor_id]
                AND EP.[name] = \'MS_Description\'
            WHERE T.[name] = ? AND S.[name] = ?
            ORDER BY column_id';
    }
    /**
     * Convert a column definition to the abstract types.
     *
     * The returned type will be a type that
     * Cake\Database\TypeFactory  can handle.
     *
     * @param string $col The column type
     * @param int|null $length the column length
     * @param int|null $precision The column precision
     * @param int|null $scale The column scale
     * @return array<string, mixed> Array of column information.
     * @link https://technet.microsoft.com/en-us/library/ms187752.aspx
     */
    protected function _convert_column(string $col, ?int $length = null, ?int $precision = null, ?int $scale = null): array
    {
        $col = strtolower($col);
        $type = $this->_apply_type_specific_column_conversion($col, compact('length', 'precision', 'scale'));
        if ($type !== null) {
            return $type;
        }
        if (in_array($col, ['date', 'time'])) {
            return ['type' => $col, 'length' => null];
        }
        if ($col === 'datetime') {
            // datetime cannot parse more than 3 digits of precision and isn't accurate
            return ['type' => Table_Schema_Interface::TYPE_DATETIME, 'length' => null];
        }
        if (str_contains($col, 'datetime')) {
            $type_name = Table_Schema_Interface::TYPE_DATETIME;
            if ($scale > 0) {
                $type_name = Table_Schema_Interface::TYPE_DATETIME_FRACTIONAL;
            }
            return ['type' => $type_name, 'length' => null, 'precision' => $scale];
        }
        if ($col === 'char') {
            return ['type' => Table_Schema_Interface::TYPE_CHAR, 'length' => $length];
        }
        if ($col === 'tinyint') {
            return ['type' => Table_Schema_Interface::TYPE_TINYINTEGER, 'length' => $precision ?: 3];
        }
        if ($col === 'smallint') {
            return ['type' => Table_Schema_Interface::TYPE_SMALLINTEGER, 'length' => $precision ?: 5];
        }
        if ($col === 'int' || $col === 'integer') {
            return ['type' => Table_Schema_Interface::TYPE_INTEGER, 'length' => $precision ?: 10];
        }
        if ($col === 'bigint') {
            return ['type' => Table_Schema_Interface::TYPE_BIGINTEGER, 'length' => $precision ?: 20];
        }
        if ($col === 'bit') {
            return ['type' => Table_Schema_Interface::TYPE_BOOLEAN, 'length' => null];
        }
        if (str_contains($col, 'numeric') || str_contains($col, 'money') || str_contains($col, 'decimal')) {
            return ['type' => Table_Schema_Interface::TYPE_DECIMAL, 'length' => $precision, 'precision' => $scale];
        }
        if ($col === 'real' || $col === 'float') {
            return ['type' => Table_Schema_Interface::TYPE_FLOAT, 'length' => null];
        }
        // SqlServer schema reflection returns double length for unicode
        // columns because internally it uses UTF16/UCS2
        if (in_array($col, ['nvarchar', 'nchar', 'ntext'], true)) {
            $length /= 2;
        }
        if (str_contains($col, 'varchar') && $length < 0) {
            return ['type' => Table_Schema_Interface::TYPE_TEXT, 'length' => null];
        }
        if (str_contains($col, 'varchar')) {
            return ['type' => Table_Schema_Interface::TYPE_STRING, 'length' => $length ?: 255];
        }
        if (str_contains($col, 'char')) {
            return ['type' => Table_Schema_Interface::TYPE_CHAR, 'length' => $length];
        }
        if (str_contains($col, 'text')) {
            return ['type' => Table_Schema_Interface::TYPE_TEXT, 'length' => null];
        }
        if ($col === 'image' || str_contains($col, 'binary')) {
            // -1 is the value for MAX which we treat as a 'long' binary
            if ($length === -1) {
                $length = Table_Schema::LENGTH_LONG;
            }
            return ['type' => Table_Schema_Interface::TYPE_BINARY, 'length' => $length];
        }
        if ($col === 'uniqueidentifier') {
            return ['type' => Table_Schema_Interface::TYPE_UUID];
        }
        if ($col === 'geometry') {
            return ['type' => Table_Schema_Interface::TYPE_GEOMETRY];
        }
        if ($col === 'geography') {
            // SQLserver only has one generic geometry type that
            // we map to point.
            return ['type' => Table_Schema_Interface::TYPE_POINT];
        }
        return ['type' => Table_Schema_Interface::TYPE_STRING, 'length' => null];
    }
    /**
     * @inheritDoc
     */
    public function convert_column_description(Table_Schema $schema, array $row): void
    {
        $field = $this->_convert_column($row['type'], $row['char_length'] !== null ? (int) $row['char_length'] : null, $row['precision'] !== null ? (int) $row['precision'] : null, $row['scale'] !== null ? (int) $row['scale'] : null);
        if (!empty($row['autoincrement'])) {
            $field['autoIncrement'] = true;
        }
        $field += ['null' => $row['null'] === '1', 'default' => $this->_default_value($field['type'], $row['default']), 'collate' => $row['collation_name']];
        $schema->add_column($row['name'], $field);
    }
    /**
     * Split a tablename into a tuple of schema, table
     * If the table does not have a schema name included, the connection
     * schema will be used.
     *
     * @param string $tableName The table name to split
     * @return array A tuple of [schema, tablename]
     */
    private function split_tablename(string $table_name): array
    {
        $config = $this->_driver->config();
        $schema = $config['schema'] ?? static::DEFAULT_SCHEMA_NAME;
        if (str_contains($table_name, '.')) {
            return explode('.', $table_name);
        }
        return [$schema, $table_name];
    }
    /**
     * @inheritDoc
     */
    public function describe_columns(string $table_name): array
    {
        [$schema, $name] = $this->split_tablename($table_name);
        $sql = $this->describe_column_query();
        $statement = $this->_driver->execute($sql, [$name, $schema]);
        $columns = [];
        foreach ($statement->fetch_all('assoc') as $row) {
            $field = $this->_convert_column($row['type'], $row['char_length'] !== null ? (int) $row['char_length'] : null, $row['precision'] !== null ? (int) $row['precision'] : null, $row['scale'] !== null ? (int) $row['scale'] : null);
            if (!empty($row['autoincrement'])) {
                $field['autoIncrement'] = true;
            }
            $field += ['name' => $row['name'], 'null' => $row['null'] === '1', 'default' => $this->_default_value($field['type'], $row['default']), 'comment' => $row['comment'] ?? null, 'collate' => $row['collation_name']];
            $columns[] = $field;
        }
        return $columns;
    }
    /**
     * Manipulate the default value.
     *
     * Removes () wrapping default values, extracts strings from
     * N'' wrappers and collation text and converts NULL strings.
     *
     * @param string $type The schema type
     * @param string|null $default The default value.
     */
    protected function _default_value(string $type, ?string $default): string|int|null
    {
        if ($default === null) {
            return null;
        }
        // remove () surrounding value (NULL) but leave () at the end of functions
        // integers might have two ((0)) wrapping value
        if (preg_match('/^\(+(.*?(\(\))?)\)+$/', $default, $matches)) {
            $default = $matches[1];
        }
        if ($default === 'NULL') {
            return null;
        }
        if ($type === Table_Schema_Interface::TYPE_BOOLEAN) {
            return (int) $default;
        }
        // Remove quotes
        if (preg_match("/^\\(?N?'(.*)'\\)?/", $default, $matches)) {
            return str_replace("''", "'", $matches[1]);
        }
        return $default;
    }
    /**
     * @inheritDoc
     */
    public function describe_index_sql(string $table_name, array $config): array
    {
        $sql = $this->describe_index_query();
        $schema = $config['schema'] ?? static::DEFAULT_SCHEMA_NAME;
        return [$sql, [$table_name, $schema]];
    }
    /**
     * Get the query to describe indexes
     */
    private function describe_index_query(): string
    {
        return "SELECT\n                I.[name] AS [index_name],\n                IC.[index_column_id] AS [index_order],\n                AC.[name] AS [column_name],\n                I.[is_unique], I.[is_primary_key],\n                I.[is_unique_constraint],\n                IC.[is_included_column]\n            FROM sys.[tables] AS T\n            INNER JOIN sys.[schemas] S ON S.[schema_id] = T.[schema_id]\n            INNER JOIN sys.[indexes] I ON T.[object_id] = I.[object_id]\n            INNER JOIN sys.[index_columns] IC ON I.[object_id] = IC.[object_id] AND I.[index_id] = IC.[index_id]\n            INNER JOIN sys.[all_columns] AC ON T.[object_id] = AC.[object_id] AND IC.[column_id] = AC.[column_id]\n            WHERE T.[is_ms_shipped] = 0 AND I.[type_desc] <> 'HEAP' AND T.[name] = ? AND S.[name] = ?\n            ORDER BY I.[index_id], IC.[index_column_id]";
    }
    /**
     * @inheritDoc
     */
    public function convert_index_description(Table_Schema $schema, array $row): void
    {
        $type = Table_Schema::INDEX_INDEX;
        $name = $row['index_name'];
        if ($row['is_primary_key']) {
            $name = Table_Schema::CONSTRAINT_PRIMARY;
            $type = Table_Schema::CONSTRAINT_PRIMARY;
        }
        if (($row['is_unique'] || $row['is_unique_constraint']) && $type === Table_Schema::INDEX_INDEX) {
            $type = Table_Schema::CONSTRAINT_UNIQUE;
        }
        if ($type === Table_Schema::INDEX_INDEX) {
            $existing = $schema->get_index($name);
        } else {
            $existing = $schema->get_constraint($name);
        }
        $columns = [$row['column_name']];
        if ($existing) {
            $columns = array_merge($existing['columns'], $columns);
        }
        if ($type === Table_Schema::CONSTRAINT_PRIMARY || $type === Table_Schema::CONSTRAINT_UNIQUE) {
            $schema->add_constraint($name, ['type' => $type, 'columns' => $columns]);
            return;
        }
        $schema->add_index($name, ['type' => $type, 'columns' => $columns]);
    }
    /**
     * @inheritDoc
     */
    public function describe_indexes(string $table_name): array
    {
        [$schema, $name] = $this->split_tablename($table_name);
        $sql = $this->describe_index_query();
        $indexes = [];
        $statement = $this->_driver->execute($sql, [$name, $schema]);
        foreach ($statement->fetch_all('assoc') as $row) {
            $type = Table_Schema::INDEX_INDEX;
            $name = $row['index_name'];
            $constraint = null;
            if ($row['is_primary_key']) {
                $constraint = $name;
                $name = Table_Schema::CONSTRAINT_PRIMARY;
                $type = Table_Schema::CONSTRAINT_PRIMARY;
            }
            if (($row['is_unique'] || $row['is_unique_constraint']) && $type === Table_Schema::INDEX_INDEX) {
                $type = Table_Schema::CONSTRAINT_UNIQUE;
            }
            if (!isset($indexes[$name])) {
                $indexes[$name] = ['name' => $name, 'type' => $type, 'columns' => [], 'length' => []];
            }
            if ($row['is_included_column']) {
                $indexes[$name]['include'][] = $row['column_name'];
            } else {
                $indexes[$name]['columns'][] = $row['column_name'];
            }
            if ($constraint) {
                $indexes[$name]['constraint'] = $constraint;
            }
        }
        return array_values($indexes);
    }
    /**
     * Get the query to describe foreign keys
     */
    private function describe_foreign_key_query(): string
    {
        // phpcs:disable Generic.Files.LineLength
        return 'SELECT FK.[name] AS [foreign_key_name],
            FK.[delete_referential_action_desc] AS [delete_type],
            FK.[update_referential_action_desc] AS [update_type],
            C.name AS [column],
            RT.name AS [reference_table],
            RC.name AS [reference_column]
            FROM sys.foreign_keys FK
            INNER JOIN sys.foreign_key_columns FKC ON FKC.constraint_object_id = FK.object_id
            INNER JOIN sys.tables T ON T.object_id = FKC.parent_object_id
            INNER JOIN sys.tables RT ON RT.object_id = FKC.referenced_object_id
            INNER JOIN sys.schemas S ON S.schema_id = T.schema_id AND S.schema_id = RT.schema_id
            INNER JOIN sys.columns C ON C.column_id = FKC.parent_column_id AND C.object_id = FKC.parent_object_id
            INNER JOIN sys.columns RC ON RC.column_id = FKC.referenced_column_id AND RC.object_id = FKC.referenced_object_id
            WHERE FK.is_ms_shipped = 0 AND T.name = ? AND S.name = ?
            ORDER BY FKC.constraint_column_id';
        // phpcs:enable Generic.Files.LineLength
    }
    /**
     * @inheritDoc
     */
    public function describe_foreign_keys(string $table_name): array
    {
        [$schema, $name] = $this->split_tablename($table_name);
        $sql = $this->describe_foreign_key_query();
        $keys = [];
        $statement = $this->_driver->execute($sql, [$name, $schema]);
        foreach ($statement->fetch_all('assoc') as $row) {
            $name = $row['foreign_key_name'];
            if (!isset($keys[$name])) {
                $keys[$name] = ['name' => $name, 'type' => Table_Schema::CONSTRAINT_FOREIGN, 'columns' => [], 'references' => [$row['reference_table'], []], 'update' => $this->_convert_on_clause($row['update_type']), 'delete' => $this->_convert_on_clause($row['delete_type'])];
            }
            $keys[$name]['columns'][] = $row['column'];
            $keys[$name]['references'][1][] = $row['reference_column'];
        }
        foreach ($keys as $id => $key) {
            // references.1 is the referenced columns. Backwards compat
            // requires a single column to be a string, but multiple to be an array.
            if (count($key['references'][1]) === 1) {
                $keys[$id]['references'][1] = $key['references'][1][0];
            }
        }
        return array_values($keys);
    }
    /**
     * @inheritDoc
     */
    public function describe_foreign_key_sql(string $table_name, array $config): array
    {
        $sql = $this->describe_foreign_key_query();
        $schema = $config['schema'] ?? static::DEFAULT_SCHEMA_NAME;
        return [$sql, [$table_name, $schema]];
    }
    /**
     * @inheritDoc
     */
    public function convert_foreign_key_description(Table_Schema $schema, array $row): void
    {
        $data = ['type' => Table_Schema::CONSTRAINT_FOREIGN, 'columns' => [$row['column']], 'references' => [$row['reference_table'], $row['reference_column']], 'update' => $this->_convert_on_clause($row['update_type']), 'delete' => $this->_convert_on_clause($row['delete_type'])];
        $name = $row['foreign_key_name'];
        $schema->add_constraint($name, $data);
    }
    /**
     * @inheritDoc
     */
    public function describe_options(string $table_name): array
    {
        return [];
    }
    /**
     * @inheritDoc
     */
    protected function _foreign_on_clause(string $on): string
    {
        $parent = parent::_foreign_on_clause($on);
        return $parent === 'RESTRICT' ? parent::_foreign_on_clause(Table_Schema::ACTION_NO_ACTION) : $parent;
    }
    /**
     * @inheritDoc
     */
    protected function _convert_on_clause(string $clause): string
    {
        return match ($clause) {
            'NO_ACTION' => Table_Schema::ACTION_NO_ACTION,
            'CASCADE' => Table_Schema::ACTION_CASCADE,
            'SET_NULL' => Table_Schema::ACTION_SET_NULL,
            'SET_DEFAULT' => Table_Schema::ACTION_SET_DEFAULT,
            default => Table_Schema::ACTION_SET_NULL,
        };
    }
    /**
     * @inheritDoc
     */
    public function column_sql(Table_Schema $schema, string $name): string
    {
        $data = $schema->get_column($name);
        assert($data !== null);
        $data['name'] = $name;
        $sql = $this->_get_type_specific_column_sql($data['type'], $schema, $name);
        if ($sql !== null) {
            return $sql;
        }
        $auto_increment_types = [Table_Schema_Interface::TYPE_TINYINTEGER, Table_Schema_Interface::TYPE_SMALLINTEGER, Table_Schema_Interface::TYPE_INTEGER, Table_Schema_Interface::TYPE_BIGINTEGER];
        $primary_key = $schema->get_primary_key();
        if (in_array($data['type'], $auto_increment_types, true) && $primary_key === [$name] && $name === 'id') {
            $data['autoIncrement'] = true;
        }
        return $this->column_definition_sql($data);
    }
    /**
     * @inheritDoc
     */
    public function column_definition_sql(array $column): string
    {
        $name = $column['name'];
        $column += ['length' => null, 'precision' => null];
        $out = $this->_driver->quote_identifier($name);
        $type_map = [Table_Schema_Interface::TYPE_TINYINTEGER => ' TINYINT', Table_Schema_Interface::TYPE_SMALLINTEGER => ' SMALLINT', Table_Schema_Interface::TYPE_INTEGER => ' INTEGER', Table_Schema_Interface::TYPE_BIGINTEGER => ' BIGINT', Table_Schema_Interface::TYPE_BINARY_UUID => ' UNIQUEIDENTIFIER', Table_Schema_Interface::TYPE_BOOLEAN => ' BIT', Table_Schema_Interface::TYPE_CHAR => ' NCHAR', Table_Schema_Interface::TYPE_STRING => ' NVARCHAR', Table_Schema_Interface::TYPE_FLOAT => ' FLOAT', Table_Schema_Interface::TYPE_DECIMAL => ' DECIMAL', Table_Schema_Interface::TYPE_DATE => ' DATE', Table_Schema_Interface::TYPE_TIME => ' TIME', Table_Schema_Interface::TYPE_DATETIME => ' DATETIME2', Table_Schema_Interface::TYPE_DATETIME_FRACTIONAL => ' DATETIME2', Table_Schema_Interface::TYPE_TIMESTAMP => ' DATETIME2', Table_Schema_Interface::TYPE_TIMESTAMP_FRACTIONAL => ' DATETIME2', Table_Schema_Interface::TYPE_TIMESTAMP_TIMEZONE => ' DATETIME2', Table_Schema_Interface::TYPE_UUID => ' UNIQUEIDENTIFIER', Table_Schema_Interface::TYPE_NATIVE_UUID => ' UNIQUEIDENTIFIER', Table_Schema_Interface::TYPE_JSON => ' NVARCHAR(MAX)', Table_Schema_Interface::TYPE_GEOMETRY => ' GEOMETRY', Table_Schema_Interface::TYPE_POINT => ' GEOGRAPHY', Table_Schema_Interface::TYPE_LINESTRING => ' GEOGRAPHY', Table_Schema_Interface::TYPE_POLYGON => ' GEOGRAPHY'];
        $found_type = false;
        if (isset($type_map[$column['type']])) {
            $out .= $type_map[$column['type']];
            $found_type = true;
        }
        $has_length = [Table_Schema_Interface::TYPE_CHAR, Table_Schema_Interface::TYPE_STRING, Table_Schema_Interface::TYPE_BINARY];
        $auto_increment_types = [Table_Schema_Interface::TYPE_TINYINTEGER, Table_Schema_Interface::TYPE_SMALLINTEGER, Table_Schema_Interface::TYPE_INTEGER, Table_Schema_Interface::TYPE_BIGINTEGER];
        $auto_increment = (bool) ($column['autoIncrement'] ?? false);
        if (in_array($column['type'], $auto_increment_types, true) && $auto_increment) {
            $out .= ' IDENTITY(1, 1)';
            $found_type = true;
            unset($column['default']);
        }
        if ($column['type'] === Table_Schema_Interface::TYPE_STRING && !isset($column['length'])) {
            $column['length'] = Table_Schema::LENGTH_TINY;
        } elseif ($column['type'] === Table_Schema_Interface::TYPE_TEXT && $column['length'] !== Table_Schema::LENGTH_TINY) {
            $out .= ' NVARCHAR(MAX)';
            $found_type = true;
        }
        if ($column['type'] === Table_Schema_Interface::TYPE_BINARY) {
            if (!isset($column['length']) || in_array($column['length'], [Table_Schema::LENGTH_MEDIUM, Table_Schema::LENGTH_LONG], true)) {
                $column['length'] = 'MAX';
            }
            if ($column['length'] === 1) {
                $out .= ' BINARY';
            } else {
                $out .= ' VARBINARY';
            }
            $found_type = true;
        }
        if ($column['type'] === Table_Schema_Interface::TYPE_TEXT && $column['length'] === Table_Schema::LENGTH_TINY) {
            $out .= ' NVARCHAR';
            $has_length[] = $column['type'];
            $found_type = true;
        }
        if (!$found_type) {
            $out .= ' ' . strtoupper((string) $column['type']);
            $has_length[] = $column['type'];
        }
        if (in_array($column['type'], $has_length, true) && isset($column['length'])) {
            $out .= '(' . $column['length'] . ')';
        }
        $has_collate = [Table_Schema_Interface::TYPE_TEXT, Table_Schema_Interface::TYPE_STRING, Table_Schema_Interface::TYPE_CHAR];
        if (in_array($column['type'], $has_collate, true) && isset($column['collate']) && $column['collate'] !== '') {
            $out .= ' COLLATE ' . $column['collate'];
        }
        $precision_types = [Table_Schema_Interface::TYPE_FLOAT, Table_Schema_Interface::TYPE_DATETIME, Table_Schema_Interface::TYPE_DATETIME_FRACTIONAL, Table_Schema_Interface::TYPE_TIMESTAMP, Table_Schema_Interface::TYPE_TIMESTAMP_FRACTIONAL];
        if (in_array($column['type'], $precision_types, true) && isset($column['precision'])) {
            $out .= '(' . (int) $column['precision'] . ')';
        }
        if ($column['type'] === Table_Schema_Interface::TYPE_DECIMAL && (isset($column['length']) || isset($column['precision']))) {
            $out .= '(' . (int) $column['length'] . ',' . (int) $column['precision'] . ')';
        }
        if (isset($column['null']) && $column['null'] === false) {
            $out .= ' NOT NULL';
        }
        $date_time_types = [Table_Schema_Interface::TYPE_DATETIME, Table_Schema_Interface::TYPE_DATETIME_FRACTIONAL, Table_Schema_Interface::TYPE_TIMESTAMP, Table_Schema_Interface::TYPE_TIMESTAMP_FRACTIONAL];
        $date_time_defaults = ['current_timestamp', 'getdate()', 'getutcdate()', 'sysdatetime()', 'sysutcdatetime()', 'sysdatetimeoffset()'];
        if (isset($column['default']) && in_array($column['type'], $date_time_types, true) && is_string($column['default']) && in_array(strtolower($column['default']), $date_time_defaults, true)) {
            $out .= ' DEFAULT ' . strtoupper($column['default']);
        } elseif (isset($column['default'])) {
            $default = is_bool($column['default']) ? (int) $column['default'] : $this->_driver->schema_value($column['default']);
            $out .= ' DEFAULT ' . $default;
        } elseif (isset($column['null']) && $column['null'] !== false) {
            $out .= ' DEFAULT NULL';
        }
        return $out;
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
        $sql_pattern = 'ALTER TABLE %s DROP CONSTRAINT %s;';
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
        $index = $schema->index($name);
        $columns = array_map($this->_driver->quote_identifier(...), (array) $index->get_columns());
        $include = '';
        $included = $index->get_include();
        if ($included !== null) {
            $included = array_map($this->_driver->quote_identifier(...), $included);
            $include = sprintf(' INCLUDE (%s)', implode(', ', $included));
        }
        return sprintf('CREATE INDEX %s ON %s (%s)%s', $this->_driver->quote_identifier($name), $this->_driver->quote_identifier($schema->name()), implode(', ', $columns), $include);
    }
    /**
     * @inheritDoc
     */
    public function constraint_sql(Table_Schema $schema, string $name): string
    {
        $data = $schema->get_constraint($name);
        assert($data !== null);
        $out = 'CONSTRAINT ' . $this->_driver->quote_identifier($name);
        if ($data['type'] === Table_Schema::CONSTRAINT_PRIMARY) {
            $out = 'PRIMARY KEY';
        }
        if ($data['type'] === Table_Schema::CONSTRAINT_UNIQUE) {
            $out .= ' UNIQUE';
        }
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
        if ($data['type'] === Table_Schema::CONSTRAINT_FOREIGN) {
            return $prefix . sprintf(' FOREIGN KEY (%s) REFERENCES %s (%s) ON UPDATE %s ON DELETE %s', implode(', ', $columns), $this->_driver->quote_identifier($data['references'][0]), $this->_convert_constraint_columns($data['references'][1]), $this->_foreign_on_clause($data['update']), $this->_foreign_on_clause($data['delete']));
        }
        return $prefix . ' (' . implode(', ', $columns) . ')';
    }
    /**
     * @inheritDoc
     */
    public function create_table_sql(Table_Schema $schema, array $columns, array $constraints, array $indexes): array
    {
        $content = array_merge($columns, $constraints);
        $content = implode(",\n", array_filter($content));
        $table_name = $this->_driver->quote_identifier($schema->name());
        $out = [];
        $out[] = sprintf("CREATE TABLE %s (\n%s\n)", $table_name, $content);
        foreach ($indexes as $index) {
            $out[] = $index;
        }
        foreach ($schema->columns() as $name) {
            $column = $schema->get_column($name);
            $comment = $column['comment'] ?? null;
            if ($comment !== null) {
                $out[] = $this->column_comment_sql($schema, $name, $comment);
            }
        }
        return $out;
    }
    /**
     * Generate the SQL to create a column comment.
     *
     * @param \Cake\Database\Schema\TableSchema $schema The table schema.
     * @param string $name The column name.
     * @param string $comment The column comment.
     */
    protected function column_comment_sql(Table_Schema $schema, string $name, string $comment): string
    {
        $table_name = $this->_driver->quote_identifier($schema->name());
        $column_name = $this->_driver->quote_identifier($name);
        $comment = $this->_driver->schema_value($comment);
        return sprintf("EXEC sp_addextendedproperty N'MS_Description', %s, N'SCHEMA', N'dbo', N'TABLE', %s, N'COLUMN', %s;", $comment, $table_name, $column_name);
    }
    /**
     * @inheritDoc
     */
    public function truncate_table_sql(Table_Schema $schema): array
    {
        $name = $this->_driver->quote_identifier($schema->name());
        $queries = [sprintf('DELETE FROM %s', $name)];
        // Restart identity sequences
        $pk = $schema->get_primary_key();
        if (count($pk) === 1) {
            $column = $schema->get_column($pk[0]);
            assert($column !== null);
            if (in_array($column['type'], ['integer', 'biginteger'])) {
                $queries[] = sprintf("IF EXISTS (SELECT * FROM sys.identity_columns WHERE OBJECT_NAME(OBJECT_ID) = '%s' AND " . "last_value IS NOT NULL) DBCC CHECKIDENT('%s', RESEED, 0)", $schema->name(), $schema->name());
            }
        }
        return $queries;
    }
}