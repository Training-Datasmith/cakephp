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
namespace Cake\ORM\Association;

use Cake\Core\App;
use Cake\Database\Expression\Identifier_Expression;
use Cake\Database\Expression\Query_Expression;
use Cake\Database\Expression_Interface;
use Cake\Datasource\Entity_Interface;
use Cake\ORM\Association;
use Cake\ORM\Association\Loader\Select_With_Pivot_Loader;
use Cake\ORM\Query\Select_Query;
use Cake\ORM\Table;
use Cake\Utility\Hash;
use Cake\Utility\Inflector;
use Closure;
use InvalidArgumentException;
use Spl_Object_Storage;
/**
 * Represents an M - N relationship where there exists a junction - or join - table
 * that contains the association fields between the source and the target table.
 *
 * An example of a BelongsToMany association would be Article belongs to many Tags.
 * In this example 'Article' is the source table and 'Tags' is the target table.
 *
 * @template T of \Cake\ORM\Table
 * @mixin T
 */
class Belongs_To_Many extends Association
{
    /**
     * Saving strategy that will only append to the links set
     *
     * @var string
     */
    public const SAVE_APPEND = 'append';
    /**
     * Saving strategy that will replace the links with the provided set
     *
     * @var string
     */
    public const SAVE_REPLACE = 'replace';
    /**
     * The type of join to be used when adding the association to a query
     */
    protected string $_join_type = Select_Query::JOIN_TYPE_INNER;
    /**
     * The strategy name to be used to fetch associated records.
     */
    protected string $_strategy = self::STRATEGY_SELECT;
    /**
     * Junction table instance
     */
    protected Table $_junction_table;
    /**
     * Junction table name
     */
    protected string $_junction_table_name;
    /**
     * The name of the hasMany association from the target table
     * to the junction table
     */
    protected string $_junction_association_name;
    /**
     * The name of the property to be set containing data from the junction table
     * once a record from the target table is hydrated
     */
    protected string $_junction_property = '_joinData';
    /**
     * Saving strategy to be used by this association
     */
    protected string $_save_strategy = self::SAVE_REPLACE;
    /**
     * The name of the field representing the foreign key to the target table
     *
     * @var array<string>|string|null
     */
    protected array|string|null $_target_foreign_key = null;
    /**
     * The table instance for the junction relation.
     */
    protected Table|string|null $_through = null;
    /**
     * Valid strategies for this type of association
     *
     * @var array<string>
     */
    protected array $_valid_strategies = [self::STRATEGY_SELECT, self::STRATEGY_SUBQUERY];
    /**
     * Whether the records on the joint table should be removed when a record
     * on the source table is deleted.
     *
     * Defaults to true for backwards compatibility.
     */
    protected bool $_dependent = true;
    /**
     * Filtered conditions that reference the target table.
     */
    protected ?array $_target_conditions = null;
    /**
     * Filtered conditions that reference the junction table.
     */
    protected ?array $_junction_conditions = null;
    /**
     * Order in which target records should be returned
     *
     * @var \Cake\Database\ExpressionInterface|\Closure|array<\Cake\Database\ExpressionInterface|string>|string|null
     */
    protected Expression_Interface|Closure|array|string|null $_sort = null;
    /**
     * Sets the name of the field representing the foreign key to the target table.
     *
     * @param array<string>|string $key the key to be used to link both tables together
     * @return $this
     */
    public function set_target_foreign_key(array|string $key): static
    {
        $this->_target_foreign_key = $key;
        return $this;
    }
    /**
     * Gets the name of the field representing the foreign key to the target table.
     *
     * @return array<string>|string
     */
    public function get_target_foreign_key(): array|string
    {
        return $this->_target_foreign_key ??= $this->_model_key($this->get_target()->get_alias());
    }
    /**
     * Whether this association can be expressed directly in a query join
     *
     * @param array<string, mixed> $options custom options key that could alter the return value
     * @return bool if the 'matching' key in $option is true then this function
     * will return true, false otherwise
     */
    public function can_be_joined(array $options = []): bool
    {
        return !empty($options['matching']);
    }
    /**
     * @inheritDoc
     */
    public function get_foreign_key(): array|string|false
    {
        return $this->_foreign_key ??= $this->_model_key($this->get_source()->get_table());
    }
    /**
     * Sets the sort order in which target records should be returned.
     *
     * @param \Cake\Database\ExpressionInterface|\Closure|array<\Cake\Database\ExpressionInterface|string>|string $sort A find() compatible order clause
     * @return $this
     */
    public function set_sort(Expression_Interface|Closure|array|string $sort): static
    {
        $this->_sort = $sort;
        return $this;
    }
    /**
     * Gets the sort order in which target records should be returned.
     *
     * @return \Cake\Database\ExpressionInterface|\Closure|array<\Cake\Database\ExpressionInterface|string>|string|null
     */
    public function get_sort(): Expression_Interface|Closure|array|string|null
    {
        return $this->_sort;
    }
    /**
     * @inheritDoc
     */
    public function default_row_value(array $row, bool $joined): array
    {
        $source_alias = $this->get_source()->get_alias();
        if (isset($row[$source_alias])) {
            $row[$source_alias][$this->get_property()] = $joined ? null : [];
        }
        return $row;
    }
    /**
     * Sets the table instance for the junction relation. If no arguments
     * are passed, the current configured table instance is returned
     *
     * @param \Cake\ORM\Table|string|null $table Name or instance for the join table
     * @throws \InvalidArgumentException If the expected associations are incompatible with existing associations.
     */
    public function junction(Table|string|null $table = null): Table
    {
        if ($table === null && isset($this->_junction_table)) {
            return $this->_junction_table;
        }
        $table_locator = $this->get_table_locator();
        if ($table === null && $this->_through !== null) {
            $table = $this->_through;
        } elseif ($table === null) {
            $table_name = $this->_junction_table_name();
            $table_alias = Inflector::camelize($table_name);
            $config = [];
            if (!$table_locator->exists($table_alias)) {
                $config = ['table' => $table_name, 'allowFallbackClass' => true];
                // Propagate the connection if we'll get an auto-model
                if (!App::class_name($table_alias, 'Model/Table', '_Table')) {
                    $config['connection'] = $this->get_source()->get_connection();
                }
            }
            $table = $table_locator->get($table_alias, $config);
        }
        if (is_string($table)) {
            $table = $table_locator->get($table);
        }
        $source = $this->get_source();
        $target = $this->get_target();
        if ($source->get_alias() === $target->get_alias()) {
            throw new InvalidArgumentException(sprintf('The `%s` association on `%s` cannot target the same table.', $this->get_name(), $source->get_alias()));
        }
        $this->_generate_source_associations($table, $source);
        $this->_generate_target_associations($table, $source, $target);
        $this->_generate_junction_associations($table, $source, $target);
        return $this->_junction_table = $table;
    }
    /**
     * Set the junction property name.
     *
     * @param string $junctionProperty Property name.
     * @return $this
     */
    public function set_junction_property(string $junction_property): static
    {
        $this->_junction_property = $junction_property;
        return $this;
    }
    /**
     * Get the junction property naeme.
     */
    public function get_junction_property(): string
    {
        return $this->_junction_property;
    }
    /**
     * Generate reciprocal associations as necessary.
     *
     * Generates the following associations:
     *
     * - target hasMany junction e.g. Articles hasMany ArticlesTags
     * - target belongsToMany source e.g Articles belongsToMany Tags.
     *
     * You can override these generated associations by defining associations
     * with the correct aliases.
     *
     * @param \Cake\ORM\Table $junction The junction table.
     * @param \Cake\ORM\Table $source The source table.
     * @param \Cake\ORM\Table $target The target table.
     */
    protected function _generate_target_associations(Table $junction, Table $source, Table $target): void
    {
        $junction_alias = $junction->get_alias();
        $s_alias = $source->get_alias();
        $t_alias = $target->get_alias();
        $target_binding_key = null;
        if ($junction->has_association($t_alias)) {
            $target_binding_key = $junction->get_association($t_alias)->get_binding_key();
        }
        if (!$target->has_association($junction_alias)) {
            $target->has_many($junction_alias, ['targetTable' => $junction, 'bindingKey' => $target_binding_key, 'foreignKey' => $this->get_target_foreign_key(), 'strategy' => $this->_strategy]);
        }
        if (!$target->has_association($s_alias)) {
            $target->belongs_to_many($s_alias, ['sourceTable' => $target, 'targetTable' => $source, 'foreignKey' => $this->get_target_foreign_key(), 'targetForeignKey' => $this->get_foreign_key(), 'through' => $junction, 'conditions' => $this->get_conditions(), 'strategy' => $this->_strategy]);
        }
    }
    /**
     * Generate additional source table associations as necessary.
     *
     * Generates the following associations:
     *
     * - source hasMany junction e.g. Tags hasMany ArticlesTags
     *
     * You can override these generated associations by defining associations
     * with the correct aliases.
     *
     * @param \Cake\ORM\Table $junction The junction table.
     * @param \Cake\ORM\Table $source The source table.
     */
    protected function _generate_source_associations(Table $junction, Table $source): void
    {
        $junction_alias = $junction->get_alias();
        $s_alias = $source->get_alias();
        $source_binding_key = null;
        if ($junction->has_association($s_alias)) {
            $source_binding_key = $junction->get_association($s_alias)->get_binding_key();
        }
        if (!$source->has_association($junction_alias)) {
            $source->has_many($junction_alias, ['targetTable' => $junction, 'bindingKey' => $source_binding_key, 'foreignKey' => $this->get_foreign_key(), 'strategy' => $this->_strategy]);
        }
    }
    /**
     * Generate associations on the junction table as necessary
     *
     * Generates the following associations:
     *
     * - junction belongsTo source e.g. ArticlesTags belongsTo Tags
     * - junction belongsTo target e.g. ArticlesTags belongsTo Articles
     *
     * You can override these generated associations by defining associations
     * with the correct aliases.
     *
     * @param \Cake\ORM\Table $junction The junction table.
     * @param \Cake\ORM\Table $source The source table.
     * @param \Cake\ORM\Table $target The target table.
     * @throws \InvalidArgumentException If the expected associations are incompatible with existing associations.
     */
    protected function _generate_junction_associations(Table $junction, Table $source, Table $target): void
    {
        $t_alias = $target->get_alias();
        $s_alias = $source->get_alias();
        if (!$junction->has_association($t_alias)) {
            $junction->belongs_to($t_alias, ['foreignKey' => $this->get_target_foreign_key(), 'targetTable' => $target]);
        } else {
            $belongs_to = $junction->get_association($t_alias);
            if ($this->get_target_foreign_key() !== $belongs_to->get_foreign_key() || $target !== $belongs_to->get_target()) {
                throw new InvalidArgumentException("The existing `{$t_alias}` association on `{$junction->get_alias()}` " . "is incompatible with the `{$this->get_name()}` association on `{$source->get_alias()}`");
            }
        }
        if (!$junction->has_association($s_alias)) {
            $junction->belongs_to($s_alias, ['bindingKey' => $this->get_binding_key(), 'foreignKey' => $this->get_foreign_key(), 'targetTable' => $source]);
        }
    }
    /**
     * Alters a Query object to include the associated target table data in the final
     * result
     *
     * The options array accept the following keys:
     *
     * - includeFields: Whether to include target model fields in the result or not
     * - foreignKey: The name of the field to use as foreign key, if false none
     *   will be used
     * - conditions: array with a list of conditions to filter the join with
     * - fields: a list of fields in the target table to include in the result
     * - type: The type of join to be used (e.g. INNER)
     *
     * @param \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $query the query to be altered to include the target table data
     * @param array<string, mixed> $options Any extra options or overrides to be taken in account
     */
    public function attach_to(Select_Query $query, array $options = []): void
    {
        if (!empty($options['negateMatch'])) {
            $this->_append_not_matching($query, $options);
            return;
        }
        $junction = $this->junction();
        $belongs_to = $junction->get_association($this->get_source()->get_alias());
        $cond = $belongs_to->_join_condition(['foreignKey' => $belongs_to->get_foreign_key()]);
        $cond += $this->junction_conditions();
        $include_fields = $options['includeFields'] ?? null;
        // Attach the junction table as well we need it to populate junction property (_joinData).
        $assoc = $this->get_target()->get_association($junction->get_alias());
        $new_options = array_intersect_key($options, ['joinType' => 1, 'fields' => 1]);
        $new_options += ['conditions' => $cond, 'includeFields' => $include_fields, 'foreignKey' => false];
        $assoc->attach_to($query, $new_options);
        $query->get_eager_loader()->add_to_joins_map($junction->get_alias(), $assoc, true);
        parent::attach_to($query, $options);
        $foreign_key = $this->get_target_foreign_key();
        $this_join = $query->clause('join')[$this->get_name()];
        /** @var \Cake\Database\Expression\QueryExpression $conditions */
        $conditions = $this_join['conditions'];
        $conditions->add($assoc->_join_condition(['foreignKey' => $foreign_key]));
    }
    /**
     * @param \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $query The query to append to.
     * @param array<string, mixed> $options The options for not matching.
     */
    protected function _append_not_matching(Select_Query $query, array $options): void
    {
        if (empty($options['negateMatch'])) {
            return;
        }
        $options['conditions'] ??= [];
        $junction = $this->junction();
        $belongs_to = $junction->get_association($this->get_source()->get_alias());
        $conds = $belongs_to->_join_condition(['foreignKey' => $belongs_to->get_foreign_key()]);
        $subquery = $this->find()->select(array_values($conds))->where($options['conditions']);
        if (!empty($options['queryBuilder'])) {
            assert(is_callable($options['queryBuilder']));
            /** @var \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $subquery */
            $subquery = $options['queryBuilder']($subquery);
        }
        $subquery = $this->_append_junction_join($subquery);
        $query->and_where(function (Query_Expression $exp) use ($subquery, $conds): \Cake\Database\Expression\Query_Expression {
            $identifiers = [];
            foreach (array_keys($conds) as $field) {
                $identifiers[] = new Identifier_Expression($field);
            }
            $identifiers = $subquery->expr()->add($identifiers)->set_conjunction(',');
            $null_exp = clone $exp;
            return $exp->or([$exp->not_in($identifiers, $subquery), $null_exp->and(array_map($null_exp->is_null(...), array_keys($conds)))]);
        });
    }
    /**
     * Get the relationship type.
     */
    public function type(): string
    {
        return self::MANY_TO_MANY;
    }
    /**
     * Return false as join conditions are defined in the junction table
     *
     * @param array<string, mixed> $options list of options passed to attachTo method
     */
    protected function _join_condition(array $options): array
    {
        return [];
    }
    /**
     * @inheritDoc
     */
    public function eager_loader(array $options): Closure
    {
        $name = $this->_junction_association_name();
        $loader = new Select_With_Pivot_Loader(['alias' => $this->get_alias(), 'sourceAlias' => $this->get_source()->get_alias(), 'targetAlias' => $this->get_target()->get_alias(), 'foreignKey' => $this->get_foreign_key(), 'bindingKey' => $this->get_binding_key(), 'strategy' => $this->get_strategy(), 'associationType' => $this->type(), 'sort' => $this->get_sort(), 'junctionAssociationName' => $name, 'junctionProperty' => $this->_junction_property, 'junctionAssoc' => $this->get_target()->get_association($name), 'junctionConditions' => $this->junction_conditions(), 'finder' => fn() => $this->_append_junction_join($this->find(), [])]);
        return $loader->build_eager_loader($options);
    }
    /**
     * Clear out the data in the junction table for a given entity.
     *
     * @param \Cake\Datasource\EntityInterface $entity The entity that started the cascading delete.
     * @param array<string, mixed> $options The options for the original delete.
     * @return bool Success.
     */
    public function cascade_delete(Entity_Interface $entity, array $options = []): bool
    {
        if (!$this->get_dependent()) {
            return true;
        }
        /** @var array<string> $foreignKeys */
        $foreign_keys = (array) $this->get_foreign_key();
        $binding_keys = (array) $this->get_binding_key();
        $conditions = [];
        if ($binding_keys) {
            $conditions = array_combine($foreign_keys, $entity->extract($binding_keys));
        }
        $table = $this->junction();
        $has_many = $this->get_source()->get_association($table->get_alias());
        if ($this->_cascade_callbacks) {
            foreach ($has_many->find('all')->where($conditions)->all()->to_list() as $related) {
                /** @phpstan-ignore argument.type (cascade callbacks always have hydration enabled) */
                $success = $table->delete($related, $options);
                if (!$success) {
                    return false;
                }
            }
            return true;
        }
        $assoc_conditions = $has_many->get_conditions();
        if (is_array($assoc_conditions)) {
            $conditions = array_merge($conditions, $assoc_conditions);
        } else {
            $conditions[] = $assoc_conditions;
        }
        $table->delete_all($conditions);
        return true;
    }
    /**
     * Returns boolean true, as both of the tables 'own' rows in the other side
     * of the association via the joint table.
     *
     * @param \Cake\ORM\Table $side The potential Table with ownership
     */
    public function is_owning_side(Table $side): bool
    {
        return true;
    }
    /**
     * Sets the strategy that should be used for saving.
     *
     * @param string $strategy the strategy name to be used
     * @throws \InvalidArgumentException if an invalid strategy name is passed
     * @return $this
     */
    public function set_save_strategy(string $strategy): static
    {
        if (!in_array($strategy, [self::SAVE_APPEND, self::SAVE_REPLACE], true)) {
            $msg = sprintf('Invalid save strategy `%s`', $strategy);
            throw new InvalidArgumentException($msg);
        }
        $this->_save_strategy = $strategy;
        return $this;
    }
    /**
     * Gets the strategy that should be used for saving.
     *
     * @return string the strategy to be used for saving
     */
    public function get_save_strategy(): string
    {
        return $this->_save_strategy;
    }
    /**
     * Takes an entity from the source table and looks if there is a field
     * matching the property name for this association. The found entity will be
     * saved on the target table for this association by passing supplied
     * `$options`
     *
     * When using the 'append' strategy, this function will only create new links
     * between each side of this association. It will not destroy existing ones even
     * though they may not be present in the array of entities to be saved.
     *
     * When using the 'replace' strategy, existing links will be removed and new links
     * will be created in the joint table. If there exists links in the database to some
     * of the entities intended to be saved by this method, they will be updated,
     * not deleted.
     *
     * @param \Cake\Datasource\EntityInterface $entity an entity from the source table
     * @param array<string, mixed> $options options to be passed to the save method in the target table
     * @throws \InvalidArgumentException if the property representing the association
     * in the parent entity cannot be traversed
     * @return \Cake\Datasource\EntityInterface|false false if $entity could not be saved, otherwise it returns
     * the saved entity
     * @see \Cake\ORM\Table::save()
     * @see \Cake\ORM\Association\BelongsToMany::replaceLinks()
     */
    public function save_associated(Entity_Interface $entity, array $options = []): Entity_Interface|false
    {
        $target_entity = $entity->get($this->get_property());
        $strategy = $this->get_save_strategy();
        $is_empty = in_array($target_entity, [null, [], '', false], true);
        if ($is_empty && $entity->is_new()) {
            return $entity;
        }
        if ($is_empty) {
            $target_entity = [];
        }
        if ($strategy === self::SAVE_APPEND) {
            return $this->_save_target($entity, $target_entity, $options);
        }
        if ($this->replace_links($entity, $target_entity, $options)) {
            return $entity;
        }
        return false;
    }
    /**
     * Persists each of the entities into the target table and creates links between
     * the parent entity and each one of the saved target entities.
     *
     * @param \Cake\Datasource\EntityInterface $parentEntity the source entity containing the target
     * entities to be saved.
     * @param array $entities list of entities to persist in target table and to
     * link to the parent entity
     * @param array<string, mixed> $options list of options accepted by `Table::save()`
     * @throws \InvalidArgumentException if the property representing the association
     * in the parent entity cannot be traversed
     * @return \Cake\Datasource\EntityInterface|false The parent entity after all links have been
     * created if no errors happened, false otherwise
     */
    protected function _save_target(Entity_Interface $parent_entity, array $entities, array $options): Entity_Interface|false
    {
        $join_associations = false;
        if (isset($options['associated']) && is_array($options['associated'])) {
            if (!empty($options['associated'][$this->_junction_property]['associated'])) {
                $join_associations = $options['associated'][$this->_junction_property]['associated'];
            }
            unset($options['associated'][$this->_junction_property]);
        }
        $table = $this->get_target();
        $original = $entities;
        $persisted = [];
        foreach ($entities as $k => $entity) {
            if (!$entity instanceof Entity_Interface) {
                break;
            }
            if (!empty($options['atomic'])) {
                $entity = clone $entity;
            }
            $saved = $table->save($entity, $options);
            if ($saved) {
                $entities[$k] = $entity;
                $persisted[] = $entity;
                continue;
            }
            // Saving the new linked entity failed, copy errors back into the
            // original entity if applicable and abort.
            if (!empty($options['atomic'])) {
                /** @var \Cake\Datasource\EntityInterface $originalEntity */
                $original_entity = $original[$k];
                $original_entity->set_errors($entity->get_errors());
            }
            return false;
        }
        $options['associated'] = $join_associations;
        $success = $this->_save_links($parent_entity, $persisted, $options);
        if (!$success && !empty($options['atomic'])) {
            $parent_entity->set($this->get_property(), $original);
            return false;
        }
        $parent_entity->set($this->get_property(), $entities);
        return $parent_entity;
    }
    /**
     * Creates links between the source entity and each of the passed target entities
     *
     * @param \Cake\Datasource\EntityInterface $sourceEntity the entity from source table in this
     * association
     * @param array<\Cake\Datasource\EntityInterface> $targetEntities list of entities to link to link to the source entity using the
     * junction table
     * @param array<string, mixed> $options list of options accepted by `Table::save()`
     * @return bool success
     */
    protected function _save_links(Entity_Interface $source_entity, array $target_entities, array $options): bool
    {
        $target = $this->get_target();
        $junction = $this->junction();
        $entity_class = $junction->get_entity_class();
        $belongs_to = $junction->get_association($target->get_alias());
        /** @var array<string> $foreignKey */
        $foreign_key = (array) $this->get_foreign_key();
        /** @var array<string> $assocForeignKey */
        $assoc_foreign_key = (array) $belongs_to->get_foreign_key();
        $target_binding_key = (array) $belongs_to->get_binding_key();
        $binding_key = (array) $this->get_binding_key();
        $joint_property = $this->_junction_property;
        $junction_registry_alias = $junction->get_registry_alias();
        foreach ($target_entities as $e) {
            $joint = $e->get($joint_property);
            if (!$joint instanceof Entity_Interface) {
                $joint = new $entity_class([], ['markNew' => true, 'source' => $junction_registry_alias]);
            }
            $source_keys = array_combine($foreign_key, $source_entity->extract($binding_key));
            $target_keys = array_combine($assoc_foreign_key, $e->extract($target_binding_key));
            $changed_keys = $source_keys !== $joint->extract($foreign_key) || $target_keys !== $joint->extract($assoc_foreign_key);
            // Keys were changed, the junction table record _could_ be
            // new. By clearing the primary key values, and marking the entity
            // as new, we let save() sort out whether we have a new link
            // or if we are updating an existing link.
            if ($changed_keys) {
                $joint->set_new(true);
                $joint->unset($junction->get_primary_key());
                if (method_exists($joint, 'patch')) {
                    $joint->patch(array_merge($source_keys, $target_keys), ['guard' => false]);
                } else {
                    $joint->set(array_merge($source_keys, $target_keys), ['guard' => false]);
                }
            }
            $saved = $junction->save($joint, $options);
            if (!$saved && !empty($options['atomic'])) {
                return false;
            }
            $e->set($joint_property, $joint);
            $e->set_dirty($joint_property, false);
        }
        return true;
    }
    /**
     * Associates the source entity to each of the target entities provided by
     * creating links in the junction table. Both the source entity and each of
     * the target entities are assumed to be already persisted, if they are marked
     * as new or their status is unknown then an exception will be thrown.
     *
     * When using this method, all entities in `$targetEntities` will be appended to
     * the source entity's property corresponding to this association object.
     *
     * This method does not check link uniqueness.
     *
     * ### Example:
     *
     * ```
     * $newTags = $tags->find('relevant')->toArray();
     * $articles->getAssociation('tags')->link($article, $newTags);
     * ```
     *
     * `$article->get('tags')` will contain all tags in `$newTags` after liking
     *
     * @param \Cake\Datasource\EntityInterface $sourceEntity the row belonging to the `source` side
     *   of this association
     * @param array<\Cake\Datasource\EntityInterface> $targetEntities list of entities belonging to the `target` side
     *   of this association
     * @param array<string, mixed> $options list of options to be passed to the internal `save` call
     * @throws \InvalidArgumentException when any of the values in $targetEntities is
     *   detected to not be already persisted
     * @return bool true on success, false otherwise
     */
    public function link(Entity_Interface $source_entity, array $target_entities, array $options = []): bool
    {
        $this->_check_persistence_status($source_entity, $target_entities);
        $property = $this->get_property();
        $links = $source_entity->get($property) ?: [];
        $links = array_merge($links, $target_entities);
        $source_entity->set($property, $links);
        return $this->junction()->get_connection()->transactional(fn() => $this->_save_links($source_entity, $target_entities, $options));
    }
    /**
     * Removes all links between the passed source entity and each of the provided
     * target entities. This method assumes that all passed objects are already persisted
     * in the database and that each of them contain a primary key value.
     *
     * ### Options
     *
     * Additionally to the default options accepted by `Table::delete()`, the following
     * keys are supported:
     *
     * - cleanProperty: Whether to remove all the objects in `$targetEntities` that
     * are stored in `$sourceEntity` (default: true)
     *
     * By default this method will unset each of the entity objects stored inside the
     * source entity.
     *
     * ### Example:
     *
     * ```
     * $article->tags = [$tag1, $tag2, $tag3, $tag4];
     * $tags = [$tag1, $tag2, $tag3];
     * $articles->getAssociation('tags')->unlink($article, $tags);
     * ```
     *
     * `$article->get('tags')` will contain only `[$tag4]` after deleting in the database
     *
     * @param \Cake\Datasource\EntityInterface $sourceEntity An entity persisted in the source table for
     *   this association.
     * @param array<\Cake\Datasource\EntityInterface> $targetEntities List of entities persisted in the target table for
     *   this association.
     * @param array<string, mixed>|bool $options List of options to be passed to the internal `delete` call,
     *   or a `boolean` as `cleanProperty` key shortcut.
     * @throws \InvalidArgumentException If non-persisted entities are passed or if
     *   any of them is lacking a primary key value.
     * @return bool Success
     */
    public function unlink(Entity_Interface $source_entity, array $target_entities, array|bool $options = []): bool
    {
        if (is_bool($options)) {
            $options = ['cleanProperty' => $options];
        } else {
            $options += ['cleanProperty' => true];
        }
        $this->_check_persistence_status($source_entity, $target_entities);
        $property = $this->get_property();
        $links = $this->_collect_joint_entities($source_entity, $target_entities);
        $return = $this->_junction_table->delete_many($links, $options);
        if ($return === false) {
            return false;
        }
        /** @var array<\Cake\Datasource\EntityInterface> $existing */
        $existing = $source_entity->get($property) ?: [];
        if (!$options['cleanProperty'] || empty($existing)) {
            return true;
        }
        /** @var \SplObjectStorage<\Cake\Datasource\EntityInterface, null> $storage */
        $storage = new Spl_Object_Storage();
        foreach ($target_entities as $e) {
            $storage->offsetSet($e);
        }
        foreach ($existing as $k => $e) {
            if ($storage->offsetExists($e)) {
                unset($existing[$k]);
            }
        }
        $source_entity->set($property, array_values($existing));
        $source_entity->set_dirty($property, false);
        return true;
    }
    /**
     * @inheritDoc
     */
    public function set_conditions(Closure|array $conditions): static
    {
        parent::set_conditions($conditions);
        $this->_target_conditions = null;
        $this->_junction_conditions = null;
        return $this;
    }
    /**
     * Sets the current join table, either the name of the Table instance or the instance itself.
     *
     * @param \Cake\ORM\Table|string $through Name of the Table instance or the instance itself
     * @return $this
     */
    public function set_through(Table|string $through): static
    {
        $this->_through = $through;
        return $this;
    }
    /**
     * Gets the current join table, either the name of the Table instance or the instance itself.
     * Returns null if not defined.
     */
    public function get_through(): Table|string|null
    {
        return $this->_through;
    }
    /**
     * Returns filtered conditions that reference the target table.
     *
     * Any string expressions, or expression objects will
     * also be returned in this list.
     *
     * @return \Closure|array|null Generally an array. If the conditions
     *   are not an array, the association conditions will be
     *   returned unmodified.
     */
    protected function target_conditions(): mixed
    {
        if ($this->_target_conditions !== null) {
            return $this->_target_conditions;
        }
        $conditions = $this->get_conditions();
        if (!is_array($conditions)) {
            return $conditions;
        }
        $matching = [];
        $alias = $this->get_alias() . '.';
        foreach ($conditions as $field => $value) {
            if (is_string($field) && str_starts_with($field, $alias)) {
                $matching[$field] = $value;
            } elseif (is_int($field) || $value instanceof Expression_Interface) {
                $matching[$field] = $value;
            }
        }
        return $this->_target_conditions = $matching;
    }
    /**
     * Returns filtered conditions that specifically reference
     * the junction table.
     */
    protected function junction_conditions(): array
    {
        if ($this->_junction_conditions !== null) {
            return $this->_junction_conditions;
        }
        $matching = [];
        $conditions = $this->get_conditions();
        if (!is_array($conditions)) {
            return $matching;
        }
        $alias = $this->_junction_association_name() . '.';
        foreach ($conditions as $field => $value) {
            $is_string = is_string($field);
            if ($is_string && str_starts_with($field, $alias)) {
                $matching[$field] = $value;
            }
            // Assume that operators contain junction conditions.
            // Trying to manage complex conditions could result in incorrect queries.
            if ($is_string && in_array(strtoupper($field), ['OR', 'NOT', 'AND', 'XOR'], true)) {
                $matching[$field] = $value;
            }
        }
        return $this->_junction_conditions = $matching;
    }
    /**
     * Proxies the finding operation to the target table's find method
     * and modifies the query accordingly based of this association
     * configuration.
     *
     * If your association includes conditions or a finder, the junction table will be
     * included in the query's contained associations.
     *
     * @param array<string, mixed>|string|null $type the type of query to perform, if an array is passed,
     *   it will be interpreted as the `$options` parameter
     * @param mixed ...$args Arguments that match up to finder-specific parameters
     * @see \Cake\ORM\Table::find()
     * @return \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array>
     */
    public function find(array|string|null $type = null, mixed ...$args): Select_Query
    {
        $type = $type ?: $this->get_finder();
        [$type, $opts] = $this->_extract_finder($type);
        $args += $opts;
        $query = $this->get_target()->find($type, ...$args)->where($this->target_conditions())->add_default_types($this->get_target());
        if ($this->junction_conditions()) {
            return $this->_append_junction_join($query);
        }
        return $query;
    }
    /**
     * Append a join to the junction table.
     *
     * @param \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $query The query to append.
     * @param array|null $conditions The query conditions to use.
     * @return \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> The modified query.
     */
    protected function _append_junction_join(Select_Query $query, ?array $conditions = null): Select_Query
    {
        $junction_table = $this->junction();
        if ($conditions === null) {
            $belongs_to = $junction_table->get_association($this->get_target()->get_alias());
            $conditions = $belongs_to->_join_condition(['foreignKey' => $this->get_target_foreign_key()]);
            $conditions += $this->junction_conditions();
        }
        $name = $this->_junction_association_name();
        $joins = $query->clause('join');
        assert(is_array($joins));
        $matching = [$name => ['table' => $junction_table->get_table(), 'conditions' => $conditions, 'type' => Select_Query::JOIN_TYPE_INNER]];
        $query->add_default_types($junction_table)->join($matching + $joins, [], true);
        return $query;
    }
    /**
     * Replaces existing association links between the source entity and the target
     * with the ones passed. This method does a smart cleanup, links that are already
     * persisted and present in `$targetEntities` will not be deleted, new links will
     * be created for the passed target entities that are not already in the database
     * and the rest will be removed.
     *
     * For example, if an article is linked to tags 'cake' and 'framework' and you pass
     * to this method an array containing the entities for tags 'cake', 'php' and 'awesome',
     * only the link for cake will be kept in database, the link for 'framework' will be
     * deleted and the links for 'php' and 'awesome' will be created.
     *
     * Existing links are not deleted and created again, they are either left untouched
     * or updated so that potential extra information stored in the joint row is not
     * lost. Updating the link row can be done by making sure the corresponding passed
     * target entity contains the joint property with its primary key and any extra
     * information to be stored.
     *
     * On success, the passed `$sourceEntity` will contain `$targetEntities` as value
     * in the corresponding property for this association.
     *
     * This method assumes that links between both the source entity and each of the
     * target entities are unique. That is, for any given row in the source table there
     * can only be one link in the junction table pointing to any other given row in
     * the target table.
     *
     * Additional options for new links to be saved can be passed in the third argument,
     * check `Table::save()` for information on the accepted options.
     *
     * ### Example:
     *
     * ```
     * $article->tags = [$tag1, $tag2, $tag3, $tag4];
     * $articles->save($article);
     * $tags = [$tag1, $tag3];
     * $articles->getAssociation('tags')->replaceLinks($article, $tags);
     * ```
     *
     * `$article->get('tags')` will contain only `[$tag1, $tag3]` at the end
     *
     * @param \Cake\Datasource\EntityInterface $sourceEntity an entity persisted in the source table for
     *   this association
     * @param array $targetEntities list of entities from the target table to be linked
     * @param array<string, mixed> $options list of options to be passed to the internal `save`/`delete` calls
     *   when persisting/updating new links, or deleting existing ones
     * @throws \InvalidArgumentException if non persisted entities are passed or if
     *   any of them is lacking a primary key value
     * @return bool success
     */
    public function replace_links(Entity_Interface $source_entity, array $target_entities, array $options = []): bool
    {
        $binding_key = (array) $this->get_binding_key();
        $primary_value = $source_entity->extract($binding_key);
        if (count(Hash::filter($primary_value)) !== count($binding_key)) {
            $message = 'Could not find primary key value for source entity';
            throw new InvalidArgumentException($message);
        }
        return $this->junction()->get_connection()->transactional(function () use ($source_entity, $target_entities, $primary_value, $options): bool {
            $junction = $this->junction();
            $target = $this->get_target();
            /** @var array<string> $foreignKey */
            $foreign_key = (array) $this->get_foreign_key();
            $assoc_foreign_key = (array) $junction->get_association($target->get_alias())->get_foreign_key();
            $prefixed_foreign_key = array_map($junction->alias_field(...), $foreign_key);
            $junction_primary_key = (array) $junction->get_primary_key();
            $junction_query_alias = $junction->get_alias() . '__matches';
            $keys = [];
            $matches_conditions = [];
            /** @var string $key */
            foreach (array_merge($assoc_foreign_key, $junction_primary_key) as $key) {
                $aliased = $junction->alias_field($key);
                $keys[$key] = $aliased;
                $matches_conditions[$aliased] = new Identifier_Expression($junction_query_alias . '.' . $key);
            }
            // Use association to create row selection
            // with finders & association conditions.
            $matches = $this->_append_junction_join($this->find())->select($keys)->where(array_combine($prefixed_foreign_key, $primary_value));
            // Create a subquery join to ensure we get
            // the correct entity passed to callbacks.
            $existing = $junction->select_query()->from([$junction_query_alias => $matches])->inner_join([$junction->get_alias() => $junction->get_table()], $matches_conditions);
            $joint_entities = $this->_collect_joint_entities($source_entity, $target_entities);
            $inserts = $this->_diff_links($existing, $joint_entities, $target_entities, $options);
            if ($inserts === false) {
                return false;
            }
            if ($inserts && !$this->_save_target($source_entity, $inserts, $options)) {
                return false;
            }
            $property = $this->get_property();
            if ($inserts !== []) {
                $inserted = array_combine(array_keys($inserts), (array) $source_entity->get($property)) ?: [];
                $target_entities = $inserted + $target_entities;
            }
            ksort($target_entities);
            $source_entity->set($property, array_values($target_entities));
            $source_entity->set_dirty($property, false);
            return true;
        });
    }
    /**
     * Helper method used to delete the difference between the links passed in
     * `$existing` and `$jointEntities`. This method will return the values from
     * `$targetEntities` that were not deleted from calculating the difference.
     *
     * @param \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $existing a query for getting existing links
     * @param array<\Cake\Datasource\EntityInterface> $jointEntities link entities that should be persisted
     * @param array $targetEntities entities in target table that are related to
     * the `$jointEntities`
     * @param array<string, mixed> $options list of options accepted by `Table::delete()`
     * @return array|false Array of entities not deleted or false in case of deletion failure for atomic saves.
     */
    protected function _diff_links(Select_Query $existing, array $joint_entities, array $target_entities, array $options = []): array|false
    {
        $junction = $this->junction();
        $target = $this->get_target();
        $belongs_to = $junction->get_association($target->get_alias());
        /** @var array<string> $foreignKey */
        $foreign_key = (array) $this->get_foreign_key();
        /** @var array<string> $assocForeignKey */
        $assoc_foreign_key = (array) $belongs_to->get_foreign_key();
        $keys = array_merge($foreign_key, $assoc_foreign_key);
        $deletes = [];
        $unmatched_entity_keys = [];
        $present = [];
        foreach ($joint_entities as $i => $entity) {
            $unmatched_entity_keys[$i] = $entity->extract($keys);
            $present[$i] = array_values($entity->extract($assoc_foreign_key));
        }
        foreach ($existing as $existing_link) {
            /** @var \Cake\ORM\Entity $existingLink */
            $existing_keys = $existing_link->extract($keys);
            $found = false;
            foreach ($unmatched_entity_keys as $i => $unmatched_keys) {
                $matched = false;
                foreach ($keys as $key) {
                    if (is_object($unmatched_keys[$key]) && is_object($existing_keys[$key])) {
                        // If both sides are an object then use == so that value objects
                        // are seen as equivalent.
                        $matched = $existing_keys[$key] == $unmatched_keys[$key];
                    } else {
                        // Use strict equality for all other values.
                        $matched = $existing_keys[$key] === $unmatched_keys[$key];
                    }
                    // Stop checks on first failure.
                    if (!$matched) {
                        break;
                    }
                }
                if ($matched) {
                    // Remove the unmatched entity so we don't look at it again.
                    unset($unmatched_entity_keys[$i]);
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $deletes[] = $existing_link;
            }
        }
        $primary = (array) $target->get_primary_key();
        $joint_property = $this->_junction_property;
        foreach ($target_entities as $k => $entity) {
            if (!$entity instanceof Entity_Interface) {
                continue;
            }
            $key = array_values($entity->extract($primary));
            foreach ($present as $i => $data) {
                if ($key === $data && !$entity->get($joint_property)) {
                    unset($target_entities[$k], $present[$i]);
                    break;
                }
            }
        }
        foreach ($deletes as $entity) {
            if (!$junction->delete($entity, $options) && !empty($options['atomic'])) {
                return false;
            }
        }
        return $target_entities;
    }
    /**
     * Throws an exception should any of the passed entities is not persisted.
     *
     * @param \Cake\Datasource\EntityInterface $sourceEntity the row belonging to the `source` side
     *   of this association
     * @param array<\Cake\Datasource\EntityInterface> $targetEntities list of entities belonging to the `target` side
     *   of this association
     * @throws \InvalidArgumentException
     */
    protected function _check_persistence_status(Entity_Interface $source_entity, array $target_entities): bool
    {
        if ($source_entity->is_new()) {
            $error = 'Source entity needs to be persisted before links can be created or removed.';
            throw new InvalidArgumentException($error);
        }
        foreach ($target_entities as $entity) {
            if ($entity->is_new()) {
                $error = 'Cannot link entities that have not been persisted yet.';
                throw new InvalidArgumentException($error);
            }
        }
        return true;
    }
    /**
     * Returns the list of joint entities that exist between the source entity
     * and each of the passed target entities
     *
     * @param \Cake\Datasource\EntityInterface $sourceEntity The row belonging to the source side
     *   of this association.
     * @param array $targetEntities The rows belonging to the target side of this
     *   association.
     * @throws \InvalidArgumentException if any of the entities is lacking a primary
     *   key value
     * @return array<\Cake\Datasource\EntityInterface>
     */
    protected function _collect_joint_entities(Entity_Interface $source_entity, array $target_entities): array
    {
        $target = $this->get_target();
        $source = $this->get_source();
        $junction = $this->junction();
        $joint_property = $this->_junction_property;
        $primary = (array) $target->get_primary_key();
        $result = [];
        $missing = [];
        foreach ($target_entities as $entity) {
            if (!$entity instanceof Entity_Interface) {
                continue;
            }
            $joint = $entity->get($joint_property);
            if (!$joint instanceof Entity_Interface) {
                $missing[] = $entity->extract($primary);
                continue;
            }
            $result[] = $joint;
        }
        if (!$missing) {
            return $result;
        }
        $belongs_to = $junction->get_association($target->get_alias());
        $has_many = $source->get_association($junction->get_alias());
        /** @var array<string> $foreignKey */
        $foreign_key = (array) $this->get_foreign_key();
        $foreign_key = array_map(fn(string $key) => $key . ' IS', $foreign_key);
        /** @var array<string> $assocForeignKey */
        $assoc_foreign_key = (array) $belongs_to->get_foreign_key();
        $assoc_foreign_key = array_map(fn(string $key) => $key . ' IS', $assoc_foreign_key);
        $source_key = $source_entity->extract((array) $source->get_primary_key());
        $unions = [];
        foreach ($missing as $key) {
            $unions[] = $has_many->find()->where(array_combine($foreign_key, $source_key))->where(array_combine($assoc_foreign_key, $key));
        }
        $query = array_shift($unions);
        foreach ($unions as $q) {
            $query->union($q);
        }
        return array_merge($result, $query->to_array());
    }
    /**
     * Returns the name of the association from the target table to the junction table,
     * this name is used to generate alias in the query and to later on retrieve the
     * results.
     */
    protected function _junction_association_name(): string
    {
        if (!isset($this->_junction_association_name)) {
            $this->_junction_association_name = $this->get_target()->get_association($this->junction()->get_alias())->get_name();
        }
        return $this->_junction_association_name;
    }
    /**
     * Sets the name of the junction table.
     * If no arguments are passed the current configured name is returned. A default
     * name based of the associated tables will be generated if none found.
     *
     * @param string|null $name The name of the junction table.
     */
    protected function _junction_table_name(?string $name = null): string
    {
        if ($name === null) {
            if (empty($this->_junction_table_name)) {
                $tables_names = array_map(Cake\Utility\Inflector::underscore(...), [$this->get_source()->get_table(), $this->get_target()->get_table()]);
                sort($tables_names);
                $this->_junction_table_name = implode('_', $tables_names);
            }
            return $this->_junction_table_name;
        }
        return $this->_junction_table_name = $name;
    }
    /**
     * Parse extra options passed in the constructor.
     *
     * @param array<string, mixed> $options original list of options passed in constructor
     */
    protected function _options(array $options): void
    {
        if (!empty($options['targetForeignKey'])) {
            $this->set_target_foreign_key($options['targetForeignKey']);
        }
        if (!empty($options['joinTable'])) {
            $this->_junction_table_name($options['joinTable']);
        }
        if (!empty($options['through'])) {
            $this->set_through($options['through']);
        }
        if (!empty($options['saveStrategy'])) {
            $this->set_save_strategy($options['saveStrategy']);
        }
        if (isset($options['sort'])) {
            $this->set_sort($options['sort']);
        }
        if (isset($options['junctionProperty'])) {
            assert(is_string($options['junctionProperty']), '`junctionProperty` must be a string');
            $this->_junction_property = $options['junctionProperty'];
        }
    }
}