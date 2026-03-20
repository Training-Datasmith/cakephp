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

use Cake\Database\Exception\Database_Exception;
use Cake\Database\Expression_Interface;
use Cake\Database\Type\Expression_Type_Caster_Trait;
use Cake\Database\Value_Binder;
use Closure;
/**
 * A Comparison is a type of query expression that represents an operation
 * involving a field an operator and a value. In its most common form the
 * string representation of a comparison is `field = value`
 */
class Comparison_Expression implements Expression_Interface, Field_Interface
{
    use Expression_Type_Caster_Trait;
    use Field_Trait;
    /**
     * The value to be used in the right hand side of the operation
     */
    protected mixed $_value;
    /**
     * Whether the value in this expression is a traversable
     */
    protected bool $_is_multiple = false;
    /**
     * A cached list of ExpressionInterface objects that were
     * found in the value for this expression.
     *
     * @var array<\Cake\Database\ExpressionInterface>
     */
    protected array $_value_expressions = [];
    /**
     * Constructor
     *
     * @param \Cake\Database\ExpressionInterface|string $field the field name to compare to a value
     * @param mixed $value The value to be used in comparison
     * @param string|null $_type the type name used to cast the value
     * @param string $_operator the operator used for comparing field and value
     */
    public function __construct(
        Expression_Interface|string $field,
        mixed $value,
        /**
         * The type to be used for casting the value to a database representation
         */
        protected ?string $_type = null,
        /**
         * The operator used for comparing field and value
         */
        protected string $_operator = '='
    )
    {
        $this->set_field($field);
        $this->set_value($value);
    }
    /**
     * Sets the value
     *
     * @param mixed $value The value to compare
     */
    public function set_value(mixed $value): void
    {
        $value = $this->_cast_to_expression($value, $this->_type);
        $is_multiple = $this->_type && str_contains($this->_type, '[]');
        if ($is_multiple) {
            [$value, $this->_value_expressions] = $this->_collect_expressions($value);
        }
        $this->_is_multiple = $is_multiple;
        $this->_value = $value;
    }
    /**
     * Returns the value used for comparison
     */
    public function get_value(): mixed
    {
        return $this->_value;
    }
    /**
     * Sets the operator to use for the comparison
     *
     * @param string $operator The operator to be used for the comparison.
     */
    public function set_operator(string $operator): void
    {
        $this->_operator = $operator;
    }
    /**
     * Returns the operator used for comparison
     */
    public function get_operator(): string
    {
        return $this->_operator;
    }
    /**
     * @inheritDoc
     */
    public function sql(Value_Binder $binder): string
    {
        $field = $this->_field;
        if ($field instanceof Expression_Interface) {
            $field = $field->sql($binder);
        }
        if ($this->_value instanceof Identifier_Expression) {
            $template = '%s %s %s';
            $value = $this->_value->sql($binder);
        } elseif ($this->_value instanceof Expression_Interface) {
            $template = '%s %s (%s)';
            $value = $this->_value->sql($binder);
        } else {
            [$template, $value] = $this->_string_expression($binder);
        }
        assert(is_string($field));
        return sprintf($template, $field, $this->_operator, $value);
    }
    /**
     * @inheritDoc
     */
    public function traverse(Closure $callback): static
    {
        if ($this->_field instanceof Expression_Interface) {
            $callback($this->_field);
            $this->_field->traverse($callback);
        }
        if ($this->_value instanceof Expression_Interface) {
            $callback($this->_value);
            $this->_value->traverse($callback);
        }
        foreach ($this->_value_expressions as $v) {
            $callback($v);
            $v->traverse($callback);
        }
        return $this;
    }
    /**
     * Create a deep clone.
     *
     * Clones the field and value if they are expression objects.
     */
    public function __clone()
    {
        foreach (['_value', '_field'] as $prop) {
            if ($this->{$prop} instanceof Expression_Interface) {
                $this->{$prop} = clone $this->{$prop};
            }
        }
    }
    /**
     * Returns a template and a placeholder for the value after registering it
     * with the placeholder $binder
     *
     * @param \Cake\Database\ValueBinder $binder The value binder to use.
     * @return array First position containing the template and the second a placeholder
     */
    protected function _string_expression(Value_Binder $binder): array
    {
        $template = '%s ';
        if ($this->_field instanceof Expression_Interface && !$this->_field instanceof Identifier_Expression) {
            $template = '(%s) ';
        }
        if ($this->_is_multiple) {
            $template .= '%s (%s)';
            $type = $this->_type;
            if ($type !== null) {
                $type = str_replace('[]', '', $type);
            }
            $value = $this->_flatten_value($this->_value, $binder, $type);
            // To avoid SQL errors when comparing a field to a list of empty values,
            // better just throw an exception here
            if ($value === '') {
                $field = $this->_field instanceof Expression_Interface ? $this->_field->sql($binder) : $this->_field;
                /** @var string $field */
                throw new Database_Exception("Impossible to generate condition with empty list of values for field ({$field})");
            }
        } else {
            $template .= '%s %s';
            $value = $this->_bind_value($this->_value, $binder, $this->_type);
        }
        return [$template, $value];
    }
    /**
     * Registers a value in the placeholder generator and returns the generated placeholder
     *
     * @param mixed $value The value to bind
     * @param \Cake\Database\ValueBinder $binder The value binder to use
     * @param string|null $type The type of $value
     * @return string generated placeholder
     */
    protected function _bind_value(mixed $value, Value_Binder $binder, ?string $type = null): string
    {
        $placeholder = $binder->placeholder('c');
        $binder->bind($placeholder, $value, $type);
        return $placeholder;
    }
    /**
     * Converts a traversable value into a set of placeholders generated by
     * $binder and separated by `,`
     *
     * @param iterable $value the value to flatten
     * @param \Cake\Database\ValueBinder $binder The value binder to use
     * @param string|null $type the type to cast values to
     */
    protected function _flatten_value(iterable $value, Value_Binder $binder, ?string $type = null): string
    {
        $parts = [];
        if (is_array($value)) {
            foreach ($this->_value_expressions as $k => $v) {
                $parts[$k] = $v->sql($binder);
                unset($value[$k]);
            }
        }
        if ($value) {
            $parts += $binder->generate_many_named($value, $type);
        }
        return implode(',', $parts);
    }
    /**
     * Returns an array with the original $values in the first position
     * and all ExpressionInterface objects that could be found in the second
     * position.
     *
     * @param \Cake\Database\ExpressionInterface|iterable $values The rows to insert
     */
    protected function _collect_expressions(Expression_Interface|iterable $values): array
    {
        if ($values instanceof Expression_Interface) {
            return [$values, []];
        }
        $expressions = [];
        $result = [];
        $is_array = is_array($values);
        if ($is_array) {
            $result = (array) $values;
        }
        foreach ($values as $k => $v) {
            if ($v instanceof Expression_Interface) {
                $expressions[$k] = $v;
            }
            if ($is_array) {
                $result[$k] = $v;
            }
        }
        return [$result, $expressions];
    }
}