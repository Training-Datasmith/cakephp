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

use function Cake\Core\Plugin_Split;
use Cake\Datasource\Entity_Interface;
use Cake\ORM\Association;
use Cake\ORM\Association\Loader\Select_Loader;
use Cake\ORM\Table;
use Cake\Utility\Inflector;
use Closure;
/**
 * Represents an 1 - N relationship where the source side of the relation is
 * related to only one record in the target table.
 *
 * An example of a BelongsTo association would be Article belongs to Author.
 *
 * @template T of \Cake\ORM\Table
 * @mixin T
 */
class Belongs_To extends Association
{
    /**
     * Valid strategies for this type of association
     *
     * @var array<string>
     */
    protected array $_valid_strategies = [self::STRATEGY_JOIN, self::STRATEGY_SELECT];
    /**
     * @inheritDoc
     */
    public function get_foreign_key(): array|string|false
    {
        return $this->_foreign_key ??= $this->_model_key($this->get_target()->get_alias());
    }
    /**
     * Sets the name of the field representing the foreign key to the target table.
     *
     * @param array<string>|string|false $key the key or keys to be used to link both tables together, if set to `false`
     *  no join conditions will be generated automatically.
     * @return $this
     */
    public function set_foreign_key(array|string|false $key): static
    {
        $this->_foreign_key = $key;
        return $this;
    }
    /**
     * Handle cascading deletes.
     *
     * BelongsTo associations are never cleared in a cascading delete scenario.
     *
     * @param \Cake\Datasource\EntityInterface $entity The entity that started the cascaded delete.
     * @param array<string, mixed> $options The options for the original delete.
     * @return bool Success.
     */
    public function cascade_delete(Entity_Interface $entity, array $options = []): bool
    {
        return true;
    }
    /**
     * Returns default property name based on association name.
     */
    protected function _property_name(): string
    {
        [, $name] = plugin_split($this->_name);
        return Inflector::underscore(Inflector::singularize($name));
    }
    /**
     * Returns whether the passed table is the owning side for this
     * association. This means that rows in the 'target' table would miss important
     * or required information if the row in 'source' did not exist.
     *
     * @param \Cake\ORM\Table $side The potential Table with ownership
     */
    public function is_owning_side(Table $side): bool
    {
        return $side === $this->get_target();
    }
    /**
     * Get the relationship type.
     */
    public function type(): string
    {
        return self::MANY_TO_ONE;
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
     */
    public function save_associated(Entity_Interface $entity, array $options = []): Entity_Interface|false
    {
        $target_entity = $entity->get($this->get_property());
        if (!$target_entity instanceof Entity_Interface) {
            return $entity;
        }
        $table = $this->get_target();
        $target_entity = $table->save($target_entity, $options);
        if (!$target_entity) {
            return false;
        }
        /** @var array<string> $foreignKeys */
        $foreign_keys = (array) $this->get_foreign_key();
        $properties = array_combine($foreign_keys, $target_entity->extract((array) $this->get_binding_key()));
        // @phpstan-ignore function.alreadyNarrowedType (patch method available on EntityInterface)
        if (method_exists($entity, 'patch')) {
            $entity = $entity->patch($properties, ['guard' => false]);
        } else {
            $entity->set($properties, ['guard' => false]);
        }
        return $entity;
    }
    /**
     * @inheritDoc
     */
    public function eager_loader(array $options): Closure
    {
        $loader = new Select_Loader(['alias' => $this->get_alias(), 'sourceAlias' => $this->get_source()->get_alias(), 'targetAlias' => $this->get_target()->get_alias(), 'foreignKey' => $this->get_foreign_key(), 'bindingKey' => $this->get_binding_key(), 'strategy' => $this->get_strategy(), 'associationType' => $this->type(), 'finder' => $this->find(...)]);
        return $loader->build_eager_loader($options);
    }
}