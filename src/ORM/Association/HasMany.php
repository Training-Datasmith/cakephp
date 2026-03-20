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

use Cake\Collection\Collection;
use Cake\Database\Expression\Field_Interface;
use Cake\Database\Expression\Query_Expression;
use Cake\Database\Expression_Interface;
use Cake\Datasource\Entity_Interface;
use Cake\Datasource\Invalid_Property_Interface;
use Cake\ORM\Association;
use Cake\ORM\Association\Loader\Select_Loader;
use Cake\ORM\Query\Select_Query;
use Cake\ORM\Table;
use Closure;
use InvalidArgumentException;
/**
 * Represents an N - 1 relationship where the target side of the relationship
 * will have one or multiple records per each one in the source side.
 *
 * An example of a HasMany association would be Author has many Articles.
 *
 * @template T of \Cake\ORM\Table
 * @mixin T
 */
class Has_Many extends Association
{
    /**
     * Order in which target records should be returned
     *
     * @var \Cake\Database\ExpressionInterface|\Closure|array<\Cake\Database\ExpressionInterface|string>|string|null
     */
    protected Expression_Interface|Closure|array|string|null $_sort = null;
    /**
     * The type of join to be used when adding the association to a query
     */
    protected string $_join_type = Select_Query::JOIN_TYPE_INNER;
    /**
     * The strategy name to be used to fetch associated records.
     */
    protected string $_strategy = self::STRATEGY_SELECT;
    /**
     * Valid strategies for this type of association
     *
     * @var array<string>
     */
    protected array $_valid_strategies = [self::STRATEGY_SELECT, self::STRATEGY_SUBQUERY];
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
     * Saving strategy to be used by this association
     */
    protected string $_save_strategy = self::SAVE_APPEND;
    /**
     * Returns whether the passed table is the owning side for this
     * association. This means that rows in the 'target' table would miss important
     * or required information if the row in 'source' did not exist.
     *
     * @param \Cake\ORM\Table $side The potential Table with ownership
     */
    public function is_owning_side(Table $side): bool
    {
        return $side === $this->get_source();
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
     * @param \Cake\Datasource\EntityInterface $entity an entity from the source table
     * @param array<string, mixed> $options options to be passed to the save method in the target table
     * @return \Cake\Datasource\EntityInterface|false false if $entity could not be saved, otherwise it returns
     * the saved entity
     * @see \Cake\ORM\Table::save()
     * @throws \InvalidArgumentException when the association data cannot be traversed.
     */
    public function save_associated(Entity_Interface $entity, array $options = []): Entity_Interface|false
    {
        $target_entities = $entity->get($this->get_property());
        $is_empty = in_array($target_entities, [null, [], '', false], true);
        if ($is_empty) {
            if ($entity->is_new() || $this->get_save_strategy() !== self::SAVE_REPLACE) {
                return $entity;
            }
            $target_entities = [];
        }
        if (!is_iterable($target_entities)) {
            $name = $this->get_property();
            $message = sprintf('Could not save %s, it cannot be traversed', $name);
            throw new InvalidArgumentException($message);
        }
        /** @var array<string> $foreignKeys */
        $foreign_keys = (array) $this->get_foreign_key();
        $foreign_key_reference = array_combine($foreign_keys, $entity->extract((array) $this->get_binding_key()));
        $options['_sourceTable'] = $this->get_source();
        if ($this->_save_strategy === self::SAVE_REPLACE && !$this->_unlink_associated($foreign_key_reference, $entity, $this->get_target(), $target_entities, $options)) {
            return false;
        }
        if (!is_array($target_entities)) {
            $target_entities = iterator_to_array($target_entities);
        }
        if (!$this->_save_target($foreign_key_reference, $entity, $target_entities, $options)) {
            return false;
        }
        return $entity;
    }
    /**
     * Persists each of the entities into the target table and creates links between
     * the parent entity and each one of the saved target entities.
     *
     * @param array $foreignKeyReference The foreign key reference defining the link between the
     * target entity, and the parent entity.
     * @param \Cake\Datasource\EntityInterface $parentEntity The source entity containing the target
     * entities to be saved.
     * @param array $entities list of entities
     * to persist in target table and to link to the parent entity
     * @param array<string, mixed> $options list of options accepted by `Table::save()`.
     * @return bool `true` on success, `false` otherwise.
     */
    protected function _save_target(array $foreign_key_reference, Entity_Interface $parent_entity, array $entities, array $options): bool
    {
        $foreign_key = array_keys($foreign_key_reference);
        $table = $this->get_target();
        $original = $entities;
        foreach ($entities as $k => $entity) {
            if (!$entity instanceof Entity_Interface) {
                break;
            }
            if (!empty($options['atomic'])) {
                $entity = clone $entity;
            }
            if ($foreign_key_reference !== $entity->extract($foreign_key)) {
                // @phpstan-ignore function.alreadyNarrowedType (patch method available on EntityInterface)
                if (method_exists($entity, 'patch')) {
                    $entity->patch($foreign_key_reference, ['guard' => false]);
                } else {
                    $entity->set($foreign_key_reference, ['guard' => false]);
                }
            }
            if ($table->save($entity, $options)) {
                $entities[$k] = $entity;
                continue;
            }
            if (!empty($options['atomic'])) {
                /** @var \Cake\ORM\Entity $originEntity */
                $origin_entity = $original[$k];
                $origin_entity->set_errors($entity->get_errors());
                if ($entity instanceof Invalid_Property_Interface) {
                    $origin_entity->set_invalid($entity->get_invalid());
                }
                return false;
            }
        }
        $parent_entity->set($this->get_property(), $entities);
        return true;
    }
    /**
     * Associates the source entity to each of the target entities provided.
     * When using this method, all entities in `$targetEntities` will be appended to
     * the source entity's property corresponding to this association object.
     *
     * This method does not check link uniqueness.
     * Changes are persisted in the database and also in the source entity.
     *
     * ### Example:
     *
     * ```
     * $user = $users->get(1);
     * $allArticles = $articles->find('all')->toArray();
     * $users->Articles->link($user, $allArticles);
     * ```
     *
     * `$user->get('articles')` will contain all articles in `$allArticles` after linking
     *
     * @param \Cake\Datasource\EntityInterface $sourceEntity the row belonging to the `source` side
     * of this association
     * @param array<\Cake\Datasource\EntityInterface> $targetEntities list of entities belonging to the `target` side
     * of this association
     * @param array<string, mixed> $options list of options to be passed to the internal `save` call
     * @return bool true on success, false otherwise
     */
    public function link(Entity_Interface $source_entity, array $target_entities, array $options = []): bool
    {
        $save_strategy = $this->get_save_strategy();
        $this->set_save_strategy(self::SAVE_APPEND);
        $property = $this->get_property();
        /** @var array<\Cake\Datasource\EntityInterface> $currentEntities */
        $current_entities = (array) $source_entity->get($property);
        if ($current_entities === []) {
            $current_entities = $target_entities;
        } else {
            $pk_fields = (array) $this->get_target()->get_primary_key();
            /** @var array<\Cake\Datasource\EntityInterface> $currentEntities */
            $target_entities = (new Collection($target_entities))->reject(function (Entity_Interface $entity) use ($current_entities, $pk_fields): bool {
                if ($entity->is_new()) {
                    return false;
                }
                foreach ($current_entities as $c_entity) {
                    if ($entity->extract($pk_fields) === $c_entity->extract($pk_fields)) {
                        return true;
                    }
                }
                return false;
            })->to_list();
            $current_entities = array_merge($current_entities, $target_entities);
        }
        $source_entity->set($property, $current_entities);
        $saved_entity = $this->get_connection()->transactional(fn(): \Cake\Datasource\Entity_Interface|false => $this->save_associated($source_entity, $options));
        $ok = $saved_entity instanceof Entity_Interface;
        $this->set_save_strategy($save_strategy);
        if ($ok) {
            $source_entity->set($property, $saved_entity->get($property));
            $source_entity->set_dirty($property, false);
        }
        return $ok;
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
     * Changes are persisted in the database and also in the source entity.
     *
     * ### Example:
     *
     * ```
     * $user = $users->get(1);
     * $user->articles = [$article1, $article2, $article3, $article4];
     * $users->save($user, ['Associated' => ['Articles']]);
     * $allArticles = [$article1, $article2, $article3];
     * $users->Articles->unlink($user, $allArticles);
     * ```
     *
     * `$article->get('articles')` will contain only `[$article4]` after deleting in the database
     *
     * @param \Cake\Datasource\EntityInterface $sourceEntity an entity persisted in the source table for
     * this association
     * @param array $targetEntities list of entities persisted in the target table for
     * this association
     * @param array<string, mixed>|bool $options list of options to be passed to the internal `delete` call.
     *   If boolean it will be used a value for "cleanProperty" option.
     * @throws \InvalidArgumentException if non persisted entities are passed or if
     * any of them is lacking a primary key value
     */
    public function unlink(Entity_Interface $source_entity, array $target_entities, array|bool $options = []): bool
    {
        if (is_bool($options)) {
            $options = ['cleanProperty' => $options];
        } else {
            $options += ['cleanProperty' => true];
        }
        if ($target_entities === []) {
            return true;
        }
        $foreign_key = (array) $this->get_foreign_key();
        $target = $this->get_target();
        $target_primary_key = array_merge((array) $target->get_primary_key(), $foreign_key);
        $property = $this->get_property();
        $conditions = ['OR' => (new Collection($target_entities))->map(function (Entity_Interface $entity) use ($target_primary_key): array {
            /** @var array<string> $targetPrimaryKey */
            return $entity->extract($target_primary_key);
        })->to_list()];
        $return = $this->_unlink($foreign_key, $target, $conditions, $options);
        if (!$return) {
            return false;
        }
        $result = $source_entity->get($property);
        if ($options['cleanProperty'] && $result !== null) {
            $source_entity->set($property, (new Collection($source_entity->get($property)))->reject(fn($assoc) => in_array($assoc, $target_entities, true))->to_list());
        }
        $source_entity->set_dirty($property, false);
        return true;
    }
    /**
     * Replaces existing association links between the source entity and the target
     * with the ones passed. This method does a smart cleanup, links that are already
     * persisted and present in `$targetEntities` will not be deleted, new links will
     * be created for the passed target entities that are not already in the database
     * and the rest will be removed.
     *
     * For example, if an author has many articles, such as 'article1','article 2' and 'article 3' and you pass
     * to this method an array containing the entities for articles 'article 1' and 'article 4',
     * only the link for 'article 1' will be kept in database, the links for 'article 2' and 'article 3' will be
     * deleted and the link for 'article 4' will be created.
     *
     * Existing links are not deleted and created again, they are either left untouched
     * or updated.
     *
     * This method does not check link uniqueness.
     *
     * On success, the passed `$sourceEntity` will contain `$targetEntities` as value
     * in the corresponding property for this association.
     *
     * Additional options for new links to be saved can be passed in the third argument,
     * check `Table::save()` for information on the accepted options.
     *
     * ### Example:
     *
     * ```
     * $author->articles = [$article1, $article2, $article3, $article4];
     * $authors->save($author);
     * $articles = [$article1, $article3];
     * $authors->getAssociation('articles')->replace($author, $articles);
     * ```
     *
     * `$author->get('articles')` will contain only `[$article1, $article3]` at the end
     *
     * @param \Cake\Datasource\EntityInterface $sourceEntity an entity persisted in the source table for
     * this association
     * @param array $targetEntities list of entities from the target table to be linked
     * @param array<string, mixed> $options list of options to be passed to the internal `save`/`delete` calls
     * when persisting/updating new links, or deleting existing ones
     * @throws \InvalidArgumentException if non persisted entities are passed or if
     * any of them is lacking a primary key value
     * @return bool success
     */
    public function replace(Entity_Interface $source_entity, array $target_entities, array $options = []): bool
    {
        $property = $this->get_property();
        $source_entity->set($property, $target_entities);
        $save_strategy = $this->get_save_strategy();
        $this->set_save_strategy(self::SAVE_REPLACE);
        $result = $this->save_associated($source_entity, $options);
        $ok = $result instanceof Entity_Interface;
        if ($ok) {
            // phpcs:ignore SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
            $source_entity = $result;
        }
        $this->set_save_strategy($save_strategy);
        return $ok;
    }
    /**
     * Deletes/sets null the related objects according to the dependency between source and targets
     * and foreign key nullability. Skips deleting records present in $remainingEntities
     *
     * @param array $foreignKeyReference The foreign key reference defining the link between the
     * target entity, and the parent entity.
     * @param \Cake\Datasource\EntityInterface $entity the entity which should have its associated entities unassigned
     * @param \Cake\ORM\Table $target The associated table
     * @param iterable $remainingEntities Entities that should not be deleted
     * @param array<string, mixed> $options list of options accepted by `Table::delete()`
     * @return bool success
     */
    protected function _unlink_associated(array $foreign_key_reference, Entity_Interface $entity, Table $target, iterable $remaining_entities = [], array $options = []): bool
    {
        $primary_key = (array) $target->get_primary_key();
        $exclusions = new Collection($remaining_entities);
        $exclusions = $exclusions->map(fn(Entity_Interface $ent) => $ent->extract($primary_key))->filter(fn($v) => !in_array(null, $v, true))->to_list();
        $conditions = $foreign_key_reference;
        if ($exclusions !== []) {
            $conditions = ['NOT' => ['OR' => $exclusions], $foreign_key_reference];
        }
        return $this->_unlink(array_keys($foreign_key_reference), $target, $conditions, $options);
    }
    /**
     * Deletes/sets null the related objects matching $conditions.
     *
     * The action which is taken depends on the dependency between source and
     * targets and also on foreign key nullability.
     *
     * @param array $foreignKey array of foreign key properties
     * @param \Cake\ORM\Table $target The associated table
     * @param array $conditions The conditions that specifies what are the objects to be unlinked
     * @param array<string, mixed> $options list of options accepted by `Table::delete()`
     * @return bool success
     */
    protected function _unlink(array $foreign_key, Table $target, array $conditions = [], array $options = []): bool
    {
        $must_be_dependent = !$this->_foreign_key_accepts_null($target, $foreign_key) || $this->get_dependent();
        if ($must_be_dependent) {
            if ($this->_cascade_callbacks) {
                $conditions = new Query_Expression($conditions);
                $conditions->traverse(function ($entry) use ($target): void {
                    if ($entry instanceof Field_Interface) {
                        $field = $entry->get_field();
                        if (is_string($field)) {
                            $entry->set_field($target->alias_field($field));
                        }
                    }
                });
                $query = $this->find()->where($conditions);
                /** @phpstan-ignore argument.type, argument.templateType (cascade callbacks always have hydration enabled) */
                $return = $target->delete_many($query->all(), $options);
                if ($return === false) {
                    return false;
                }
                return true;
            }
            $this->delete_all($conditions);
            return true;
        }
        $update_fields = array_fill_keys($foreign_key, null);
        $this->update_all($update_fields, $conditions);
        return true;
    }
    /**
     * Checks the nullable flag of the foreign key
     *
     * @param \Cake\ORM\Table $table the table containing the foreign key
     * @param array $properties the list of fields that compose the foreign key
     */
    protected function _foreign_key_accepts_null(Table $table, array $properties): bool
    {
        return !in_array(false, array_map($table->get_schema()->is_nullable(...), $properties), true);
    }
    /**
     * Get the relationship type.
     */
    public function type(): string
    {
        return self::ONE_TO_MANY;
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
     * Parse extra options passed in the constructor.
     *
     * @param array<string, mixed> $options original list of options passed in constructor
     */
    protected function _options(array $options): void
    {
        if (!empty($options['saveStrategy'])) {
            $this->set_save_strategy($options['saveStrategy']);
        }
        if (isset($options['sort'])) {
            $this->set_sort($options['sort']);
        }
    }
    /**
     * @inheritDoc
     */
    public function eager_loader(array $options): Closure
    {
        $loader = new Select_Loader(['alias' => $this->get_alias(), 'sourceAlias' => $this->get_source()->get_alias(), 'targetAlias' => $this->get_target()->get_alias(), 'foreignKey' => $this->get_foreign_key(), 'bindingKey' => $this->get_binding_key(), 'strategy' => $this->get_strategy(), 'associationType' => $this->type(), 'sort' => $this->get_sort(), 'finder' => $this->find(...)]);
        return $loader->build_eager_loader($options);
    }
    /**
     * @inheritDoc
     */
    public function cascade_delete(Entity_Interface $entity, array $options = []): bool
    {
        $helper = new Dependent_Delete_Helper();
        return $helper->cascade_delete($this, $entity, $options);
    }
}