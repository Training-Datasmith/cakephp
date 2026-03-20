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
namespace Cake\Database\Expression;

use function Cake\Core\Deprecation_Warning;
use Cake\Database\Expression_Interface;
use Cake\Database\Value_Binder;
use Closure;
/**
 * This represents an SQL aggregate function expression in an SQL statement.
 * Calls can be constructed by passing the name of the function and a list of params.
 * For security reasons, all params passed are quoted by default unless
 * explicitly told otherwise.
 */
class Aggregate_Expression extends Function_Expression implements Window_Interface
{
    protected ?Query_Expression $filter = null;
    protected ?Window_Expression $window = null;
    /**
     * Adds conditions to the FILTER clause. The conditions are the same format as
     * `Query::where()`.
     *
     * @param \Cake\Database\ExpressionInterface|\Closure|array|string $conditions The conditions to filter on.
     * @param array<string, string> $types Associative array of type names used to bind values to query
     * @return $this
     * @see \Cake\Database\Query::where()
     */
    public function filter(Expression_Interface|Closure|array|string $conditions, array $types = []): static
    {
        $this->filter ??= new Query_Expression();
        if ($conditions instanceof Closure) {
            $conditions = $conditions(new Query_Expression());
        }
        $this->filter->add($conditions, $types);
        return $this;
    }
    /**
     * Adds an empty `OVER()` window expression or a named window expression.
     *
     * @param string|null $name Window name
     * @return $this
     */
    public function over(?string $name = null): static
    {
        $window = $this->get_window();
        if ($name) {
            // Set name manually in case this was chained from FunctionsBuilder wrapper
            $window->name($name);
        }
        return $this;
    }
    /**
     * @inheritDoc
     */
    public function partition(Expression_Interface|Closure|array|string $partitions): static
    {
        $this->get_window()->partition($partitions);
        return $this;
    }
    /**
     * @inheritDoc
     */
    public function order(Expression_Interface|Closure|array|string $fields)
    {
        deprecation_warning('5.0.0', 'AggregateExpression::order() is deprecated. Use AggregateExpression::orderBy() instead.');
        return $this->order_by($fields);
    }
    /**
     * @inheritDoc
     */
    public function order_by(Expression_Interface|Closure|array|string $fields): static
    {
        $this->get_window()->order_by($fields);
        return $this;
    }
    /**
     * @inheritDoc
     */
    public function range(Expression_Interface|string|int|null $start, Expression_Interface|string|int|null $end = 0): static
    {
        $this->get_window()->range($start, $end);
        return $this;
    }
    /**
     * @inheritDoc
     */
    public function rows(?int $start, ?int $end = 0): static
    {
        $this->get_window()->rows($start, $end);
        return $this;
    }
    /**
     * @inheritDoc
     */
    public function groups(?int $start, ?int $end = 0): static
    {
        $this->get_window()->groups($start, $end);
        return $this;
    }
    /**
     * @inheritDoc
     */
    public function frame(string $type, Expression_Interface|string|int|null $start_offset, string $start_direction, Expression_Interface|string|int|null $end_offset, string $end_direction): static
    {
        $this->get_window()->frame($type, $start_offset, $start_direction, $end_offset, $end_direction);
        return $this;
    }
    /**
     * @inheritDoc
     */
    public function exclude_current(): static
    {
        $this->get_window()->exclude_current();
        return $this;
    }
    /**
     * @inheritDoc
     */
    public function exclude_group(): static
    {
        $this->get_window()->exclude_group();
        return $this;
    }
    /**
     * @inheritDoc
     */
    public function exclude_ties(): static
    {
        $this->get_window()->exclude_ties();
        return $this;
    }
    /**
     * Returns or creates WindowExpression for function.
     */
    protected function get_window(): Window_Expression
    {
        return $this->window ??= new Window_Expression();
    }
    /**
     * @inheritDoc
     */
    public function sql(Value_Binder $binder): string
    {
        $sql = parent::sql($binder);
        if ($this->filter !== null) {
            $sql .= ' FILTER (WHERE ' . $this->filter->sql($binder) . ')';
        }
        if ($this->window !== null) {
            if ($this->window->is_named_only()) {
                $sql .= ' OVER ' . $this->window->sql($binder);
            } else {
                $sql .= ' OVER (' . $this->window->sql($binder) . ')';
            }
        }
        return $sql;
    }
    /**
     * @inheritDoc
     */
    public function traverse(Closure $callback): static
    {
        parent::traverse($callback);
        if ($this->filter !== null) {
            $callback($this->filter);
            $this->filter->traverse($callback);
        }
        if ($this->window !== null) {
            $callback($this->window);
            $this->window->traverse($callback);
        }
        return $this;
    }
    /**
     * @inheritDoc
     */
    public function count(): int
    {
        $count = parent::count();
        if ($this->window !== null) {
            $count += 1;
        }
        return $count;
    }
    /**
     * Clone this object and its subtree of expressions.
     */
    public function __clone()
    {
        parent::__clone();
        if ($this->filter !== null) {
            $this->filter = clone $this->filter;
        }
        if ($this->window !== null) {
            $this->window = clone $this->window;
        }
    }
}