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

use Cake\Database\Exception\Database_Exception;
/**
 * Schema management/reflection features for Postgres.
 *
 * @internal
 */
class Postgres_Schema_Dialect extends Schema_Dialect
{
    /**
     * @const int
     */
    public const DEFAULT_SRID = 4326;
    /**
     * @const string
     */
    public const GENERATED_BY_DEFAULT = 'BY DEFAULT';
    /**
     * Generate the SQL to list the tables and views.
     *
     * @param array<string, mixed> $config The connection configuration to use for
     *    getting tables from.
     * @return array An array of (sql, params) to execute.
     */
    public function list_tables_sql(array $config): array
    {
        $sql = 'SELECT table_name as name FROM information_schema.tables
                WHERE table_schema = ? ORDER BY name';
        $schema = $config['schema'] ?? 'public';
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
        $sql = 'SELECT table_name as name FROM information_schema.tables
                WHERE table_schema = ? AND table_type = \'BASE TABLE\' ORDER BY name';
        $schema = $config['schema'] ?? 'public';
        return [$sql, [$schema]];
    }
    /**
     * @inheritDoc
     */
    public function describe_column_sql(string $table_name, array $config): array
    {
        $sql = $this->describe_column_query();
        $schema = $config['schema'] ?? 'public';
        return [$sql, [$table_name, $schema, $config['database']]];
    }
    /**
     * Helper method for creating SQL to describe columns in a table.
     *
     * @return string SQL to reflect columns
     */
    private function describe_column_query(): string
    {
        return 'SELECT DISTINCT table_schema AS schema,
            column_name AS name,
            data_type AS type,
            udt_name,
            is_identity,
            is_nullable AS null,
            column_default AS default,
            character_maximum_length AS char_length,
            c.collation_name,
            d.description as comment,
            ordinal_position,
            c.datetime_precision,
            c.numeric_precision as column_precision,
            c.numeric_scale as column_scale,
            c.identity_generation,
            pg_get_serial_sequence(attr.attrelid::regclass::text, attr.attname) IS NOT NULL AS has_serial
        FROM information_schema.columns c
        INNER JOIN pg_catalog.pg_namespace ns ON (ns.nspname = table_schema)
        INNER JOIN pg_catalog.pg_class cl ON (cl.relnamespace = ns.oid AND cl.relname = table_name)
        LEFT JOIN pg_catalog.pg_index i ON (i.indrelid = cl.oid AND i.indkey[0] = c.ordinal_position)
        LEFT JOIN pg_catalog.pg_description d on (cl.oid = d.objoid AND d.objsubid = c.ordinal_position)
        LEFT JOIN pg_catalog.pg_attribute attr ON (cl.oid = attr.attrelid AND column_name = attr.attname)
        WHERE table_name = ? AND table_schema = ? AND table_catalog = ?
        ORDER BY ordinal_position';
    }
    /**
     * Convert a column definition to the abstract types.
     *
     * The returned type will be a type that
     * Cake\Database\TypeFactory can handle.
     *
     * @param string $column The column type + length
     * @throws \Cake\Database\Exception\DatabaseException when column cannot be parsed.
     * @return array<string, mixed> Array of column information.
     */
    protected function _convert_column(string $column): array
    {
        preg_match('/([a-z\s]+)(?:\(([0-9,]+)\))?/i', $column, $matches);
        if (!$matches) {
            throw new Database_Exception(sprintf('Unable to parse column type from `%s`', $column));
        }
        $col = strtolower($matches[1]);
        $length = null;
        $precision = null;
        $scale = null;
        if (isset($matches[2])) {
            $length = (int) $matches[2];
        }
        $type = $this->_apply_type_specific_column_conversion($col, compact('length', 'precision', 'scale'));
        if ($type !== null) {
            return $type;
        }
        if (in_array($col, ['date', 'time', 'boolean', 'inet', 'cidr', 'macaddr', 'citext', 'interval'], true)) {
            return ['type' => $col, 'length' => null];
        }
        if (in_array($col, ['timestamptz', 'timestamp with time zone'], true)) {
            return ['type' => Table_Schema_Interface::TYPE_TIMESTAMP_TIMEZONE, 'length' => null];
        }
        if (str_contains($col, 'timestamp')) {
            return ['type' => Table_Schema_Interface::TYPE_TIMESTAMP_FRACTIONAL, 'length' => null];
        }
        if (str_contains($col, 'time')) {
            return ['type' => Table_Schema_Interface::TYPE_TIME, 'length' => null];
        }
        if ($col === 'serial' || $col === 'integer') {
            return ['type' => Table_Schema_Interface::TYPE_INTEGER, 'length' => 10];
        }
        if ($col === 'bigserial' || $col === 'bigint') {
            return ['type' => Table_Schema_Interface::TYPE_BIGINTEGER, 'length' => 20];
        }
        if ($col === 'smallint') {
            return ['type' => Table_Schema_Interface::TYPE_SMALLINTEGER, 'length' => 5];
        }
        if ($col === 'uuid') {
            return ['type' => Table_Schema_Interface::TYPE_UUID, 'length' => null];
        }
        if ($col === 'char') {
            return ['type' => Table_Schema_Interface::TYPE_CHAR, 'length' => $length];
        }
        if (str_contains($col, 'character')) {
            return ['type' => Table_Schema_Interface::TYPE_STRING, 'length' => $length];
        }
        // money is 'string' as it includes arbitrary text content
        // before the number value.
        if (str_contains($col, 'money') || $col === 'string') {
            return ['type' => Table_Schema_Interface::TYPE_STRING, 'length' => $length];
        }
        if (str_contains($col, 'text')) {
            return ['type' => Table_Schema_Interface::TYPE_TEXT, 'length' => null];
        }
        if ($col === 'bytea') {
            return ['type' => Table_Schema_Interface::TYPE_BINARY, 'length' => null];
        }
        if ($col === 'real' || str_contains($col, 'double')) {
            return ['type' => Table_Schema_Interface::TYPE_FLOAT, 'length' => null];
        }
        if (str_contains($col, 'numeric') || str_contains($col, 'decimal')) {
            return ['type' => Table_Schema_Interface::TYPE_DECIMAL, 'length' => null];
        }
        if (str_contains($col, 'json')) {
            return ['type' => Table_Schema_Interface::TYPE_JSON, 'length' => null];
        }
        if (in_array($col, ['geometry', 'geography'])) {
            return ['type' => $col, 'length' => null];
        }
        $length = is_numeric($length) ? $length : null;
        return ['type' => Table_Schema_Interface::TYPE_STRING, 'length' => $length];
    }
    /**
     * @inheritDoc
     */
    public function convert_column_description(Table_Schema $schema, array $row): void
    {
        $field = $this->_convert_column($row['type']);
        if ($field['type'] === Table_Schema_Interface::TYPE_BOOLEAN) {
            if ($row['default'] === 'true') {
                $row['default'] = 1;
            }
            if ($row['default'] === 'false') {
                $row['default'] = 0;
            }
        }
        if (!empty($row['has_serial'])) {
            $field['autoIncrement'] = true;
        }
        $field += ['default' => $this->_default_value($row['default']), 'null' => $row['null'] === 'YES', 'collate' => $row['collation_name'], 'comment' => $row['comment']];
        $field['length'] = $row['char_length'] ?: $field['length'];
        if ($field['type'] === 'numeric' || $field['type'] === 'decimal') {
            $field['length'] = $row['column_precision'];
            $field['precision'] = $row['column_scale'] ?: null;
        }
        if ($field['type'] === Table_Schema_Interface::TYPE_TIMESTAMP_FRACTIONAL) {
            $field['precision'] = $row['datetime_precision'];
            if ($field['precision'] === 0) {
                $field['type'] = Table_Schema_Interface::TYPE_TIMESTAMP;
            }
        }
        if ($field['type'] === Table_Schema_Interface::TYPE_TIMESTAMP_TIMEZONE) {
            $field['precision'] = $row['datetime_precision'];
        }
        $schema->add_column($row['name'], $field);
    }
    /**
     * Split a tablename into a tuple of schema, table
     * If the table does not have a schema name included, the connection
     * schema will be used.
     *
     * @param string $tableName The table name to split
     * @param array $config Additional configuration data
     * @return array A tuple of [schema, tablename]
     */
    private function split_tablename(string $table_name, array $config = []): array
    {
        if (str_contains($table_name, '.')) {
            return explode('.', $table_name);
        }
        $driver_config = $this->_driver->config();
        $schema = $config['schema'] ?? $driver_config['schema'] ?? 'public';
        return [$schema, $table_name];
    }
    /**
     * @inheritDoc
     */
    public function describe_columns(string $table_name): array
    {
        $config = $this->_driver->config();
        [$schema, $name] = $this->split_tablename($table_name);
        $sql = $this->describe_column_query();
        $statement = $this->_driver->execute($sql, [$name, $schema, $config['database']]);
        $columns = [];
        foreach ($statement->fetch_all('assoc') as $row) {
            $type = $row['type'];
            if ($type === 'USER-DEFINED') {
                $type = $row['udt_name'];
            }
            $field = $this->_convert_column($type);
            if ($field['type'] === Table_Schema_Interface::TYPE_BOOLEAN) {
                if ($row['default'] === 'true') {
                    $row['default'] = 1;
                } elseif ($row['default'] === 'false') {
                    $row['default'] = 0;
                }
            }
            if (!empty($row['has_serial'])) {
                $field['autoIncrement'] = true;
            }
            $field += ['name' => $row['name'], 'default' => $this->_default_value($row['default']), 'null' => $row['null'] === 'YES', 'collate' => $row['collation_name'], 'comment' => $row['comment']];
            $field['length'] = $row['char_length'] ?: $field['length'];
            if ($field['type'] === 'numeric' || $field['type'] === 'decimal') {
                $field['length'] = $row['column_precision'];
                $field['precision'] = $row['column_scale'] ?: null;
            }
            if ($field['type'] === Table_Schema_Interface::TYPE_TIMESTAMP_FRACTIONAL) {
                $field['precision'] = $row['datetime_precision'];
                if ($field['precision'] === 0) {
                    $field['type'] = Table_Schema_Interface::TYPE_TIMESTAMP;
                }
            }
            if ($field['type'] === Table_Schema_Interface::TYPE_TIMESTAMP_TIMEZONE) {
                $field['precision'] = $row['datetime_precision'];
            }
            if (isset($row['identity_generation']) && $row['identity_generation']) {
                $field['generated'] = $row['identity_generation'];
            }
            $columns[] = $field;
        }
        return $columns;
    }
    /**
     * Manipulate the default value.
     *
     * Postgres includes sequence data and casting information in default values.
     * We need to remove those.
     *
     * @param string|int|null $default The default value.
     */
    protected function _default_value(string|int|null $default): string|int|null
    {
        if (is_numeric($default) || $default === null) {
            return $default;
        }
        // Sequences
        if (str_starts_with($default, 'nextval')) {
            return null;
        }
        if (str_starts_with($default, 'NULL::')) {
            return null;
        }
        // Remove quotes and postgres casts
        return preg_replace("/^'(.*)'(?:::.*)\$/", '$1', $default);
    }
    /**
     * Get the query to describe indexes
     */
    private function describe_index_query(): string
    {
        return 'SELECT
        c2.relname,
        a.attname,
        i.indisprimary,
        i.indisunique,
        i.indnkeyatts
        FROM pg_catalog.pg_namespace n
        INNER JOIN pg_catalog.pg_class c ON (n.oid = c.relnamespace)
        INNER JOIN pg_catalog.pg_index i ON (c.oid = i.indrelid)
        INNER JOIN pg_catalog.pg_class c2 ON (c2.oid = i.indexrelid)
        INNER JOIN pg_catalog.pg_attribute a ON (a.attrelid = c.oid AND i.indrelid::regclass = a.attrelid::regclass)
        WHERE n.nspname = ?
        AND a.attnum = ANY(i.indkey)
        AND c.relname = ?
        ORDER BY i.indisprimary DESC, i.indisunique DESC, c.relname, a.attnum';
    }
    /**
     * @inheritDoc
     */
    public function describe_index_sql(string $table_name, array $config): array
    {
        $sql = $this->describe_index_query();
        [$schema, $name] = $this->split_tablename($table_name, $config);
        return [$sql, [$schema, $name]];
    }
    /**
     * @inheritDoc
     */
    public function convert_index_description(Table_Schema $schema, array $row): void
    {
        $type = Table_Schema::INDEX_INDEX;
        $name = $row['relname'];
        if ($row['indisprimary']) {
            $name = Table_Schema::CONSTRAINT_PRIMARY;
            $type = Table_Schema::CONSTRAINT_PRIMARY;
        }
        if ($row['indisunique'] && $type === Table_Schema::INDEX_INDEX) {
            $type = Table_Schema::CONSTRAINT_UNIQUE;
        }
        if ($type === Table_Schema::CONSTRAINT_PRIMARY || $type === Table_Schema::CONSTRAINT_UNIQUE) {
            $this->_convert_constraint($schema, $name, $type, $row);
            return;
        }
        $index = $schema->get_index($name);
        if (!$index) {
            $index = ['type' => $type, 'columns' => []];
        }
        $index['columns'][] = $row['attname'];
        $schema->add_index($name, $index);
    }
    /**
     * @inheritDoc
     */
    public function describe_indexes(string $table_name): array
    {
        [$schema, $name] = $this->split_tablename($table_name);
        $sql = $this->describe_index_query();
        $indexes = [];
        $statement = $this->_driver->execute($sql, [$schema, $name]);
        foreach ($statement->fetch_all('assoc') as $row) {
            $type = Table_Schema::INDEX_INDEX;
            $name = $row['relname'];
            $constraint = null;
            $include_column_index = $row['indnkeyatts'];
            if ($row['indisprimary']) {
                $constraint = $name;
                $name = Table_Schema::CONSTRAINT_PRIMARY;
                $type = Table_Schema::CONSTRAINT_PRIMARY;
            }
            if ($row['indisunique'] && $type === Table_Schema::INDEX_INDEX) {
                $type = Table_Schema::CONSTRAINT_UNIQUE;
            }
            if (!isset($indexes[$name])) {
                $indexes[$name] = ['name' => $name, 'type' => $type, 'columns' => [], 'length' => []];
            }
            if ($constraint) {
                $indexes[$name]['constraint'] = $constraint;
            }
            if (count($indexes[$name]['columns']) < $include_column_index) {
                $indexes[$name]['columns'][] = $row['attname'];
            } else {
                $indexes[$name]['include'][] = $row['attname'];
            }
        }
        return array_values($indexes);
    }
    /**
     * Add/update a constraint into the schema object.
     *
     * @param \Cake\Database\Schema\TableSchema $schema The table to update.
     * @param string $name The index name.
     * @param string $type The index type.
     * @param array $row The metadata record to update with.
     */
    protected function _convert_constraint(Table_Schema $schema, string $name, string $type, array $row): void
    {
        $constraint = $schema->get_constraint($name);
        if (!$constraint) {
            $constraint = ['type' => $type, 'columns' => []];
        }
        $constraint['columns'][] = $row['attname'];
        $schema->add_constraint($name, $constraint);
    }
    /**
     * @inheritDoc
     */
    public function describe_foreign_key_sql(string $table_name, array $config): array
    {
        $sql = $this->describe_foreign_key_query();
        [$schema, $name] = $this->split_tablename($table_name, $config);
        return [$sql, [$schema, $name]];
    }
    /**
     * @inheritDoc
     */
    public function convert_foreign_key_description(Table_Schema $schema, array $row): void
    {
        $data = ['type' => Table_Schema::CONSTRAINT_FOREIGN, 'columns' => $row['column_name'], 'references' => [$row['references_table'], $row['references_field']], 'update' => $this->_convert_on_clause($row['on_update']), 'delete' => $this->_convert_on_clause($row['on_delete']), 'deferrable' => $this->convert_deferrable($row)];
        $schema->add_constraint($row['name'], $data);
    }
    /**
     * @inheritDoc
     */
    public function describe_foreign_keys(string $table_name): array
    {
        [$schema, $name] = $this->split_tablename($table_name);
        $sql = $this->describe_foreign_key_query();
        $keys = [];
        $statement = $this->_driver->execute($sql, [$schema, $name]);
        foreach ($statement->fetch_all('assoc') as $row) {
            $name = $row['name'];
            if (!isset($keys[$name])) {
                $keys[$name] = ['name' => $name, 'type' => Table_Schema::CONSTRAINT_FOREIGN, 'columns' => [], 'references' => [$row['references_table'], []], 'update' => $this->_convert_on_clause($row['on_update']), 'delete' => $this->_convert_on_clause($row['on_delete']), 'deferrable' => $this->convert_deferrable($row)];
            }
            // column indexes start at 1
            $column_order = $row['column_order'] - 1;
            $referenced_column_order = $row['references_field_order'] - 1;
            $keys[$name]['columns'][$column_order] = $row['column_name'];
            $keys[$name]['references'][1][$referenced_column_order] = $row['references_field'];
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
     * Get the query to describe foreign keys
     */
    private function describe_foreign_key_query(): string
    {
        // phpcs:disable Generic.Files.LineLength
        $sql = 'SELECT
        c.conname AS name,
        c.contype AS type,
        a.attname AS column_name,
        array_position(c.conkey, a.attnum) AS column_order,
        c.confmatchtype AS match_type,
        c.confupdtype AS on_update,
        c.confdeltype AS on_delete,
        c.confrelid::regclass AS references_table,
        ab.attname AS references_field,
        array_position(c.confkey, ab.attnum) AS references_field_order,
        c.condeferrable AS deferrable,
        c.condeferred AS initially_deferred
        FROM pg_catalog.pg_namespace n
        INNER JOIN pg_catalog.pg_class cl ON (n.oid = cl.relnamespace)
        INNER JOIN pg_catalog.pg_constraint c ON (n.oid = c.connamespace)
        INNER JOIN pg_catalog.pg_attribute a ON (a.attrelid = cl.oid AND c.conrelid = a.attrelid AND a.attnum = ANY(c.conkey))
        INNER JOIN pg_catalog.pg_attribute ab ON (a.attrelid = cl.oid AND c.confrelid = ab.attrelid AND ab.attnum = ANY(c.confkey))
        WHERE n.nspname = ?
        AND cl.relname = ?
        ORDER BY name, column_order ASC, references_field_order ASC';
        // phpcs:enable Generic.Files.LineLength
        return $sql;
    }
    /**
     * @inheritDoc
     */
    public function describe_check_constraints(string $table_name): array
    {
        [$schema, $name] = $this->split_tablename($table_name);
        $sql = 'SELECT
        con.conname AS name,
        pg_get_constraintdef(con.oid) AS expression
        FROM pg_catalog.pg_constraint AS con
        INNER JOIN pg_catalog.pg_namespace AS ns ON (ns.oid = con.connamespace)
        INNER JOIN pg_catalog.pg_class AS cls ON (cls.oid = con.conrelid)
        WHERE ns.nspname = ? AND cls.relname = ? AND con.contype = \'c\'';
        $results = [];
        $statement = $this->_driver->execute($sql, [$schema, $name]);
        foreach ($statement->fetch_all('assoc') as $row) {
            $expression = preg_replace('/^CHECK \(\((.*)\)\)$/i', '$1', (string) $row['expression']);
            $results[] = ['name' => $row['name'], 'type' => Table_Schema::CONSTRAINT_CHECK, 'expression' => $expression];
        }
        return $results;
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
    protected function _convert_on_clause(string $clause): string
    {
        if ($clause === 'r') {
            return Table_Schema::ACTION_RESTRICT;
        }
        if ($clause === 'a') {
            return Table_Schema::ACTION_NO_ACTION;
        }
        if ($clause === 'c') {
            return Table_Schema::ACTION_CASCADE;
        }
        return Table_Schema::ACTION_SET_NULL;
    }
    /**
     * Convert deferrable option from the postgres metadata into a string
     *
     * @param array $row The row to convert.
     * @return string|null The deferrable value or null if not deferrable.
     */
    protected function convert_deferrable(array $row): ?string
    {
        if (!isset($row['deferrable'])) {
            return null;
        }
        if (!$row['deferrable']) {
            return Foreign_Key::NOT_DEFERRED;
        }
        if (isset($row['initially_deferred']) && $row['initially_deferred']) {
            return Foreign_Key::DEFERRED;
        }
        return Foreign_Key::IMMEDIATE;
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
        $type_map = [Table_Schema_Interface::TYPE_TINYINTEGER => ' SMALLINT', Table_Schema_Interface::TYPE_SMALLINTEGER => ' SMALLINT', Table_Schema_Interface::TYPE_INTEGER => ' INT', Table_Schema_Interface::TYPE_BIGINTEGER => ' BIGINT', Table_Schema_Interface::TYPE_BINARY => ' BYTEA', Table_Schema_Interface::TYPE_BINARY_UUID => ' UUID', Table_Schema_Interface::TYPE_BOOLEAN => ' BOOLEAN', Table_Schema_Interface::TYPE_FLOAT => ' FLOAT', Table_Schema_Interface::TYPE_DECIMAL => ' DECIMAL', Table_Schema_Interface::TYPE_DATE => ' DATE', Table_Schema_Interface::TYPE_TIME => ' TIME', Table_Schema_Interface::TYPE_DATETIME => ' TIMESTAMP', Table_Schema_Interface::TYPE_DATETIME_FRACTIONAL => ' TIMESTAMP', Table_Schema_Interface::TYPE_TIMESTAMP => ' TIMESTAMP', Table_Schema_Interface::TYPE_TIMESTAMP_FRACTIONAL => ' TIMESTAMP', Table_Schema_Interface::TYPE_TIMESTAMP_TIMEZONE => ' TIMESTAMPTZ', Table_Schema_Interface::TYPE_UUID => ' UUID', Table_Schema_Interface::TYPE_NATIVE_UUID => ' UUID', Table_Schema_Interface::TYPE_CHAR => ' CHAR', Table_Schema_Interface::TYPE_CITEXT => ' CITEXT', Table_Schema_Interface::TYPE_JSON => ' JSONB', Table_Schema_Interface::TYPE_INTERVAL => ' INTERVAL', Table_Schema_Interface::TYPE_GEOMETRY => ' GEOGRAPHY(GEOMETRY, %s)', Table_Schema_Interface::TYPE_POINT => ' GEOGRAPHY(POINT, %s)', Table_Schema_Interface::TYPE_LINESTRING => ' GEOGRAPHY(LINESTRING, %s)', Table_Schema_Interface::TYPE_POLYGON => ' GEOGRAPHY(POLYGON, %s)', Table_Schema_Interface::TYPE_CIDR => ' CIDR', Table_Schema_Interface::TYPE_INET => ' INET', Table_Schema_Interface::TYPE_MACADDR => ' MACADDR'];
        $auto_increment_types = [Table_Schema_Interface::TYPE_TINYINTEGER, Table_Schema_Interface::TYPE_SMALLINTEGER, Table_Schema_Interface::TYPE_INTEGER, Table_Schema_Interface::TYPE_BIGINTEGER];
        $auto_increment = (bool) ($column['autoIncrement'] ?? false);
        $is_autoincrement = in_array($column['type'], $auto_increment_types, true) && $auto_increment;
        $version = $this->_driver->version();
        $identity_version = version_compare($version, '10.0', '>=');
        if ($is_autoincrement && !$identity_version) {
            $type_map[$column['type']] = str_replace('INT', 'SERIAL', $type_map[$column['type']]);
            unset($column['default']);
        }
        $found_type = false;
        if (isset($type_map[$column['type']])) {
            $out .= $type_map[$column['type']];
            $found_type = true;
        }
        $has_length = [Table_Schema_Interface::TYPE_CHAR, Table_Schema_Interface::TYPE_STRING];
        if ($column['type'] === Table_Schema_Interface::TYPE_TEXT && $column['length'] !== Table_Schema::LENGTH_TINY) {
            $out .= ' TEXT';
            $found_type = true;
        } elseif ($column['type'] === Table_Schema_Interface::TYPE_STRING || $column['type'] === Table_Schema_Interface::TYPE_TEXT && $column['length'] === Table_Schema::LENGTH_TINY) {
            $out .= ' VARCHAR';
            $has_length[] = $column['type'];
            $found_type = true;
        }
        if (!$found_type) {
            $out .= ' ' . strtoupper((string) $column['type']);
            $has_length[] = $column['type'];
        }
        if (in_array($column['type'], $has_length, true) && !empty($column['length'])) {
            $out .= '(' . $column['length'] . ')';
        }
        $has_collate = [Table_Schema_Interface::TYPE_TEXT, Table_Schema_Interface::TYPE_STRING, Table_Schema_Interface::TYPE_CHAR];
        if (in_array($column['type'], $has_collate, true) && isset($column['collate']) && $column['collate'] !== '') {
            $out .= ' COLLATE "' . $column['collate'] . '"';
        }
        $has_precision = [Table_Schema_Interface::TYPE_FLOAT, Table_Schema_Interface::TYPE_DATETIME, Table_Schema_Interface::TYPE_DATETIME_FRACTIONAL, Table_Schema_Interface::TYPE_TIMESTAMP, Table_Schema_Interface::TYPE_TIMESTAMP_FRACTIONAL, Table_Schema_Interface::TYPE_TIMESTAMP_TIMEZONE];
        if (in_array($column['type'], $has_precision) && isset($column['precision'])) {
            $out .= '(' . $column['precision'] . ')';
        }
        if ($column['type'] === Table_Schema_Interface::TYPE_DECIMAL && (isset($column['length']) || isset($column['precision']))) {
            $out .= '(' . $column['length'] . ',' . (int) $column['precision'] . ')';
        }
        if (in_array($column['type'], Table_Schema_Interface::GEOSPATIAL_TYPES)) {
            $out = sprintf($out, $column['srid'] ?? self::DEFAULT_SRID);
        }
        if (isset($column['null']) && $column['null'] === false) {
            $out .= ' NOT NULL';
        }
        if ($is_autoincrement && $identity_version) {
            $generated = $column['generated'] ?? static::GENERATED_BY_DEFAULT;
            $out .= ' GENERATED ' . $generated . ' AS IDENTITY';
        }
        $datetime_types = [Table_Schema_Interface::TYPE_DATETIME, Table_Schema_Interface::TYPE_DATETIME_FRACTIONAL, Table_Schema_Interface::TYPE_TIMESTAMP, Table_Schema_Interface::TYPE_TIMESTAMP_FRACTIONAL, Table_Schema_Interface::TYPE_TIMESTAMP_TIMEZONE];
        if (isset($column['default']) && in_array($column['type'], $datetime_types) && is_string($column['default']) && strtolower($column['default']) === 'current_timestamp') {
            $out .= ' DEFAULT CURRENT_TIMESTAMP';
        } elseif (isset($column['default'])) {
            $default_value = $column['default'];
            if ($column['type'] === 'boolean') {
                $default_value = (bool) $default_value;
            }
            $out .= ' DEFAULT ' . $this->_driver->schema_value($default_value);
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
        if ($index->get_include()) {
            $included = array_map($this->_driver->quote_identifier(...), $index->get_include());
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
        } elseif ($data['type'] === Table_Schema::CONSTRAINT_UNIQUE) {
            $out .= ' UNIQUE';
        } elseif ($data['type'] === Table_Schema::CONSTRAINT_CHECK) {
            return $out . ' CHECK (' . $data['expression'] . ')';
        }
        return $this->_key_sql($out, $data);
    }
    /**
     * Helper method for generating key SQL snippets.
     *
     * @param string $prefix The key prefix
     * @param array<string, mixed> $data Key data.
     */
    protected function _key_sql(string $prefix, array $data): string
    {
        $columns = array_map($this->_driver->quote_identifier(...), $data['columns']);
        if ($data['type'] === Table_Schema::CONSTRAINT_FOREIGN) {
            return $prefix . sprintf(
                ' FOREIGN KEY (%s) REFERENCES %s (%s) ON UPDATE %s ON DELETE %s %s',
                implode(', ', $columns),
                $this->_driver->quote_identifier($data['references'][0]),
                $this->_convert_constraint_columns($data['references'][1]),
                $this->_foreign_on_clause($data['update']),
                $this->_foreign_on_clause($data['delete']),
                // Historically CakePHP used 'DEFERRABLE INITIALLY IMEDIATE, and this maintains backwards compat.
                $data['deferrable'] ?? Foreign_Key::IMMEDIATE
            );
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
        $db_schema = $this->_driver->schema();
        if ($db_schema !== 'public') {
            $table_name = $this->_driver->quote_identifier($db_schema) . '.' . $table_name;
        }
        $temporary = $schema->is_temporary() ? ' TEMPORARY ' : ' ';
        $out = [];
        $out[] = sprintf("CREATE%sTABLE %s (\n%s\n)", $temporary, $table_name, $content);
        foreach ($indexes as $index) {
            $out[] = $index;
        }
        foreach ($schema->columns() as $column) {
            $column_data = $schema->get_column($column);
            if (isset($column_data['comment'])) {
                $out[] = sprintf('COMMENT ON COLUMN %s.%s IS %s', $table_name, $this->_driver->quote_identifier($column), $this->_driver->schema_value($column_data['comment']));
            }
        }
        return $out;
    }
    /**
     * @inheritDoc
     */
    public function truncate_table_sql(Table_Schema $schema): array
    {
        $name = $this->_driver->quote_identifier($schema->name());
        return [sprintf('TRUNCATE %s RESTART IDENTITY CASCADE', $name)];
    }
    /**
     * Generate the SQL to drop a table.
     *
     * @param \Cake\Database\Schema\TableSchema $schema Table instance
     * @return array SQL statements to drop a table.
     */
    public function drop_table_sql(Table_Schema $schema): array
    {
        $sql = sprintf('DROP TABLE %s CASCADE', $this->_driver->quote_identifier($schema->name()));
        return [$sql];
    }
}