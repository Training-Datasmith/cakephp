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
namespace Cake\Collection\Iterator;

use Cake\Collection\Collection;
use Recursive_Iterator;
use Traversable;
/**
 * A type of collection that is aware of nested items and exposes methods to
 * check or retrieve them
 *
 * @extends \Cake\Collection\Collection<mixed, mixed>
 * @implements \RecursiveIterator<mixed, mixed>
 */
class Nest_Iterator extends Collection implements Recursive_Iterator
{
    /**
     * The name of the property that contains the nested items for each element
     *
     * @var callable|string
     */
    protected $_nest_key;
    /**
     * Constructor
     *
     * @param iterable $items Collection items.
     * @param callable|string $nestKey the property that contains the nested items
     * If a callable is passed, it should return the children for the passed item
     */
    public function __construct(iterable $items, callable|string $nest_key)
    {
        parent::__construct($items);
        $this->_nest_key = $nest_key;
    }
    /**
     * Returns a traversable containing the children for the current item
     *
     * @return \RecursiveIterator<mixed, mixed>
     */
    public function get_children(): Recursive_Iterator
    {
        $property = $this->_property_extractor($this->_nest_key);
        return new static($property($this->current()), $this->_nest_key);
    }
    /**
     * Returns true if there is an array or a traversable object stored under the
     * configured nestKey for the current item
     */
    public function has_children(): bool
    {
        $property = $this->_property_extractor($this->_nest_key);
        $children = $property($this->current());
        if (is_array($children)) {
            return $children !== [];
        }
        return $children instanceof Traversable;
    }
}