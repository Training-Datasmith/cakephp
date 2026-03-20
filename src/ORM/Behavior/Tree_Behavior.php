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

use Cake\Collection\Collection_Interface;
use Cake\Collection\Iterator\Tree_Iterator;
use Cake\Database\Exception\Database_Exception;
use Cake\Database\Expression\Identifier_Expression;
use Cake\Database\Expression\Query_Expression;
use Cake\Datasource\Entity_Interface;
use Cake\Datasource\Exception\Record_Not_Found_Exception;
use Cake\Event\Event_Interface;
use Cake\ORM\Behavior;
use Cake\ORM\Query\Delete_Query;
use Cake\ORM\Query\Select_Query;
use Cake\ORM\Query\Update_Query;
use Closure;
/**
 * Makes the table to which this is attached to behave like a nested set and
 * provides methods for managing and retrieving information out of the derived
 * hierarchical structure.
 *
 * Tables attaching this behavior are required to have a column referencing the
 * parent row, and two other numeric columns (lft and rght) where the implicit
 * order will be cached.
 *
 * For more information on what is a nested set and a how it works refer to
 * https://www.sitepoint.com/hierarchical-data-database-2/
 */
class Tree_Behavior extends Behavior
{
    /**
     * Cached copy of the first column in a table's primary key.
     */
    protected string $_primary_key = '';
    /**
     * Default config
     *
     * These are merged with user-provided configuration when the behavior is used.
     *
     * @var array<string, mixed>
     */
    protected array $_default_config = ['implementedFinders' => ['path' => 'findPath', 'children' => 'findChildren', 'treeList' => 'findTreeList'], 'implementedMethods' => ['childCount' => 'childCount', 'moveUp' => 'moveUp', 'moveDown' => 'moveDown', 'recover' => 'recover', 'removeFromTree' => 'removeFromTree', 'getLevel' => 'getLevel', 'formatTreeList' => 'formatTreeList'], 'parent' => 'parent_id', 'left' => 'lft', 'right' => 'rght', 'scope' => null, 'level' => null, 'recoverOrder' => null, 'cascadeCallbacks' => false];
    /**
     * @inheritDoc
     */
    public function initialize(array $config): void
    {
        $this->_config['leftField'] = new Identifier_Expression($this->_config['left']);
        $this->_config['rightField'] = new Identifier_Expression($this->_config['right']);
    }
    /**
     * Before save listener.
     * Transparently manages setting the lft and rght fields if the parent field is
     * included in the parameters to be saved.
     *
     * @param \Cake\Event\EventInterface<\Cake\ORM\Table> $event The beforeSave event that was fired
     * @param \Cake\Datasource\EntityInterface $entity the entity that is going to be saved
     * @throws \Cake\Database\Exception\DatabaseException if the parent to set for the node is invalid
     */
    public function before_save(Event_Interface $event, Entity_Interface $entity): void
    {
        $is_new = $entity->is_new();
        $config = $this->get_config();
        $parent = $entity->get($config['parent']);
        $primary_key = $this->_get_primary_key();
        $dirty = $entity->is_dirty($config['parent']);
        $level = $config['level'];
        if ($parent && $entity->get($primary_key) === $parent) {
            throw new Database_Exception("Cannot set a node's parent as itself.");
        }
        if ($is_new) {
            if ($parent) {
                $parent_node = $this->_get_node($parent);
                $edge = $parent_node->get($config['right']);
                $entity->set($config['left'], $edge);
                $entity->set($config['right'], $edge + 1);
                $this->_sync(2, '+', ">= {$edge}");
                if ($level) {
                    $entity->set($level, $parent_node[$level] + 1);
                }
                return;
            }
            $edge = $this->_get_max();
            $entity->set($config['left'], $edge + 1);
            $entity->set($config['right'], $edge + 2);
            if ($level) {
                $entity->set($level, 0);
            }
            return;
        }
        if ($dirty) {
            if ($parent) {
                $this->_set_parent($entity, $parent);
                if ($level) {
                    $parent_node = $this->_get_node($parent);
                    $entity->set($level, $parent_node[$level] + 1);
                }
                return;
            }
            $this->_set_as_root($entity);
            if ($level) {
                $entity->set($level, 0);
            }
        }
    }
    /**
     * After save listener.
     *
     * Manages updating level of descendants of currently saved entity.
     *
     * @param \Cake\Event\EventInterface<\Cake\ORM\Table> $event The afterSave event that was fired
     * @param \Cake\Datasource\EntityInterface $entity the entity that is going to be saved
     */
    public function after_save(Event_Interface $event, Entity_Interface $entity): void
    {
        if (!$this->_config['level'] || $entity->is_new()) {
            return;
        }
        $this->_set_children_level($entity);
    }
    /**
     * Set level for descendants.
     *
     * @param \Cake\Datasource\EntityInterface $entity The entity whose descendants need to be updated.
     */
    protected function _set_children_level(Entity_Interface $entity): void
    {
        $config = $this->get_config();
        if ($entity->get($config['left']) + 1 === $entity->get($config['right'])) {
            return;
        }
        $primary_key = $this->_get_primary_key();
        $primary_key_value = $entity->get($primary_key);
        $depths = [$primary_key_value => $entity->get($config['level'])];
        /** @var \Traversable<\Cake\Datasource\EntityInterface> $children */
        $children = $this->_table->find('children', for: $primary_key_value, fields: [$this->_get_primary_key(), $config['parent'], $config['level']], order: $config['left'])->all();
        foreach ($children as $node) {
            $parent_id_value = $node->get($config['parent']);
            $depth = $depths[$parent_id_value] + 1;
            $depths[$node->get($primary_key)] = $depth;
            $this->_table->update_all([$config['level'] => $depth], [$primary_key => $node->get($primary_key)]);
        }
    }
    /**
     * Also deletes the nodes in the subtree of the entity to be delete
     *
     * @param \Cake\Event\EventInterface<\Cake\ORM\Table> $event The beforeDelete event that was fired
     * @param \Cake\Datasource\EntityInterface $entity The entity that is going to be saved
     */
    public function before_delete(Event_Interface $event, Entity_Interface $entity): void
    {
        $config = $this->get_config();
        $this->_ensure_fields($entity);
        $left = $entity->get($config['left']);
        $right = $entity->get($config['right']);
        $diff = (int) ($right - $left + 1);
        if ($diff > 2) {
            if ($this->get_config('cascadeCallbacks')) {
                $query = $this->_scope($this->_table->query())->where(fn(Query_Expression $exp) => $exp->gte($config['leftField'], $left + 1)->lte($config['leftField'], $right - 1));
                $entities = $query->to_array();
                foreach ($entities as $entity_to_delete) {
                    $this->_table->delete($entity_to_delete, ['atomic' => false]);
                }
            } else {
                $this->_scope($this->_table->delete_query())->where(fn(Query_Expression $exp) => $exp->gte($config['leftField'], $left + 1)->lte($config['leftField'], $right - 1))->execute();
            }
        }
        $this->_sync($diff, '-', "> {$right}");
    }
    /**
     * Sets the correct left and right values for the passed entity so it can be
     * updated to a new parent. It also makes the hole in the tree so the node
     * move can be done without corrupting the structure.
     *
     * @param \Cake\Datasource\EntityInterface $entity The entity to re-parent
     * @param mixed $parent the id of the parent to set
     * @throws \Cake\Database\Exception\DatabaseException if the parent to set to the entity is not valid
     */
    protected function _set_parent(Entity_Interface $entity, mixed $parent): void
    {
        $config = $this->get_config();
        $parent_node = $this->_get_node($parent);
        $this->_ensure_fields($entity);
        $parent_left = $parent_node->get($config['left']);
        $parent_right = $parent_node->get($config['right']);
        $right = $entity->get($config['right']);
        $left = $entity->get($config['left']);
        if ($parent_left > $left && $parent_left < $right) {
            throw new Database_Exception(sprintf('Cannot use node `%s` as parent for entity `%s`.', $parent, $entity->get($this->_get_primary_key())));
        }
        // Values for moving to the left
        $diff = $right - $left + 1;
        $target_left = $parent_right;
        $target_right = $diff + $parent_right - 1;
        $min = $parent_right;
        $max = $left - 1;
        if ($left < $target_left) {
            // Moving to the right
            $target_left = $parent_right - $diff;
            $target_right = $parent_right - 1;
            $min = $right + 1;
            $max = $parent_right - 1;
            $diff *= -1;
        }
        if ($right - $left > 1) {
            // Correcting internal subtree
            $internal_left = $left + 1;
            $internal_right = $right - 1;
            $this->_sync($target_left - $left, '+', "BETWEEN {$internal_left} AND {$internal_right}", true);
        }
        $this->_sync($diff, '+', "BETWEEN {$min} AND {$max}");
        if ($right - $left > 1) {
            $this->_unmark_internal_tree();
        }
        // Allocating new position
        $entity->set($config['left'], $target_left);
        $entity->set($config['right'], $target_right);
    }
    /**
     * Updates the left and right column for the passed entity so it can be set as
     * a new root in the tree. It also modifies the ordering in the rest of the tree
     * so the structure remains valid
     *
     * @param \Cake\Datasource\EntityInterface $entity The entity to set as a new root
     */
    protected function _set_as_root(Entity_Interface $entity): void
    {
        $config = $this->get_config();
        $edge = $this->_get_max();
        $this->_ensure_fields($entity);
        $right = $entity->get($config['right']);
        $left = $entity->get($config['left']);
        $diff = $right - $left;
        if ($right - $left > 1) {
            //Correcting internal subtree
            $internal_left = $left + 1;
            $internal_right = $right - 1;
            $this->_sync($edge - $diff - $left, '+', "BETWEEN {$internal_left} AND {$internal_right}", true);
        }
        $this->_sync($diff + 1, '-', "BETWEEN {$right} AND {$edge}");
        if ($right - $left > 1) {
            $this->_unmark_internal_tree();
        }
        $entity->set($config['left'], $edge - $diff);
        $entity->set($config['right'], $edge);
    }
    /**
     * Helper method used to invert the sign of the left and right columns that are
     * less than 0. They were set to negative values before so their absolute value
     * wouldn't change while performing other tree transformations.
     */
    protected function _unmark_internal_tree(): void
    {
        $config = $this->get_config();
        $this->_table->update_all(function (Query_Expression $exp) use ($config) {
            $left_inverse = clone $exp;
            $left_inverse->set_conjunction('*')->add('-1');
            $right_inverse = clone $left_inverse;
            return $exp->eq($config['leftField'], $left_inverse->add($config['leftField']))->eq($config['rightField'], $right_inverse->add($config['rightField']));
        }, fn(Query_Expression $exp) => $exp->lt($config['leftField'], 0));
    }
    /**
     * Custom finder method which can be used to return the list of nodes from the root
     * to a specific node in the tree. This custom finder requires that the key 'for'
     * is passed in the options containing the id of the node to get its path for.
     *
     * @param \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $query The constructed query to modify
     * @param string|int $for The path to find or an array of options with `for`.
     * @return \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array>
     * @throws \InvalidArgumentException If the 'for' key is missing in options
     */
    public function find_path(Select_Query $query, string|int $for): Select_Query
    {
        $config = $this->get_config();
        [$left, $right] = array_map($this->_table->alias_field(...), [$config['left'], $config['right']]);
        $node = $this->_table->get($for, select: [$left, $right]);
        return $this->_scope($query)->where(["{$left} <=" => $node->get($config['left']), "{$right} >=" => $node->get($config['right'])])->order_by([$left => 'ASC']);
    }
    /**
     * Get the number of children nodes.
     *
     * @param \Cake\Datasource\EntityInterface $node The entity to count children for
     * @param bool $direct whether to count all nodes in the subtree or just
     * direct children
     * @return int Number of children nodes.
     */
    public function child_count(Entity_Interface $node, bool $direct = false): int
    {
        $config = $this->get_config();
        $parent = $this->_table->alias_field($config['parent']);
        if ($direct) {
            return $this->_scope($this->_table->find())->where([$parent => $node->get($this->_get_primary_key())])->count();
        }
        $this->_ensure_fields($node);
        return ($node->get($config['right']) - $node->get($config['left']) - 1) / 2;
    }
    /**
     * Get the children nodes of the current model.
     *
     * If the direct option is set to true, only the direct children are returned
     * (based upon the parent_id field).
     *
     * @param \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $query Query.
     * @param string|int $for The id of the record to read. Can also be an array of options.
     * @param bool $direct Whether to return only the direct (true) or all children (false).
     * @return \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array>
     * @throws \InvalidArgumentException When the 'for' key is not passed in $options
     */
    public function find_children(Select_Query $query, int|string $for, bool $direct = false): Select_Query
    {
        $config = $this->get_config();
        [$parent, $left, $right] = array_map($this->_table->alias_field(...), [$config['parent'], $config['left'], $config['right']]);
        if ($query->clause('order') === null) {
            $query->order_by([$left => 'ASC']);
        }
        if ($direct) {
            return $this->_scope($query)->where([$parent => $for]);
        }
        $node = $this->_get_node($for);
        return $this->_scope($query)->where(["{$right} <" => $node->get($config['right']), "{$left} >" => $node->get($config['left'])]);
    }
    /**
     * Gets a representation of the elements in the tree as a flat list where the keys are
     * the primary key for the table and the values are the display field for the table.
     * Values are prefixed to visually indicate relative depth in the tree.
     *
     * @param \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $query Query.
     * @param \Closure|string|null $keyPath A dot separated path to fetch the field to use for the array key, or a closure to
     *   return the key out of the provided row.
     * @param \Closure|string|null $valuePath A dot separated path to fetch the field to use for the array value, or a closure to
     *   return the value out of the provided row.
     * @param string|null $spacer A string to be used as prefix for denoting the depth in the tree for each item.
     * @return \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array>
     */
    public function find_tree_list(Select_Query $query, Closure|string|null $key_path = null, Closure|string|null $value_path = null, ?string $spacer = null): Select_Query
    {
        $left = $this->_table->alias_field($this->get_config('left'));
        $results = $this->_scope($query)->find('threaded', parentField: $this->get_config('parent'), order: [$left => 'ASC']);
        return $this->format_tree_list($results, $key_path, $value_path, $spacer);
    }
    /**
     * Formats query as a flat list where the keys are the primary key for the table
     * and the values are the display field for the table. Values are prefixed to visually
     * indicate relative depth in the tree.
     *
     * @param \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $query The query object to format.
     * @param \Closure|string|null $keyPath A dot separated path to the field that will be the result array key, or a closure to
     *   return the key from the provided row.
     * @param \Closure|string|null $valuePath A dot separated path to the field that is the array's value, or a closure to
     *   return the value from the provided row.
     * @param string|null $spacer A string to be used as prefix for denoting the depth in the tree for each item.
     * @return \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> Augmented query.
     */
    public function format_tree_list(Select_Query $query, Closure|string|null $key_path = null, Closure|string|null $value_path = null, ?string $spacer = null): Select_Query
    {
        return $query->format_results(function (Collection_Interface $results) use ($key_path, $value_path, $spacer): \Cake\Collection\Iterator\Tree_Printer {
            $key_path ??= $this->_get_primary_key();
            $value_path ??= $this->_table->get_display_field();
            $spacer ??= '_';
            $nested = $results->list_nested();
            assert($nested instanceof Tree_Iterator);
            assert(is_callable($value_path) || is_string($value_path));
            return $nested->printer($value_path, $key_path, $spacer);
        });
    }
    /**
     * Removes the current node from the tree, by positioning it as a new root
     * and re-parents all children up one level.
     *
     * Note that the node will not be deleted just moved away from its current position
     * without moving its children with it.
     *
     * @param \Cake\Datasource\EntityInterface $node The node to remove from the tree
     * @return \Cake\Datasource\EntityInterface|false the node after being removed from the tree or
     * false on error
     */
    public function remove_from_tree(Entity_Interface $node): Entity_Interface|false
    {
        return $this->_table->get_connection()->transactional(function () use ($node): \Cake\Datasource\Entity_Interface|false {
            $this->_ensure_fields($node);
            return $this->_remove_from_tree($node);
        });
    }
    /**
     * Helper function containing the actual code for removeFromTree
     *
     * @param \Cake\Datasource\EntityInterface $node The node to remove from the tree
     * @return \Cake\Datasource\EntityInterface|false the node after being removed from the tree or
     * false on error
     */
    protected function _remove_from_tree(Entity_Interface $node): Entity_Interface|false
    {
        $config = $this->get_config();
        $left = $node->get($config['left']);
        $right = $node->get($config['right']);
        $parent = $node->get($config['parent']);
        $node->set($config['parent']);
        if ($right - $left === 1) {
            return $this->_table->save($node);
        }
        $primary = $this->_get_primary_key();
        $this->_table->update_all([$config['parent'] => $parent], [$config['parent'] => $node->get($primary)]);
        $this->_sync(1, '-', 'BETWEEN ' . ($left + 1) . ' AND ' . ($right - 1));
        $this->_sync(2, '-', "> {$right}");
        $edge = $this->_get_max();
        $node->set($config['left'], $edge + 1);
        $node->set($config['right'], $edge + 2);
        $fields = [$config['parent'], $config['left'], $config['right']];
        $this->_table->update_all($node->extract($fields), [$primary => $node->get($primary)]);
        foreach ($fields as $field) {
            $node->set_dirty($field, false);
        }
        return $node;
    }
    /**
     * Reorders the node without changing its parent.
     *
     * If the node is the first child, or is a top level node with no previous node
     * this method will return the same node without any changes
     *
     * @param \Cake\Datasource\EntityInterface $node The node to move
     * @param int|true $number How many places to move the node, or true to move to first position
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When node was not found
     * @return \Cake\Datasource\EntityInterface|false $node The node after being moved or false if `$number` is < 1
     */
    public function move_up(Entity_Interface $node, int|true $number = 1): Entity_Interface|false
    {
        if ($number < 1) {
            return false;
        }
        return $this->_table->get_connection()->transactional(function () use ($node, $number): \Cake\Datasource\Entity_Interface {
            $this->_ensure_fields($node);
            return $this->_move_up($node, $number);
        });
    }
    /**
     * Helper function used with the actual code for moveUp
     *
     * @param \Cake\Datasource\EntityInterface $node The node to move
     * @param int|true $number How many places to move the node, or true to move to first position
     * @return \Cake\Datasource\EntityInterface $node The node after being moved
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When node was not found
     */
    protected function _move_up(Entity_Interface $node, int|true $number): Entity_Interface
    {
        $config = $this->get_config();
        [$parent, $left, $right] = [$config['parent'], $config['left'], $config['right']];
        [$node_parent, $node_left, $node_right] = array_values($node->extract([$parent, $left, $right]));
        $target_node = null;
        if ($number !== true) {
            /** @var \Cake\Datasource\EntityInterface|null $targetNode */
            $target_node = $this->_scope($this->_table->find())->select([$left, $right])->where(["{$parent} IS" => $node_parent])->where(fn(Query_Expression $exp) => $exp->lt($config['rightField'], $node_left))->order_by_desc($config['leftField'])->offset($number - 1)->limit(1)->first();
        }
        if (!$target_node) {
            /** @var \Cake\Datasource\EntityInterface|null $targetNode */
            $target_node = $this->_scope($this->_table->find())->select([$left, $right])->where(["{$parent} IS" => $node_parent])->where(fn(Query_Expression $exp) => $exp->lt($config['rightField'], $node_left))->order_by_asc($config['leftField'])->limit(1)->first();
            if (!$target_node) {
                return $node;
            }
        }
        [$target_left] = array_values($target_node->extract([$left, $right]));
        $edge = $this->_get_max();
        $left_boundary = $target_left;
        $right_boundary = $node_left - 1;
        $node_to_edge = $edge - $node_left + 1;
        $shift = $node_right - $node_left + 1;
        $node_to_hole = $edge - $left_boundary + 1;
        $this->_sync($node_to_edge, '+', "BETWEEN {$node_left} AND {$node_right}");
        $this->_sync($shift, '+', "BETWEEN {$left_boundary} AND {$right_boundary}");
        $this->_sync($node_to_hole, '-', "> {$edge}");
        /** @var string $left */
        $node->set($left, $target_left);
        /** @var string $right */
        $node->set($right, $target_left + $node_right - $node_left);
        $node->set_dirty($left, false);
        $node->set_dirty($right, false);
        return $node;
    }
    /**
     * Reorders the node without changing the parent.
     *
     * If the node is the last child, or is a top level node with no subsequent node
     * this method will return the same node without any changes
     *
     * @param \Cake\Datasource\EntityInterface $node The node to move
     * @param int|true $number How many places to move the node or true to move to last position
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When node was not found
     * @return \Cake\Datasource\EntityInterface|false the entity after being moved or false if `$number` is < 1
     */
    public function move_down(Entity_Interface $node, int|true $number = 1): Entity_Interface|false
    {
        if ($number < 1) {
            return false;
        }
        return $this->_table->get_connection()->transactional(function () use ($node, $number): \Cake\Datasource\Entity_Interface {
            $this->_ensure_fields($node);
            return $this->_move_down($node, $number);
        });
    }
    /**
     * Helper function used with the actual code for moveDown
     *
     * @param \Cake\Datasource\EntityInterface $node The node to move
     * @param int|true $number How many places to move the node, or true to move to last position
     * @return \Cake\Datasource\EntityInterface $node The node after being moved
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When node was not found
     */
    protected function _move_down(Entity_Interface $node, int|true $number): Entity_Interface
    {
        $config = $this->get_config();
        [$parent, $left, $right] = [$config['parent'], $config['left'], $config['right']];
        assert(is_string($parent) && is_string($left) && is_string($right));
        [$node_parent, $node_left, $node_right] = array_values($node->extract([$parent, $left, $right]));
        $target_node = null;
        if ($number !== true) {
            /** @var \Cake\Datasource\EntityInterface|null $targetNode */
            $target_node = $this->_scope($this->_table->find())->select([$left, $right])->where(["{$parent} IS" => $node_parent])->where(fn(Query_Expression $exp) => $exp->gt($config['leftField'], $node_right))->order_by_asc($config['leftField'])->offset($number - 1)->limit(1)->first();
        }
        if (!$target_node) {
            /** @var \Cake\Datasource\EntityInterface|null $targetNode */
            $target_node = $this->_scope($this->_table->find())->select([$left, $right])->where(["{$parent} IS" => $node_parent])->where(fn(Query_Expression $exp) => $exp->gt($config['leftField'], $node_right))->order_by_desc($config['leftField'])->limit(1)->first();
            if (!$target_node) {
                return $node;
            }
        }
        [, $target_right] = array_values($target_node->extract([$left, $right]));
        $edge = $this->_get_max();
        $left_boundary = $node_right + 1;
        $right_boundary = $target_right;
        $node_to_edge = $edge - $node_left + 1;
        $shift = $node_right - $node_left + 1;
        $node_to_hole = $edge - $right_boundary + $shift;
        $this->_sync($node_to_edge, '+', "BETWEEN {$node_left} AND {$node_right}");
        $this->_sync($shift, '-', "BETWEEN {$left_boundary} AND {$right_boundary}");
        $this->_sync($node_to_hole, '-', "> {$edge}");
        $node->set($left, $target_right - ($node_right - $node_left));
        $node->set($right, $target_right);
        $node->set_dirty($left, false);
        $node->set_dirty($right, false);
        return $node;
    }
    /**
     * Returns a single node from the tree from its primary key
     *
     * @param mixed $id Record id.
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When node was not found
     */
    protected function _get_node(mixed $id): Entity_Interface
    {
        $config = $this->get_config();
        [$parent, $left, $right] = [$config['parent'], $config['left'], $config['right']];
        $primary_key = $this->_get_primary_key();
        $fields = [$parent, $left, $right];
        if ($config['level']) {
            $fields[] = $config['level'];
        }
        $node = $this->_scope($this->_table->find())->select($fields)->where([$this->_table->alias_field($primary_key) => $id])->first();
        if (!$node) {
            throw new Record_Not_Found_Exception(sprintf('Node `%s` was not found in the tree.', $id));
        }
        return $node;
    }
    /**
     * Recovers the lft and right column values out of the hierarchy defined by the
     * parent column.
     */
    public function recover(): void
    {
        $this->_table->get_connection()->transactional(function (): void {
            $this->_recover_tree();
        });
    }
    /**
     * Recursive method used to recover a single level of the tree
     *
     * @param int $lftRght The starting lft/rght value
     * @param mixed $parentId the parent id of the level to be recovered
     * @param int $level Node level
     * @return int The next lftRght value
     */
    protected function _recover_tree(int $lft_rght = 1, mixed $parent_id = null, int $level = 0): int
    {
        $config = $this->get_config();
        [$parent, $left, $right] = [$config['parent'], $config['left'], $config['right']];
        $primary_key = $this->_get_primary_key();
        $order = $config['recoverOrder'] ?: $primary_key;
        $nodes = $this->_scope($this->_table->select_query())->select($primary_key)->where([$parent . ' IS' => $parent_id])->order_by($order)->disable_hydration()->all();
        foreach ($nodes as $node) {
            $node_lft = $lft_rght++;
            $lft_rght = $this->_recover_tree($lft_rght, $node[$primary_key], $level + 1);
            $fields = [$left => $node_lft, $right => $lft_rght++];
            if ($config['level']) {
                $fields[$config['level']] = $level;
            }
            $this->_table->update_all($fields, [$primary_key => $node[$primary_key]]);
        }
        return $lft_rght;
    }
    /**
     * Returns the maximum index value in the table.
     */
    protected function _get_max(): int
    {
        $field = $this->_config['right'];
        $right_field = $this->_config['rightField'];
        $edge = $this->_scope($this->_table->find())->select([$field])->order_by_desc($right_field)->first();
        if ($edge === null || empty($edge[$field])) {
            return 0;
        }
        return $edge[$field];
    }
    /**
     * Auxiliary function used to automatically alter the value of both the left and
     * right columns by a certain amount that match the passed conditions
     *
     * @param int $shift the value to use for operating the left and right columns
     * @param string $dir The operator to use for shifting the value (+/-)
     * @param string $conditions a SQL snipped to be used for comparing left or right
     * against it.
     * @param bool $mark whether to mark the updated values so that they can not be
     * modified by future calls to this function.
     */
    protected function _sync(int $shift, string $dir, string $conditions, bool $mark = false): void
    {
        $config = $this->_config;
        /** @var \Cake\Database\Expression\IdentifierExpression $field */
        foreach ([$config['leftField'], $config['rightField']] as $field) {
            $query = $this->_scope($this->_table->update_query());
            $exp = $query->expr();
            $movement = clone $exp;
            $movement->add($field)->add((string) $shift)->set_conjunction($dir);
            $inverse = clone $exp;
            $movement = $mark ? $inverse->add($movement)->set_conjunction('*')->add('-1') : $movement;
            $where = clone $exp;
            $where->add($field)->add($conditions)->set_conjunction('');
            $query->set($exp->eq($field, $movement))->where($where)->execute();
        }
    }
    /**
     * Alters the passed query so that it only returns scoped records as defined
     * in the tree configuration.
     *
     * @template TQuery of \Cake\ORM\Query\SelectQuery|\Cake\ORM\Query\UpdateQuery|\Cake\ORM\Query\DeleteQuery
     * @param TQuery $query the Query to modify
     * @return TQuery
     */
    protected function _scope(Select_Query|Update_Query|Delete_Query $query): Select_Query|Update_Query|Delete_Query
    {
        $scope = $this->get_config('scope');
        if ($scope === null) {
            return $query;
        }
        return $query->where($scope);
    }
    /**
     * Ensures that the provided entity contains non-empty values for the left and
     * right fields
     *
     * @param \Cake\Datasource\EntityInterface $entity The entity to ensure fields for
     */
    protected function _ensure_fields(Entity_Interface $entity): void
    {
        $config = $this->get_config();
        $fields = [$config['left'], $config['right']];
        $values = array_filter($entity->extract($fields));
        if (count($values) === count($fields)) {
            return;
        }
        $fresh = $this->_table->get($entity->get($this->_get_primary_key()));
        // @phpstan-ignore function.alreadyNarrowedType (patch method available on EntityInterface)
        if (method_exists($entity, 'patch')) {
            $entity->patch($fresh->extract($fields), ['guard' => false]);
        } else {
            $entity->set($fresh->extract($fields), ['guard' => false]);
        }
        foreach ($fields as $field) {
            $entity->set_dirty($field, false);
        }
    }
    /**
     * Returns a single string value representing the primary key of the attached table
     */
    protected function _get_primary_key(): string
    {
        if (!$this->_primary_key) {
            $primary_key = (array) $this->_table->get_primary_key();
            $this->_primary_key = $primary_key[0];
        }
        return $this->_primary_key;
    }
    /**
     * Returns the depth level of a node in the tree.
     *
     * @param \Cake\Datasource\EntityInterface|string|int $entity The entity or primary key get the level of.
     * @return int|false Integer of the level or false if the node does not exist.
     */
    public function get_level(Entity_Interface|string|int $entity): int|false
    {
        $primary_key = $this->_get_primary_key();
        $id = $entity;
        if ($entity instanceof Entity_Interface) {
            $id = $entity->get($primary_key);
        }
        $config = $this->get_config();
        $entity = $this->_table->find('all')->select([$config['left'], $config['right']])->where([$primary_key => $id])->first();
        if ($entity === null) {
            return false;
        }
        $query = $this->_table->find('all')->where([$config['left'] . ' <' => $entity[$config['left']], $config['right'] . ' >' => $entity[$config['right']]]);
        return $this->_scope($query)->count();
    }
}