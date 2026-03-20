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

use Cake\Core\Configure;
use Cake\Database\Exception\Database_Exception;
use PDO;
/**
 * Schema management/reflection features for Sqlite
 *
 * @internal
 */
class Sqlite_Schema_Dialect extends Schema_Dialect
{
    /**
     * Whether there is any table in this connection to SQLite containing sequences.
     */
    protected bool $_has_sequences;
    /**
     * Convert a column definition to the abstract types.
     *
     * The returned type will be a type that
     * Cake\Database\TypeFactory can handle.
     *
     * @param string $column The column type + length
     * @throws \Cake\Database\Exception\DatabaseException when unable to parse column type
     * @return array<string, mixed> Array of column information.
     */
    protected function _convert_column(string $column): array
    {
        if ($column === '') {
            return ['type' => Table_Schema_Interface::TYPE_TEXT, 'length' => null];
        }
        preg_match('/(unsigned)?\s*([a-z]+)(?:\(([0-9,]+)\))?/i', $column, $matches);
        if (!$matches) {
            throw new Database_Exception(sprintf('Unable to parse column type from `%s`', $column));
        }
        $unsigned = false;
        if (strtolower($matches[1]) === 'unsigned') {
            $unsigned = true;
        }
        $col = strtolower($matches[2]);
        $length = null;
        $precision = null;
        $scale = null;
        if (isset($matches[3])) {
            $length = $matches[3];
            if (str_contains($length, ',')) {
                [$length, $precision] = explode(',', $length);
            }
            $length = (int) $length;
            $precision = (int) $precision;
        }
        $type = $this->_apply_type_specific_column_conversion($col, compact('length', 'precision', 'scale'));
        if ($type !== null) {
            return $type;
        }
        if ($col === 'bigint') {
            return ['type' => Table_Schema_Interface::TYPE_BIGINTEGER, 'length' => $length, 'unsigned' => $unsigned];
        }
        if ($col === 'smallint') {
            return ['type' => Table_Schema_Interface::TYPE_SMALLINTEGER, 'length' => $length, 'unsigned' => $unsigned];
        }
        if ($col === 'tinyint') {
            return ['type' => Table_Schema_Interface::TYPE_TINYINTEGER, 'length' => $length, 'unsigned' => $unsigned];
        }
        if (str_contains($col, 'int') && $col !== 'point') {
            return ['type' => Table_Schema_Interface::TYPE_INTEGER, 'length' => $length, 'unsigned' => $unsigned];
        }
        if (str_contains($col, 'decimal')) {
            return ['type' => Table_Schema_Interface::TYPE_DECIMAL, 'length' => $length, 'precision' => $precision, 'unsigned' => $unsigned];
        }
        if (in_array($col, ['float', 'real', 'double'])) {
            return ['type' => Table_Schema_Interface::TYPE_FLOAT, 'length' => $length, 'precision' => $precision, 'unsigned' => $unsigned];
        }
        if (str_contains($col, 'boolean')) {
            return ['type' => Table_Schema_Interface::TYPE_BOOLEAN, 'length' => null];
        }
        if ($col === 'binary' && $length === 16 || strtolower($column) === 'uuid_blob') {
            return ['type' => Table_Schema_Interface::TYPE_BINARY_UUID, 'length' => null];
        }
        if ($col === 'char' && $length === 36 || $col === 'uuid') {
            return ['type' => Table_Schema_Interface::TYPE_UUID, 'length' => null];
        }
        if ($col === 'char') {
            return ['type' => Table_Schema_Interface::TYPE_CHAR, 'length' => $length];
        }
        if (str_contains($col, 'char')) {
            return ['type' => Table_Schema_Interface::TYPE_STRING, 'length' => $length];
        }
        if (in_array($col, ['blob', 'clob', 'binary', 'varbinary'])) {
            return ['type' => Table_Schema_Interface::TYPE_BINARY, 'length' => $length];
        }
        $datetime_types = ['date', 'time', 'timestamp', 'timestampfractional', 'timestamptimezone', 'datetime', 'datetimefractional'];
        if (in_array($col, $datetime_types)) {
            return ['type' => $col, 'length' => null];
        }
        if (Configure::read('ORM.mapJsonTypeForSqlite') === true && (str_contains($col, Table_Schema_Interface::TYPE_JSON) && !str_contains($col, 'jsonb'))) {
            return ['type' => Table_Schema_Interface::TYPE_JSON, 'length' => null];
        }
        if (in_array($col, Table_Schema_Interface::GEOSPATIAL_TYPES)) {
            // TODO how can srid be preserved? It doesn't come back
            // in the output of show full columns from ...
            return ['type' => $col, 'length' => null];
        }
        return ['type' => Table_Schema_Interface::TYPE_TEXT, 'length' => null];
    }
    /**
     * Generate the SQL to list the tables and views.
     *
     * @param array<string, mixed> $config The connection configuration to use for
     *    getting tables from.
     * @return array An array of (sql, params) to execute.
     */
    public function list_tables_sql(array $config): array
    {
        return ['SELECT name FROM sqlite_master ' . 'WHERE (type="table" OR type="view") ' . 'AND name != "sqlite_sequence" ORDER BY name', []];
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
        return ['SELECT name FROM sqlite_master WHERE type="table" ' . 'AND name != "sqlite_sequence" ORDER BY name', []];
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
     * @inheritDoc
     */
    public function convert_column_description(Table_Schema $schema, array $row): void
    {
        $field = $this->_convert_column($row['type']);
        $field += ['null' => !$row['notnull'], 'default' => $this->_default_value($row['dflt_value'], $row['type'])];
        $primary = $schema->get_constraint('primary');
        if ($row['pk'] && empty($primary)) {
            $field['null'] = false;
            $field['autoIncrement'] = true;
        }
        // SQLite does not support autoincrement on composite keys.
        if ($row['pk'] && !empty($primary)) {
            $existing_column = $primary['columns'][0];
            $schema->add_column($existing_column, ['autoIncrement' => null] + $schema->get_column($existing_column));
        }
        $schema->add_column($row['name'], $field);
        if ($row['pk']) {
            $constraint = (array) $schema->get_constraint('primary') + ['type' => Table_Schema::CONSTRAINT_PRIMARY, 'columns' => []];
            $constraint['columns'] = array_merge($constraint['columns'], [$row['name']]);
            $schema->add_constraint('primary', $constraint);
        }
    }
    /**
     * Helper method for creating SQL to describe columns in a table.
     *
     * @param string $tableName The table to describe.
     * @return string SQL to reflect columns
     */
    private function describe_column_query(string $table_name): string
    {
        $pragma = 'table_xinfo';
        if (version_compare($this->_driver->version(), '3.26.0', '<')) {
            $pragma = 'table_info';
        }
        return sprintf('PRAGMA %s(%s)', $pragma, $this->_driver->quote_identifier($table_name));
    }
    /**
     * @inheritDoc
     */
    public function describe_columns(string $table_name): array
    {
        if (str_contains($table_name, '.')) {
            [, $table_name] = explode('.', $table_name);
        }
        $sql = $this->describe_column_query($table_name);
        $columns = [];
        $statement = $this->_driver->execute($sql);
        $primary = [];
        foreach ($statement->fetch_all('assoc') as $i => $row) {
            $name = $row['name'];
            $field = $this->_convert_column($row['type']);
            $field += ['name' => $name, 'null' => !$row['notnull'], 'default' => $this->_default_value($row['dflt_value'], $row['type']), 'comment' => null, 'length' => null];
            if ($row['pk']) {
                $primary[] = $i;
            }
            $columns[] = $field;
        }
        // If sqlite has a single primary column, it can be marked as autoIncrement
        if (count($primary) == 1) {
            $offset = $primary[0];
            $columns[$offset]['autoIncrement'] = true;
            $columns[$offset]['null'] = false;
        }
        return $columns;
    }
    /**
     * Manipulate the default value.
     *
     * Sqlite includes quotes and bared NULLs in default values.
     * We need to remove those.
     *
     * @param string|int|null $default The default value.
     * @param string|null $type The column type.
     */
    protected function _default_value(string|int|null $default, ?string $type = null): string|int|null
    {
        if ($default === 'NULL' || $default === null) {
            return null;
        }
        if ($type !== null && strtolower($type) === Table_Schema_Interface::TYPE_BOOLEAN) {
            if ($default === '0' || $default === '1') {
                return (int) $default;
            }
            return (int) filter_var($default, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }
        // Remove quotes
        if (is_string($default) && preg_match("/^'(.*)'\$/", $default, $matches)) {
            return str_replace("''", "'", $matches[1]);
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
     * Generates a regular expression to match identifiers that may or
     * may not be quoted with any of the supported quotes.
     *
     * @param string $identifier The identifier to match.
     */
    protected function possibly_quoted_identifier_regex(string $identifier): string
    {
        // Trim all quoting characters from the provided identifier,
        // and double all quotes up because that's how sqlite returns them.
        $identifier = trim($identifier, '\'"`[]');
        $identifier = str_replace(["'", '"', '`'], ["''", '""', '``'], $identifier);
        $quoted = preg_quote($identifier, '/');
        return "[\\['\"`]?{$quoted}[\\]'\"`]?";
    }
    /**
     * Removes possible escape characters and surrounding quotes from
     * identifiers.
     *
     * @param string $value The identifier to normalize.
     */
    protected function normalize_possibly_quoted_identifier(string $value): string
    {
        $value = trim($value);
        if (str_starts_with($value, '[') && str_ends_with($value, ']')) {
            return mb_substr($value, 1, -1);
        }
        foreach (['`', "'", '"'] as $quote) {
            if (str_starts_with($value, $quote) && str_ends_with($value, $quote)) {
                $value = str_replace($quote . $quote, $quote, $value);
                return mb_substr($value, 1, -1);
            }
        }
        return $value;
    }
    /**
     * {@inheritDoc}
     *
     * Since SQLite does not have a way to get metadata about all indexes at once,
     * additional queries are done here. Sqlite constraint names are not
     * stable, and the names for constraints will not match those used to create
     * the table. This is a limitation in Sqlite's metadata features.
     *
     * @param \Cake\Database\Schema\TableSchema $schema The table object to append
     *    an index or constraint to.
     * @param array $row The row data from `describeIndexSql`.
     * @deprecated 5.2.0 Use `describeIndexes` instead.
     */
    public function convert_index_description(Table_Schema $schema, array $row): void
    {
        // Skip auto-indexes created for non-ROWID primary keys.
        if (($row['origin'] ?? null) === 'pk') {
            return;
        }
        $sql = sprintf('PRAGMA index_info(%s)', $this->_driver->quote_identifier($row['name']));
        $statement = $this->_driver->execute($sql);
        $columns = [];
        foreach ($statement->fetch_all(PDO::FETCH_ASSOC) as $column) {
            $columns[] = $column['name'];
        }
        if ($row['unique']) {
            if (($row['origin'] ?? null) === 'u') {
                $create_table_sql = $this->get_create_table_sql($schema->name());
                $name = $this->extract_index_name($create_table_sql, 'UNIQUE', $columns);
                if ($name !== null) {
                    $row['name'] = $name;
                }
            }
            $schema->add_constraint($row['name'], ['type' => Table_Schema::CONSTRAINT_UNIQUE, 'columns' => $columns]);
        } else {
            $schema->add_index($row['name'], ['type' => Table_Schema::INDEX_INDEX, 'columns' => $columns]);
        }
    }
    /**
     * Helper method for creating SQL to reflect indexes in a table.
     *
     * @param string $tableName The table to get indexes from.
     * @return string SQL to reflect indexes
     */
    private function describe_index_query(string $table_name): string
    {
        return sprintf('PRAGMA index_list(%s)', $this->_driver->quote_identifier($table_name));
    }
    /**
     * Try to extract the original constraint name from table sql.
     *
     * @param string $tableSql The create table statement
     * @param string $type The type of index/constraint
     * @param array $columns The columns in the index.
     * @return string|null The name of the unique index if it could be inferred.
     */
    private function extract_index_name(string $table_sql, string $type, array $columns): ?string
    {
        $columns_pattern = implode('\s*,\s*', array_map(fn(string $column): string => '(?:' . $this->possibly_quoted_identifier_regex($column) . ')', $columns));
        $regex = "/CONSTRAINT\\s*(?<name>.+?)\\s*{$type}\\s*\\(\\s*{$columns_pattern}\\s*\\)/i";
        if (preg_match($regex, $table_sql, $matches)) {
            return $this->normalize_possibly_quoted_identifier($matches['name']);
        }
        return null;
    }
    /**
     * Try to extract the deferrable clause from the table SQL.
     *
     * @param string $tableSql The create table statement
     * @param array $columns The columns in the index.
     * @return string|null The name of the unique index if it could be inferred.
     */
    private function extract_deferrable(string $table_sql, array $columns): ?string
    {
        $columns_pattern = implode('\s*,\s*', array_map(fn(string $column): string => '(?:' . $this->possibly_quoted_identifier_regex($column) . ')', $columns));
        $regex = "/CONSTRAINT\\s*(?<name>.+?)\\s*FOREIGN\\s+KEY\\s*\\(\\s*{$columns_pattern}\\s*\\).*?' .\n            '(?<deferable>((?:NOT\\s+)?DEFERRABLE)?(?:\\s+INITIALLY\\s+(DEFERRED|IMMEDIATE)))?/i";
        if (preg_match($regex, $table_sql, $matches)) {
            return match ($matches['deferable']) {
                'NOT DEFERRABLE' => Foreign_Key::NOT_DEFERRED,
                'DEFERRABLE INITIALLY DEFERRED' => Foreign_Key::DEFERRED,
                'DEFERRABLE INITIALLY IMMEDIATE' => Foreign_Key::IMMEDIATE,
                default => null,
            };
        }
        return null;
    }
    /**
     * Get the normalized SQL query used to create a table.
     *
     * @param string $tableName The tablename
     */
    private function get_create_table_sql(string $table_name): string
    {
        $master_sql = "SELECT sql FROM sqlite_master WHERE \"type\" = 'table' AND \"name\" = ?";
        $statement = $this->_driver->execute($master_sql, [$table_name]);
        $result = $statement->fetch_column(0);
        return $result ?: '';
    }
    /**
     * @inheritDoc
     */
    public function describe_indexes(string $table_name): array
    {
        if (str_contains($table_name, '.')) {
            [, $table_name] = explode('.', $table_name);
        }
        $sql = $this->describe_index_query($table_name);
        $statement = $this->_driver->execute($sql);
        $indexes = [];
        $create_table_sql = $this->get_create_table_sql($table_name);
        $found_primary = false;
        foreach ($statement->fetch_all('assoc') as $row) {
            $index_name = $row['name'];
            $index_sql = sprintf('PRAGMA index_info(%s)', $this->_driver->quote_identifier($index_name));
            $columns = [];
            $index_data = $this->_driver->execute($index_sql)->fetch_all('assoc');
            foreach ($index_data as $index_item) {
                $columns[] = $index_item['name'];
            }
            $index_type = Table_Schema::INDEX_INDEX;
            if ($row['unique']) {
                $index_type = Table_Schema::CONSTRAINT_UNIQUE;
            }
            if (($row['origin'] ?? null) === 'pk') {
                $index_type = Table_Schema::CONSTRAINT_PRIMARY;
                $found_primary = true;
            }
            if ($index_type == Table_Schema::CONSTRAINT_UNIQUE) {
                $name = $this->extract_index_name($create_table_sql, 'UNIQUE', $columns);
                if ($name !== null) {
                    $index_name = $name;
                }
            }
            $indexes[$index_name] = ['name' => $index_name, 'type' => $index_type, 'columns' => $columns, 'length' => []];
        }
        // Primary keys aren't always available from the index_info pragma
        // instead we have to read the columns again.
        if (!$found_primary) {
            $sql = $this->describe_column_query($table_name);
            $statement = $this->_driver->execute($sql);
            foreach ($statement->fetch_all('assoc') as $row) {
                if (!$row['pk']) {
                    continue;
                }
                if (!isset($indexes['primary'])) {
                    $indexes['primary'] = ['name' => 'primary', 'type' => Table_Schema::CONSTRAINT_PRIMARY, 'columns' => [], 'length' => []];
                }
                $indexes['primary']['columns'][] = $row['name'];
            }
        }
        return array_values($indexes);
    }
    /**
     * @inheritDoc
     */
    public function describe_foreign_key_sql(string $table_name, array $config): array
    {
        $sql = sprintf('SELECT id FROM pragma_foreign_key_list(%s) GROUP BY id', $this->_driver->quote_identifier($table_name));
        return [$sql, []];
    }
    /**
     * @inheritDoc
     */
    public function convert_foreign_key_description(Table_Schema $schema, array $row): void
    {
        $sql = sprintf('SELECT * FROM pragma_foreign_key_list(%s) WHERE id = %d ORDER BY seq', $this->_driver->quote_identifier($schema->name()), $row['id']);
        $statement = $this->_driver->prepare($sql);
        $statement->execute();
        $data = ['type' => Table_Schema::CONSTRAINT_FOREIGN, 'columns' => [], 'references' => []];
        $foreign_key = null;
        foreach ($statement->fetch_all(PDO::FETCH_ASSOC) as $foreign_key) {
            $data['columns'][] = $foreign_key['from'];
            $data['references'][] = $foreign_key['to'];
        }
        if (count($data['references']) === 1) {
            $data['references'] = [$foreign_key['table'], $data['references'][0]];
        } else {
            $data['references'] = [$foreign_key['table'], $data['references']];
        }
        $data['update'] = $this->_convert_on_clause($foreign_key['on_update'] ?? '');
        $data['delete'] = $this->_convert_on_clause($foreign_key['on_delete'] ?? '');
        $name = implode('_', $data['columns']) . '_' . $row['id'] . '_fk';
        $schema->add_constraint($name, $data);
    }
    /**
     * @inheritDoc
     */
    public function describe_foreign_keys(string $table_name): array
    {
        if (str_contains($table_name, '.')) {
            [, $table_name] = explode('.', $table_name);
        }
        $keys = [];
        $sql = sprintf('PRAGMA foreign_key_list(%s)', $this->_driver->quote_identifier($table_name));
        $statement = $this->_driver->execute($sql);
        foreach ($statement->fetch_all('assoc') as $row) {
            $id = $row['id'];
            if (!isset($keys[$id])) {
                $keys[$id] = ['name' => $id, 'type' => Table_Schema::CONSTRAINT_FOREIGN, 'columns' => [], 'references' => [$row['table'], []], 'update' => $this->_convert_on_clause($row['on_update'] ?? ''), 'delete' => $this->_convert_on_clause($row['on_delete'] ?? ''), 'deferrable' => null];
            }
            $keys[$id]['columns'][$row['seq']] = $row['from'];
            $keys[$id]['references'][1][$row['seq']] = $row['to'];
        }
        $create_table_sql = $this->get_create_table_sql($table_name);
        foreach ($keys as $id => $data) {
            // sqlite doesn't provide a simple way to get foreign key names, but we
            // can extract them from the normalized create table sql.
            $name = $this->extract_index_name($create_table_sql, 'FOREIGN\s*KEY', $data['columns']);
            if ($name === null) {
                $name = implode('_', $data['columns']) . '_' . $id . '_fk';
            }
            $keys[$id]['name'] = $name;
            // Collapse single columns to a string.
            // Long term this should go away, as we can narrow the types on `references`
            if (count($data['references'][1]) === 1) {
                $keys[$id]['references'][1] = $data['references'][1][0];
            }
            // sqlite doesn't provide a simple way to get foreign key names, but we
            // can extract them from the normalized create table sql.
            $keys[$id]['deferrable'] = $this->extract_deferrable($create_table_sql, $data['columns']);
        }
        return array_values($keys);
    }
    /**
     * @inheritDoc
     */
    public function describe_check_constraints(string $table_name): array
    {
        $constraints = [];
        $create_sql = $this->get_create_table_sql($table_name);
        // Parse CHECK constraints from CREATE TABLE statement
        // Match CONSTRAINT name CHECK (expression) or just CHECK (expression)
        $pattern = '/(?:CONSTRAINT\s+([^\s]+)\s+)?CHECK\s*\(([^)]+(?:\([^)]*\)[^)]*)*)\)/is';
        if (preg_match_all($pattern, $create_sql, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $index => $match) {
                $name = !empty($match[1]) ? trim($match[1], '"`[]') : 'check_' . $index;
                $expression = trim($match[2]);
                $constraints[] = ['name' => $name, 'type' => Table_Schema::CONSTRAINT_CHECK, 'expression' => $expression];
            }
        }
        return $constraints;
    }
    /**
     * @inheritDoc
     */
    public function describe_options(string $table_name): array
    {
        return [];
    }
    /**
     * {@inheritDoc}
     *
     * @param \Cake\Database\Schema\TableSchema $schema The table instance the column is in.
     * @param string $name The name of the column.
     * @return string SQL fragment.
     * @throws \Cake\Database\Exception\DatabaseException when the column type is unknown
     */
    public function column_sql(Table_Schema $schema, string $name): string
    {
        $data = $schema->get_column($name);
        assert($data !== null);
        $sql = $this->_get_type_specific_column_sql($data['type'], $schema, $name);
        if ($sql !== null) {
            return $sql;
        }
        $data['name'] = $name;
        $auto_increment_types = [Table_Schema_Interface::TYPE_TINYINTEGER, Table_Schema_Interface::TYPE_SMALLINTEGER, Table_Schema_Interface::TYPE_INTEGER, Table_Schema_Interface::TYPE_BIGINTEGER];
        $primary_key = $schema->get_primary_key();
        if (in_array($data['type'], $auto_increment_types, true) && $primary_key === [$name]) {
            $data['autoIncrement'] = true;
        }
        // Composite autoincrement columns are not supported.
        if (count($primary_key) > 1) {
            unset($data['autoIncrement']);
        }
        return $this->column_definition_sql($data);
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
        $column += ['length' => null, 'precision' => null];
        $type_map = [Table_Schema_Interface::TYPE_BINARY_UUID => ' BINARY(16)', Table_Schema_Interface::TYPE_BINARY => ' BLOB', Table_Schema_Interface::TYPE_UUID => ' CHAR(36)', Table_Schema_Interface::TYPE_CHAR => ' CHAR', Table_Schema_Interface::TYPE_STRING => ' VARCHAR', Table_Schema_Interface::TYPE_TINYINTEGER => ' TINYINT', Table_Schema_Interface::TYPE_SMALLINTEGER => ' SMALLINT', Table_Schema_Interface::TYPE_INTEGER => ' INTEGER', Table_Schema_Interface::TYPE_BIGINTEGER => ' BIGINT', Table_Schema_Interface::TYPE_BOOLEAN => ' BOOLEAN', Table_Schema_Interface::TYPE_FLOAT => ' FLOAT', Table_Schema_Interface::TYPE_DECIMAL => ' DECIMAL', Table_Schema_Interface::TYPE_DATE => ' DATE', Table_Schema_Interface::TYPE_TIME => ' TIME', Table_Schema_Interface::TYPE_DATETIME => ' DATETIME', Table_Schema_Interface::TYPE_DATETIME_FRACTIONAL => ' DATETIMEFRACTIONAL', Table_Schema_Interface::TYPE_TIMESTAMP => ' TIMESTAMP', Table_Schema_Interface::TYPE_TIMESTAMP_FRACTIONAL => ' TIMESTAMPFRACTIONAL', Table_Schema_Interface::TYPE_TIMESTAMP_TIMEZONE => ' TIMESTAMPTIMEZONE', Table_Schema_Interface::TYPE_JSON => ' TEXT', Table_Schema_Interface::TYPE_GEOMETRY => ' GEOMETRY_TEXT', Table_Schema_Interface::TYPE_POINT => ' POINT_TEXT', Table_Schema_Interface::TYPE_LINESTRING => ' LINESTRING_TEXT', Table_Schema_Interface::TYPE_POLYGON => ' POLYGON_TEXT'];
        $out = $this->_driver->quote_identifier($name);
        $has_unsigned = [Table_Schema_Interface::TYPE_TINYINTEGER, Table_Schema_Interface::TYPE_SMALLINTEGER, Table_Schema_Interface::TYPE_INTEGER, Table_Schema_Interface::TYPE_BIGINTEGER, Table_Schema_Interface::TYPE_FLOAT, Table_Schema_Interface::TYPE_DECIMAL];
        $auto_increment = (bool) ($column['autoIncrement'] ?? false);
        if (!$auto_increment && isset($column['unsigned']) && $column['unsigned'] === true && in_array($column['type'], $has_unsigned, true)) {
            $out .= ' UNSIGNED';
        }
        $found_type = false;
        if (isset($type_map[$column['type']])) {
            $out .= $type_map[$column['type']];
            $found_type = true;
        }
        $has_length = [Table_Schema_Interface::TYPE_BINARY, Table_Schema_Interface::TYPE_STRING, Table_Schema_Interface::TYPE_CHAR, Table_Schema_Interface::TYPE_TINYINTEGER, Table_Schema_Interface::TYPE_SMALLINTEGER, Table_Schema_Interface::TYPE_INTEGER];
        if ($column['type'] === Table_Schema_Interface::TYPE_TEXT && $column['length'] !== Table_Schema::LENGTH_TINY) {
            $out .= ' TEXT';
            $found_type = true;
        } elseif ($column['type'] === Table_Schema_Interface::TYPE_TEXT && $column['length'] === Table_Schema::LENGTH_TINY) {
            $out .= ' VARCHAR';
            $has_length[] = $column['type'];
            $found_type = true;
        }
        if (!$found_type) {
            $out .= ' ' . strtoupper((string) $column['type']);
            $has_length[] = $column['type'];
        }
        if (in_array($column['type'], $has_length, true) && isset($column['length']) && !$auto_increment) {
            $out .= '(' . (int) $column['length'] . ')';
        }
        $has_precision = [Table_Schema_Interface::TYPE_FLOAT, Table_Schema_Interface::TYPE_DECIMAL];
        if (in_array($column['type'], $has_precision, true) && (isset($column['length']) || isset($column['precision']))) {
            $out .= '(' . (int) $column['length'] . ',' . (int) $column['precision'] . ')';
        }
        if (isset($column['null']) && $column['null'] === false) {
            $out .= ' NOT NULL';
        }
        if ($column['type'] === Table_Schema_Interface::TYPE_INTEGER && $auto_increment) {
            $out .= ' PRIMARY KEY AUTOINCREMENT';
            unset($column['default']);
        }
        $timestamp_types = [Table_Schema_Interface::TYPE_DATETIME, Table_Schema_Interface::TYPE_DATETIME_FRACTIONAL, Table_Schema_Interface::TYPE_TIMESTAMP, Table_Schema_Interface::TYPE_TIMESTAMP_FRACTIONAL, Table_Schema_Interface::TYPE_TIMESTAMP_TIMEZONE];
        if (isset($column['null']) && $column['null'] === true && in_array($column['type'], $timestamp_types, true)) {
            $out .= ' DEFAULT NULL';
        }
        if (isset($column['default'])) {
            $out .= ' DEFAULT ' . $this->_driver->schema_value($column['default']);
        }
        if (isset($column['comment']) && $column['comment']) {
            $out .= " /* {$column['comment']} */";
        }
        return $out;
    }
    /**
     * {@inheritDoc}
     *
     * Note integer primary keys will return ''. This is intentional as Sqlite requires
     * that integer primary keys be defined in the column definition.
     *
     * @param \Cake\Database\Schema\TableSchema $schema The table instance the column is in.
     * @param string $name The name of the column.
     * @return string SQL fragment.
     */
    public function constraint_sql(Table_Schema $schema, string $name): string
    {
        $data = $schema->get_constraint($name);
        assert($data !== null, 'Data does not exist');
        $columns = '';
        if (isset($data['columns'])) {
            $column = $schema->get_column($data['columns'][0]);
            assert($column !== null, 'Data does not exist');
            if ($data['type'] === Table_Schema::CONSTRAINT_PRIMARY && count($data['columns']) === 1 && $column['type'] === Table_Schema_Interface::TYPE_INTEGER) {
                return '';
            }
            $aliased = array_map($this->_driver->quote_identifier(...), $data['columns']);
            $columns = implode(', ', $aliased);
        }
        $clause = '';
        $type = '';
        if ($data['type'] === Table_Schema::CONSTRAINT_PRIMARY) {
            $type = 'PRIMARY KEY';
        } elseif ($data['type'] === Table_Schema::CONSTRAINT_UNIQUE) {
            $type = 'UNIQUE';
        } elseif ($data['type'] === Table_Schema::CONSTRAINT_FOREIGN) {
            $type = 'FOREIGN KEY';
            $clause = rtrim(sprintf(' REFERENCES %s (%s) ON UPDATE %s ON DELETE %s %s', $this->_driver->quote_identifier($data['references'][0]), $this->_convert_constraint_columns($data['references'][1]), $this->_foreign_on_clause($data['update']), $this->_foreign_on_clause($data['delete']), $data['deferrable'] ?? null));
        } elseif ($data['type'] === Table_Schema::CONSTRAINT_CHECK) {
            $type = 'CHECK';
            $columns = $data['expression'];
        }
        return sprintf('CONSTRAINT %s %s (%s)%s', $this->_driver->quote_identifier($name), $type, $columns, $clause);
    }
    /**
     * {@inheritDoc}
     *
     * SQLite can not properly handle adding a constraint to an existing table.
     * This method is no-op
     *
     * @param \Cake\Database\Schema\TableSchema $schema The table instance the foreign key constraints are.
     * @return array SQL fragment.
     */
    public function add_constraint_sql(Table_Schema $schema): array
    {
        return [];
    }
    /**
     * {@inheritDoc}
     *
     * SQLite can not properly handle dropping a constraint to an existing table.
     * This method is no-op
     *
     * @param \Cake\Database\Schema\TableSchema $schema The table instance the foreign key constraints are.
     * @return array SQL fragment.
     */
    public function drop_constraint_sql(Table_Schema $schema): array
    {
        return [];
    }
    /**
     * @inheritDoc
     */
    public function index_sql(Table_Schema $schema, string $name): string
    {
        $data = $schema->get_index($name);
        assert($data !== null);
        $columns = array_map($this->_driver->quote_identifier(...), $data['columns']);
        return sprintf('CREATE INDEX %s ON %s (%s)', $this->_driver->quote_identifier($name), $this->_driver->quote_identifier($schema->name()), implode(', ', $columns));
    }
    /**
     * @inheritDoc
     */
    public function create_table_sql(Table_Schema $schema, array $columns, array $constraints, array $indexes): array
    {
        $lines = array_merge($columns, $constraints);
        $content = implode(",\n", array_filter($lines));
        $temporary = $schema->is_temporary() ? ' TEMPORARY ' : ' ';
        $table = sprintf("CREATE%sTABLE \"%s\" (\n%s\n)", $temporary, $schema->name(), $content);
        $out = [$table];
        foreach ($indexes as $index) {
            $out[] = $index;
        }
        return $out;
    }
    /**
     * @inheritDoc
     */
    public function truncate_table_sql(Table_Schema $schema): array
    {
        $name = $schema->name();
        $sql = [];
        if ($this->has_sequences()) {
            $sql[] = sprintf('DELETE FROM sqlite_sequence WHERE name="%s"', $name);
        }
        $sql[] = sprintf('DELETE FROM "%s"', $name);
        return $sql;
    }
    /**
     * Returns whether there is any table in this connection to SQLite containing
     * sequences
     */
    public function has_sequences(): bool
    {
        $result = $this->_driver->prepare('SELECT 1 FROM sqlite_master WHERE name = "sqlite_sequence"');
        $result->execute();
        $this->_has_sequences = (bool) $result->fetch();
        return $this->_has_sequences;
    }
}