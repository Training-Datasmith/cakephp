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
 * @since         3.4.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\ORM\Association\Loader;

use Cake\Database\Exception\Database_Exception;
use Cake\Database\Expression\Identifier_Expression;
use Cake\Database\Expression\Tuple_Comparison;
use Cake\Database\Expression_Interface;
use Cake\Database\Value_Binder;
use Cake\ORM\Association;
use Cake\ORM\Query\Select_Query;
use Closure;
/**
 * Implements the logic for loading an association using a SELECT query
 *
 * @internal
 */
class Select_Loader
{
    /**
     * The alias of the association loading the results
     */
    protected string $alias;
    /**
     * The alias of the source association
     */
    protected string $source_alias;
    /**
     * The alias of the target association
     */
    protected string $target_alias;
    /**
     * The foreignKey to the target association
     */
    protected array|string $foreign_key;
    /**
     * The strategy to use for loading, either select or subquery
     */
    protected string $strategy;
    /**
     * The binding key for the source association.
     */
    protected array|string $binding_key;
    /**
     * A callable that will return a query object used for loading the association results
     *
     * @var callable
     */
    protected $finder;
    /**
     * The type of the association triggering the load
     */
    protected string $association_type;
    /**
     * The sorting options for loading the association
     */
    protected Expression_Interface|Closure|array|string|null $sort = null;
    /**
     * Copies the options array to properties in this class. The keys in the array correspond
     * to properties in this class.
     *
     * @param array<string, mixed> $options Properties to be copied to this class
     */
    public function __construct(array $options)
    {
        $this->alias = $options['alias'];
        $this->source_alias = $options['sourceAlias'];
        $this->target_alias = $options['targetAlias'];
        $this->foreign_key = $options['foreignKey'];
        $this->strategy = $options['strategy'];
        $this->binding_key = $options['bindingKey'];
        $this->finder = $options['finder'];
        $this->association_type = $options['associationType'];
        $this->sort = $options['sort'] ?? null;
    }
    /**
     * Returns a callable that can be used for injecting association results into a given
     * iterator. The options accepted by this method are the same as `Association::eagerLoader()`
     *
     * @param array<string, mixed> $options Same options as `Association::eagerLoader()`
     */
    public function build_eager_loader(array $options): Closure
    {
        $options += $this->_default_options();
        $fetch_query = $this->_build_query($options);
        $result_map = $this->_build_result_map($fetch_query, $options);
        return $this->_result_injector($fetch_query, $result_map, $options);
    }
    /**
     * Returns the default options to use for the eagerLoader
     *
     * @return array<string, mixed>
     */
    protected function _default_options(): array
    {
        return ['foreignKey' => $this->foreign_key, 'conditions' => [], 'strategy' => $this->strategy, 'nestKey' => $this->alias, 'sort' => $this->sort];
    }
    /**
     * Auxiliary function to construct a new Query object to return all the records
     * in the target table that are associated to those specified in $options from
     * the source table
     *
     * @param array<string, mixed> $options options accepted by eagerLoader()
     * @return \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array>
     * @throws \InvalidArgumentException When a key is required for associations but not selected.
     */
    protected function _build_query(array $options): Select_Query
    {
        $key = $this->_link_field($options);
        $filter = $options['keys'];
        $use_subquery = $options['strategy'] === Association::STRATEGY_SUBQUERY;
        $finder = $this->finder;
        $options['fields'] ??= [];
        $query = $finder();
        assert($query instanceof Select_Query);
        if (isset($options['finder'])) {
            [$finder_name, $opts] = $this->_extract_finder($options['finder']);
            $query = $query->find($finder_name, ...$opts);
        }
        /** @var \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $selectQuery */
        $select_query = $options['query'];
        // Disable hydration for external queries when parent has DTO projection
        // The DTO's setFromArray() expects arrays, not entities
        $should_hydrate = $select_query->is_hydration_enabled() && !$select_query->is_dto_projection_enabled();
        $fetch_query = $query->select($options['fields'])->where($options['conditions'])->eager_loaded(true)->enable_hydration($should_hydrate)->set_connection_role($select_query->get_connection_role());
        if ($select_query->is_results_casting_enabled()) {
            $fetch_query->enable_results_casting();
        } else {
            $fetch_query->disable_results_casting();
        }
        if ($use_subquery) {
            $filter = $this->_build_subquery($select_query);
            $fetch_query = $this->_add_filtering_join($fetch_query, $key, $filter);
        } else {
            $fetch_query = $this->_add_filtering_condition($fetch_query, $key, $filter);
        }
        if (!empty($options['sort'])) {
            $fetch_query->order_by($options['sort']);
        }
        if (!empty($options['contain'])) {
            $fetch_query->contain($options['contain']);
        }
        if (!empty($options['queryBuilder'])) {
            assert(is_callable($options['queryBuilder']));
            /** @var \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $fetchQuery */
            $fetch_query = $options['queryBuilder']($fetch_query);
        }
        $this->_assert_fields_present($fetch_query, (array) $key);
        return $fetch_query;
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
     * Checks that the fetching query either has auto fields on or
     * has the foreignKey fields selected.
     * If the required fields are missing, automatically adds them to ensure
     * entities can be properly identified and loaded.
     *
     * @param \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $fetchQuery The association fetching query
     * @param array<string> $key The foreign key fields to check
     * @throws \InvalidArgumentException
     */
    protected function _assert_fields_present(Select_Query $fetch_query, array $key): void
    {
        if ($fetch_query->is_auto_fields_enabled()) {
            return;
        }
        $select = $fetch_query->alias_fields($fetch_query->clause('select'));
        if (!$select) {
            return;
        }
        $missing_fields = [];
        foreach ($key as $key_field) {
            if (!in_array($key_field, $select, true)) {
                $driver = $fetch_query->get_driver();
                $quoted = $driver->quote_identifier($key_field);
                if (!in_array($quoted, $select, true)) {
                    $missing_fields[] = $key_field;
                }
            }
        }
        // Automatically add missing primary key fields to the query
        if ($missing_fields) {
            $fetch_query->select($missing_fields);
        }
    }
    /**
     * Appends any conditions required to load the relevant set of records in the
     * target table query given a filter key and some filtering values when the
     * filtering needs to be done using a subquery.
     *
     * @param \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $query Target table's query
     * @param array<string>|string $key the fields that should be used for filtering
     * @param \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $subquery The Subquery to use for filtering
     * @return \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array>
     */
    protected function _add_filtering_join(Select_Query $query, array|string $key, Select_Query $subquery): Select_Query
    {
        $filter = [];
        $aliased_table = $this->source_alias;
        foreach ($subquery->clause('select') as $aliased_field => $field) {
            if (is_int($aliased_field)) {
                $filter[] = new Identifier_Expression($field);
            } else {
                $filter[$aliased_field] = $field;
            }
        }
        $subquery->select($filter, true);
        if (is_array($key)) {
            $conditions = $this->_create_tuple_condition($query, $key, $filter, '=');
        } else {
            $filter = current($filter);
            $conditions = $query->expr([$key => $filter]);
        }
        return $query->inner_join([$aliased_table => $subquery], $conditions);
    }
    /**
     * Appends any conditions required to load the relevant set of records in the
     * target table query given a filter key and some filtering values.
     *
     * @param \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $query Target table's query
     * @param array<string>|string $key The fields that should be used for filtering
     * @param mixed $filter The value that should be used to match for $key
     * @return \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array>
     */
    protected function _add_filtering_condition(Select_Query $query, array|string $key, mixed $filter): Select_Query
    {
        if (is_array($key)) {
            $conditions = $this->_create_tuple_condition($query, $key, $filter, 'IN');
        } else {
            $conditions = [$key . ' IN' => $filter];
        }
        return $query->and_where($conditions);
    }
    /**
     * Returns a TupleComparison object that can be used for matching all the fields
     * from $keys with the tuple values in $filter using the provided operator.
     *
     * @param \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $query Target table's query
     * @param array<string> $keys the fields that should be used for filtering
     * @param mixed $filter the value that should be used to match for $key
     * @param string $operator The operator for comparing the tuples
     */
    protected function _create_tuple_condition(Select_Query $query, array $keys, mixed $filter, string $operator): Tuple_Comparison
    {
        $types = [];
        $defaults = $query->get_default_types();
        foreach ($keys as $k) {
            if (isset($defaults[$k])) {
                $types[] = $defaults[$k];
            }
        }
        return new Tuple_Comparison($keys, $filter, $types, $operator);
    }
    /**
     * Generates a string used as a table field that contains the values upon
     * which the filter should be applied
     *
     * @param array<string, mixed> $options The options for getting the link field.
     * @return array<string>|string
     * @throws \Cake\Database\Exception\DatabaseException
     */
    protected function _link_field(array $options): array|string
    {
        $links = [];
        $name = $this->alias;
        if ($options['foreignKey'] === false && $this->association_type === Association::ONE_TO_MANY) {
            $msg = 'Cannot have foreignKey = false for hasMany associations. ' . 'You must provide a foreignKey column.';
            throw new Database_Exception($msg);
        }
        $keys = in_array($this->association_type, [Association::ONE_TO_ONE, Association::ONE_TO_MANY], true) ? $this->foreign_key : $this->binding_key;
        foreach ((array) $keys as $key) {
            $links[] = sprintf('%s.%s', $name, $key);
        }
        if (count($links) === 1) {
            return $links[0];
        }
        return $links;
    }
    /**
     * Builds a query to be used as a condition for filtering records in the
     * target table, it is constructed by cloning the original query that was used
     * to load records in the source table.
     *
     * @param \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $query the original query used to load source records
     * @return \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array>
     */
    protected function _build_subquery(Select_Query $query): Select_Query
    {
        $filter_query = clone $query;
        $filter_query->disable_auto_fields();
        $filter_query->map_reduce(null, null, true);
        $filter_query->format_results(null, true);
        $filter_query->contain([], true);
        $filter_query->set_value_binder(new Value_Binder());
        // Only remove limit and order when BOTH are missing or when order exists without limit
        // When limit exists with order, preserve both for proper subquery results
        $has_limit = $filter_query->clause('limit') !== null;
        $has_order = $filter_query->clause('order') !== null;
        // Remove order if there's no limit to avoid SQL grouping errors
        // But preserve both when they exist together
        if (!$has_limit) {
            $filter_query->limit(null);
            $filter_query->offset(null);
            if ($has_order) {
                $filter_query->order_by([], true);
            }
        }
        $fields = $this->_subquery_fields($query);
        $filter_query->select($fields['select'], true)->group_by($fields['group']);
        return $filter_query;
    }
    /**
     * Calculate the fields that need to participate in a subquery.
     *
     * Normally this includes the binding key columns. If there is a an ORDER BY,
     * those columns are also included as the fields may be calculated or constant values,
     * that need to be present to ensure the correct association data is loaded.
     *
     * @param \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $query The query to get fields from.
     * @return array<string, array> The list of fields for the subquery.
     */
    protected function _subquery_fields(Select_Query $query): array
    {
        $keys = (array) $this->binding_key;
        if ($this->association_type === Association::MANY_TO_ONE) {
            $keys = (array) $this->foreign_key;
        }
        $fields = $query->alias_fields($keys, $this->source_alias);
        $group = array_values($fields);
        $fields = $group;
        /** @var \Cake\Database\Expression\QueryExpression $order */
        $order = $query->clause('order');
        if ($order) {
            $columns = $query->clause('select');
            $order->iterate_parts(function ($direction, $field) use (&$fields, $columns): void {
                if (isset($columns[$field])) {
                    $fields[$field] = $columns[$field];
                }
            });
        }
        return ['select' => $fields, 'group' => $group];
    }
    /**
     * Builds an array containing the results from fetchQuery indexed by
     * the foreignKey value corresponding to this association.
     *
     * @param \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $fetchQuery The query to get results from
     * @param array<string, mixed> $options The options passed to the eager loader
     * @return array<string, mixed>
     */
    protected function _build_result_map(Select_Query $fetch_query, array $options): array
    {
        $result_map = [];
        $single_result = in_array($this->association_type, [Association::MANY_TO_ONE, Association::ONE_TO_ONE], true);
        $keys = in_array($this->association_type, [Association::ONE_TO_ONE, Association::ONE_TO_MANY], true) ? $this->foreign_key : $this->binding_key;
        $key = (array) $keys;
        $preserve_keys = $fetch_query->get_options()['preserveKeys'] ?? false;
        foreach ($fetch_query->all() as $i => $result) {
            $values = [];
            foreach ($key as $k) {
                $values[] = $result[$k];
            }
            if ($single_result) {
                $result_map[implode(';', $values)] = $result;
                continue;
            }
            if ($preserve_keys) {
                $result_map[implode(';', $values)][$i] = $result;
                continue;
            }
            $result_map[implode(';', $values)][] = $result;
        }
        return $result_map;
    }
    /**
     * Returns a callable to be used for each row in a query result set
     * for injecting the eager loaded rows
     *
     * @param \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $fetchQuery the Query used to fetch results
     * @param array<string, mixed> $resultMap an array with the foreignKey as keys and
     * the corresponding target table results as value.
     * @param array<string, mixed> $options The options passed to the eagerLoader method
     */
    protected function _result_injector(Select_Query $fetch_query, array $result_map, array $options): Closure
    {
        $keys = $this->association_type === Association::MANY_TO_ONE ? $this->foreign_key : $this->binding_key;
        $source_keys = [];
        foreach ((array) $keys as $key) {
            $f = $fetch_query->alias_field($key, $this->source_alias);
            $source_keys[] = (string) key($f);
        }
        $nest_key = $options['nestKey'];
        if (count($source_keys) > 1) {
            return $this->_multi_keys_injector($result_map, $source_keys, $nest_key);
        }
        $source_key = $source_keys[0];
        return function (array $row) use ($result_map, $source_key, $nest_key): array {
            if (isset($row[$source_key], $result_map[$row[$source_key]])) {
                $row[$nest_key] = $result_map[$row[$source_key]];
            }
            return $row;
        };
    }
    /**
     * Returns a callable to be used for each row in a query result set
     * for injecting the eager loaded rows when the matching needs to
     * be done with multiple foreign keys
     *
     * @param array<string, mixed> $resultMap A keyed arrays containing the target table
     * @param array<string> $sourceKeys An array with aliased keys to match
     * @param string $nestKey The key under which results should be nested
     */
    protected function _multi_keys_injector(array $result_map, array $source_keys, string $nest_key): Closure
    {
        return function (array $row) use ($result_map, $source_keys, $nest_key): array {
            $values = [];
            foreach ($source_keys as $key) {
                $values[] = $row[$key];
            }
            $key = implode(';', $values);
            if (isset($result_map[$key])) {
                $row[$nest_key] = $result_map[$key];
            }
            return $row;
        };
    }
}