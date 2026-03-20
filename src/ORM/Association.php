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
namespace Cake\ORM;

use Cake\Collection\Collection_Interface;
use Cake\Core\App;
use Cake\Core\Conventions_Trait;
use function Cake\Core\Plugin_Split;
use Cake\Database\Exception\Database_Exception;
use Cake\Database\Expression\Identifier_Expression;
use Cake\Database\Expression\Query_Expression;
use Cake\Database\Expression_Interface;
use Cake\Datasource\Entity_Interface;
use Cake\Datasource\Result_Set_Interface;
use Cake\ORM\Locator\Locator_Aware_Trait;
use Cake\ORM\Query\Select_Query;
use Cake\Utility\Inflector;
use Closure;
use InvalidArgumentException;
/**
 * An Association is a relationship established between two tables and is used
 * to configure and customize the way interconnected records are retrieved.
 *
 * @mixin \Cake\ORM\Table
 */
abstract class Association
{
    use Conventions_Trait;
    use Locator_Aware_Trait;
    /**
     * Strategy name to use joins for fetching associated records
     *
     * @var string
     */
    public const STRATEGY_JOIN = 'join';
    /**
     * Strategy name to use a subquery for fetching associated records
     *
     * @var string
     */
    public const STRATEGY_SUBQUERY = 'subquery';
    /**
     * Strategy name to use a select for fetching associated records
     *
     * @var string
     */
    public const STRATEGY_SELECT = 'select';
    /**
     * Association type for one to one associations.
     *
     * @var string
     */
    public const ONE_TO_ONE = 'oneToOne';
    /**
     * Association type for one to many associations.
     *
     * @var string
     */
    public const ONE_TO_MANY = 'oneToMany';
    /**
     * Association type for many to many associations.
     *
     * @var string
     */
    public const MANY_TO_MANY = 'manyToMany';
    /**
     * Association type for many to one associations.
     *
     * @var string
     */
    public const MANY_TO_ONE = 'manyToOne';
    /**
     * Name given to the association, it usually represents the alias
     * assigned to the target associated table
     */
    protected string $_name;
    /**
     * The class name of the target table object
     */
    protected string $_class_name;
    /**
     * The field name in the owning side table that is used to match with the foreignKey
     *
     * @var array<string>|string
     */
    protected array|string $_binding_key;
    /**
     * The name of the field representing the foreign key to the table to load
     *
     * @var array<string>|string|false
     */
    protected array|string|false $_foreign_key;
    /**
     * A list of conditions to be always included when fetching records from
     * the target association
     */
    protected Closure|array $_conditions = [];
    /**
     * Whether the records on the target table are dependent on the source table,
     * often used to indicate that records should be removed if the owning record in
     * the source table is deleted.
     */
    protected bool $_dependent = false;
    /**
     * Whether cascaded deletes should also fire callbacks.
     */
    protected bool $_cascade_callbacks = false;
    /**
     * Source table instance
     */
    protected Table $_source_table;
    /**
     * Target table instance
     */
    protected Table $_target_table;
    /**
     * The type of join to be used when adding the association to a query
     */
    protected string $_join_type = Select_Query::JOIN_TYPE_LEFT;
    /**
     * The property name that should be filled with data from the target table
     * in the source table record.
     */
    protected string $_property_name;
    /**
     * The strategy name to be used to fetch associated records. Some association
     * types might not implement but one strategy to fetch records.
     */
    protected string $_strategy = self::STRATEGY_JOIN;
    /**
     * The default finder name to use for fetching rows from the target table
     * With array value, finder name and default options are allowed.
     */
    protected array|string $_finder = 'all';
    /**
     * Valid strategies for this association. Subclasses can narrow this down.
     *
     * @var array<string>
     */
    protected array $_valid_strategies = [self::STRATEGY_JOIN, self::STRATEGY_SELECT, self::STRATEGY_SUBQUERY];
    /**
     * Constructor. Subclasses can override _options function to get the original
     * list of passed options if expecting any other special key
     *
     * @param string $alias The name given to the association
     * @param array<string, mixed> $options A list of properties to be set on this object
     */
    public function __construct(string $alias, array $options = [])
    {
        $defaults = ['cascadeCallbacks', 'className', 'conditions', 'dependent', 'finder', 'bindingKey', 'foreignKey', 'joinType', 'tableLocator', 'sourceTable', 'targetTable'];
        foreach ($defaults as $property) {
            if (isset($options[$property])) {
                $this->{'_' . $property} = $options[$property];
            }
        }
        if (isset($options['propertyName'])) {
            $this->set_property($options['propertyName']);
        }
        $this->_class_name ??= $alias;
        [, $name] = plugin_split($alias);
        $this->_name = $name;
        $this->_options($options);
        if (!empty($options['strategy'])) {
            $this->set_strategy($options['strategy']);
        }
    }
    /**
     * Gets the name for this association, usually the alias
     * assigned to the target associated table
     */
    public function get_name(): string
    {
        return $this->_name;
    }
    /**
     * Sets whether cascaded deletes should also fire callbacks.
     *
     * @param bool $cascadeCallbacks cascade callbacks switch value
     * @return $this
     */
    public function set_cascade_callbacks(bool $cascade_callbacks)
    {
        $this->_cascade_callbacks = $cascade_callbacks;
        return $this;
    }
    /**
     * Gets whether cascaded deletes should also fire callbacks.
     */
    public function get_cascade_callbacks(): bool
    {
        return $this->_cascade_callbacks;
    }
    /**
     * Sets the class name of the target table object.
     *
     * @param string $className Class name to set.
     * @return $this
     * @throws \InvalidArgumentException In case the class name is set after the target table has been
     *  resolved, and it doesn't match the target table's class name.
     */
    public function set_class_name(string $class_name)
    {
        if (isset($this->_target_table) && $this->_target_table::class !== App::class_name($class_name, 'Model/Table', 'Table')) {
            throw new InvalidArgumentException(sprintf("The class name `%s` doesn't match the target table class name of `%s`.", $class_name, $this->_target_table::class));
        }
        $this->_class_name = $class_name;
        return $this;
    }
    /**
     * Gets the class name of the target table object.
     */
    public function get_class_name(): string
    {
        return $this->_class_name;
    }
    /**
     * Sets the table instance for the source side of the association.
     *
     * @param \Cake\ORM\Table $table the instance to be assigned as source side
     * @return $this
     */
    public function set_source(Table $table)
    {
        $this->_source_table = $table;
        return $this;
    }
    /**
     * Gets the table instance for the source side of the association.
     */
    public function get_source(): Table
    {
        return $this->_source_table;
    }
    /**
     * Sets the table instance for the target side of the association.
     *
     * @param \Cake\ORM\Table $table the instance to be assigned as target side
     * @return $this
     */
    public function set_target(Table $table)
    {
        $this->_target_table = $table;
        return $this;
    }
    /**
     * Gets the table instance for the target side of the association.
     */
    public function get_target(): Table
    {
        if (!isset($this->_target_table)) {
            if (str_contains($this->_class_name, '.')) {
                [$plugin] = plugin_split($this->_class_name, true);
                $registry_alias = $plugin . $this->_name;
            } else {
                $registry_alias = $this->_name;
            }
            $table_locator = $this->get_table_locator();
            $config = [];
            $exists = $table_locator->exists($registry_alias);
            if (!$exists) {
                $config = ['className' => $this->_class_name];
            }
            $this->_target_table = $table_locator->get($registry_alias, $config);
            if ($exists) {
                $class_name = App::class_name($this->_class_name, 'Model/Table', 'Table') ?: Table::class;
                if (!$this->_target_table instanceof $class_name) {
                    $msg = "`%s` association `%s` of type `%s` to `%s` doesn't match the expected class `%s`. ";
                    $msg .= "You can't have an association of the same name with a different target ";
                    $msg .= '"className" option anywhere in your app.';
                    throw new Database_Exception(sprintf($msg, isset($this->_source_table) ? $this->_source_table::class : 'null', $this->get_name(), $this->type(), $this->_target_table::class, $class_name));
                }
            }
        }
        return $this->_target_table;
    }
    /**
     * Sets a list of conditions to be always included when fetching records from
     * the target association.
     *
     * @param \Closure|array $conditions list of conditions to be used
     * @see \Cake\Database\Query::where() for examples on the format of the array
     * @return $this
     */
    public function set_conditions(Closure|array $conditions)
    {
        $this->_conditions = $conditions;
        return $this;
    }
    /**
     * Gets a list of conditions to be always included when fetching records from
     * the target association.
     *
     * @see \Cake\Database\Query::where() for examples on the format of the array
     */
    public function get_conditions(): Closure|array
    {
        return $this->_conditions;
    }
    /**
     * Sets the name of the field representing the binding field with the target table.
     * When not manually specified the primary key of the owning side table is used.
     *
     * @param array<string>|string $key the table field or fields to be used to link both tables together
     * @return $this
     */
    public function set_binding_key(array|string $key)
    {
        $this->_binding_key = $key;
        return $this;
    }
    /**
     * Gets the name of the field representing the binding field with the target table.
     * When not manually specified the primary key of the owning side table is used.
     *
     * @return array<string>|string
     */
    public function get_binding_key(): array|string
    {
        if (!isset($this->_binding_key)) {
            $this->_binding_key = $this->is_owning_side($this->get_source()) ? $this->get_source()->get_primary_key() : $this->get_target()->get_primary_key();
        }
        return $this->_binding_key;
    }
    /**
     * Gets the name of the field representing the foreign key to the target table.
     *
     * @return array<string>|string|false
     */
    public function get_foreign_key(): array|string|false
    {
        return $this->_foreign_key;
    }
    /**
     * Sets the name of the field representing the foreign key to the target table.
     *
     * @param array<string>|string $key the key or keys to be used to link both tables together
     * @return $this
     */
    public function set_foreign_key(array|string $key)
    {
        $this->_foreign_key = $key;
        return $this;
    }
    /**
     * Sets whether the records on the target table are dependent on the source table.
     *
     * This is primarily used to indicate that records should be removed if the owning record in
     * the source table is deleted.
     *
     * If no parameters are passed the current setting is returned.
     *
     * @param bool $dependent Set the dependent mode. Use null to read the current state.
     * @return $this
     */
    public function set_dependent(bool $dependent)
    {
        $this->_dependent = $dependent;
        return $this;
    }
    /**
     * Sets whether the records on the target table are dependent on the source table.
     *
     * This is primarily used to indicate that records should be removed if the owning record in
     * the source table is deleted.
     */
    public function get_dependent(): bool
    {
        return $this->_dependent;
    }
    /**
     * Whether this association can be expressed directly in a query join
     *
     * @param array<string, mixed> $options custom options key that could alter the return value
     */
    public function can_be_joined(array $options = []): bool
    {
        $strategy = $options['strategy'] ?? $this->get_strategy();
        return $strategy === $this::STRATEGY_JOIN;
    }
    /**
     * Sets the type of join to be used when adding the association to a query.
     *
     * @param string $type the join type to be used (e.g. INNER)
     * @return $this
     */
    public function set_join_type(string $type)
    {
        $this->_join_type = $type;
        return $this;
    }
    /**
     * Gets the type of join to be used when adding the association to a query.
     */
    public function get_join_type(): string
    {
        return $this->_join_type;
    }
    /**
     * Sets the property name that should be filled with data from the target table
     * in the source table record.
     *
     * @param string $name The name of the association property.
     * @return $this
     */
    public function set_property(string $name)
    {
        $this->_property_name = $name;
        try {
            if (in_array($this->_property_name, $this->_source_table->get_schema()->columns(), true)) {
                $msg = 'Association property name `%s` clashes with field of same name of table `%s`.' . ' You should specify an alterate name using the `propertyName` option or `setProperty()` method.';
                trigger_error(sprintf($msg, $this->_property_name, $this->_source_table->get_table()), E_USER_WARNING);
            }
        } catch (Database_Exception) {
            // Schema is not yet loaded, can't check for clashes
        }
        return $this;
    }
    /**
     * Gets the property name that should be filled with data from the target table
     * in the source table record.
     */
    public function get_property(): string
    {
        if (!isset($this->_property_name)) {
            $this->set_property($this->_property_name());
        }
        return $this->_property_name;
    }
    /**
     * Returns default property name based on association name.
     */
    protected function _property_name(): string
    {
        [, $name] = plugin_split($this->_name);
        return Inflector::underscore($name);
    }
    /**
     * Sets the strategy name to be used to fetch associated records.
     *
     * Valid strategies depend on the association type and are stored in $_validStrategies.
     * Some association types might only implement a default strategy, making this setting
     * ineffective.
     *
     * @param string $name The strategy type (e.g., 'select', 'subquery', 'join').
     *   Available strategies vary by association type.
     * @return $this
     * @throws \InvalidArgumentException When an invalid strategy is provided.
     * @see getStrategy() to retrieve the current strategy.
     */
    public function set_strategy(string $name)
    {
        if (!in_array($name, $this->_valid_strategies, true)) {
            throw new InvalidArgumentException(sprintf('Invalid strategy `%s` was provided. Valid options are `(%s)`.', $name, implode(', ', $this->_valid_strategies)));
        }
        $this->_strategy = $name;
        return $this;
    }
    /**
     * Gets the strategy name to be used to fetch associated records. Keep in mind
     * that some association types might not implement but a default strategy,
     * rendering any changes to this setting void.
     */
    public function get_strategy(): string
    {
        return $this->_strategy;
    }
    /**
     * Gets the default finder to use for fetching rows from the target table.
     */
    public function get_finder(): array|string
    {
        return $this->_finder;
    }
    /**
     * Sets the default finder to use for fetching rows from the target table.
     *
     * @param array|string $finder the finder name to use or array of finder name and option.
     * @return $this
     */
    public function set_finder(array|string $finder)
    {
        $this->_finder = $finder;
        return $this;
    }
    /**
     * Override this function to initialize any concrete association class, it will
     * get passed the original list of options used in the constructor
     *
     * @param array<string, mixed> $options List of options used for initialization
     */
    protected function _options(array $options): void
    {
    }
    /**
     * Alters a Query object to include the associated target table data in the final
     * result
     *
     * The options array accepts the following keys:
     *
     * - includeFields: Whether to include target model fields in the result or not
     * - foreignKey: The name of the field to use as foreign key, if false none
     *   will be used
     * - conditions: array with a list of conditions to filter the join with, this
     *   will be merged with any conditions originally configured for this association
     * - fields: a list of fields in the target table to include in the result
     * - aliasPath: A dot separated string representing the path of association names
     *   followed from the passed query main table to this association.
     * - propertyPath: A dot separated string representing the path of association
     *   properties to be followed from the passed query main entity to this
     *   association
     * - joinType: The SQL join type to use in the query.
     * - negateMatch: Will append a condition to the passed query for excluding matches.
     *   with this association.
     *
     * @param \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $query the query to be altered to include the target table data
     * @param array<string, mixed> $options Any extra options or overrides to be taken into account
     * @throws \RuntimeException Unable to build the query or associations.
     */
    public function attach_to(Select_Query $query, array $options = []): void
    {
        $target = $this->get_target();
        $table = $target->get_table();
        $options += ['includeFields' => true, 'foreignKey' => $this->get_foreign_key(), 'conditions' => [], 'joinType' => $this->get_join_type(), 'fields' => [], 'table' => $table, 'finder' => $this->get_finder()];
        // This is set by joinWith to disable matching results
        if ($options['fields'] === false) {
            $options['fields'] = [];
            $options['includeFields'] = false;
        }
        if ($options['foreignKey']) {
            $join_condition = $this->_join_condition($options);
            if ($join_condition) {
                $options['conditions'][] = $join_condition;
            }
        }
        [$finder, $opts] = $this->_extract_finder($options['finder']);
        $dummy = $this->find($finder, ...$opts)->eager_loaded(true);
        if (!empty($options['queryBuilder'])) {
            assert(is_callable($options['queryBuilder']));
            $dummy = $options['queryBuilder']($dummy);
            if (!$dummy instanceof Select_Query) {
                throw new Database_Exception(sprintf('Query builder for association `%s` did not return a query.', $this->get_name()));
            }
        }
        if (!empty($options['matching']) && $this->_strategy === static::STRATEGY_JOIN && $dummy->get_contain()) {
            throw new Database_Exception(sprintf('`%s` association cannot contain() associations when using JOIN strategy.', $this->get_name()));
        }
        $dummy->where($options['conditions']);
        $this->_dispatch_before_find($dummy);
        $query->join([$this->_name => ['table' => $options['table'], 'conditions' => $dummy->clause('where'), 'type' => $options['joinType']]]);
        $this->_append_fields($query, $dummy, $options);
        $this->_format_association_results($query, $dummy, $options);
        $this->_bind_new_associations($query, $dummy, $options);
        $this->_append_not_matching($query, $options);
    }
    /**
     * Conditionally adds a condition to the passed Query that will make it find
     * records where there is no match with this association.
     *
     * @param \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $query The query to modify
     * @param array<string, mixed> $options Options array containing the `negateMatch` key.
     */
    protected function _append_not_matching(Select_Query $query, array $options): void
    {
        $target = $this->get_target();
        if (!empty($options['negateMatch'])) {
            $primary_key = $query->alias_fields((array) $target->get_primary_key(), $this->_name);
            $query->and_where(function ($exp) use ($primary_key) {
                /** @var callable $callable */
                $callable = [$exp, 'isNull'];
                array_map($callable, $primary_key);
                return $exp;
            });
        }
    }
    /**
     * Correctly nests a result row associated values into the correct array keys inside the
     * source results.
     *
     * @param array<string, mixed> $row The row to transform
     * @param string $nestKey The array key under which the results for this association
     *   should be found
     * @param bool $joined Whether the row is a result of a direct join
     *   with this association
     * @param string|null $targetProperty The property name in the source results where the association
     * data should be nested in. Will use the default one if not provided.
     */
    public function transform_row(array $row, string $nest_key, bool $joined, ?string $target_property = null): array
    {
        $source_alias = $this->get_source()->get_alias();
        $nest_key = $nest_key ?: $this->_name;
        $target_property = $target_property ?: $this->get_property();
        if (isset($row[$source_alias])) {
            $row[$source_alias][$target_property] = $row[$nest_key];
            unset($row[$nest_key]);
        }
        return $row;
    }
    /**
     * Returns a modified row after appending a property for this association
     * with the default empty value according to whether the association was
     * joined or fetched externally.
     *
     * @param array<string, mixed> $row The row to set a default on.
     * @param bool $joined Whether the row is a result of a direct join
     *   with this association
     * @return array<string, mixed>
     */
    public function default_row_value(array $row, bool $joined): array
    {
        $source_alias = $this->get_source()->get_alias();
        if (isset($row[$source_alias])) {
            $row[$source_alias][$this->get_property()] = null;
        }
        return $row;
    }
    /**
     * Proxies the finding operation to the target table's find method
     * and modifies the query accordingly based of this association
     * configuration
     *
     * @param array<string, mixed>|string|null $type the type of query to perform if an array is passed,
     *   it will be interpreted as the `$args` parameter
     * @param mixed ...$args Arguments that match up to finder-specific parameters
     * @see \Cake\ORM\Table::find()
     * @return \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array>
     */
    public function find(array|string|null $type = null, mixed ...$args): Select_Query
    {
        $type = $type ?: $this->get_finder();
        [$type, $opts] = $this->_extract_finder($type);
        $args += $opts;
        return $this->get_target()->find($type, ...$args)->where($this->get_conditions());
    }
    /**
     * Proxies the operation to the target table's exists method after
     * appending the default conditions for this association
     *
     * @param \Cake\Database\ExpressionInterface|\Closure|array|string|null $conditions The conditions to use
     * for checking if any record matches.
     * @see \Cake\ORM\Table::exists()
     */
    public function exists(Expression_Interface|Closure|array|string|null $conditions): bool
    {
        $conditions = $this->find()->where($conditions)->clause('where');
        return $this->get_target()->exists($conditions);
    }
    /**
     * Proxies the update operation to the target `Table::updateAll()` method
     *
     * @param \Cake\Database\Expression\QueryExpression|\Closure|array|string $fields A hash of field => new value.
     * @param \Cake\Database\Expression\QueryExpression|\Closure|array|string|null $conditions Conditions to be used, accepts anything Query::where()
     * @return int Count Returns the affected rows.
     * @see \Cake\ORM\Table::updateAll()
     */
    public function update_all(Query_Expression|Closure|array|string $fields, Query_Expression|Closure|array|string|null $conditions): int
    {
        $expression = $this->find()->where($conditions)->clause('where');
        return $this->get_target()->update_all($fields, $expression);
    }
    /**
     * Proxies the delete operation to the target `Table::deleteAll()` method
     *
     * @param \Cake\Database\Expression\QueryExpression|\Closure|array|string|null $conditions Conditions to be used, accepts anything Query::where()
     * can take.
     * @return int Returns the number of affected rows.
     * @see \Cake\ORM\Table::deleteAll()
     */
    public function delete_all(Query_Expression|Closure|array|string|null $conditions): int
    {
        $expression = $this->find()->where($conditions)->clause('where');
        return $this->get_target()->delete_all($expression);
    }
    /**
     * Returns true if the eager loading process will require a set of the owning table's
     * binding keys in order to use them as a filter in the finder query.
     *
     * @param array<string, mixed> $options The options containing the strategy to be used.
     * @return bool true if a list of keys will be required
     */
    public function requires_keys(array $options = []): bool
    {
        $strategy = $options['strategy'] ?? $this->get_strategy();
        return $strategy === static::STRATEGY_SELECT;
    }
    /**
     * Triggers `beforeFind` on the target table for the query this association is
     * attaching to
     *
     * @param \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $query the query this association is attaching itself to
     */
    protected function _dispatch_before_find(Select_Query $query): void
    {
        $query->trigger_before_find();
    }
    /**
     * Helper function used to conditionally append fields to the select clause of
     * a query from the fields found in another query object.
     *
     * @param \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $query the query that will get the fields appended to
     * @param \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $surrogate the query having the fields to be copied from
     * @param array<string, mixed> $options options passed to the method `attachTo`
     */
    protected function _append_fields(Select_Query $query, Select_Query $surrogate, array $options): void
    {
        if ($query->get_eager_loader()->is_auto_fields_enabled() === false) {
            return;
        }
        $fields = array_merge($surrogate->clause('select'), $options['fields']);
        if ($fields === [] && $options['includeFields'] || $surrogate->is_auto_fields_enabled()) {
            $fields = array_merge($fields, $this->get_target()->get_schema()->columns());
        } elseif ($fields !== []) {
            // Ensure primary key fields are always included when specific fields are selected
            // This prevents issues with entity hydration when only nullable columns are selected
            $primary_key = $this->get_target()->get_primary_key();
            $primary_key_fields = is_array($primary_key) ? $primary_key : [$primary_key];
            $fields_to_add = [];
            foreach ($primary_key_fields as $pk_field) {
                $found = false;
                foreach ($fields as $field) {
                    if (is_string($field) && ($field === $pk_field || str_ends_with($field, '.' . $pk_field))) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $fields_to_add[] = $pk_field;
                }
            }
            if ($fields_to_add) {
                $fields = array_merge($fields, $fields_to_add);
            }
        }
        $query->select($query->alias_fields($fields, $this->_name));
        $query->add_default_types($this->get_target());
    }
    /**
     * Adds a formatter function to the passed `$query` if the `$surrogate` query
     * declares any other formatter. Since the `$surrogate` query corresponds to
     * the associated target table, the resulting formatter will be the result of
     * applying the surrogate formatters to only the property corresponding to
     * such a table.
     *
     * @param \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $query the query that will get the formatter applied to
     * @param \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $surrogate the query having formatters for the associated
     * target table.
     * @param array<string, mixed> $options options passed to the method `attachTo`
     */
    protected function _format_association_results(Select_Query $query, Select_Query $surrogate, array $options): void
    {
        $formatters = $surrogate->get_result_formatters();
        if (!$formatters || empty($options['propertyPath'])) {
            return;
        }
        $property = $options['propertyPath'];
        $property_path = explode('.', (string) $property);
        $query->format_results(function (Collection_Interface $results, Select_Query $query) use ($formatters, $property, $property_path): \Cake\Collection\Collection_Interface {
            $extracted = [];
            foreach ($results as $result) {
                foreach ($property_path as $property_path_item) {
                    if (!isset($result[$property_path_item])) {
                        $result = null;
                        break;
                    }
                    $result = $result[$property_path_item];
                }
                $extracted[] = $result;
            }
            $extracted = $query->result_set_factory()->create_result_set($extracted);
            $result_set_class = $query->result_set_factory()->get_result_set_class();
            foreach ($formatters as $callable) {
                $extracted = $callable($extracted, $query);
                if (!$extracted instanceof Result_Set_Interface) {
                    $extracted = new $result_set_class($extracted);
                }
            }
            $results = $results->insert($property, $extracted);
            if ($query->is_hydration_enabled()) {
                return $results->map(function (Entity_Interface $result): \Cake\Datasource\Entity_Interface {
                    $result->clean();
                    return $result;
                });
            }
            return $results;
        }, Select_Query::PREPEND);
    }
    /**
     * Applies all attachable associations to `$query` out of the containments found
     * in the `$surrogate` query.
     *
     * Copies all contained associations from the `$surrogate` query into the
     * passed `$query`. Containments are altered so that they respect the association
     * chain from which they originated.
     *
     * @param \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $query the query that will get the associations attached to
     * @param \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $surrogate the query having the containments to be attached
     * @param array<string, mixed> $options options passed to the method `attachTo`
     */
    protected function _bind_new_associations(Select_Query $query, Select_Query $surrogate, array $options): void
    {
        $loader = $surrogate->get_eager_loader();
        $contain = $loader->get_contain();
        $matching = $loader->get_matching();
        if (!$contain && !$matching) {
            return;
        }
        $new_contain = [];
        foreach ($contain as $alias => $value) {
            $new_contain[$options['aliasPath'] . '.' . $alias] = $value;
        }
        $eager_loader = $query->get_eager_loader();
        if ($new_contain) {
            $eager_loader->contain($new_contain);
        }
        foreach ($matching as $alias => $value) {
            $eager_loader->set_matching($options['aliasPath'] . '.' . $alias, $value['queryBuilder'], $value);
        }
    }
    /**
     * Returns a single or multiple conditions to be appended to the generated join
     * clause for getting the results on the target table.
     *
     * @param array<string, mixed> $options list of options passed to attachTo method
     * @throws \Cake\Database\Exception\DatabaseException if the number of columns in the foreignKey do not
     * match the number of columns in the source table primaryKey
     */
    protected function _join_condition(array $options): array
    {
        $conditions = [];
        $t_alias = $this->_name;
        $s_alias = $this->get_source()->get_alias();
        $foreign_key = (array) $options['foreignKey'];
        $binding_key = (array) $this->get_binding_key();
        $target_owns = $this->is_owning_side($this->get_target());
        if (count($foreign_key) !== count($binding_key)) {
            if (!$binding_key) {
                $table = $target_owns ? $this->get_target()->get_table() : $this->get_source()->get_table();
                $msg = 'The `%s` table does not define a primary key, and cannot have join conditions generated.';
                throw new Database_Exception(sprintf($msg, $table));
            }
            $msg = 'Cannot match provided foreignKey for `%s`, got `(%s)` but expected foreign key for `(%s)`';
            throw new Database_Exception(sprintf($msg, $this->_name, implode(', ', $foreign_key), implode(', ', $binding_key)));
        }
        foreach ($foreign_key as $k => $f) {
            // Set foreign and binding aliases based on which side has the foreign key
            $f_alias = $target_owns ? $s_alias : $t_alias;
            $b_alias = $target_owns ? $t_alias : $s_alias;
            $field = sprintf('%s.%s', $b_alias, $binding_key[$k]);
            $value = new Identifier_Expression(sprintf('%s.%s', $f_alias, $f));
            $conditions[$field] = $value;
        }
        return $conditions;
    }
    /**
     * Helper method to infer the requested finder and its options.
     *
     * Returns the inferred options from the finder $type.
     *
     * ### Examples:
     *
     * The following will call the finder 'translations' with the value of the finder as its options:
     * $query->contain(['Comments' => ['finder' => ['translations']]]);
     * $query->contain(['Comments' => ['finder' => ['translations' => []]]]);
     * $query->contain(['Comments' => ['finder' => ['translations' => ['locales' => ['en_US']]]]]);
     *
     * @param array|string $finderData The finder name or an array having the name as key
     * and options as value.
     */
    protected function _extract_finder(array|string $finder_data): array
    {
        $finder_data = (array) $finder_data;
        if (is_numeric(key($finder_data))) {
            return [current($finder_data), []];
        }
        return [key($finder_data), current($finder_data)];
    }
    /**
     * Proxies property retrieval to the target table. This is handy for getting this
     * association's associations
     *
     * @param string $property the property name
     * @throws \RuntimeException if no association with such a name exists
     */
    public function __get(string $property): Association
    {
        return $this->get_target()->{$property};
    }
    /**
     * Proxies the isset call to the target table. This is handy to check if the
     * target table has another association with the passed name
     *
     * @param string $property the property name
     * @return bool true if the association exists
     */
    public function __isset(string $property): bool
    {
        return $this->get_target()->has_association($property);
    }
    /**
     * Proxies method calls to the target table.
     *
     * @param string $method name of the method to be invoked
     * @param array $argument List of arguments passed to the function
     * @throws \BadMethodCallException
     */
    public function __call(string $method, array $argument): mixed
    {
        return $this->get_target()->{$method}(...$argument);
    }
    /**
     * Get the relationship type.
     *
     * @return string Constant of either ONE_TO_ONE, MANY_TO_ONE, ONE_TO_MANY or MANY_TO_MANY.
     */
    abstract public function type(): string;
    /**
     * Eager loads a list of records in the target table that are related to another
     * set of records in the source table. Source records can be specified in two ways:
     * first one is by passing a Query object setup to find on the source table and
     * the other way is by explicitly passing an array of primary key values from
     * the source table.
     *
     * The required way of passing related source records is controlled by "strategy"
     * When the subquery strategy is used it will require a query on the source table.
     * When using the select strategy, the list of primary keys will be used.
     *
     * Returns a closure that should be run for each record returned in a specific
     * Query. This callable will be responsible for injecting the fields that are
     * related to each specific passed row.
     *
     * Options array accepts the following keys:
     *
     * - query: SelectQuery object setup to find the source table records
     * - keys: List of primary key values from the source table
     * - foreignKey: The name of the field used to relate both tables
     * - conditions: List of conditions to be passed to the query where() method
     * - sort: The direction in which the records should be returned
     * - fields: List of fields to select from the target table
     * - contain: List of related tables to eager load associated to the target table
     * - strategy: The name of strategy to use for finding target table records
     * - nestKey: The array key under which results will be found when transforming the row
     *
     * @param array<string, mixed> $options The options for eager loading.
     */
    abstract public function eager_loader(array $options): Closure;
    /**
     * Handles cascading a delete from an associated model.
     *
     * Each implementing class should handle the cascaded delete as
     * required.
     *
     * @param \Cake\Datasource\EntityInterface $entity The entity that started the cascaded delete.
     * @param array<string, mixed> $options The options for the original delete.
     * @return bool Success
     */
    abstract public function cascade_delete(Entity_Interface $entity, array $options = []): bool;
    /**
     * Returns whether the passed table is the owning side for this
     * association. This means that rows in the 'target' table would miss important
     * or required information if the row in 'source' did not exist.
     *
     * @param \Cake\ORM\Table $side The potential Table with ownership
     */
    abstract public function is_owning_side(Table $side): bool;
    /**
     * Extract the target's association data our from the passed entity and proxies
     * the saving operation to the target table.
     *
     * @param \Cake\Datasource\EntityInterface $entity the data to be saved
     * @param array<string, mixed> $options The options for saving associated data.
     * @return \Cake\Datasource\EntityInterface|false false if $entity could not be saved, otherwise it returns
     * the saved entity
     * @see \Cake\ORM\Table::save()
     */
    abstract public function save_associated(Entity_Interface $entity, array $options = []): Entity_Interface|false;
}