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
 * Dump node for Array values.
 */
class Array_Node implements Node_Interface
{
    /**
     * @var array<\Cake\Error\Debug\ArrayItemNode>
     */
    private array $items = [];
    /**
     * Constructor
     *
     * @param array<\Cake\Error\Debug\ArrayItemNode> $items The items for the array
     */
    public function __construct(array $items = [])
    {
        foreach ($items as $item) {
            $this->add($item);
        }
    }
    /**
     * Add an item
     *
     * @param \Cake\Error\Debug\ArrayItemNode $node The item to add.
     */
    public function add(Array_Item_Node $node): void
    {
        $this->items[] = $node;
    }
    /**
     * Get the contained items
     *
     * @return array<\Cake\Error\Debug\ArrayItemNode>
     */
    public function get_value(): array
    {
        return $this->items;
    }
    /**
     * Get Item nodes
     *
     * @return array<\Cake\Error\Debug\ArrayItemNode>
     */
    public function get_children(): array
    {
        return $this->items;
    }
}