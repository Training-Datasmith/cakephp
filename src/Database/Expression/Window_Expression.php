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
 * This represents a SQL window expression used by aggregate and window functions.
 */
class Window_Expression implements Expression_Interface, Window_Interface
{
    protected Identifier_Expression $name;
    /**
     * @var array<\Cake\Database\ExpressionInterface>
     */
    protected array $partitions = [];
    protected ?Order_By_Expression $order = null;
    protected ?array $frame = null;
    protected ?string $exclusion = null;
    /**
     * @param string $name Window name
     */
    public function __construct(string $name = '')
    {
        $this->name = new Identifier_Expression($name);
    }
    /**
     * Return whether is only a named window expression.
     *
     * These window expressions only specify a named window and do not
     * specify their own partitions, frame or order.
     */
    public function is_named_only(): bool
    {
        return $this->name->get_identifier() && (!$this->partitions && !$this->frame && !$this->order);
    }
    /**
     * Sets the window name.
     *
     * @param string $name Window name
     * @return $this
     */
    public function name(string $name): static
    {
        $this->name = new Identifier_Expression($name);
        return $this;
    }
    /**
     * @inheritDoc
     */
    public function partition(Expression_Interface|Closure|array|string $partitions): static
    {
        if (!$partitions) {
            return $this;
        }
        if ($partitions instanceof Closure) {
            $partitions = $partitions(new Query_Expression([], [], ''));
        }
        if (!is_array($partitions)) {
            $partitions = [$partitions];
        }
        foreach ($partitions as &$partition) {
            if (is_string($partition)) {
                $partition = new Identifier_Expression($partition);
            }
        }
        $this->partitions = array_merge($this->partitions, $partitions);
        return $this;
    }
    /**
     * @inheritDoc
     */
    public function order(Expression_Interface|Closure|array|string $fields)
    {
        deprecation_warning('5.0.0', 'WindowExpression::order() is deprecated. Use WindowExpression::orderBy() instead.');
        return $this->order_by($fields);
    }
    /**
     * @inheritDoc
     */
    public function order_by(Expression_Interface|Closure|array|string $fields): static
    {
        if (!$fields) {
            return $this;
        }
        $this->order ??= new Order_By_Expression();
        if ($fields instanceof Closure) {
            $fields = $fields(new Query_Expression([], [], ''));
        }
        $this->order->add($fields);
        return $this;
    }
    /**
     * @inheritDoc
     */
    public function range(Expression_Interface|string|int|null $start, Expression_Interface|string|int|null $end = 0)
    {
        return $this->frame(self::RANGE, $start, self::PRECEDING, $end, self::FOLLOWING);
    }
    /**
     * @inheritDoc
     */
    public function rows(?int $start, ?int $end = 0)
    {
        return $this->frame(self::ROWS, $start, self::PRECEDING, $end, self::FOLLOWING);
    }
    /**
     * @inheritDoc
     */
    public function groups(?int $start, ?int $end = 0)
    {
        return $this->frame(self::GROUPS, $start, self::PRECEDING, $end, self::FOLLOWING);
    }
    /**
     * @inheritDoc
     */
    public function frame(string $type, Expression_Interface|string|int|null $start_offset, string $start_direction, Expression_Interface|string|int|null $end_offset, string $end_direction): static
    {
        $this->frame = ['type' => $type, 'start' => ['offset' => $start_offset, 'direction' => $start_direction], 'end' => ['offset' => $end_offset, 'direction' => $end_direction]];
        return $this;
    }
    /**
     * @inheritDoc
     */
    public function exclude_current(): static
    {
        $this->exclusion = 'CURRENT ROW';
        return $this;
    }
    /**
     * @inheritDoc
     */
    public function exclude_group(): static
    {
        $this->exclusion = 'GROUP';
        return $this;
    }
    /**
     * @inheritDoc
     */
    public function exclude_ties(): static
    {
        $this->exclusion = 'TIES';
        return $this;
    }
    /**
     * @inheritDoc
     */
    public function sql(Value_Binder $binder): string
    {
        $clauses = [];
        if ($this->name->get_identifier()) {
            $clauses[] = $this->name->sql($binder);
        }
        if ($this->partitions) {
            $expressions = [];
            foreach ($this->partitions as $partition) {
                $expressions[] = $partition->sql($binder);
            }
            $clauses[] = 'PARTITION BY ' . implode(', ', $expressions);
        }
        if ($this->order) {
            $clauses[] = $this->order->sql($binder);
        }
        if ($this->frame) {
            $start = $this->build_offset_sql($binder, $this->frame['start']['offset'], $this->frame['start']['direction']);
            $end = $this->build_offset_sql($binder, $this->frame['end']['offset'], $this->frame['end']['direction']);
            $frame_sql = sprintf('%s BETWEEN %s AND %s', $this->frame['type'], $start, $end);
            if ($this->exclusion !== null) {
                $frame_sql .= ' EXCLUDE ' . $this->exclusion;
            }
            $clauses[] = $frame_sql;
        }
        return implode(' ', $clauses);
    }
    /**
     * @inheritDoc
     */
    public function traverse(Closure $callback): static
    {
        $callback($this->name);
        foreach ($this->partitions as $partition) {
            $callback($partition);
            $partition->traverse($callback);
        }
        if ($this->order) {
            $callback($this->order);
            $this->order->traverse($callback);
        }
        if ($this->frame !== null) {
            $offset = $this->frame['start']['offset'];
            if ($offset instanceof Expression_Interface) {
                $callback($offset);
                $offset->traverse($callback);
            }
            $offset = $this->frame['end']['offset'] ?? null;
            if ($offset instanceof Expression_Interface) {
                $callback($offset);
                $offset->traverse($callback);
            }
        }
        return $this;
    }
    /**
     * Builds frame offset sql.
     *
     * @param \Cake\Database\ValueBinder $binder Value binder
     * @param \Cake\Database\ExpressionInterface|string|int|null $offset Frame offset
     * @param string $direction Frame offset direction
     */
    protected function build_offset_sql(Value_Binder $binder, Expression_Interface|string|int|null $offset, string $direction): string
    {
        if ($offset === 0) {
            return 'CURRENT ROW';
        }
        if ($offset instanceof Expression_Interface) {
            $offset = $offset->sql($binder);
        }
        return sprintf('%s %s', $offset ?? 'UNBOUNDED', $direction);
    }
    /**
     * Clone this object and its subtree of expressions.
     */
    public function __clone()
    {
        $this->name = clone $this->name;
        foreach ($this->partitions as $i => $partition) {
            $this->partitions[$i] = clone $partition;
        }
        if ($this->order !== null) {
            $this->order = clone $this->order;
        }
    }
}