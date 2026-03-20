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
use Cake\Database\Query;
use Cake\Database\Type_Map;
use Cake\Database\Type_Map_Trait;
use Cake\Database\Value_Binder;
use Closure;
use Countable;
use InvalidArgumentException;
/**
 * Represents a SQL Query expression. Internally it stores a tree of
 * expressions that can be compiled by converting this object to string
 * and will contain a correctly parenthesized and nested expression.
 */
class Query_Expression implements Expression_Interface, Countable
{
    use Type_Map_Trait;
    /**
     * String to be used for joining each of the internal expressions
     * this object internally stores for example "AND", "OR", etc.
     */
    protected string $_conjunction;
    /**
     * A list of strings or other expression objects that represent the "branches" of
     * the expression tree. For example one key of the array might look like "sum > :value"
     */
    protected array $_conditions = [];
    /**
     * Constructor. A new expression object can be created without any params and
     * be built dynamically. Otherwise, it is possible to pass an array of conditions
     * containing either a tree-like array structure to be parsed and/or other
     * expression objects. Optionally, you can set the conjunction keyword to be used
     * for joining each part of this level of the expression tree.
     *
     * @param \Cake\Database\ExpressionInterface|array|string $conditions Tree like array structure
     * containing all the conditions to be added or nested inside this expression object.
     * @param \Cake\Database\TypeMap|array $types Associative array of types to be associated with the values
     * passed in $conditions.
     * @param string $conjunction the glue that will join all the string conditions at this
     * level of the expression tree. For example "AND", "OR", "XOR"...
     * @see \Cake\Database\Expression\QueryExpression::add() for more details on $conditions and $types
     */
    public function __construct(Expression_Interface|array|string $conditions = [], Type_Map|array $types = [], string $conjunction = 'AND')
    {
        $this->set_type_map($types);
        $this->set_conjunction(strtoupper($conjunction));
        if ($conditions) {
            $this->add($conditions, $this->get_type_map()->get_types());
        }
    }
    /**
     * Changes the conjunction for the conditions at this level of the expression tree.
     *
     * @param string $conjunction Value to be used for joining conditions
     * @return $this
     */
    public function set_conjunction(string $conjunction): static
    {
        $this->_conjunction = strtoupper($conjunction);
        return $this;
    }
    /**
     * Gets the currently configured conjunction for the conditions at this level of the expression tree.
     */
    public function get_conjunction(): string
    {
        return $this->_conjunction;
    }
    /**
     * Adds one or more conditions to this expression object. Conditions can be
     * expressed in a one dimensional array, that will cause all conditions to
     * be added directly at this level of the tree or they can be nested arbitrarily
     * making it create more expression objects that will be nested inside and
     * configured to use the specified conjunction.
     *
     * If the type passed for any of the fields is expressed "type[]" (note braces)
     * then it will cause the placeholder to be re-written dynamically so if the
     * value is an array, it will create as many placeholders as values are in it.
     *
     * @param \Cake\Database\ExpressionInterface|array|string $conditions single or multiple conditions to
     * be added. When using an array and the key is 'OR' or 'AND' a new expression
     * object will be created with that conjunction and internal array value passed
     * as conditions.
     * @param array<int|string, string> $types Associative array of fields pointing to the type of the
     * values that are being passed. Used for correctly binding values to statements.
     * @see \Cake\Database\Query::where() for examples on conditions
     * @return $this
     */
    public function add(Expression_Interface|array|string $conditions, array $types = []): static
    {
        if (is_string($conditions) || $conditions instanceof Expression_Interface) {
            $this->_conditions[] = $conditions;
            return $this;
        }
        $this->_add_conditions($conditions, $types);
        return $this;
    }
    /**
     * Adds a new condition to the expression object in the form "field = value".
     *
     * @param \Cake\Database\ExpressionInterface|string $field Database field to be compared against value
     * @param mixed $value The value to be bound to $field for comparison
     * @param string|null $type the type name for $value as configured using the Type map.
     * If it is suffixed with "[]" and the value is an array then multiple placeholders
     * will be created, one per each value in the array.
     * @return $this
     */
    public function eq(Expression_Interface|string $field, mixed $value, ?string $type = null)
    {
        $type ??= $this->_calculate_type($field);
        return $this->add(new Comparison_Expression($field, $value, $type, '='));
    }
    /**
     * Adds a new condition to the expression object in the form "field != value".
     *
     * @param \Cake\Database\ExpressionInterface|string $field Database field to be compared against value
     * @param mixed $value The value to be bound to $field for comparison
     * @param string|null $type the type name for $value as configured using the Type map.
     * If it is suffixed with "[]" and the value is an array then multiple placeholders
     * will be created, one per each value in the array.
     * @return $this
     */
    public function not_eq(Expression_Interface|string $field, mixed $value, ?string $type = null)
    {
        $type ??= $this->_calculate_type($field);
        return $this->add(new Comparison_Expression($field, $value, $type, '!='));
    }
    /**
     * Adds a new condition to the expression object in the form "field > value".
     *
     * @param \Cake\Database\ExpressionInterface|string $field Database field to be compared against value
     * @param mixed $value The value to be bound to $field for comparison
     * @param string|null $type the type name for $value as configured using the Type map.
     * @return $this
     */
    public function gt(Expression_Interface|string $field, mixed $value, ?string $type = null)
    {
        $type ??= $this->_calculate_type($field);
        return $this->add(new Comparison_Expression($field, $value, $type, '>'));
    }
    /**
     * Adds a new condition to the expression object in the form "field < value".
     *
     * @param \Cake\Database\ExpressionInterface|string $field Database field to be compared against value
     * @param mixed $value The value to be bound to $field for comparison
     * @param string|null $type the type name for $value as configured using the Type map.
     * @return $this
     */
    public function lt(Expression_Interface|string $field, mixed $value, ?string $type = null)
    {
        $type ??= $this->_calculate_type($field);
        return $this->add(new Comparison_Expression($field, $value, $type, '<'));
    }
    /**
     * Adds a new condition to the expression object in the form "field >= value".
     *
     * @param \Cake\Database\ExpressionInterface|string $field Database field to be compared against value
     * @param mixed $value The value to be bound to $field for comparison
     * @param string|null $type the type name for $value as configured using the Type map.
     * @return $this
     */
    public function gte(Expression_Interface|string $field, mixed $value, ?string $type = null)
    {
        $type ??= $this->_calculate_type($field);
        return $this->add(new Comparison_Expression($field, $value, $type, '>='));
    }
    /**
     * Adds a new condition to the expression object in the form "field <= value".
     *
     * @param \Cake\Database\ExpressionInterface|string $field Database field to be compared against value
     * @param mixed $value The value to be bound to $field for comparison
     * @param string|null $type the type name for $value as configured using the Type map.
     * @return $this
     */
    public function lte(Expression_Interface|string $field, mixed $value, ?string $type = null)
    {
        $type ??= $this->_calculate_type($field);
        return $this->add(new Comparison_Expression($field, $value, $type, '<='));
    }
    /**
     * Adds a new condition to the expression object in the form "field IS NULL".
     *
     * @param \Cake\Database\ExpressionInterface|string $field database field to be
     * tested for null
     * @return $this
     */
    public function is_null(Expression_Interface|string $field)
    {
        if (!$field instanceof Expression_Interface) {
            $field = new Identifier_Expression($field);
        }
        return $this->add(new Unary_Expression('IS NULL', $field, Unary_Expression::POSTFIX));
    }
    /**
     * Adds a new condition to the expression object in the form "field IS NOT NULL".
     *
     * @param \Cake\Database\ExpressionInterface|string $field database field to be
     * tested for not null
     * @return $this
     */
    public function is_not_null(Expression_Interface|string $field)
    {
        if (!$field instanceof Expression_Interface) {
            $field = new Identifier_Expression($field);
        }
        return $this->add(new Unary_Expression('IS NOT NULL', $field, Unary_Expression::POSTFIX));
    }
    /**
     * Adds a new condition to the expression object in the form "field LIKE value".
     *
     * @param \Cake\Database\ExpressionInterface|string $field Database field to be compared against value
     * @param mixed $value The value to be bound to $field for comparison
     * @param string|null $type the type name for $value as configured using the Type map.
     * @return $this
     */
    public function like(Expression_Interface|string $field, mixed $value, ?string $type = null)
    {
        $type ??= $this->_calculate_type($field);
        return $this->add(new Comparison_Expression($field, $value, $type, 'LIKE'));
    }
    /**
     * Adds a new condition to the expression object in the form "field NOT LIKE value".
     *
     * @param \Cake\Database\ExpressionInterface|string $field Database field to be compared against value
     * @param mixed $value The value to be bound to $field for comparison
     * @param string|null $type the type name for $value as configured using the Type map.
     * @return $this
     */
    public function not_like(Expression_Interface|string $field, mixed $value, ?string $type = null)
    {
        $type ??= $this->_calculate_type($field);
        return $this->add(new Comparison_Expression($field, $value, $type, 'NOT LIKE'));
    }
    /**
     * Adds a new condition to the expression object in the form
     * "field IN (value1, value2)".
     *
     * @param \Cake\Database\ExpressionInterface|string $field Database field to be compared against value
     * @param \Cake\Database\ExpressionInterface|array|string $values the value to be bound to $field for comparison
     * @param string|null $type the type name for $value as configured using the Type map.
     * @return $this
     */
    public function in(Expression_Interface|string $field, Expression_Interface|array|string $values, ?string $type = null)
    {
        $type ??= $this->_calculate_type($field);
        $type = $type ?: 'string';
        $type .= '[]';
        $values = $values instanceof Expression_Interface ? $values : (array) $values;
        return $this->add(new Comparison_Expression($field, $values, $type, 'IN'));
    }
    /**
     * Returns a new case expression object.
     *
     * When a value is set, the syntax generated is
     * `CASE case_value WHEN when_value ... END` (simple case),
     * where the `when_value`'s are compared against the
     * `case_value`.
     *
     * When no value is set, the syntax generated is
     * `CASE WHEN when_conditions ... END` (searched case),
     * where the conditions hold the comparisons.
     *
     * Note that `null` is a valid case value, and thus should
     * only be passed if you actually want to create the simple
     * case expression variant!
     *
     * @param \Cake\Database\ExpressionInterface|object|scalar|null $value The case value.
     * @param string|null $type The case value type. If no type is provided, the type will be tried to be inferred
     *  from the value.
     */
    public function case(mixed $value = null, ?string $type = null): Case_Statement_Expression
    {
        if (func_num_args() > 0) {
            $expression = new Case_Statement_Expression($value, $type);
        } else {
            $expression = new Case_Statement_Expression();
        }
        return $expression->set_type_map($this->get_type_map());
    }
    /**
     * Adds a new condition to the expression object in the form
     * "field NOT IN (value1, value2)".
     *
     * @param \Cake\Database\ExpressionInterface|string $field Database field to be compared against value
     * @param \Cake\Database\ExpressionInterface|array|string $values the value to be bound to $field for comparison
     * @param string|null $type the type name for $value as configured using the Type map.
     * @return $this
     */
    public function not_in(Expression_Interface|string $field, Expression_Interface|array|string $values, ?string $type = null)
    {
        $type ??= $this->_calculate_type($field);
        $type = $type ?: 'string';
        $type .= '[]';
        $values = $values instanceof Expression_Interface ? $values : (array) $values;
        return $this->add(new Comparison_Expression($field, $values, $type, 'NOT IN'));
    }
    /**
     * Adds a new condition to the expression object in the form
     * "(field NOT IN (value1, value2) OR field IS NULL".
     *
     * @param \Cake\Database\ExpressionInterface|string $field Database field to be compared against value
     * @param \Cake\Database\ExpressionInterface|array|string $values the value to be bound to $field for comparison
     * @param string|null $type the type name for $value as configured using the Type map.
     * @return $this
     */
    public function not_in_or_null(Expression_Interface|string $field, Expression_Interface|array|string $values, ?string $type = null)
    {
        $or = new static([], [], 'OR');
        $or->not_in($field, $values, $type)->is_null($field);
        return $this->add($or);
    }
    /**
     * Adds a new condition to the expression object in the form "EXISTS (...)".
     *
     * @param \Cake\Database\ExpressionInterface $expression the inner query
     * @return $this
     */
    public function exists(Expression_Interface $expression)
    {
        return $this->add(new Unary_Expression('EXISTS', $expression, Unary_Expression::PREFIX));
    }
    /**
     * Adds a new condition to the expression object in the form "NOT EXISTS (...)".
     *
     * @param \Cake\Database\ExpressionInterface $expression the inner query
     * @return $this
     */
    public function not_exists(Expression_Interface $expression)
    {
        return $this->add(new Unary_Expression('NOT EXISTS', $expression, Unary_Expression::PREFIX));
    }
    /**
     * Adds a new condition to the expression object in the form
     * "field BETWEEN from AND to".
     *
     * @param \Cake\Database\ExpressionInterface|string $field The field name to compare for values in between the range.
     * @param mixed $from The initial value of the range.
     * @param mixed $to The ending value in the comparison range.
     * @param string|null $type the type name for $value as configured using the Type map.
     * @return $this
     */
    public function between(Expression_Interface|string $field, mixed $from, mixed $to, ?string $type = null)
    {
        $type ??= $this->_calculate_type($field);
        return $this->add(new Between_Expression($field, $from, $to, $type));
    }
    /**
     * Returns a new QueryExpression object containing all the conditions passed
     * and set up the conjunction to be "AND"
     *
     * @param \Cake\Database\ExpressionInterface|\Closure|array|string $conditions to be joined with AND
     * @param array<string, string> $types Associative array of fields pointing to the type of the
     * values that are being passed. Used for correctly binding values to statements.
     */
    public function and(Expression_Interface|Closure|array|string $conditions, array $types = []): static
    {
        if ($conditions instanceof Closure) {
            return $conditions(new static([], $this->get_type_map()->set_types($types)));
        }
        return new static($conditions, $this->get_type_map()->set_types($types));
    }
    /**
     * Returns a new QueryExpression object containing all the conditions passed
     * and set up the conjunction to be "OR"
     *
     * @param \Cake\Database\ExpressionInterface|\Closure|array|string $conditions to be joined with OR
     * @param array<string, string> $types Associative array of fields pointing to the type of the
     * values that are being passed. Used for correctly binding values to statements.
     */
    public function or(Expression_Interface|Closure|array|string $conditions, array $types = []): static
    {
        if ($conditions instanceof Closure) {
            return $conditions(new static([], $this->get_type_map()->set_types($types), 'OR'));
        }
        return new static($conditions, $this->get_type_map()->set_types($types), 'OR');
    }
    /**
     * Adds a new set of conditions to this level of the tree and negates
     * the final result by prepending a NOT, it will look like
     * "NOT ( (condition1) AND (conditions2) )" conjunction depends on the one
     * currently configured for this object.
     *
     * @param \Cake\Database\ExpressionInterface|\Closure|array|string $conditions to be added and negated
     * @param array<string, string> $types Associative array of fields pointing to the type of the
     * values that are being passed. Used for correctly binding values to statements.
     * @return $this
     */
    public function not(Expression_Interface|Closure|array|string $conditions, array $types = [])
    {
        return $this->add(['NOT' => $conditions], $types);
    }
    /**
     * Returns the number of internal conditions that are stored in this expression.
     * Useful to determine if this expression object is void or it will generate
     * a non-empty string when compiled
     */
    public function count(): int
    {
        return count($this->_conditions);
    }
    /**
     * Builds equal condition or assignment with identifier wrapping.
     *
     * @param string $leftField Left join condition field name.
     * @param string $rightField Right join condition field name.
     * @return $this
     */
    public function equal_fields(string $left_field, string $right_field)
    {
        $wrap_identifier = function ($field): Expression_Interface {
            if ($field instanceof Expression_Interface) {
                return $field;
            }
            return new Identifier_Expression($field);
        };
        return $this->eq($wrap_identifier($left_field), $wrap_identifier($right_field));
    }
    /**
     * @inheritDoc
     */
    public function sql(Value_Binder $binder): string
    {
        $len = $this->count();
        if ($len === 0) {
            return '';
        }
        $conjunction = $this->_conjunction;
        $template = $len === 1 ? '%s' : '(%s)';
        $parts = [];
        foreach ($this->_conditions as $part) {
            if ($part instanceof Query) {
                $part = '(' . $part->sql($binder) . ')';
            } elseif ($part instanceof Expression_Interface) {
                $part = $part->sql($binder);
            }
            if ($part !== '') {
                $parts[] = $part;
            }
        }
        return sprintf($template, implode(" {$conjunction} ", $parts));
    }
    /**
     * @inheritDoc
     */
    public function traverse(Closure $callback): static
    {
        foreach ($this->_conditions as $c) {
            if ($c instanceof Expression_Interface) {
                $callback($c);
                $c->traverse($callback);
            }
        }
        return $this;
    }
    /**
     * Executes a callback for each of the parts that form this expression.
     *
     * The callback is required to return a value with which the currently
     * visited part will be replaced. If the callback returns null then
     * the part will be discarded completely from this expression.
     *
     * The callback function will receive each of the conditions as first param and
     * the key as second param. It is possible to declare the second parameter as
     * passed by reference, this will enable you to change the key under which the
     * modified part is stored.
     *
     * @param \Closure $callback The callback to run for each part
     * @return $this
     */
    public function iterate_parts(Closure $callback): static
    {
        $parts = [];
        foreach ($this->_conditions as $k => $c) {
            $key =& $k;
            $part = $callback($c, $key);
            if ($part !== null) {
                $parts[$key] = $part;
            }
        }
        $this->_conditions = $parts;
        return $this;
    }
    /**
     * Returns true if this expression contains any other nested
     * ExpressionInterface objects
     */
    public function has_nested_expression(): bool
    {
        foreach ($this->_conditions as $c) {
            if ($c instanceof Expression_Interface) {
                return true;
            }
        }
        return false;
    }
    /**
     * Auxiliary function used for decomposing a nested array of conditions and build
     * a tree structure inside this object to represent the full SQL expression.
     * String conditions are stored directly in the conditions, while any other
     * representation is wrapped around an adequate instance or of this class.
     *
     * @param array $conditions list of conditions to be stored in this object
     * @param array<int|string, string> $types list of types associated on fields referenced in $conditions
     */
    protected function _add_conditions(array $conditions, array $types): void
    {
        $operators = ['and', 'or', 'xor'];
        $type_map = $this->get_type_map()->set_types($types);
        foreach ($conditions as $k => $c) {
            $numeric_key = is_numeric($k);
            if ($c instanceof Closure) {
                $expr = new static([], $type_map);
                $c = $c($expr, $this);
            }
            if ($numeric_key && empty($c)) {
                continue;
            }
            $is_array = is_array($c);
            $is_operator = false;
            $is_not = false;
            if (!$numeric_key) {
                $normalized_key = strtolower($k);
                $is_operator = in_array($normalized_key, $operators);
                $is_not = $normalized_key === 'not';
            }
            if (($is_operator || $is_not) && ($is_array || $c instanceof Countable) && count($c) === 0) {
                continue;
            }
            if ($numeric_key && $c instanceof Expression_Interface) {
                $this->_conditions[] = $c;
                continue;
            }
            if ($numeric_key && is_string($c)) {
                $this->_conditions[] = $c;
                continue;
            }
            if ($numeric_key && $is_array || $is_operator) {
                $this->_conditions[] = new static($c, $type_map, $numeric_key ? 'AND' : $k);
                continue;
            }
            if ($is_not) {
                $this->_conditions[] = new Unary_Expression('NOT', new static($c, $type_map));
                continue;
            }
            if (!$numeric_key) {
                $this->_conditions[] = $this->_parse_condition($k, $c);
            }
        }
    }
    /**
     * Parses a string conditions by trying to extract the operator inside it if any
     * and finally returning either an adequate QueryExpression object or a plain
     * string representation of the condition. This function is responsible for
     * generating the placeholders and replacing the values by them, while storing
     * the value elsewhere for future binding.
     *
     * @param string $condition The value from which the actual field and operator will
     * be extracted.
     * @param mixed $value The value to be bound to a placeholder for the field
     * @throws \InvalidArgumentException If operator is invalid or missing on NULL usage.
     */
    protected function _parse_condition(string $condition, mixed $value): Expression_Interface|string
    {
        $expression = trim($condition);
        $operator = '=';
        $spaces = substr_count($expression, ' ');
        // Handle expression values that contain multiple spaces, such as
        // operators with a space in them like `field IS NOT` and
        // `field NOT LIKE`, or combinations with function expressions
        // like `CONCAT(first_name, ' ', last_name) IN`.
        if ($spaces > 1) {
            $parts = explode(' ', $expression);
            if (preg_match('/(is not|not \w+)$/i', $expression)) {
                $last = array_pop($parts);
                $second = array_pop($parts);
                $parts[] = "{$second} {$last}";
            }
            $operator = array_pop($parts);
            $expression = implode(' ', $parts);
        } elseif ($spaces === 1) {
            $parts = explode(' ', $expression, 2);
            [$expression, $operator] = $parts;
        }
        $operator = strtoupper(trim($operator));
        $type = $this->get_type_map()->type($expression);
        $type_multiple = is_string($type) && str_contains($type, '[]');
        if (in_array($operator, ['IN', 'NOT IN']) || $type_multiple) {
            $type = $type ?: 'string';
            if (!$type_multiple) {
                $type .= '[]';
            }
            $operator = $operator === '=' ? 'IN' : $operator;
            $operator = $operator === '!=' ? 'NOT IN' : $operator;
            $type_multiple = true;
        }
        if ($type_multiple) {
            $value = $value instanceof Expression_Interface ? $value : (array) $value;
        }
        if ($operator === 'IS' && $value === null) {
            return new Unary_Expression('IS NULL', new Identifier_Expression($expression), Unary_Expression::POSTFIX);
        }
        if ($operator === 'IS NOT' && $value === null) {
            return new Unary_Expression('IS NOT NULL', new Identifier_Expression($expression), Unary_Expression::POSTFIX);
        }
        if ($operator === 'IS' && $value !== null) {
            $operator = '=';
        }
        if ($operator === 'IS NOT' && $value !== null) {
            $operator = '!=';
        }
        if ($value === null && $this->_conjunction !== ',') {
            throw new InvalidArgumentException(sprintf('Expression `%s` has invalid `null` value.' . ' If `null` is a valid value, operator (IS, IS NOT) is missing.', $expression));
        }
        return new Comparison_Expression($expression, $value, $type, $operator);
    }
    /**
     * Returns the type name for the passed field if it was stored in the typeMap
     *
     * @param \Cake\Database\ExpressionInterface|string $field The field name to get a type for.
     * @return string|null The computed type or null, if the type is unknown.
     */
    protected function _calculate_type(Expression_Interface|string $field): ?string
    {
        $field = $field instanceof Identifier_Expression ? $field->get_identifier() : $field;
        if (!is_string($field)) {
            return null;
        }
        return $this->get_type_map()->type($field);
    }
    /**
     * Clone this object and its subtree of expressions.
     */
    public function __clone()
    {
        foreach ($this->_conditions as $i => $condition) {
            if ($condition instanceof Expression_Interface) {
                $this->_conditions[$i] = clone $condition;
            }
        }
    }
}