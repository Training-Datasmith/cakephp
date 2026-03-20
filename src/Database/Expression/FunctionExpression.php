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
use Cake\Database\Type\Expression_Type_Caster_Trait;
use Cake\Database\Typed_Result_Interface;
use Cake\Database\Typed_Result_Trait;
use Cake\Database\Value_Binder;
/**
 * This class represents a function call string in a SQL statement. Calls can be
 * constructed by passing the name of the function and a list of params.
 * For security reasons, all params passed are quoted by default unless
 * explicitly told otherwise.
 */
class Function_Expression extends Query_Expression implements Typed_Result_Interface
{
    use Expression_Type_Caster_Trait;
    use Typed_Result_Trait;
    /**
     * Constructor. Takes a name for the function to be invoked and a list of params
     * to be passed into the function. Optionally you can pass a list of types to
     * be used for each bound param.
     *
     * By default, all params that are passed will be quoted. If you wish to use
     * literal arguments, you need to explicitly hint this function.
     *
     * ### Examples:
     *
     * `$f = new FunctionExpression('CONCAT', ['CakePHP', ' rules']);`
     *
     * Previous line will generate `CONCAT('CakePHP', ' rules')`
     *
     * `$f = new FunctionExpression('CONCAT', ['name' => 'literal', ' rules']);`
     *
     * Will produce `CONCAT(name, ' rules')`
     *
     * @param string $_name the name of the function to be constructed
     * @param array $params list of arguments to be passed to the function
     * If associative the key would be used as argument when value is 'literal'
     * @param array<string, string>|array<string|null> $types Associative array of types to be associated with the
     * passed arguments
     * @param string $returnType The return type of this expression
     */
    public function __construct(
        /**
         * The name of the function to be constructed when generating the SQL string
         */
        protected string $_name,
        array $params = [],
        array $types = [],
        string $return_type = 'string'
    )
    {
        $this->_return_type = $return_type;
        parent::__construct($params, $types, ',');
    }
    /**
     * Sets the name of the SQL function to be invoke in this expression.
     *
     * @param string $name The name of the function
     * @return $this
     */
    public function set_name(string $name): static
    {
        $this->_name = $name;
        return $this;
    }
    /**
     * Gets the name of the SQL function to be invoke in this expression.
     */
    public function get_name(): string
    {
        return $this->_name;
    }
    /**
     * Adds one or more arguments for the function call.
     *
     * @param \Cake\Database\ExpressionInterface|array|string $conditions list of arguments to be passed to the function
     * If associative the key would be used as argument when value is 'literal'
     * @param array<string, string> $types Associative array of types to be associated with the
     * passed arguments
     * @param bool $prepend Whether to prepend or append to the list of arguments
     * @see \Cake\Database\Expression\FunctionExpression::__construct() for more details.
     * @return $this
     */
    public function add(Expression_Interface|array|string $conditions, array $types = [], bool $prepend = false): static
    {
        $put = $prepend ? 'array_unshift' : 'array_push';
        $type_map = $this->get_type_map()->set_types($types);
        /** @var array $conditions */
        foreach ($conditions as $k => $p) {
            if ($p === 'literal') {
                $put($this->_conditions, $k);
                continue;
            }
            if ($p === 'identifier') {
                $put($this->_conditions, new Identifier_Expression($k));
                continue;
            }
            $type = $type_map->type($k);
            if ($type !== null && !$p instanceof Expression_Interface) {
                $p = $this->_cast_to_expression($p, $type);
            }
            if ($p instanceof Expression_Interface) {
                $put($this->_conditions, $p);
                continue;
            }
            $put($this->_conditions, ['value' => $p, 'type' => $type]);
        }
        return $this;
    }
    /**
     * @inheritDoc
     */
    public function sql(Value_Binder $binder): string
    {
        $parts = [];
        foreach ($this->_conditions as $condition) {
            if ($condition instanceof Query) {
                $condition = sprintf('(%s)', $condition->sql($binder));
            } elseif ($condition instanceof Expression_Interface) {
                $condition = $condition->sql($binder);
            } elseif (is_array($condition)) {
                $p = $binder->placeholder('param');
                $binder->bind($p, $condition['value'], $condition['type']);
                $condition = $p;
            }
            $parts[] = $condition;
        }
        return $this->_name . sprintf('(%s)', implode($this->_conjunction . ' ', $parts));
    }
    /**
     * The name of the function is in itself an expression to generate, thus
     * always adding 1 to the amount of expressions stored in this object.
     */
    public function count(): int
    {
        return 1 + count($this->_conditions);
    }
}