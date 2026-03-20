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
use Cake\Database\Expression_Interface;
use Cake\ORM\Association\Has_Many;
use Cake\ORM\Query\Select_Query;
use Closure;
/**
 * Implements the logic for loading an association using a SELECT query and a pivot table
 *
 * @internal
 */
class Select_With_Pivot_Loader extends Select_Loader
{
    /**
     * The name of the junction association
     */
    protected string $junction_association_name;
    /**
     * The property name for the junction association, where its results should be nested at.
     */
    protected string $junction_property;
    /**
     * The junction association instance
     *
     * @var \Cake\ORM\Association\HasMany<\Cake\ORM\Table>
     */
    protected Has_Many $junction_assoc;
    /**
     * Custom conditions for the junction association
     */
    protected Expression_Interface|Closure|array|string|null $junction_conditions = null;
    /**
     * @inheritDoc
     */
    public function __construct(array $options)
    {
        parent::__construct($options);
        $this->junction_association_name = $options['junctionAssociationName'];
        $this->junction_property = $options['junctionProperty'];
        $this->junction_assoc = $options['junctionAssoc'];
        $this->junction_conditions = $options['junctionConditions'];
    }
    /**
     * Auxiliary function to construct a new Query object to return all the records
     * in the target table that are associated to those specified in $options from
     * the source table.
     *
     * This is used for eager loading records on the target table based on conditions.
     *
     * @param array<string, mixed> $options options accepted by eagerLoader()
     * @return \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array>
     * @throws \InvalidArgumentException When a key is required for associations but not selected.
     */
    protected function _build_query(array $options): Select_Query
    {
        $name = $this->junction_association_name;
        $assoc = $this->junction_assoc;
        $query_builder = false;
        if (!empty($options['queryBuilder'])) {
            assert(is_callable($options['queryBuilder']));
            $query_builder = $options['queryBuilder'];
            unset($options['queryBuilder']);
        }
        $query = parent::_build_query($options);
        if ($query_builder) {
            /** @var \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $query */
            $query = $query_builder($query);
        }
        if ($query->is_auto_fields_enabled() === null) {
            $query->enable_auto_fields($query->clause('select') === []);
        }
        // Ensure that association conditions are applied
        // and that the required keys are in the selected columns.
        $temp_name = $this->alias . '_CJoin';
        $schema = $assoc->get_schema();
        $join_fields = [];
        $types = [];
        foreach ($schema->type_map() as $f => $type) {
            $key = $temp_name . '__' . $f;
            $join_fields[$key] = "{$name}.{$f}";
            $types[$key] = $type;
        }
        $query->where($this->junction_conditions)->select($join_fields);
        $query->get_eager_loader()->add_to_joins_map($temp_name, $assoc, false, $this->junction_property);
        $assoc->attach_to($query, ['aliasPath' => $assoc->get_alias(), 'includeFields' => false, 'propertyPath' => $this->junction_property]);
        $query->get_type_map()->add_defaults($types);
        return $query;
    }
    /**
     * @param \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $fetchQuery The association fetching query
     * @param array<string> $key The foreign key fields to check
     */
    protected function _assert_fields_present(Select_Query $fetch_query, array $key): void
    {
        // _buildQuery() manually adds in required fields from junction table
    }
    /**
     * Generates a string used as a table field that contains the values upon
     * which the filter should be applied
     *
     * @param array<string, mixed> $options the options to use for getting the link field.
     * @return array<string>|string
     */
    protected function _link_field(array $options): array|string
    {
        $links = [];
        $name = $this->junction_association_name;
        foreach ((array) $options['foreignKey'] as $key) {
            $links[] = sprintf('%s.%s', $name, $key);
        }
        if (count($links) === 1) {
            return array_pop($links);
        }
        return $links;
    }
    /**
     * Builds an array containing the results from fetchQuery indexed by
     * the foreignKey value corresponding to this association.
     *
     * @param \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $fetchQuery The query to get results from
     * @param array<string, mixed> $options The options passed to the eager loader
     * @return array<string, mixed>
     * @throws \Cake\Database\Exception\DatabaseException when the association property is not part of the results set.
     */
    protected function _build_result_map(Select_Query $fetch_query, array $options): array
    {
        $result_map = [];
        $key = (array) $options['foreignKey'];
        $preserve_keys = $fetch_query->get_options()['preserveKeys'] ?? false;
        foreach ($fetch_query->all() as $i => $result) {
            if (!isset($result[$this->junction_property])) {
                throw new Database_Exception(sprintf('`%s` is missing from the belongsToMany results. Results cannot be created.', $this->junction_property));
            }
            $values = [];
            foreach ($key as $k) {
                $values[] = $result[$this->junction_property][$k];
            }
            if ($preserve_keys) {
                $result_map[implode(';', $values)][$i] = $result;
                continue;
            }
            $result_map[implode(';', $values)][] = $result;
        }
        return $result_map;
    }
}