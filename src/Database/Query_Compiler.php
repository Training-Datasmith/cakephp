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

use Cake\Database\Exception\Database_Exception;
use Closure;
use Countable;
/**
 * Responsible for compiling a Query object into its SQL representation
 *
 * @internal
 */
class Query_Compiler
{
    /**
     * List of sprintf templates that will be used for compiling the SQL for
     * this query. There are some clauses that can be built as just as the
     * direct concatenation of the internal parts, those are listed here.
     *
     * @var array<string, string>
     */
    protected array $_templates = ['delete' => 'DELETE', 'where' => ' WHERE %s', 'group' => ' GROUP BY %s', 'having' => ' HAVING %s', 'order' => ' %s', 'limit' => ' LIMIT %s', 'offset' => ' OFFSET %s', 'epilog' => ' %s', 'comment' => '/* %s */ '];
    /**
     * The list of query clauses to traverse for generating a SELECT statement
     *
     * @var array<string>
     */
    protected array $_select_parts = ['comment', 'with', 'select', 'from', 'join', 'where', 'group', 'having', 'window', 'order', 'limit', 'offset', 'union', 'epilog', 'intersect'];
    /**
     * The list of query clauses to traverse for generating an UPDATE statement
     *
     * @var array<string>
     */
    protected array $_update_parts = ['comment', 'with', 'update', 'set', 'where', 'epilog'];
    /**
     * The list of query clauses to traverse for generating a DELETE statement
     *
     * @var array<string>
     */
    protected array $_delete_parts = ['comment', 'with', 'delete', 'optimizerHint', 'modifier', 'from', 'where', 'epilog'];
    /**
     * The list of query clauses to traverse for generating an INSERT statement
     *
     * @var array<string>
     */
    protected array $_insert_parts = ['comment', 'with', 'insert', 'values', 'epilog'];
    /**
     * Indicate whether aliases in SELECT clause need to be always quoted.
     */
    protected bool $_quoted_select_aliases = false;
    /**
     * Returns the SQL representation of the provided query after generating
     * the placeholders for the bound values using the provided generator
     *
     * @param \Cake\Database\Query $query The query that is being compiled
     * @param \Cake\Database\ValueBinder $binder Value binder used to generate parameter placeholders
     */
    public function compile(Query $query, Value_Binder $binder): string
    {
        $sql = '';
        $type = $query->type();
        $query->traverse_parts($this->_sql_compiler($sql, $query, $binder), $this->{"_{$type}Parts"});
        // Propagate bound parameters from sub-queries if the
        // placeholders can be found in the SQL statement. Only
        // add new placeholders, as sub-queries may have been executed already.
        if ($query->get_value_binder() !== $binder) {
            $existing = $binder->bindings();
            foreach ($query->get_value_binder()->bindings() as $binding) {
                $placeholder = ':' . $binding['placeholder'];
                if (!isset($existing[$placeholder]) && preg_match('/' . $placeholder . '(?:\W|$)/', $sql) > 0) {
                    $binder->bind($placeholder, $binding['value'], $binding['type']);
                }
            }
        }
        return $sql;
    }
    /**
     * Returns a closure that can be used to compile a SQL string representation
     * of this query.
     *
     * @param string $sql initial sql string to append to
     * @param \Cake\Database\Query $query The query that is being compiled
     * @param \Cake\Database\ValueBinder $binder Value binder used to generate parameter placeholder
     */
    protected function _sql_compiler(string &$sql, Query $query, Value_Binder $binder): Closure
    {
        return function ($part, string $part_name) use (&$sql, $query, $binder): void {
            if ($part === null || $part === [] || $part instanceof Countable && count($part) === 0) {
                return;
            }
            if ($part instanceof Expression_Interface) {
                $part = [$part->sql($binder)];
            }
            if (isset($this->_templates[$part_name])) {
                $part = $this->_stringify_expressions((array) $part, $binder);
                $sql .= sprintf($this->_templates[$part_name], implode(', ', $part));
                return;
            }
            $sql .= $this->{'_build' . $part_name . 'Part'}($part, $query, $binder);
        };
    }
    /**
     * Helper function used to build the string representation of a `WITH` clause,
     * it constructs the CTE definitions list and generates the `RECURSIVE`
     * keyword when required.
     *
     * @param array<\Cake\Database\Expression\CommonTableExpression> $parts List of CTEs to be transformed to string
     * @param \Cake\Database\Query $query The query that is being compiled
     * @param \Cake\Database\ValueBinder $binder Value binder used to generate parameter placeholder
     */
    protected function _build_with_part(array $parts, Query $query, Value_Binder $binder): string
    {
        $recursive = false;
        $expressions = [];
        foreach ($parts as $cte) {
            $recursive = $recursive || $cte->is_recursive();
            $expressions[] = $cte->sql($binder);
        }
        $recursive = $recursive ? 'RECURSIVE ' : '';
        return sprintf('WITH %s%s ', $recursive, implode(', ', $expressions));
    }
    /**
     * Helper function used to build the string representation of a SELECT clause,
     * it constructs the field list taking care of aliasing and
     * converting expression objects to string. This function also constructs the
     * DISTINCT clause for the query.
     *
     * @param array $parts list of fields to be transformed to string
     * @param \Cake\Database\Query $query The query that is being compiled
     * @param \Cake\Database\ValueBinder $binder Value binder used to generate parameter placeholder
     */
    protected function _build_select_part(array $parts, Query $query, Value_Binder $binder): string
    {
        $driver = $query->get_driver();
        $select = 'SELECT%s%s %s%s';
        if (($query->clause('union') || $query->clause('intersect')) && $driver->supports(Driver_Feature_Enum::SET_OPERATIONS_ORDER_BY)) {
            $select = '(SELECT%s%s %s%s';
        }
        $hint = $this->_build_optimizer_hint_part($query->clause('optimizerHint'), $query, $binder);
        $modifiers = $this->_build_modifier_part($query->clause('modifier'), $query, $binder);
        $quote_identifiers = $driver->is_auto_quoting_enabled() || $this->_quoted_select_aliases;
        $normalized = [];
        $parts = $this->_stringify_expressions($parts, $binder);
        foreach ($parts as $k => $p) {
            if (!is_numeric($k)) {
                $p .= ' AS ';
                if ($quote_identifiers) {
                    $p .= $driver->quote_identifier($k);
                } else {
                    $p .= $k;
                }
            }
            $normalized[] = $p;
        }
        $distinct = $query->clause('distinct');
        if ($distinct === true) {
            $distinct = 'DISTINCT ';
        } elseif (is_array($distinct)) {
            $distinct = $this->_stringify_expressions($distinct, $binder);
            $distinct = sprintf('DISTINCT ON (%s) ', implode(', ', $distinct));
        }
        return sprintf($select, $hint, $modifiers, $distinct, implode(', ', $normalized));
    }
    /**
     * Helper function used to build the string representation of a FROM clause,
     * it constructs the tables list taking care of aliasing and
     * converting expression objects to string.
     *
     * @param array $parts list of tables to be transformed to string
     * @param \Cake\Database\Query $query The query that is being compiled
     * @param \Cake\Database\ValueBinder $binder Value binder used to generate parameter placeholder
     */
    protected function _build_from_part(array $parts, Query $query, Value_Binder $binder): string
    {
        $select = ' FROM %s';
        $normalized = [];
        $parts = $this->_stringify_expressions($parts, $binder);
        foreach ($parts as $k => $p) {
            if (!is_numeric($k)) {
                $p = $p . ' ' . $k;
            }
            $normalized[] = $p;
        }
        return sprintf($select, implode(', ', $normalized));
    }
    /**
     * Helper function used to build the string representation of multiple JOIN clauses,
     * it constructs the joins list taking care of aliasing and converting
     * expression objects to string in both the table to be joined and the conditions
     * to be used.
     *
     * @param array $parts list of joins to be transformed to string
     * @param \Cake\Database\Query $query The query that is being compiled
     * @param \Cake\Database\ValueBinder $binder Value binder used to generate parameter placeholder
     */
    protected function _build_join_part(array $parts, Query $query, Value_Binder $binder): string
    {
        $joins = '';
        foreach ($parts as $join) {
            if (!isset($join['table'])) {
                throw new Database_Exception(sprintf('Could not compile join clause for alias `%s`. No table was specified. ' . 'Use the `table` key to define a table.', $join['alias']));
            }
            if ($join['table'] instanceof Expression_Interface) {
                $join['table'] = '(' . $join['table']->sql($binder) . ')';
            }
            $joins .= sprintf(' %s JOIN %s %s', $join['type'], $join['table'], $join['alias']);
            $condition = '';
            if (isset($join['conditions']) && $join['conditions'] instanceof Expression_Interface) {
                $condition = $join['conditions']->sql($binder);
            }
            if ($condition === '') {
                $joins .= ' ON 1 = 1';
            } else {
                $joins .= " ON {$condition}";
            }
        }
        return $joins;
    }
    /**
     * Helper function to build the string representation of a window clause.
     *
     * @param array $parts List of windows to be transformed to string
     * @param \Cake\Database\Query $query The query that is being compiled
     * @param \Cake\Database\ValueBinder $binder Value binder used to generate parameter placeholder
     */
    protected function _build_window_part(array $parts, Query $query, Value_Binder $binder): string
    {
        $windows = [];
        foreach ($parts as $window) {
            /** @var \Cake\Database\Expression\IdentifierExpression $expr */
            $expr = $window['name'];
            /** @var \Cake\Database\Expression\IdentifierExpression $windowExpr */
            $window_expr = $window['window'];
            $windows[] = $expr->sql($binder) . ' AS (' . $window_expr->sql($binder) . ')';
        }
        return ' WINDOW ' . implode(', ', $windows);
    }
    /**
     * Helper function to generate SQL for SET expressions.
     *
     * @param array $parts List of keys and values to set.
     * @param \Cake\Database\Query $query The query that is being compiled
     * @param \Cake\Database\ValueBinder $binder Value binder used to generate parameter placeholder
     */
    protected function _build_set_part(array $parts, Query $query, Value_Binder $binder): string
    {
        $set = [];
        foreach ($parts as $part) {
            if ($part instanceof Expression_Interface) {
                $part = $part->sql($binder);
            }
            if (str_starts_with((string) $part, '(')) {
                $part = substr((string) $part, 1, -1);
            }
            $set[] = $part;
        }
        return ' SET ' . implode('', $set);
    }
    /**
     * Builds the SQL string for all the `operation` clauses in this query, when dealing
     * with query objects it will also transform them using their configured SQL
     * dialect.
     */
    protected function _build_set_operation_part(string $operation, array $parts, Query $query, Value_Binder $binder): string
    {
        $set_operations_order_by = $query->get_connection()->get_driver($query->get_connection_role())->supports(Driver_Feature_Enum::SET_OPERATIONS_ORDER_BY);
        $parts = array_map(function (array $p) use ($binder, $set_operations_order_by): string {
            /** @var \Cake\Database\Expression\IdentifierExpression $expr */
            $expr = $p['query'];
            $p['query'] = $expr->sql($binder);
            $p['query'] = str_starts_with($p['query'], '(') ? trim($p['query'], '()') : $p['query'];
            $prefix = $p['all'] ? 'ALL ' : '';
            if ($set_operations_order_by) {
                return "{$prefix}({$p['query']})";
            }
            return $prefix . $p['query'];
        }, $parts);
        if ($set_operations_order_by) {
            return sprintf(")\n{$operation} %s", implode("\n{$operation} ", $parts));
        }
        return sprintf("\n{$operation} %s", implode("\n{$operation} ", $parts));
    }
    /**
     * Builds the SQL string for all the INTERSECT clauses in this query, when dealing
     * with query objects it will also transform them using their configured SQL
     * dialect.
     *
     * @param array $parts list of queries to be operated with INTERSECT
     * @param \Cake\Database\Query $query The query that is being compiled
     * @param \Cake\Database\ValueBinder $binder Value binder used to generate parameter placeholder
     */
    protected function _build_intersect_part(array $parts, Query $query, Value_Binder $binder): string
    {
        return $this->_build_set_operation_part('INTERSECT', $parts, $query, $binder);
    }
    /**
     * Builds the SQL string for all the UNION clauses in this query, when dealing
     * with query objects it will also transform them using their configured SQL
     * dialect.
     *
     * @param array $parts list of queries to be operated with UNION
     * @param \Cake\Database\Query $query The query that is being compiled
     * @param \Cake\Database\ValueBinder $binder Value binder used to generate parameter placeholder
     */
    protected function _build_union_part(array $parts, Query $query, Value_Binder $binder): string
    {
        return $this->_build_set_operation_part('UNION', $parts, $query, $binder);
    }
    /**
     * Builds the SQL fragment for INSERT INTO.
     *
     * @param array $parts The insert parts.
     * @param \Cake\Database\Query $query The query that is being compiled
     * @param \Cake\Database\ValueBinder $binder Value binder used to generate parameter placeholder
     * @return string SQL fragment.
     */
    protected function _build_insert_part(array $parts, Query $query, Value_Binder $binder): string
    {
        if (!isset($parts[0])) {
            throw new Database_Exception('Could not compile insert query. No table was specified. ' . 'Use `into()` to define a table.');
        }
        $table = $parts[0];
        $columns = $this->_stringify_expressions($parts[1], $binder);
        $hint = $this->_build_optimizer_hint_part($query->clause('optimizerHint'), $query, $binder);
        $modifiers = $this->_build_modifier_part($query->clause('modifier'), $query, $binder);
        return sprintf('INSERT%s%s INTO %s (%s)', $hint, $modifiers, $table, implode(', ', $columns));
    }
    /**
     * Builds the SQL fragment for INSERT INTO.
     *
     * @param array $parts The values parts.
     * @param \Cake\Database\Query $query The query that is being compiled
     * @param \Cake\Database\ValueBinder $binder Value binder used to generate parameter placeholder
     * @return string SQL fragment.
     */
    protected function _build_values_part(array $parts, Query $query, Value_Binder $binder): string
    {
        return implode('', $this->_stringify_expressions($parts, $binder));
    }
    /**
     * Builds the SQL fragment for UPDATE.
     *
     * @param array $parts The update parts.
     * @param \Cake\Database\Query $query The query that is being compiled
     * @param \Cake\Database\ValueBinder $binder Value binder used to generate parameter placeholder
     * @return string SQL fragment.
     */
    protected function _build_update_part(array $parts, Query $query, Value_Binder $binder): string
    {
        $table = $this->_stringify_expressions($parts, $binder);
        $hint = $this->_build_optimizer_hint_part($query->clause('optimizerHint'), $query, $binder);
        $modifiers = $this->_build_modifier_part($query->clause('modifier'), $query, $binder);
        return sprintf('UPDATE%s%s %s', $hint, $modifiers, implode(',', $table));
    }
    /**
     * Builds the optimizer hint comment part.
     *
     * @param list<string> $parts The optmizer hints
     * @param \Cake\Database\Query $query The query that is being compiled
     * @param \Cake\Database\ValueBinder $binder Value binder used to generate parameter placeholder
     * @return string Optimizer hint comment
     */
    protected function _build_optimizer_hint_part(array $parts, Query $query, Value_Binder $binder): string
    {
        if ($parts === [] || !$query->get_driver()->supports(Driver_Feature_Enum::OPTIMIZER_HINT_COMMENT)) {
            return '';
        }
        return sprintf(' /*+ %s */', implode(' ', $parts));
    }
    /**
     * Builds the SQL modifier fragment
     *
     * @param array $parts The query modifier parts
     * @param \Cake\Database\Query $query The query that is being compiled
     * @param \Cake\Database\ValueBinder $binder Value binder used to generate parameter placeholder
     * @return string SQL fragment.
     */
    protected function _build_modifier_part(array $parts, Query $query, Value_Binder $binder): string
    {
        if ($parts === []) {
            return '';
        }
        return ' ' . implode(' ', $this->_stringify_expressions($parts, $binder, false));
    }
    /**
     * Helper function used to covert ExpressionInterface objects inside an array
     * into their string representation.
     *
     * @param array $expressions list of strings and ExpressionInterface objects
     * @param \Cake\Database\ValueBinder $binder Value binder used to generate parameter placeholder
     * @param bool $wrap Whether to wrap each expression object with parenthesis
     */
    protected function _stringify_expressions(array $expressions, Value_Binder $binder, bool $wrap = true): array
    {
        $result = [];
        foreach ($expressions as $k => $expression) {
            if ($expression instanceof Expression_Interface) {
                $value = $expression->sql($binder);
                $expression = $wrap ? '(' . $value . ')' : $value;
            }
            $result[$k] = $expression;
        }
        return $result;
    }
}