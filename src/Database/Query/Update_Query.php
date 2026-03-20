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
 * @since         4.5.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Database\Query;

use Cake\Database\Expression\Comparison_Expression;
use Cake\Database\Expression\Query_Expression;
use Cake\Database\Expression_Interface;
use Cake\Database\Query;
use Closure;
/**
 * This class is used to generate UPDATE queries for the relational database.
 */
class Update_Query extends Query
{
    /**
     * Type of this query.
     */
    protected string $_type = self::TYPE_UPDATE;
    /**
     * List of SQL parts that will be used to build this query.
     *
     * @var array<string, mixed>
     */
    protected array $_parts = ['comment' => null, 'with' => [], 'update' => [], 'optimizerHint' => [], 'modifier' => [], 'join' => [], 'set' => [], 'where' => null, 'order' => null, 'limit' => null, 'epilog' => null];
    /**
     * Create an update query.
     *
     * Can be combined with set() and where() methods to create update queries.
     *
     * @param \Cake\Database\ExpressionInterface|string $table The table you want to update.
     * @return $this
     */
    public function update(Expression_Interface|string $table): static
    {
        $this->_dirty();
        $this->_parts['update'][0] = $table;
        return $this;
    }
    /**
     * Set one or many fields to update.
     *
     * ### Examples
     *
     * Passing a string:
     *
     * ```
     * $query->update('articles')->set('title', 'The Title');
     * ```
     *
     * Passing an array:
     *
     * ```
     * $query->update('articles')->set(['title' => 'The Title'], ['title' => 'string']);
     * ```
     *
     * Passing a callback:
     *
     * ```
     * $query->update('articles')->set(function (ExpressionInterface $exp) {
     *   return $exp->eq('title', 'The title', 'string');
     * });
     * ```
     *
     * @param \Cake\Database\Expression\QueryExpression|\Closure|array|string $key The column name or array of keys
     *    + values to set. This can also be a QueryExpression containing a SQL fragment.
     *    It can also be a Closure, that is required to return an expression object.
     * @param mixed $value The value to update $key to. Can be null if $key is an
     *    array or QueryExpression. When $key is an array, this parameter will be
     *    used as $types instead.
     * @param array<string, string>|string $types The column types to treat data as.
     * @return $this
     */
    public function set(Query_Expression|Closure|array|string $key, mixed $value = null, array|string $types = []): static
    {
        if (empty($this->_parts['set'])) {
            $this->_parts['set'] = $this->expr()->set_conjunction(',');
        }
        if ($key instanceof Closure) {
            $exp = $this->expr()->set_conjunction(',');
            /** @var \Cake\Database\Expression\QueryExpression $setExpr */
            $set_expr = $this->_parts['set'];
            $set_expr->add($key($exp));
            return $this;
        }
        if (is_array($key) && !isset($key[0])) {
            $type_map = $this->get_type_map()->set_types($value ?? []);
            /** @var \Cake\Database\Expression\QueryExpression $setExpr */
            $set_expr = $this->_parts['set'];
            foreach ($key as $k => $v) {
                $set_expr->add(new Comparison_Expression($k, $v, $type_map->type($k)));
            }
            return $this;
        }
        if (is_array($key) || $key instanceof Expression_Interface) {
            $types = (array) $value;
            /** @var \Cake\Database\Expression\QueryExpression $setExpr */
            $set_expr = $this->_parts['set'];
            $set_expr->add($key, $types);
            return $this;
        }
        if (!is_string($types)) {
            $types = null;
        }
        /** @var \Cake\Database\Expression\QueryExpression $setExpr */
        $set_expr = $this->_parts['set'];
        $set_expr->eq($key, $value, $types);
        return $this;
    }
}