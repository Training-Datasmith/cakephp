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

use Cake\Database\Connection;
use Cake\Database\Exception\Database_Exception;
/**
 * Represents a single table in a database schema.
 *
 * Can either be populated using the reflection API's
 * or by incrementally building an instance using
 * methods.
 *
 * Once created TableSchema instances can be added to
 * Schema\Collection objects. They can also be converted into SQL using the
 * createSql(), dropSql() and truncateSql() methods.
 */
class Table_Schema implements Table_Schema_Interface, Sql_Generator_Interface
{
    /**
     * Columns in the table.
     *
     * @var array<string, \Cake\Database\Schema\Column>
     */
    protected array $_columns = [];
    /**
     * A map with columns to types
     *
     * @var array<string, string>
     */
    protected array $_type_map = [];
    /**
     * Indexes in the table.
     *
     * @var array<string, \Cake\Database\Schema\Index>
     */
    protected array $_indexes = [];
    /**
     * Constraints in the table.
     *
     * @var array<string, \Cake\Database\Schema\Constraint>
     */
    protected array $_constraints = [];
    /**
     * Options for the table.
     *
     * @var array<string, mixed>
     */
    protected array $_options = [];
    /**
     * Whether the table is temporary
     */
    protected bool $_temporary = false;
    /**
     * Column length when using a `tiny` column type
     *
     * @var int
     */
    public const LENGTH_TINY = 255;
    /**
     * Column length when using a `medium` column type
     *
     * @var int
     */
    public const LENGTH_MEDIUM = 16777215;
    /**
     * Column length when using a `long` column type
     *
     * @var int
     */
    public const LENGTH_LONG = 4294967295;
    /**
     * Valid column length that can be used with text type columns
     *
     * @var array<string, int>
     */
    public static array $column_lengths = ['tiny' => self::LENGTH_TINY, 'medium' => self::LENGTH_MEDIUM, 'long' => self::LENGTH_LONG];
    /**
     * The valid keys that can be used in a column
     * definition.
     *
     * @var array<string, mixed>
     */
    protected static array $_column_keys = ['type' => null, 'baseType' => null, 'length' => null, 'precision' => null, 'null' => null, 'default' => null, 'comment' => null];
    /**
     * Additional type specific properties.
     *
     * @var array<string, array<string, mixed>>
     */
    protected static array $_column_extras = ['string' => ['collate' => null], 'char' => ['collate' => null], 'text' => ['collate' => null], 'uuid' => ['collate' => null], 'tinyinteger' => ['unsigned' => null, 'autoIncrement' => null], 'smallinteger' => ['unsigned' => null, 'autoIncrement' => null], 'integer' => ['unsigned' => null, 'autoIncrement' => null, 'generated' => null], 'biginteger' => ['unsigned' => null, 'autoIncrement' => null, 'generated' => null], 'decimal' => ['unsigned' => null], 'float' => ['unsigned' => null], 'geometry' => ['srid' => null], 'point' => ['srid' => null], 'linestring' => ['srid' => null], 'polygon' => ['srid' => null], 'datetime' => ['onUpdate' => null], 'datetimefractional' => ['onUpdate' => null], 'timestamp' => ['onUpdate' => null], 'timestampfractional' => ['onUpdate' => null], 'timestamptimezone' => ['onUpdate' => null], 'binary' => ['fixed' => null]];
    /**
     * The valid keys that can be used in an index
     * definition.
     *
     * @var array<string, mixed>
     */
    protected static array $_index_keys = ['type' => null, 'columns' => [], 'length' => [], 'references' => [], 'include' => null, 'update' => 'restrict', 'delete' => 'restrict', 'constraint' => null, 'deferrable' => null, 'expression' => null];
    /**
     * Names of the valid index types.
     *
     * @var array<string>
     */
    protected static array $_valid_index_types = [self::INDEX_INDEX, self::INDEX_FULLTEXT];
    /**
     * Names of the valid constraint types.
     *
     * @var array<string>
     */
    protected static array $_valid_constraint_types = [self::CONSTRAINT_PRIMARY, self::CONSTRAINT_UNIQUE, self::CONSTRAINT_FOREIGN, self::CONSTRAINT_CHECK];
    /**
     * Names of the valid foreign key actions.
     *
     * @var array<string>
     */
    protected static array $_valid_foreign_key_actions = [self::ACTION_CASCADE, self::ACTION_SET_NULL, self::ACTION_SET_DEFAULT, self::ACTION_NO_ACTION, self::ACTION_RESTRICT];
    /**
     * Primary constraint type
     *
     * @var string
     */
    public const CONSTRAINT_PRIMARY = 'primary';
    /**
     * Unique constraint type
     *
     * @var string
     */
    public const CONSTRAINT_UNIQUE = 'unique';
    /**
     * Foreign constraint type
     *
     * @var string
     */
    public const CONSTRAINT_FOREIGN = 'foreign';
    /**
     * check constraint type
     *
     * @var string
     */
    public const CONSTRAINT_CHECK = 'check';
    /**
     * Index - index type
     *
     * @var string
     */
    public const INDEX_INDEX = Index::INDEX;
    /**
     * Fulltext index type
     *
     * @var string
     */
    public const INDEX_FULLTEXT = Index::FULLTEXT;
    /**
     * Foreign key cascade action
     *
     * @var string
     */
    public const ACTION_CASCADE = Foreign_Key::CASCADE;
    /**
     * Foreign key set null action
     *
     * @var string
     */
    public const ACTION_SET_NULL = Foreign_Key::SET_NULL;
    /**
     * Foreign key no action
     *
     * @var string
     */
    public const ACTION_NO_ACTION = Foreign_Key::NO_ACTION;
    /**
     * Foreign key restrict action
     *
     * @var string
     */
    public const ACTION_RESTRICT = Foreign_Key::RESTRICT;
    /**
     * Foreign key restrict default
     *
     * @var string
     */
    public const ACTION_SET_DEFAULT = Foreign_Key::SET_DEFAULT;
    /**
     * Constructor.
     *
     * @param string $_table The table name.
     * @param array<string, array|string> $columns The list of columns for the schema.
     */
    public function __construct(
        /**
         * The name of the table
         */
        protected string $_table,
        array $columns = []
    )
    {
        foreach ($columns as $field => $definition) {
            $this->add_column($field, $definition);
        }
    }
    /**
     * @inheritDoc
     */
    public function name(): string
    {
        return $this->_table;
    }
    /**
     * @inheritDoc
     */
    public function add_column(string $name, array|string $attrs): static
    {
        if (is_string($attrs)) {
            $attrs = ['type' => $attrs];
        }
        $valid = static::$_column_keys;
        if (isset(static::$_column_extras[$attrs['type']])) {
            $valid += static::$_column_extras[$attrs['type']];
        }
        $attrs = array_intersect_key($attrs, $valid);
        $attrs['name'] = $name;
        foreach (array_keys($attrs) as $key) {
            $value = $attrs[$key];
            if ($value === null) {
                unset($attrs[$key]);
                continue;
            }
            if ($key === 'autoIncrement') {
                $attrs['identity'] = $value;
                unset($attrs[$key]);
                continue;
            }
            $attrs[$key] = $value;
        }
        // Cast numeric values that may come as floats from database drivers.
        // PHP 8.4 is stricter about implicit float-to-int conversions.
        // Known to affect SQLite on Windows x86.
        foreach (['length', 'precision', 'srid'] as $key) {
            if (isset($attrs[$key])) {
                $attrs[$key] = (int) $attrs[$key];
            }
        }
        $column = new Column(...$attrs);
        $this->_columns[$name] = $column;
        $this->_type_map[$name] = $column->get_type();
        return $this;
    }
    /**
     * @inheritDoc
     */
    public function remove_column(string $name): static
    {
        unset($this->_columns[$name], $this->_type_map[$name]);
        return $this;
    }
    /**
     * @inheritDoc
     */
    public function columns(): array
    {
        return array_keys($this->_columns);
    }
    /**
     * @inheritDoc
     */
    public function get_column(string $name): ?array
    {
        if (!isset($this->_columns[$name])) {
            return null;
        }
        $column = $this->_columns[$name];
        $attrs = $column->to_array();
        $expected = static::$_column_keys;
        if (isset(static::$_column_extras[$attrs['type']])) {
            $expected += static::$_column_extras[$attrs['type']];
        }
        // Remove any attributes that weren't in the allow list.
        // This is to provide backwards compatible keys
        $remove = array_diff(array_keys($attrs), array_keys($expected));
        foreach ($remove as $key) {
            unset($attrs[$key]);
        }
        if (isset($attrs['baseType']) && $attrs['baseType'] === $attrs['type']) {
            unset($attrs['baseType']);
        }
        return $attrs;
    }
    /**
     * Get a column object for a given column name.
     *
     * Will raise an exception if the column does not exist.
     *
     * @param string $name The name of the column to get.
     */
    public function column(string $name): Column
    {
        $column = $this->_columns[$name] ?? null;
        if ($column === null) {
            $message = sprintf('Table `%s` does not contain a column named `%s`.', $this->_table, $name);
            throw new Database_Exception($message);
        }
        return $column;
    }
    /**
     * @inheritDoc
     */
    public function get_column_type(string $name): ?string
    {
        if (!isset($this->_columns[$name])) {
            return null;
        }
        return $this->_columns[$name]->get_type();
    }
    /**
     * @inheritDoc
     */
    public function set_column_type(string $name, string $type): static
    {
        if (!isset($this->_columns[$name])) {
            $message = sprintf('Column `%s` of table `%s`: The column type `%s` can only be set if the column already exists;', $name, $this->_table, $type);
            $message .= ' can be checked using `hasColumn()`.';
            throw new Database_Exception($message);
        }
        $this->_columns[$name]->set_type($type)->set_base_type(null);
        $this->_type_map[$name] = $type;
        return $this;
    }
    /**
     * @inheritDoc
     */
    public function has_column(string $name): bool
    {
        return isset($this->_columns[$name]);
    }
    /**
     * @inheritDoc
     */
    public function base_column_type(string $column): ?string
    {
        if (!isset($this->_columns[$column])) {
            return null;
        }
        return $this->_columns[$column]->get_base_type();
    }
    /**
     * @inheritDoc
     */
    public function type_map(): array
    {
        return $this->_type_map;
    }
    /**
     * @inheritDoc
     */
    public function is_nullable(string $name): bool
    {
        if (!isset($this->_columns[$name])) {
            return true;
        }
        return $this->_columns[$name]->get_null() === true;
    }
    /**
     * @inheritDoc
     */
    public function default_values(): array
    {
        $defaults = [];
        foreach ($this->_columns as $column) {
            $default = $column->get_default();
            if ($default === null && $column->get_null() !== true && $column->get_name()) {
                continue;
            }
            $defaults[$column->get_name()] = $default;
        }
        return $defaults;
    }
    /**
     * @inheritDoc
     */
    public function add_index(string $name, array|string $attrs): static
    {
        if (is_string($attrs)) {
            $attrs = ['type' => $attrs];
        }
        $attrs = array_intersect_key($attrs, static::$_index_keys);
        $attrs += static::$_index_keys;
        unset($attrs['references'], $attrs['update'], $attrs['delete'], $attrs['constraint'], $attrs['deferrable'], $attrs['expression']);
        if (!in_array($attrs['type'], static::$_valid_index_types, true)) {
            throw new Database_Exception(sprintf('Invalid index type `%s` in index `%s` in table `%s`.', $attrs['type'], $name, $this->_table));
        }
        $attrs['columns'] = (array) $attrs['columns'];
        foreach ($attrs['columns'] as $field) {
            if (empty($this->_columns[$field])) {
                $msg = sprintf('Columns used in index `%s` in table `%s` must be added to the Table schema first. ' . 'The column `%s` was not found.', $name, $this->_table, $field);
                throw new Database_Exception($msg);
            }
        }
        $attrs['name'] = $name;
        $this->_indexes[$name] = new Index(...$attrs);
        return $this;
    }
    /**
     * @inheritDoc
     */
    public function indexes(): array
    {
        return array_keys($this->_indexes);
    }
    /**
     * @inheritDoc
     */
    public function get_index(string $name): ?array
    {
        if (!isset($this->_indexes[$name])) {
            return null;
        }
        $index = $this->_indexes[$name];
        $attrs = $index->to_array();
        $optional = ['order', 'include', 'where'];
        foreach ($optional as $key) {
            if ($attrs[$key] === null) {
                unset($attrs[$key]);
            }
        }
        unset($attrs['name']);
        return $attrs;
    }
    /**
     * Get a index object for a given index name.
     *
     * Will raise an exception if no index can be found.
     *
     * @param string $name The name of the index to get.
     */
    public function index(string $name): Index
    {
        $index = $this->_indexes[$name] ?? null;
        if ($index === null) {
            $message = sprintf('Table `%s` does not contain a index named `%s`.', $this->_table, $name);
            throw new Database_Exception($message);
        }
        return $index;
    }
    /**
     * @inheritDoc
     */
    public function get_primary_key(): array
    {
        foreach ($this->_constraints as $data) {
            if ($data->get_type() === static::CONSTRAINT_PRIMARY) {
                return (array) $data->get_columns();
            }
        }
        return [];
    }
    /**
     * @inheritDoc
     */
    public function add_constraint(string $name, array|string $attrs): static
    {
        if (is_string($attrs)) {
            $attrs = ['type' => $attrs];
        }
        $attrs = array_intersect_key($attrs, static::$_index_keys);
        $attrs += static::$_index_keys;
        if ($attrs['constraint'] === null) {
            unset($attrs['constraint']);
        }
        if (!in_array($attrs['type'], static::$_valid_constraint_types, true)) {
            throw new Database_Exception(sprintf('Invalid constraint type `%s` in table `%s`.', $attrs['type'], $this->_table));
        }
        if ($attrs['type'] !== Table_Schema::CONSTRAINT_CHECK) {
            if (empty($attrs['columns'])) {
                throw new Database_Exception(sprintf('Constraints in table `%s` must have at least one column.', $this->_table));
            }
            $attrs['columns'] = (array) $attrs['columns'];
            foreach ($attrs['columns'] as $field) {
                if (empty($this->_columns[$field])) {
                    $msg = sprintf('Columns used in constraints must be added to the Table schema first. ' . 'The column `%s` was not found in table `%s`.', $field, $this->_table);
                    throw new Database_Exception($msg);
                }
            }
        }
        $attrs['name'] = $attrs['constraint'] ?? $name;
        unset($attrs['constraint'], $attrs['include']);
        $type = $attrs['type'] ?? null;
        if ($type === static::CONSTRAINT_FOREIGN) {
            $attrs = $this->_check_foreign_key($attrs);
        } elseif ($type === static::CONSTRAINT_PRIMARY) {
            $attrs = ['type' => $type, 'name' => $attrs['name'], 'columns' => $attrs['columns']];
        } elseif ($type === static::CONSTRAINT_CHECK) {
            $attrs = ['name' => $attrs['name'], 'expression' => $attrs['expression']];
        } elseif ($type === static::CONSTRAINT_UNIQUE) {
            $attrs = ['name' => $attrs['name'], 'columns' => $attrs['columns'], 'length' => $attrs['length']];
        }
        if ($type === static::CONSTRAINT_FOREIGN) {
            $constraint = $this->_constraints[$name] ?? null;
            if ($constraint instanceof Foreign_Key) {
                // Update an existing foreign key constraint.
                // This is backwards compatible with the incremental
                // build API that I would like to deprecate.
                $constraint->set_columns(array_unique(array_merge((array) $constraint->get_columns(), $attrs['columns'])));
                if ($constraint->get_referenced_table()) {
                    $constraint->set_columns(array_unique(array_merge($constraint->get_referenced_columns(), [$attrs['references'][1]])));
                }
                return $this;
            }
        }
        $this->_constraints[$name] = match ($type) {
            static::CONSTRAINT_UNIQUE => new Unique_Key(...$attrs),
            static::CONSTRAINT_FOREIGN => new Foreign_Key(...$attrs),
            static::CONSTRAINT_PRIMARY => new Constraint(...$attrs),
            static::CONSTRAINT_CHECK => new Check_Constraint(...$attrs),
            default => new Constraint(...$attrs),
        };
        return $this;
    }
    /**
     * @inheritDoc
     */
    public function drop_constraint(string $name): static
    {
        if (isset($this->_constraints[$name])) {
            unset($this->_constraints[$name]);
        }
        return $this;
    }
    /**
     * Check whether a table has an autoIncrement column defined.
     */
    public function has_autoincrement(): bool
    {
        foreach ($this->_columns as $column) {
            if ($column->get_identity()) {
                return true;
            }
        }
        return false;
    }
    /**
     * Helper method to check/validate foreign keys.
     *
     * @param array<string, mixed> $attrs Attributes to set.
     * @return array<string, mixed>
     * @throws \Cake\Database\Exception\DatabaseException When foreign key definition is not valid.
     */
    protected function _check_foreign_key(array $attrs): array
    {
        if (count($attrs['references']) < 2) {
            throw new Database_Exception('References must contain a table and column.');
        }
        if (!in_array($attrs['update'], static::$_valid_foreign_key_actions)) {
            throw new Database_Exception(sprintf('Update action is invalid. Must be one of %s', implode(',', static::$_valid_foreign_key_actions)));
        }
        if (!in_array($attrs['delete'], static::$_valid_foreign_key_actions)) {
            throw new Database_Exception(sprintf('Delete action is invalid. Must be one of %s', implode(',', static::$_valid_foreign_key_actions)));
        }
        // Map the backwards compatible attributes in. Need to check for existing instance.
        $attrs['referencedTable'] = $attrs['references'][0];
        $attrs['referencedColumns'] = (array) $attrs['references'][1];
        unset($attrs['type'], $attrs['references'], $attrs['length'], $attrs['expression']);
        return $attrs;
    }
    /**
     * @inheritDoc
     */
    public function constraints(): array
    {
        return array_keys($this->_constraints);
    }
    /**
     * @inheritDoc
     */
    public function get_constraint(string $name): ?array
    {
        $constraint = $this->_constraints[$name] ?? null;
        if ($constraint === null) {
            return null;
        }
        $data = $constraint->to_array();
        if ($constraint instanceof Foreign_Key) {
            $data['references'] = [$constraint->get_referenced_table(), $constraint->get_referenced_columns()];
            // If there is only one referenced column, we return it as a string.
            // TODO this should be deprecated, but I don't know how to warn about it.
            if (count($data['references'][1]) === 1) {
                $data['references'][1] = $data['references'][1][0];
            }
            unset($data['referencedTable'], $data['referencedColumns']);
        }
        if ($constraint->get_type() === static::CONSTRAINT_PRIMARY && $name === 'primary') {
            $alias = $constraint->get_name();
            if ($alias !== 'primary') {
                $data['constraint'] = $alias;
            }
        }
        unset($data['name']);
        return $data;
    }
    /**
     * Get a constraint object for a given constraint name.
     *
     * Constraints have a few subtypes such as foreign keys and primary keys.
     * You can either use `instanceof` or getType() to check for subclass types.
     *
     * @param string $name The name of the constraint to get.
     * @return \Cake\Database\Schema\Constraint A constraint object.
     */
    public function constraint(string $name): Constraint
    {
        if (!isset($this->_constraints[$name])) {
            $message = sprintf('Table `%s` does not contain a constraint named `%s`.', $this->_table, $name);
            throw new Database_Exception($message);
        }
        return $this->_constraints[$name];
    }
    /**
     * @inheritDoc
     */
    public function set_options(array $options): static
    {
        $this->_options = $options + $this->_options;
        return $this;
    }
    /**
     * @inheritDoc
     */
    public function get_options(): array
    {
        return $this->_options;
    }
    /**
     * @inheritDoc
     */
    public function set_temporary(bool $temporary): static
    {
        $this->_temporary = $temporary;
        return $this;
    }
    /**
     * @inheritDoc
     */
    public function is_temporary(): bool
    {
        return $this->_temporary;
    }
    /**
     * @inheritDoc
     */
    public function create_sql(Connection $connection): array
    {
        $dialect = $connection->get_write_driver()->schema_dialect();
        $columns = [];
        $constraints = [];
        $indexes = [];
        foreach (array_keys($this->_columns) as $name) {
            $columns[] = $dialect->column_sql($this, $name);
        }
        foreach (array_keys($this->_constraints) as $name) {
            $constraints[] = $dialect->constraint_sql($this, $name);
        }
        foreach (array_keys($this->_indexes) as $name) {
            $indexes[] = $dialect->index_sql($this, $name);
        }
        return $dialect->create_table_sql($this, $columns, $constraints, $indexes);
    }
    /**
     * @inheritDoc
     */
    public function drop_sql(Connection $connection): array
    {
        $dialect = $connection->get_write_driver()->schema_dialect();
        return $dialect->drop_table_sql($this);
    }
    /**
     * @inheritDoc
     */
    public function truncate_sql(Connection $connection): array
    {
        $dialect = $connection->get_write_driver()->schema_dialect();
        return $dialect->truncate_table_sql($this);
    }
    /**
     * @inheritDoc
     */
    public function add_constraint_sql(Connection $connection): array
    {
        $dialect = $connection->get_write_driver()->schema_dialect();
        return $dialect->add_constraint_sql($this);
    }
    /**
     * @inheritDoc
     */
    public function drop_constraint_sql(Connection $connection): array
    {
        $dialect = $connection->get_write_driver()->schema_dialect();
        return $dialect->drop_constraint_sql($this);
    }
    /**
     * Custom unserialization that handles compatibility
     * with older CakePHP versions.
     *
     * Previously the `_columns`, `_indexes`, and `_constraints`
     * attributes contained array data. As of 5.3, those attributes
     * contain arrays of objects.
     *
     * @param array<string, mixed> $data The serialized data.
     */
    public function __unserialize(array $data): void
    {
        $this->_table = $data["\x00*\x00_table"] ?? '';
        $columns = $data["\x00*\x00_columns"] ?? [];
        foreach ($columns as $name => $column) {
            $name = (string) $name;
            if (is_array($column)) {
                $this->add_column($name, $column);
            } else {
                $this->_columns[$name] = $column;
            }
        }
        $indexes = $data["\x00*\x00_indexes"] ?? [];
        foreach ($indexes as $name => $index) {
            $name = (string) $name;
            if (is_array($index)) {
                $this->add_index($name, $index);
            } else {
                $this->_indexes[$name] = $index;
            }
        }
        $constraints = $data["\x00*\x00_constraints"] ?? [];
        foreach ($constraints as $name => $constraint) {
            $name = (string) $name;
            if (is_array($constraint)) {
                $this->add_constraint($name, $constraint);
            } else {
                $this->_constraints[$name] = $constraint;
            }
        }
        $this->_options = $data["\x00*\x00_options"] ?? [];
        $this->_type_map = $data["\x00*\x00_typeMap"] ?? [];
        $this->_temporary = $data["\x00*\x00_temporary"] ?? false;
    }
    /**
     * Returns an array of the table schema.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['table' => $this->_table, 'columns' => $this->_columns, 'indexes' => $this->_indexes, 'constraints' => $this->_constraints, 'options' => $this->_options, 'typeMap' => $this->_type_map, 'temporary' => $this->_temporary];
    }
}