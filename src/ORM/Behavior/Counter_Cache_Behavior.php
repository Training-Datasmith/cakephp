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
namespace Cake\ORM\Behavior;

use ArrayObject;
use Cake\Datasource\Entity_Interface;
use Cake\Event\Event_Interface;
use Cake\ORM\Association;
use Cake\ORM\Association\Belongs_To;
use Cake\ORM\Behavior;
use Cake\ORM\Query\Select_Query;
use Closure;
/**
 * CounterCache behavior
 *
 * Enables models to cache the amount of connections in a given relation.
 *
 * Examples with Post model belonging to User model
 *
 * Regular counter cache
 * ```
 * [
 *     'Users' => [
 *         'post_count'
 *     ]
 * ]
 * ```
 *
 * Counter cache with scope
 * ```
 * [
 *     'Users' => [
 *         'posts_published' => [
 *             'conditions' => [
 *                 'published' => true
 *             ]
 *         ]
 *     ]
 * ]
 * ```
 *
 * Counter cache using custom find
 * ```
 * [
 *     'Users' => [
 *         'posts_published' => [
 *             'finder' => 'published' // Will be using findPublished()
 *         ]
 *     ]
 * ]
 * ```
 *
 * Counter cache using lambda function returning the count
 * This is equivalent to example #2
 *
 * ```
 * [
 *     'Users' => [
 *         'posts_published' => function (EventInterface $event, EntityInterface $entity, Table $table) {
 *             $query = $table->find('all')->where([
 *                 'published' => true,
 *                 'user_id' => $entity->get('user_id')
 *             ]);
 *             return $query->count();
 *          }
 *     ]
 * ]
 * ```
 *
 * When using a lambda function you can return `false` to disable updating the counter value
 * for the current operation.
 *
 * Ignore updating the field if it is dirty
 * ```
 * [
 *     'Users' => [
 *         'posts_published' => [
 *             'ignoreDirty' => true
 *         ]
 *     ]
 * ]
 * ```
 *
 * You can disable counter updates entirely by sending the `ignoreCounterCache` option
 * to your save operation:
 *
 * ```
 * $this->Articles->save($article, ['ignoreCounterCache' => true]);
 * ```
 */
