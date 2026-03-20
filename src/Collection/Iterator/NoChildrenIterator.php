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
/**
 * An iterator that can be used as an argument for other iterators that require
 * a RecursiveIterator but do not want children. This iterator will
 * always behave as having no nested items.
 *
 * @template TKey
 * @template TValue
 * @extends \Cake\Collection\Collection<TKey, TValue>
 * @implements \RecursiveIterator<TKey, TValue>
 */
class No_Children_Iterator extends Collection implements Recursive_Iterator
{
    /**
     * Returns false as there are no children iterators in this collection
     */
    public function has_children(): bool
    {
        return false;
    }
    /**
     * Returns a self instance without any elements.
     *
     * @return \RecursiveIterator<mixed, mixed>
     */
    public function get_children(): Recursive_Iterator
    {
        return new static([]);
    }
}