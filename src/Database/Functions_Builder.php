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
namespace Cake\Database;

use Cake\Database\Expression\Aggregate_Expression;
use Cake\Database\Expression\Function_Expression;
use InvalidArgumentException;
/**
 * Contains methods related to generating FunctionExpression objects
 * with most commonly used SQL functions.
 * This acts as a factory for FunctionExpression objects.
 */
class Functions_Builder
{
    /**
     * Returns a FunctionExpression representing a call to SQL RAND function.
     */
    public function rand(): Function_Expression
    {
        return new Function_Expression('RAND', [], [], 'float');
    }
    /**
     * Returns a AggregateExpression representing a call to SQL SUM function.
     *
     * @param \Cake\Database\ExpressionInterface|string $expression the function argument
     * @param array $types list of types to bind to the arguments
     */
    public function sum(Expression_Interface|string $expression, array $types = []): Aggregate_Expression
    {
        $return_type = 'float';
        if (current($types) === 'integer') {
            $return_type = 'integer';
        }
        return $this->aggregate('SUM', $this->to_literal_param($expression), $types, $return_type);
    }
    /**
     * Returns a AggregateExpression representing a call to SQL AVG function.
     *
     * @param \Cake\Database\ExpressionInterface|string $expression the function argument
     * @param array $types list of types to bind to the arguments
     */
    public function avg(Expression_Interface|string $expression, array $types = []): Aggregate_Expression
    {
        return $this->aggregate('AVG', $this->to_literal_param($expression), $types, 'float');
    }
    /**
     * Returns a AggregateExpression representing a call to SQL MAX function.
     *
     * @param \Cake\Database\ExpressionInterface|string $expression the function argument
     * @param array $types list of types to bind to the arguments
     */
    public function max(Expression_Interface|string $expression, array $types = []): Aggregate_Expression
    {
        return $this->aggregate('MAX', $this->to_literal_param($expression), $types, current($types) ?: 'float');
    }
    /**
     * Returns a AggregateExpression representing a call to SQL MIN function.
     *
     * @param \Cake\Database\ExpressionInterface|string $expression the function argument
     * @param array $types list of types to bind to the arguments
     */
    public function min(Expression_Interface|string $expression, array $types = []): Aggregate_Expression
    {
        return $this->aggregate('MIN', $this->to_literal_param($expression), $types, current($types) ?: 'float');
    }
    /**
     * Returns a AggregateExpression representing a call to SQL COUNT function.
     *
     * @param \Cake\Database\ExpressionInterface|string $expression the function argument
     * @param array $types list of types to bind to the arguments
     */
    public function count(Expression_Interface|string $expression, array $types = []): Aggregate_Expression
    {
        return $this->aggregate('COUNT', $this->to_literal_param($expression), $types, 'integer');
    }
    /**
     * Returns a FunctionExpression representing a string concatenation
     *
     * @param array $args List of strings or expressions to concatenate
     * @param array $types list of types to bind to the arguments
     */
    public function concat(array $args, array $types = []): Function_Expression
    {
        return new Function_Expression('CONCAT', $args, $types, 'string');
    }
    /**
     * Returns a FunctionExpression representing a call to SQL COALESCE function.
     *
     * @param array $args List of expressions to evaluate as function parameters
     * @param array $types list of types to bind to the arguments
     */
    public function coalesce(array $args, array $types = []): Function_Expression
    {
        return new Function_Expression('COALESCE', $args, $types, current($types) ?: 'string');
    }
    /**
     * Returns a FunctionExpression representing a SQL CAST.
     *
     * The `$type` parameter is a SQL type. The return type for the returned expression
     * is the default type name. Use `setReturnType()` to update it.
     *
     * @param \Cake\Database\ExpressionInterface|string $field Field or expression to cast.
     * @param string $dataType The SQL data type
     */
    public function cast(Expression_Interface|string $field, string $data_type): Function_Expression
    {
        $expression = new Function_Expression('CAST', $this->to_literal_param($field));
        return $expression->set_conjunction(' AS')->add([$data_type => 'literal']);
    }
    /**
     * Returns a FunctionExpression representing the difference in days between
     * two dates.
     *
     * @param array $args List of expressions to obtain the difference in days.
     * @param array $types list of types to bind to the arguments
     */
    public function date_diff(array $args, array $types = []): Function_Expression
    {
        return new Function_Expression('DATEDIFF', $args, $types, 'integer');
    }
    /**
     * Returns the specified date part from the SQL expression.
     *
     * @param string $part Part of the date to return.
     * @param \Cake\Database\ExpressionInterface|string $expression Expression to obtain the date part from.
     * @param array $types list of types to bind to the arguments
     */
    public function date_part(string $part, Expression_Interface|string $expression, array $types = []): Function_Expression
    {
        return $this->extract($part, $expression, $types);
    }
    /**
     * Returns the specified date part from the SQL expression.
     *
     * @param string $part Part of the date to return.
     * @param \Cake\Database\ExpressionInterface|string $expression Expression to obtain the date part from.
     * @param array $types list of types to bind to the arguments
     */
    public function extract(string $part, Expression_Interface|string $expression, array $types = []): Function_Expression
    {
        $expression = new Function_Expression('EXTRACT', $this->to_literal_param($expression), $types, 'integer');
        return $expression->set_conjunction(' FROM')->add([$part => 'literal'], [], true);
    }
    /**
     * Add the time unit to the date expression
     *
     * @param \Cake\Database\ExpressionInterface|string $expression Expression to obtain the date part from.
     * @param string|int $value Value to be added. Use negative to subtract.
     * @param string $unit Unit of the value e.g. hour or day.
     * @param array $types list of types to bind to the arguments
     */
    public function date_add(Expression_Interface|string $expression, string|int $value, string $unit, array $types = []): Function_Expression
    {
        if (!is_numeric($value)) {
            $value = 0;
        }
        $interval = $value . ' ' . $unit;
        $expression = new Function_Expression('DATE_ADD', $this->to_literal_param($expression), $types, 'datetime');
        return $expression->set_conjunction(', INTERVAL')->add([$interval => 'literal']);
    }
    /**
     * Returns a FunctionExpression representing a call to SQL WEEKDAY function.
     * 1 - Sunday, 2 - Monday, 3 - Tuesday...
     *
     * @param \Cake\Database\ExpressionInterface|string $expression the function argument
     * @param array $types list of types to bind to the arguments
     */
    public function day_of_week(Expression_Interface|string $expression, array $types = []): Function_Expression
    {
        return new Function_Expression('DAYOFWEEK', $this->to_literal_param($expression), $types, 'integer');
    }
    /**
     * Returns a FunctionExpression representing a call to SQL WEEKDAY function.
     * 1 - Sunday, 2 - Monday, 3 - Tuesday...
     *
     * @param \Cake\Database\ExpressionInterface|string $expression the function argument
     * @param array $types list of types to bind to the arguments
     */
    public function weekday(Expression_Interface|string $expression, array $types = []): Function_Expression
    {
        return $this->day_of_week($expression, $types);
    }
    /**
     * Returns a FunctionExpression representing a call that will return the current
     * date and time. By default it returns both date and time, but you can also
     * make it generate only the date or only the time.
     *
     * @param string $type (datetime|date|time)
     */
    public function now(string $type = 'datetime'): Function_Expression
    {
        return match ($type) {
            'datetime' => new Function_Expression('NOW', [], [], 'datetime'),
            'date' => new Function_Expression('CURRENT_DATE', [], [], 'date'),
            'time' => new Function_Expression('CURRENT_TIME', [], [], 'time'),
            default => throw new InvalidArgumentException('Invalid argument for FunctionsBuilder::now(): ' . $type),
        };
    }
    /**
     * Returns an AggregateExpression representing call to SQL ROW_NUMBER().
     */
    public function row_number(): Aggregate_Expression
    {
        return (new Aggregate_Expression('ROW_NUMBER', [], [], 'integer'))->over();
    }
    /**
     * Returns an AggregateExpression representing call to SQL LAG().
     *
     * @param \Cake\Database\ExpressionInterface|string $expression The value evaluated at offset
     * @param int $offset The row offset
     * @param mixed $default The default value if offset doesn't exist
     * @param string|null $type The output type of the lag expression. Defaults to float.
     */
    public function lag(Expression_Interface|string $expression, int $offset, mixed $default = null, ?string $type = null): Aggregate_Expression
    {
        $params = $this->to_literal_param($expression) + [$offset => 'literal'];
        if ($default !== null) {
            $params[] = $default;
        }
        $types = [];
        if ($type !== null) {
            $types = [$type, 'integer', $type];
        }
        return (new Aggregate_Expression('LAG', $params, $types, $type ?? 'float'))->over();
    }
    /**
     * Returns an AggregateExpression representing call to SQL LEAD().
     *
     * @param \Cake\Database\ExpressionInterface|string $expression The value evaluated at offset
     * @param int $offset The row offset
     * @param mixed $default The default value if offset doesn't exist
     * @param string|null $type The output type of the lead expression. Defaults to float.
     */
    public function lead(Expression_Interface|string $expression, int $offset, mixed $default = null, ?string $type = null): Aggregate_Expression
    {
        $params = $this->to_literal_param($expression) + [$offset => 'literal'];
        if ($default !== null) {
            $params[] = $default;
        }
        $types = [];
        if ($type !== null) {
            $types = [$type, 'integer', $type];
        }
        return (new Aggregate_Expression('LEAD', $params, $types, $type ?? 'float'))->over();
    }
    /**
     * Returns a FunctionExpression representing the Json Value
     *
     * @param \Cake\Database\ExpressionInterface|string $expression The Json value or json field
     * @param string $jsonPath A valid JSON PATH Query
     * @param array $types list of types to bind to the arguments
     */
    public function json_value(Expression_Interface|string $expression, string $json_path, array $types = []): Function_Expression
    {
        $params = $this->to_literal_param($expression) + [$json_path];
        return new Function_Expression('JSON_VALUE', $params, $types);
    }
    /**
     * Helper method to create arbitrary SQL aggregate function calls.
     *
     * @param string $name The SQL aggregate function name
     * @param array $params Array of arguments to be passed to the function.
     *     Can be an associative array with the literal value or identifier:
     *     `['value' => 'literal']` or `['value' => 'identifier']
     * @param array $types Array of types that match the names used in `$params`:
     *     `['name' => 'type']`
     * @param string $return Return type of the entire expression. Defaults to float.
     */
    public function aggregate(string $name, array $params = [], array $types = [], string $return = 'float'): Aggregate_Expression
    {
        return new Aggregate_Expression($name, $params, $types, $return);
    }
    /**
     * Magic method dispatcher to create custom SQL function calls
     *
     * @param string $name the SQL function name to construct
     * @param array $args list with up to 3 arguments, first one being an array with
     * parameters for the SQL function, the second one a list of types to bind to those
     * params, and the third one the return type of the function
     */
    public function __call(string $name, array $args): Function_Expression
    {
        return new Function_Expression($name, ...$args);
    }
    /**
     * Creates function parameter array from expression or string literal.
     *
     * @param \Cake\Database\ExpressionInterface|string $expression function argument
     * @return array<\Cake\Database\ExpressionInterface|string>
     */
    protected function to_literal_param(Expression_Interface|string $expression): array
    {
        if (is_string($expression)) {
            return [$expression => 'literal'];
        }
        return [$expression];
    }
}