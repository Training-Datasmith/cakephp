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
namespace Cake\Database;

/*
 * Represents a class that holds a TypeMap object
 */
/**
 * Trait TypeMapTrait
 */
trait Type_Map_Trait
{
    protected ?Type_Map $_type_map = null;
    /**
     * Creates a new TypeMap if $typeMap is an array, otherwise exchanges it for the given one.
     *
     * @param \Cake\Database\TypeMap|array<int|string, string> $typeMap Creates a TypeMap if array, otherwise sets the given TypeMap
     * @return $this
     */
    public function set_type_map(Type_Map|array $type_map)
    {
        $this->_type_map = is_array($type_map) ? new Type_Map($type_map) : $type_map;
        return $this;
    }
    /**
     * Returns the existing type map.
     */
    public function get_type_map(): Type_Map
    {
        return $this->_type_map ??= new Type_Map();
    }
    /**
     * Overwrite the default type mappings for fields
     * in the implementing object.
     *
     * This method is useful if you need to set type mappings that are shared across
     * multiple functions/expressions in a query.
     *
     * To add a default without overwriting existing ones
     * use `getTypeMap()->addDefaults()`
     *
     * @param array<int|string, string> $types The array of types to set.
     * @return $this
     * @see \Cake\Database\TypeMap::setDefaults()
     */
    public function set_default_types(array $types)
    {
        $this->get_type_map()->set_defaults($types);
        return $this;
    }
    /**
     * Gets default types of current type map.
     *
     * @return array<int|string, string>
     */
    public function get_default_types(): array
    {
        return $this->get_type_map()->get_defaults();
    }
}