class Counter_Cache_Behavior extends Behavior
{
    /**
     * Store the fields which should be ignored
     *
     * @var array<string, array<string, bool>>
     */
    protected array $_ignore_dirty = [];
    /**
     * beforeSave callback.
     *
     * Check if a field, which should be ignored, is dirty
     *
     * @param \Cake\Event\EventInterface<\Cake\ORM\Table> $event The beforeSave event that was fired
     * @param \Cake\Datasource\EntityInterface $entity The entity that is going to be saved
     * @param \ArrayObject<string, mixed> $options The options for the query
     */
    public function before_save(Event_Interface $event, Entity_Interface $entity, ArrayObject $options): void
    {
        if (isset($options['ignoreCounterCache']) && $options['ignoreCounterCache'] === true) {
            return;
        }
        foreach ($this->_config as $assoc => $settings) {
            $assoc = $this->_table->get_association($assoc);
            /** @var string|int $field */
            foreach ($settings as $field => $config) {
                if (is_int($field)) {
                    continue;
                }
                $registry_alias = $assoc->get_target()->get_registry_alias();
                $entity_alias = $assoc->get_property();
                /** @var \Cake\Datasource\EntityInterface $assocEntity */
                $assoc_entity = $entity->{$entity_alias};
                if (!is_callable($config) && isset($config['ignoreDirty']) && $config['ignoreDirty'] === true && $assoc_entity->is_dirty($field)) {
                    $this->_ignore_dirty[$registry_alias][$field] = true;
                }
            }
        }
    }
    /**
     * afterSave callback.
     *
     * Makes sure to update counter cache when a new record is created or updated.
     *
     * @param \Cake\Event\EventInterface<\Cake\ORM\Table> $event The afterSave event that was fired.
     * @param \Cake\Datasource\EntityInterface $entity The entity that was saved.
     * @param \ArrayObject<string, mixed> $options The options for the query
     */
    public function after_save(Event_Interface $event, Entity_Interface $entity, ArrayObject $options): void
    {
        if (isset($options['ignoreCounterCache']) && $options['ignoreCounterCache'] === true) {
            return;
        }
        $this->_process_associations($event, $entity);
        $this->_ignore_dirty = [];
    }
    /**
     * afterDelete callback.
     *
     * Makes sure to update counter cache when a record is deleted.
     *
     * @param \Cake\Event\EventInterface<\Cake\ORM\Table> $event The afterDelete event that was fired.
     * @param \Cake\Datasource\EntityInterface $entity The entity that was deleted.
     * @param \ArrayObject<string, mixed> $options The options for the query
     */
    public function after_delete(Event_Interface $event, Entity_Interface $entity, ArrayObject $options): void
    {
        if (isset($options['ignoreCounterCache']) && $options['ignoreCounterCache'] === true) {
            return;
        }
        $this->_process_associations($event, $entity);
    }
    /**
     * Update counter cache for a batch of records.
     *
     * Counter caches configured to use closures will not be updated by the method.
     *
     * @param string|null $assocName The association name to update counter cache for.
     *  If null, all configured associations will be processed.
     * @param int $limit The number of records to update per page/iteration.
     * @param int|null $page The page/iteration number. If null (default), all
     *   records will be updated one page at a time.
     * @since 5.2.0
     */
    public function update_counter_cache(?string $assoc_name = null, int $limit = 100, ?int $page = null): void
    {
        $config = $this->_config;
        if ($assoc_name !== null) {
            $config = [$assoc_name => $config[$assoc_name]];
        }
        foreach ($config as $assoc => $settings) {
            /** @var \Cake\ORM\Association\BelongsTo<\Cake\ORM\Table> $belongsTo */
            $belongs_to = $this->_table->get_association($assoc);
            foreach ($settings as $field => $config) {
                if ($config instanceof Closure) {
                    // Cannot update counter cache which use a closure
                    return;
                }
                if (is_int($field)) {
                    $field = $config;
                    $config = [];
                }
                $this->update_count_for_association($belongs_to, $field, $config, $limit, $page);
            }
        }
    }
    /**
     * Update counter cache for the given association.
     *
     * @param \Cake\ORM\Association\BelongsTo<\Cake\ORM\Table> $assoc The association object.
     * @param string $field Counter cache field.
     * @param array $config Config array.
     * @param int $limit Limit.
     * @param int|null $page Page number.
     */
    protected function update_count_for_association(Belongs_To $assoc, string $field, array $config, int $limit = 100, ?int $page = null): void
    {
        $primary_keys = (array) $assoc->get_binding_key();
        /** @var array<string> $foreignKeys */
        $foreign_keys = (array) $assoc->get_foreign_key();
        $query = $assoc->get_target()->find()->select($primary_keys)->limit($limit);
        foreach ($primary_keys as $key) {
            $query->order_by_asc($key);
        }
        $single_page = $page !== null;
        $page ??= 1;
        do {
            $results = $query->page($page++)->all();
            /** @var \Cake\Datasource\EntityInterface $entity */
            foreach ($results as $entity) {
                $update_conditions = $entity->extract($primary_keys);
                foreach ($update_conditions as $f => $value) {
                    if ($value === null) {
                        $update_conditions[$f . ' IS'] = $value;
                        unset($update_conditions[$f]);
                    }
                }
                $count_conditions = array_combine($foreign_keys, $update_conditions);
                $count = $this->_get_count($config, $count_conditions);
                $assoc->get_target()->update_all([$field => $count], $update_conditions);
            }
        } while (!$single_page && $results->count() === $limit);
    }
    /**
     * Iterate all associations and update counter caches.
     *
     * @param \Cake\Event\EventInterface<\Cake\ORM\Table> $event Event instance.
     * @param \Cake\Datasource\EntityInterface $entity Entity.
     */
    protected function _process_associations(Event_Interface $event, Entity_Interface $entity): void
    {
        foreach ($this->_config as $assoc => $settings) {
            $assoc = $this->_table->get_association($assoc);
            $this->_process_association($event, $entity, $assoc, $settings);
        }
    }
    /**
     * Updates counter cache for a single association
     *
     * @param \Cake\Event\EventInterface<\Cake\ORM\Table> $event Event instance.
     * @param \Cake\Datasource\EntityInterface $entity Entity
     * @param \Cake\ORM\Association $assoc The association object
     * @param array $settings The settings for counter cache for this association
     * @throws \RuntimeException If invalid callable is passed.
     */
    protected function _process_association(Event_Interface $event, Entity_Interface $entity, Association $assoc, array $settings): void
    {
        /** @var array<string> $foreignKeys */
        $foreign_keys = (array) $assoc->get_foreign_key();
        $count_conditions = $entity->extract($foreign_keys);
        foreach ($count_conditions as $field => $value) {
            if ($value === null) {
                $count_conditions[$field . ' IS'] = $value;
                unset($count_conditions[$field]);
            }
        }
        $primary_keys = (array) $assoc->get_binding_key();
        $update_conditions = array_combine($primary_keys, $count_conditions);
        $count_original_conditions = $entity->extract_original_changed($foreign_keys);
        $update_original_conditions = null;
        if ($count_original_conditions !== []) {
            $update_original_conditions = array_combine($primary_keys, $count_original_conditions);
        }
        foreach ($settings as $field => $config) {
            if (is_int($field)) {
                $field = $config;
                $config = [];
            }
            if (isset($this->_ignore_dirty[$assoc->get_target()->get_registry_alias()][$field]) && $this->_ignore_dirty[$assoc->get_target()->get_registry_alias()][$field] === true) {
                continue;
            }
            if ($this->_should_update_count($update_conditions)) {
                if ($config instanceof Closure) {
                    $count = $config($event, $entity, $this->_table, false);
                } else {
                    $count = $this->_get_count($config, $count_conditions);
                }
                if ($count !== false) {
                    $assoc->get_target()->update_all([$field => $count], $update_conditions);
                }
            }
            if ($update_original_conditions && $this->_should_update_count($update_original_conditions)) {
                if ($config instanceof Closure) {
                    $count = $config($event, $entity, $this->_table, true);
                } else {
                    $count = $this->_get_count($config, $count_original_conditions);
                }
                if ($count !== false) {
                    $assoc->get_target()->update_all([$field => $count], $update_original_conditions);
                }
            }
        }
    }
    /**
     * Checks if the count should be updated given a set of conditions.
     *
     * @param array $conditions Conditions to update count.
     * @return bool True if the count update should happen, false otherwise.
     */
    protected function _should_update_count(array $conditions): bool
    {
        return !empty(array_filter($conditions, fn($value) => $value !== null));
    }
    /**
     * Fetches and returns the count for a single field in an association
     *
     * @param array<string, mixed> $config The counter cache configuration for a single field
     * @param array $conditions Additional conditions given to the query
     * @return \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array>|int The query to fetch the number of
     *   relations matching the given config and conditions or the number itself.
     */
    protected function _get_count(array $config, array $conditions): Select_Query|int
    {
        $finder = 'all';
        if (!empty($config['finder'])) {
            $finder = $config['finder'];
            unset($config['finder']);
        }
        $config['conditions'] = array_merge($conditions, $config['conditions'] ?? []);
        $query = $this->_table->find($finder, ...$config);
        if (isset($config['useSubQuery']) && $config['useSubQuery'] === false) {
            return $query->count();
        }
        return $query->select(['count' => $query->func()->count('*')], true)->order_by([], true);
    }
}