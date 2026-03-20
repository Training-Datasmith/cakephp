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
 * @since         4.1.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Error\Debug;

use Spl_Object_Storage;
/**
 * Context tracking for Debugger::exportVar()
 *
 * This class is used by Debugger to track element depth, and
 * prevent cyclic references from being traversed multiple times.
 *
 * @internal
 */
class Debug_Context
{
    private int $depth = 0;
    /**
     * @var \SplObjectStorage<object, int>
     */
    private Spl_Object_Storage $refs;
    /**
     * Constructor
     *
     * @param int $maxDepth The desired depth of dump output.
     */
    public function __construct(private int $max_depth)
    {
        $this->refs = new Spl_Object_Storage();
    }
    /**
     * Return a clone with increased depth.
     */
    public function with_added_depth(): static
    {
        $new = clone $this;
        $new->depth += 1;
        return $new;
    }
    /**
     * Get the remaining depth levels
     */
    public function remaining_depth(): int
    {
        return $this->max_depth - $this->depth;
    }
    /**
     * Get the reference ID for an object.
     *
     * If this object does not exist in the reference storage,
     * it will be added and the id will be returned.
     *
     * @param object $object The object to get a reference for.
     */
    public function get_reference_id(object $object): int
    {
        if ($this->refs->offsetExists($object)) {
            return $this->refs[$object];
        }
        $ref_id = $this->refs->count();
        $this->refs->offsetSet($object, $ref_id);
        return $ref_id;
    }
    /**
     * Check whether an object has been seen before.
     *
     * @param object $object The object to get a reference for.
     */
    public function has_reference(object $object): bool
    {
        return $this->refs->offsetExists($object);
    }
}