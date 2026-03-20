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

use function Cake\Core\Deprecation_Warning;
use Cake\Database\Driver;
use Cake\Database\Exception\Database_Exception;
use Cake\Database\Exception\Query_Exception;
use Cake\Database\Type\Column_Schema_Aware_Interface;
use Cake\Database\Type_Factory;
use InvalidArgumentException;
use PDOException;
/**
 * Base class for schema implementations.
 *
 * This class contains methods that are common across
 * the various SQL dialects.
 *
 * Provides methods for performing schema reflection. Results
 * will be in the form of structured arrays. The structure
 * of each result will be documented in this class. Subclasses
 * are free to include *additional* data that is not documented.
 *
 * @method array<mixed> listTablesWithoutViewsSql(array<string, mixed> $config) Generate the SQL to list the tables, excluding all views.
 */
abstract class Schema_Dialect
{
    /**
     * The driver instance being used.
     */
    protected Driver $_driver;
    /**
     * Constructor
     *
     * This constructor will connect the driver so that methods like columnSql() and others
     * will fail when the driver has not been connected.
     *
     * @param \Cake\Database\Driver $driver The driver to use.
     */
    public function __construct(Driver $driver)
    {
        $driver->connect();
        $this->_driver = $driver;
    }
    /**
     * Generate an ON clause for a foreign key.
     *
     * @param string $on The on clause
     */
    protected function _foreign_on_clause(string $on): string
    {
        if ($on === Table_Schema::ACTION_SET_NULL) {
            return 'SET NULL';
        }
        if ($on === Table_Schema::ACTION_SET_DEFAULT) {
            return 'SET DEFAULT';
        }
        if ($on === Table_Schema::ACTION_CASCADE) {
            return 'CASCADE';
        }
        if ($on === Table_Schema::ACTION_RESTRICT) {
            return 'RESTRICT';
        }
        if ($on === Table_Schema::ACTION_NO_ACTION) {
            return 'NO ACTION';
        }
        throw new InvalidArgumentException('Invalid value for "on": ' . $on);
    }
    /**
     * Convert string on clauses to the abstract ones.
     *
     * @param string $clause The on clause to convert.
     */
    protected function _convert_on_clause(string $clause): string
    {
        if ($clause === 'CASCADE' || $clause === 'RESTRICT') {
            return strtolower($clause);
        }
        if ($clause === 'NO ACTION') {
            return Table_Schema::ACTION_NO_ACTION;
        }
        return Table_Schema::ACTION_SET_NULL;
    }
    /**
     * Convert foreign key constraints references to a valid
     * stringified list
     *
     * @param array<string>|string $references The referenced columns of a foreign key constraint statement
     */
    protected function _convert_constraint_columns(array|string $references): string
    {
        if (is_string($references)) {
            return $this->_driver->quote_identifier($references);
        }
        return implode(', ', array_map($this->_driver->quote_identifier(...), $references));
    }
    /**
     * Tries to use a matching database type to generate the SQL
     * fragment for a single column in a table.
     *
     * @param string $columnType The column type.
     * @param \Cake\Database\Schema\TableSchemaInterface $schema The table schema instance the column is in.
     * @param string $column The name of the column.
     * @return string|null An SQL fragment, or `null` in case no corresponding type was found or the type didn't provide
     *  custom column SQL.
     */
    protected function _get_type_specific_column_sql(string $column_type, Table_Schema_Interface $schema, string $column): ?string
    {
        if (!Type_Factory::get_mapped($column_type)) {
            return null;
        }
        $type = Type_Factory::build($column_type);
        if (!$type instanceof Column_Schema_Aware_Interface) {
            return null;
        }
        return $type->get_column_sql($schema, $column, $this->_driver);
    }
    /**
     * Tries to use a matching database type to convert a SQL column
     * definition to an abstract type definition.
     *
     * @param string $columnType The column type.
     * @param array $definition The column definition.
     * @return array<string, mixed>|null Array of column information, or `null`
     *  in case no corresponding type was found or the type didn't provide custom column information.
     */
    protected function _apply_type_specific_column_conversion(string $column_type, array $definition): ?array
    {
        if (!Type_Factory::get_mapped($column_type)) {
            return null;
        }
        $type = Type_Factory::build($column_type);
        if (!$type instanceof Column_Schema_Aware_Interface) {
            return null;
        }
        return $type->convert_column_definition($definition, $this->_driver);
    }
    /**
     * Generate the SQL to drop a table.
     *
     * @param \Cake\Database\Schema\TableSchema $schema Schema instance
     * @return array SQL statements to drop a table.
     */
    public function drop_table_sql(Table_Schema $schema): array
    {
        $sql = sprintf('DROP TABLE %s', $this->_driver->quote_identifier($schema->name()));
        return [$sql];
    }
    /**
     * Generate the SQL to list the tables.
     *
     * @param array<string, mixed> $config The connection configuration to use for
     *    getting tables from.
     * @return array An array of (sql, params) to execute.
     * @deprecated 5.2.0 Use `listTables()` instead.
     */
    abstract public function list_tables_sql(array $config): array;
    /**
     * Generate the SQL to describe a table.
     *
     * @param string $tableName The table name to get information on.
     * @param array<string, mixed> $config The connection configuration.
     * @return array An array of (sql, params) to execute.
     * @deprecated 5.2.0 Use `describeColumns()` instead.
     */
    abstract public function describe_column_sql(string $table_name, array $config): array;
    /**
     * Generate the SQL to describe the indexes in a table.
     *
     * @param string $tableName The table name to get information on.
     * @param array<string, mixed> $config The connection configuration.
     * @return array An array of (sql, params) to execute.
     * @deprecated 5.2.0 Use `describeIndexes()` instead.
     */
    abstract public function describe_index_sql(string $table_name, array $config): array;
    /**
     * Generate the SQL to describe the foreign keys in a table.
     *
     * @param string $tableName The table name to get information on.
     * @param array<string, mixed> $config The connection configuration.
     * @return array An array of (sql, params) to execute.
     * @deprecated 5.2.0 Use `describeForeignKeys()` instead.
     */
    abstract public function describe_foreign_key_sql(string $table_name, array $config): array;
    /**
     * Generate the SQL to describe table options
     *
     * @param string $tableName Table name.
     * @param array<string, mixed> $config The connection configuration.
     * @return array SQL statements to get options for a table.
     * @deprecated 5.2.0 Use `describeOptions()` instead.
     */
    public function describe_options_sql(string $table_name, array $config): array
    {
        return ['', ''];
    }
    /**
     * Convert field description results into abstract schema fields.
     *
     * @param \Cake\Database\Schema\TableSchema $schema The table object to append fields to.
     * @param array $row The row data from `describeColumnSql`.
     * @deprecated 5.2.0 Use `describeColumns()` instead.
     */
    abstract public function convert_column_description(Table_Schema $schema, array $row): void;
    /**
     * Convert an index description results into abstract schema indexes or constraints.
     *
     * @param \Cake\Database\Schema\TableSchema $schema The table object to append
     *    an index or constraint to.
     * @param array $row The row data from `describeIndexSql`.
     * @deprecated 5.2.0 Use `describeIndexes()` instead.
     */
    abstract public function convert_index_description(Table_Schema $schema, array $row): void;
    /**
     * Convert a foreign key description into constraints on the Table object.
     *
     * @param \Cake\Database\Schema\TableSchema $schema The table object to append
     *    a constraint to.
     * @param array $row The row data from `describeForeignKeySql`.
     * @deprecated 5.2.0 Use `describeForeignKeys()` instead.
     */
    abstract public function convert_foreign_key_description(Table_Schema $schema, array $row): void;
    /**
     * Convert options data into table options.
     *
     * @param \Cake\Database\Schema\TableSchema $schema Table instance.
     * @param array $row The row of data.
     * @deprecated 5.2.0 Use `describeOptions()` instead.
     */
    public function convert_options_description(Table_Schema $schema, array $row): void
    {
    }
    /**
     * Generate the SQL to create a table.
     *
     * @param \Cake\Database\Schema\TableSchema $schema Table instance.
     * @param array<string> $columns The columns to go inside the table.
     * @param array<string> $constraints The constraints for the table.
     * @param array<string> $indexes The indexes for the table.
     * @return array<string> SQL statements to create a table.
     */
    abstract public function create_table_sql(Table_Schema $schema, array $columns, array $constraints, array $indexes): array;
    /**
     * Generate the SQL fragment for a single column in a table.
     *
     * @param \Cake\Database\Schema\TableSchema $schema The table instance the column is in.
     * @param string $name The name of the column.
     * @return string SQL fragment.
     */
    abstract public function column_sql(Table_Schema $schema, string $name): string;
    /**
     * Generate the SQL queries needed to add foreign key constraints to the table
     *
     * @param \Cake\Database\Schema\TableSchema $schema The table instance the foreign key constraints are.
     * @return array SQL fragment.
     */
    abstract public function add_constraint_sql(Table_Schema $schema): array;
    /**
     * Generate the SQL queries needed to drop foreign key constraints from the table
     *
     * @param \Cake\Database\Schema\TableSchema $schema The table instance the foreign key constraints are.
     * @return array SQL fragment.
     */
    abstract public function drop_constraint_sql(Table_Schema $schema): array;
    /**
     * Generate the SQL fragments for defining table constraints.
     *
     * @param \Cake\Database\Schema\TableSchema $schema The table instance the column is in.
     * @param string $name The name of the column.
     * @return string SQL fragment.
     */
    abstract public function constraint_sql(Table_Schema $schema, string $name): string;
    /**
     * Generate the SQL fragment for a single index in a table.
     *
     * @param \Cake\Database\Schema\TableSchema $schema The table object the column is in.
     * @param string $name The name of the column.
     * @return string SQL fragment.
     */
    abstract public function index_sql(Table_Schema $schema, string $name): string;
    /**
     * Generate the SQL to truncate a table.
     *
     * @param \Cake\Database\Schema\TableSchema $schema Table instance.
     * @return array SQL statements to truncate a table.
     */
    abstract public function truncate_table_sql(Table_Schema $schema): array;
    /**
     * Create a SQL snippet for a column based on the array shape
     * that `describeColumns()` creates.
     *
     * @param array $column The column metadata
     * @return string Generated SQL fragment for a column
     */
    public function column_definition_sql(array $column): string
    {
        deprecation_warning('5.2.0', 'SchemaDialect subclasses need to implement `columnDefinitionSql` before 6.0.0');
        $table = new Table_Schema('placeholder');
        $table->add_column($column['name'], $column);
        return $this->column_sql($table, $column['name']);
    }
    /**
     * Get the list of tables, excluding any views, available in the current connection.
     *
     * @return array<string> The list of tables in the connected database/schema.
     */
    public function list_tables_without_views(): array
    {
        [$sql, $params] = $this->list_tables_without_views_sql($this->_driver->config());
        $result = [];
        $statement = $this->_driver->execute($sql, $params);
        while ($row = $statement->fetch()) {
            $result[] = $row[0];
        }
        return $result;
    }
    /**
     * Get the list of tables and views available in the current connection.
     *
     * @param string|null $schema The schema to get the tables for. If null the default schema is used.
     * @return array<string> The list of tables and views in the connected database/schema.
     */
    public function list_tables(?string $schema = null): array
    {
        $config = $this->_driver->config();
        if ($schema !== null) {
            $config['schema'] = $schema;
            // Set database for MySQL
            $config['database'] = $schema;
        }
        [$sql, $params] = $this->list_tables_sql($config);
        $result = [];
        $statement = $this->_driver->execute($sql, $params);
        while ($row = $statement->fetch()) {
            $result[] = $row[0];
        }
        return $result;
    }
    /**
     * Get the column metadata for a table.
     *
     * The name can include a database schema name in the form 'schema.table'.
     *
     * @param string $name The name of the table to describe.
     * @return \Cake\Database\Schema\TableSchemaInterface Object with column metadata.
     * @throws \Cake\Database\Exception\DatabaseException when table cannot be described.
     */
    public function describe(string $name): Table_Schema_Interface
    {
        $table_name = $name;
        if (str_contains($name, '.')) {
            $table_name = explode('.', $name)[1];
        }
        $table = $this->_driver->new_table_schema($table_name);
        foreach ($this->describe_columns($name) as $column) {
            $table->add_column($column['name'], $column);
        }
        foreach ($this->describe_indexes($name) as $index) {
            if (in_array($index['type'], [Table_Schema::CONSTRAINT_UNIQUE, Table_Schema::CONSTRAINT_PRIMARY])) {
                $table->add_constraint($index['name'], $index);
            } else {
                $table->add_index($index['name'], $index);
            }
        }
        foreach ($this->describe_foreign_keys($name) as $key) {
            $table->add_constraint($key['name'], $key);
        }
        foreach ($this->describe_check_constraints($name) as $key) {
            $table->add_constraint($key['name'], $key);
        }
        $options = $this->describe_options($name);
        if ($options) {
            $table->set_options($options);
        }
        if ($table->columns() === []) {
            throw new Database_Exception(sprintf('Cannot describe %s. It has 0 columns.', $name));
        }
        return $table;
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
     * Additionaly the `autoIncrement` key will be set for columns that are a primary key.
     *
     * @param string $tableName The name of the table to describe columns on.
     */
    public function describe_columns(string $table_name): array
    {
        deprecation_warning('5.2.0', 'SchemaDialect subclasses need to implement `describeColumns` before 6.0.0');
        $config = $this->_driver->config();
        if (str_contains($table_name, '.')) {
            [$config['schema'], $table_name] = explode('.', $table_name);
        }
        /** @var \Cake\Database\Schema\TableSchema $table */
        $table = $this->_driver->new_table_schema($table_name);
        [$sql, $params] = $this->describe_column_sql($table_name, $config);
        $statement = $this->_driver->execute($sql, $params);
        foreach ($statement->fetch_all('assoc') as $row) {
            $this->convert_column_description($table, $row);
        }
        $columns = [];
        foreach ($table->columns() as $column_name) {
            $column = $table->get_column($column_name);
            $column['name'] = $column_name;
            $columns[] = $column;
        }
        return $columns;
    }
    /**
     * Get a list of constraint metadata as a array
     *
     * Each item in the array will contain the following:
     *
     * - name : The name of the constraint
     * - type : the type of the constraint. Generally `foreign`.
     * - columns : the columns in the constraint on the.
     * - references : A list of the table + all columns in the referenced table
     * - update : The update action or null
     * - delete : The delete action or null
     *
     * @param string $tableName The name of the table to describe foreign keys on.
     */
    public function describe_foreign_keys(string $table_name): array
    {
        deprecation_warning('5.2.0', 'SchemaDialect subclasses need to implement `describeForeignKeys` before 6.0.0');
        $config = $this->_driver->config();
        if (str_contains($table_name, '.')) {
            [$config['schema'], $table_name] = explode('.', $table_name);
        }
        /** @var \Cake\Database\Schema\TableSchema $table */
        $table = $this->_driver->new_table_schema($table_name);
        // Add the columns because TableSchema needs them.
        foreach ($this->describe_columns($table_name) as $column) {
            $table->add_column($column['name'], $column);
        }
        [$sql, $params] = $this->describe_foreign_key_sql($table_name, $config);
        $statement = $this->_driver->execute($sql, $params);
        foreach ($statement->fetch_all('assoc') as $row) {
            $this->convert_foreign_key_description($table, $row);
        }
        $keys = [];
        foreach ($table->constraints() as $name) {
            $key = $table->get_constraint($name);
            $key['name'] = $name;
            $keys[] = $key;
        }
        return $keys;
    }
    /**
     * Get a list of index metadata as a array
     *
     * Each item in the array will contain the following:
     *
     * - name : the name of the index.
     * - type : the type of the index. One of `unique`, `index`, `primary`.
     * - columns : the columns in the index.
     * - length : the length of the index if applicable.
     *
     * @param string $tableName The name of the table to describe indexes on.
     */
    public function describe_indexes(string $table_name): array
    {
        deprecation_warning('5.2.0', 'SchemaDialect subclasses need to implement `describeIndexes` before 6.0.0');
        $config = $this->_driver->config();
        if (str_contains($table_name, '.')) {
            [$config['schema'], $table_name] = explode('.', $table_name);
        }
        /** @var \Cake\Database\Schema\TableSchema $table */
        $table = $this->_driver->new_table_schema($table_name);
        // Add the columns because TableSchema needs them.
        foreach ($this->describe_columns($table_name) as $column) {
            $table->add_column($column['name'], $column);
        }
        [$sql, $params] = $this->describe_index_sql($table_name, $config);
        $statement = $this->_driver->execute($sql, $params);
        foreach ($statement->fetch_all('assoc') as $row) {
            $this->convert_index_description($table, $row);
        }
        $indexes = [];
        foreach ($table->indexes() as $name) {
            $index = $table->get_index($name);
            $index['name'] = $name;
            $indexes[] = $index;
        }
        return $indexes;
    }
    /**
     * Get platform specific options
     *
     * No keys are guaranteed to be present as they are database driver dependent.
     *
     * @param string $tableName The name of the table to describe options on.
     */
    public function describe_options(string $table_name): array
    {
        deprecation_warning('5.2.0', 'SchemaDialect subclasses need to implement `describeOptions` before 6.0.0');
        $config = $this->_driver->config();
        if (str_contains($table_name, '.')) {
            [$config['schema'], $table_name] = explode('.', $table_name);
        }
        /** @var \Cake\Database\Schema\TableSchema $table */
        $table = $this->_driver->new_table_schema($table_name);
        [$sql, $params] = $this->describe_options_sql($table_name, $config);
        if ($sql) {
            $statement = $this->_driver->execute($sql, $params);
            foreach ($statement->fetch_all('assoc') as $row) {
                $this->convert_options_description($table, $row);
            }
        }
        return $table->get_options();
    }
    /**
     * Get a list of check constraint metadata as an array.
     *
     * Each item in the array will contain the following keys:
     *
     * - name - The name of the constraint.
     * - expression - The check constraint expression as a SQL fragment.
     *
     * @param string $tableName The name of the table to describe options on.
     */
    public function describe_check_constraints(string $table_name): array
    {
        return [];
    }
    /**
     * Check if a table has a column with a given name.
     *
     * @param string $tableName The name of the table
     * @param string $columnName The name of the column
     */
    public function has_column(string $table_name, string $column_name): bool
    {
        try {
            $columns = $this->describe_columns($table_name);
        } catch (PDOException|Database_Exception) {
            return false;
        }
        foreach ($columns as $column) {
            if ($column['name'] === $column_name) {
                return true;
            }
        }
        return false;
    }
    /**
     * Check if a table exists
     *
     * @param string $tableName The name of the table
     * @param string|null $schema The schema look for table in. If null the default schema is used.
     */
    public function has_table(string $table_name, ?string $schema = null): bool
    {
        $tables = $this->list_tables($schema);
        return in_array($table_name, $tables, true);
    }
    /**
     * Check if a table has an index with a given name.
     *
     * @param string $tableName The name of the table
     * @param array<string> $columns The columns in the index. Specific
     *   ordering matters.
     * @param string $name The name of the index to match on. Can be used alone,
     *   or with $columns to match indexes more precisely.
     */
    public function has_index(string $table_name, array $columns = [], ?string $name = null): bool
    {
        try {
            $indexes = $this->describe_indexes($table_name);
        } catch (Query_Exception) {
            return false;
        }
        $found = null;
        foreach ($indexes as $index) {
            if ($columns && $index['columns'] === $columns) {
                $found = $index;
                break;
            }
            if ($columns === [] && $name !== null) {
                if ($index['name'] === $name) {
                    $found = $index;
                    break;
                }
                if (isset($index['constraint']) && $index['constraint'] === $name) {
                    $found = $index;
                    break;
                }
            }
        }
        // Both columns and name provided, both must match;
        if ($columns && $found && $name !== null && $found['name'] !== $name) {
            return false;
        }
        return $found !== null;
    }
    /**
     * Check if a table has a foreign key with a given name.
     *
     * @param string $tableName The name of the table
     * @param array<string> $columns The columns in the foreign key. Specific
     *   ordering matters.
     * @param string $name The name of the foreign key to match on. Can be used alone,
     *   or with $columns to match keys more precisely.
     */
    public function has_foreign_key(string $table_name, array $columns = [], ?string $name = null): bool
    {
        try {
            $keys = $this->describe_foreign_keys($table_name);
        } catch (Query_Exception) {
            return false;
        }
        $found = null;
        foreach ($keys as $key) {
            if ($columns && $key['columns'] === $columns) {
                $found = $key;
                break;
            }
            if (!$columns && $name !== null && $key['name'] === $name) {
                $found = $key;
                break;
            }
        }
        // Both columns and name provided, both must match;
        if ($found !== null && $name !== null && $found['name'] !== $name) {
            return false;
        }
        return $found !== null;
    }
}