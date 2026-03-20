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
use Cake\Database\Value_Binder;
use Closure;
use InvalidArgumentException;
/**
 * This expression represents SQL fragments that are used for comparing one tuple
 * to another, one tuple to a set of other tuples or one tuple to an expression
 */
class Tuple_Comparison extends Comparison_Expression
{
    /**
     * Constructor
     *
     * @param \Cake\Database\ExpressionInterface|array|string $fields the fields to use to form a tuple
     * @param \Cake\Database\ExpressionInterface|array $values the values to use to form a tuple
     * @param array<string|null> $types the types names to use for casting each of the values, only
     * one type per position in the value array in needed
     * @param string $conjunction the operator used for comparing field and value
     */
    public function __construct(
        Expression_Interface|array|string $fields,
        Expression_Interface|array $values,
        /**
         * The type to be used for casting the value to a database representation
         */
        protected array $types = [],
        string $conjunction = '='
    )
    {
        $this->set_field($fields);
        $this->_operator = $conjunction;
        $this->set_value($values);
    }
    /**
     * Returns the type to be used for casting the value to a database representation
     *
     * @return array<string|null>
     */
    public function get_type(): array
    {
        return $this->types;
    }
    /**
     * Sets the value
     *
     * @param mixed $value The value to compare
     */
    public function set_value(mixed $value): void
    {
        if ($this->is_multi()) {
            if (is_array($value) && !is_array(current($value))) {
                throw new InvalidArgumentException('Multi-tuple comparisons require a multi-tuple value, single-tuple given.');
            }
        } elseif (is_array($value) && is_array(current($value))) {
            throw new InvalidArgumentException('Single-tuple comparisons require a single-tuple value, multi-tuple given.');
        }
        $this->_value = $value;
    }
    /**
     * @inheritDoc
     */
    public function sql(Value_Binder $binder): string
    {
        $template = '(%s) %s (%s)';
        $fields = [];
        $original_fields = $this->get_field();
        if (!is_array($original_fields)) {
            $original_fields = [$original_fields];
        }
        foreach ($original_fields as $field) {
            $fields[] = $field instanceof Expression_Interface ? $field->sql($binder) : $field;
        }
        $values = $this->_stringify_values($binder);
        $field = implode(', ', $fields);
        return sprintf($template, $field, $this->_operator, $values);
    }
    /**
     * Returns a string with the values as placeholders in a string to be used
     * for the SQL version of this expression
     *
     * @param \Cake\Database\ValueBinder $binder The value binder to convert expressions with.
     */
    protected function _stringify_values(Value_Binder $binder): string
    {
        $values = [];
        $parts = $this->get_value();
        if ($parts instanceof Expression_Interface) {
            return $parts->sql($binder);
        }
        foreach ($parts as $i => $value) {
            if ($value instanceof Expression_Interface) {
                $values[] = $value->sql($binder);
                continue;
            }
            $type = $this->types;
            $is_multi_operation = $this->is_multi();
            if (!$type) {
                $type = null;
            }
            if ($is_multi_operation) {
                $bound = [];
                foreach ($value as $k => $val) {
                    $val_type = $type && isset($type[$k]) ? $type[$k] : $type;
                    assert($val_type === null || is_scalar($val_type));
                    $bound[] = $this->_bind_value($val, $binder, $val_type);
                }
                $values[] = sprintf('(%s)', implode(',', $bound));
                continue;
            }
            $val_type = $type && isset($type[$i]) ? $type[$i] : $type;
            assert($val_type === null || is_scalar($val_type));
            $values[] = $this->_bind_value($value, $binder, $val_type);
        }
        return implode(', ', $values);
    }
    /**
     * @inheritDoc
     */
    protected function _bind_value(mixed $value, Value_Binder $binder, ?string $type = null): string
    {
        $placeholder = $binder->placeholder('tuple');
        $binder->bind($placeholder, $value, $type);
        return $placeholder;
    }
    /**
     * @inheritDoc
     */
    public function traverse(Closure $callback): static
    {
        $fields = (array) $this->get_field();
        foreach ($fields as $field) {
            $this->_traverse_value($field, $callback);
        }
        $value = $this->get_value();
        if ($value instanceof Expression_Interface) {
            $callback($value);
            $value->traverse($callback);
            return $this;
        }
        foreach ($value as $val) {
            if ($this->is_multi()) {
                foreach ($val as $v) {
                    $this->_traverse_value($v, $callback);
                }
            } else {
                $this->_traverse_value($val, $callback);
            }
        }
        return $this;
    }
    /**
     * Conditionally executes the callback for the passed value if
     * it is an ExpressionInterface
     *
     * @param mixed $value The value to traverse
     * @param \Closure $callback The callback to use when traversing
     */
    protected function _traverse_value(mixed $value, Closure $callback): void
    {
        if ($value instanceof Expression_Interface) {
            $callback($value);
            $value->traverse($callback);
        }
    }
    /**
     * Determines if each of the values in this expressions is a tuple in
     * itself
     */
    public function is_multi(): bool
    {
        return in_array(strtolower($this->_operator), ['in', 'not in']);
    }
}