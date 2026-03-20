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
namespace Cake\Database\Expression;

use Cake\Database\Expression_Interface;
use Cake\Database\Type\Expression_Type_Caster_Trait;
use Cake\Database\Value_Binder;
use Closure;
/**
 * An expression object that represents a SQL BETWEEN snippet
 */
class Between_Expression implements Expression_Interface, Field_Interface
{
    use Expression_Type_Caster_Trait;
    use Field_Trait;
    /**
     * The first value in the expression
     */
    protected mixed $_from;
    /**
     * The second value in the expression
     */
    protected mixed $_to;
    /**
     * The data type for the from and to arguments
     */
    protected mixed $_type;
    /**
     * Constructor
     *
     * @param \Cake\Database\ExpressionInterface|string $field The field name to compare for values in between the range.
     * @param mixed $from The initial value of the range.
     * @param mixed $to The ending value in the comparison range.
     * @param string|null $type The data type name to bind the values with.
     */
    public function __construct(Expression_Interface|string $field, mixed $from, mixed $to, ?string $type = null)
    {
        if ($type !== null) {
            $from = $this->_cast_to_expression($from, $type);
            $to = $this->_cast_to_expression($to, $type);
        }
        $this->_field = $field;
        $this->_from = $from;
        $this->_to = $to;
        $this->_type = $type;
    }
    /**
     * @inheritDoc
     */
    public function sql(Value_Binder $binder): string
    {
        $parts = ['from' => $this->_from, 'to' => $this->_to];
        $field = $this->_field;
        if ($field instanceof Expression_Interface) {
            $field = $field->sql($binder);
        }
        foreach ($parts as $name => $part) {
            if ($part instanceof Expression_Interface) {
                $parts[$name] = $part->sql($binder);
                continue;
            }
            $parts[$name] = $this->_bind_value($part, $binder, $this->_type);
        }
        assert(is_string($field));
        return sprintf('%s BETWEEN %s AND %s', $field, $parts['from'], $parts['to']);
    }
    /**
     * @inheritDoc
     */
    public function traverse(Closure $callback): static
    {
        foreach ([$this->_field, $this->_from, $this->_to] as $part) {
            if ($part instanceof Expression_Interface) {
                $callback($part);
            }
        }
        return $this;
    }
    /**
     * Registers a value in the placeholder generator and returns the generated placeholder
     *
     * @param mixed $value The value to bind
     * @param \Cake\Database\ValueBinder $binder The value binder to use
     * @param string|null $type The type of $value
     * @return string generated placeholder
     */
    protected function _bind_value(mixed $value, Value_Binder $binder, ?string $type): string
    {
        $placeholder = $binder->placeholder('c');
        $binder->bind($placeholder, $value, $type);
        return $placeholder;
    }
    /**
     * Do a deep clone of this expression.
     */
    public function __clone()
    {
        foreach (['_field', '_from', '_to'] as $part) {
            if ($this->{$part} instanceof Expression_Interface) {
                $this->{$part} = clone $this->{$part};
            }
        }
    }
}