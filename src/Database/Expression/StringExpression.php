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
 * @since         4.2.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Database\Expression;

use Cake\Database\Expression_Interface;
use Cake\Database\Value_Binder;
use Closure;
/**
 * String expression with collation.
 */
class String_Expression implements Expression_Interface
{
    /**
     * @param string $string String value
     * @param string $collation String collation
     */
    public function __construct(protected string $string, protected string $collation)
    {
    }
    /**
     * Sets the string collation.
     *
     * @param string $collation String collation
     */
    public function set_collation(string $collation): void
    {
        $this->collation = $collation;
    }
    /**
     * Returns the string collation.
     */
    public function get_collation(): string
    {
        return $this->collation;
    }
    /**
     * @inheritDoc
     */
    public function sql(Value_Binder $binder): string
    {
        $placeholder = $binder->placeholder('c');
        $binder->bind($placeholder, $this->string, 'string');
        return $placeholder . ' COLLATE ' . $this->collation;
    }
    /**
     * @inheritDoc
     */
    public function traverse(Closure $callback): static
    {
        return $this;
    }
}