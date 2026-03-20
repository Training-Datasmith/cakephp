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

/**
 * Dump node for Array Items.
 */
class Array_Item_Node implements Node_Interface
{
    /**
     * Constructor
     *
     * @param \Cake\Error\Debug\NodeInterface $key The node for the item key
     * @param \Cake\Error\Debug\NodeInterface $value The node for the array value
     */
    public function __construct(private readonly Node_Interface $key, private readonly Node_Interface $value)
    {
    }
    /**
     * Get the value
     */
    public function get_value(): Node_Interface
    {
        return $this->value;
    }
    /**
     * Get the key
     */
    public function get_key(): Node_Interface
    {
        return $this->key;
    }
    /**
     * @inheritDoc
     */
    public function get_children(): array
    {
        return [$this->value];
    }
}