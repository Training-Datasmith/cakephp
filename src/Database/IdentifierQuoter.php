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
use Cake\Database\Expression\Field_Interface;
use Cake\Database\Expression\Identifier_Expression;
use Cake\Database\Expression\Order_By_Expression;
use Cake\Database\Query\Delete_Query;
use Cake\Database\Query\Insert_Query;
use Cake\Database\Query\Select_Query;
use Cake\Database\Query\Update_Query;
/**
 * Contains all the logic related to quoting identifiers in a Query object
 *
 * @internal
 */
class Identifier_Quoter
{
    /**
     * Constructor
     *
     * @param string $startQuote String used to start a database identifier quoting to make it safe.
     * @param string $endQuote String used to end a database identifier quoting to make it safe.
     */
    public function __construct(protected string $start_quote, protected string $end_quote)
    {
    }
    /**
     * Quotes a database identifier (a column name, table name, etc..) to
     * be used safely in queries without the risk of using reserved words
     *
     * @param string $identifier The identifier to quote.
     */
    public function quote_identifier(string $identifier): string
    {
        $identifier = trim($identifier);
        if ($identifier === '*' || $identifier === '') {
            return $identifier;
        }
        // string
        if (preg_match('/^[\w-]+$/u', $identifier)) {
            return $this->start_quote . $identifier . $this->end_quote;
        }
        // string.string
        if (preg_match('/^[\w-]+\.[^ \*]*$/u', $identifier)) {
            $items = explode('.', $identifier);
            return $this->start_quote . implode($this->end_quote . '.' . $this->start_quote, $items) . $this->end_quote;
        }
        // string.*
        if (preg_match('/^[\w-]+\.\*$/u', $identifier)) {
            return $this->start_quote . str_replace('.*', $this->end_quote . '.*', $identifier);
        }
        // Functions
        if (preg_match('/^([\w-]+)\((.*)\)$/', $identifier, $matches)) {
            return $matches[1] . '(' . $this->quote_identifier($matches[2]) . ')';
        }
        // Alias.field AS thing
        if (preg_match('/^([\w-]+(\.[\w\s-]+|\(.*\))*)\s+AS\s*([\w-]+)$/ui', $identifier, $matches)) {
            return $this->quote_identifier($matches[1]) . ' AS ' . $this->quote_identifier($matches[3]);
        }
        // string.string with spaces
        if (preg_match('/^([\w-]+\.[\w][\w\s-]*[\w])(.*)/u', $identifier, $matches)) {
            $items = explode('.', $matches[1]);
            $field = implode($this->end_quote . '.' . $this->start_quote, $items);
            return $this->start_quote . $field . $this->end_quote . $matches[2];
        }
        if (preg_match('/^[\w\s-]*[\w-]+/u', $identifier)) {
            return $this->start_quote . $identifier . $this->end_quote;
        }
        return $identifier;
    }
    /**
     * Iterates over each of the clauses in a query looking for identifiers and
     * quotes them
     *
     * @param \Cake\Database\Query $query The query to have its identifiers quoted
     */
    public function quote(Query $query): Query
    {
        $binder = $query->get_value_binder();
        $query->set_value_binder(null);
        match (true) {
            $query instanceof Insert_Query => $this->_quote_insert($query),
            $query instanceof Select_Query => $this->_quote_select($query),
            $query instanceof Update_Query => $this->_quote_update($query),
            $query instanceof Delete_Query => $this->_quote_delete($query),
            default => throw new Database_Exception(sprintf('Instance of SelectQuery, UpdateQuery, InsertQuery, DeleteQuery expected. Found `%s` instead.', get_debug_type($query))),
        };
        $query->traverse_expressions($this->quote_expression(...));
        return $query->set_value_binder($binder);
    }
    /**
     * Quotes identifiers inside expression objects
     *
     * @param \Cake\Database\ExpressionInterface $expression The expression object to walk and quote.
     */
    public function quote_expression(Expression_Interface $expression): void
    {
        match (true) {
            $expression instanceof Field_Interface => $this->_quote_comparison($expression),
            $expression instanceof Order_By_Expression => $this->_quote_order_by($expression),
            $expression instanceof Identifier_Expression => $this->_quote_identifier_expression($expression),
            default => null,
        };
    }
    /**
     * Quotes all identifiers in each of the clauses/parts of a query
     *
     * @param \Cake\Database\Query $query The query to quote.
     * @param array<string> $parts Query clauses.
     */
    protected function _quote_parts(Query $query, array $parts): void
    {
        foreach ($parts as $part) {
            $contents = $query->clause($part);
            if (!is_array($contents)) {
                continue;
            }
            $result = $this->_basic_quoter($contents);
            if ($result) {
                $part = match ($part) {
                    'group' => 'groupBy',
                    'order' => 'orderBy',
                    default => $part,
                };
                $query->{$part}($result, true);
            }
        }
    }
    /**
     * A generic identifier quoting function used for various parts of the query
     *
     * @param array<string, mixed> $part the part of the query to quote
     * @return array<string, mixed>
     */
    protected function _basic_quoter(array $part): array
    {
        $result = [];
        foreach ($part as $alias => $value) {
            $value = is_string($value) ? $this->quote_identifier($value) : $value;
            $alias = is_numeric($alias) ? $alias : $this->quote_identifier($alias);
            $result[$alias] = $value;
        }
        return $result;
    }
    /**
     * Quotes both the table and alias for an array of joins as stored in a Query
     * object
     *
     * @param array<array> $joins The joins to quote.
     * @return array<string, array>
     */
    protected function _quote_joins(array $joins): array
    {
        $result = [];
        foreach ($joins as $value) {
            $alias = '';
            if (!empty($value['alias'])) {
                $alias = $this->quote_identifier($value['alias']);
                $value['alias'] = $alias;
            }
            if (is_string($value['table'])) {
                $value['table'] = $this->quote_identifier($value['table']);
            }
            $result[$alias] = $value;
        }
        return $result;
    }
    /**
     * Quotes all identifiers in each of the clauses of a SELECT query
     *
     * @param \Cake\Database\Query\SelectQuery<mixed> $query The query to quote.
     */
    protected function _quote_select(Select_Query $query): void
    {
        $this->_quote_parts($query, ['select', 'distinct', 'from', 'group']);
        $joins = $query->clause('join');
        if ($joins) {
            $joins = $this->_quote_joins($joins);
            $query->join($joins, [], true);
        }
    }
    /**
     * Quotes all identifiers in each of the clauses of a DELETE query
     *
     * @param \Cake\Database\Query\DeleteQuery $query The query to quote.
     */
    protected function _quote_delete(Delete_Query $query): void
    {
        $this->_quote_parts($query, ['from']);
        $joins = $query->clause('join');
        if ($joins) {
            $joins = $this->_quote_joins($joins);
            $query->join($joins, [], true);
        }
    }
    /**
     * Quotes the table name and columns for an insert query
     *
     * @param \Cake\Database\Query\InsertQuery $query The insert query to quote.
     */
    protected function _quote_insert(Insert_Query $query): void
    {
        /** @var array{0?: string, 1?: array} $insert */
        $insert = $query->clause('insert');
        if (!isset($insert[0]) || !isset($insert[1])) {
            return;
        }
        [$table, $columns] = $insert;
        $table = $this->quote_identifier($table);
        foreach ($columns as &$column) {
            if (is_scalar($column)) {
                $column = $this->quote_identifier((string) $column);
            }
        }
        $query->insert($columns)->into($table);
    }
    /**
     * Quotes the table name for an update query
     *
     * @param \Cake\Database\Query\UpdateQuery $query The update query to quote.
     */
    protected function _quote_update(Update_Query $query): void
    {
        $table = $query->clause('update')[0];
        if (is_string($table)) {
            $query->update($this->quote_identifier($table));
        }
    }
    /**
     * Quotes identifiers in expression objects implementing the field interface
     *
     * @param \Cake\Database\Expression\FieldInterface $expression The expression to quote.
     */
    protected function _quote_comparison(Field_Interface $expression): void
    {
        $field = $expression->get_field();
        if (is_string($field)) {
            $expression->set_field($this->quote_identifier($field));
        } elseif (is_array($field)) {
            $quoted = [];
            foreach ($field as $f) {
                $quoted[] = $this->quote_identifier($f);
            }
            $expression->set_field($quoted);
        } else {
            $this->quote_expression($field);
        }
    }
    /**
     * Quotes identifiers in "order by" expression objects
     *
     * Strings with spaces are treated as literal expressions
     * and will not have identifiers quoted.
     *
     * @param \Cake\Database\Expression\OrderByExpression $expression The expression to quote.
     */
    protected function _quote_order_by(Order_By_Expression $expression): void
    {
        $expression->iterate_parts(function ($part, &$field) {
            if (is_string($field)) {
                $field = $this->quote_identifier($field);
                return $part;
            }
            if (is_string($part) && !str_contains($part, ' ')) {
                return $this->quote_identifier($part);
            }
            return $part;
        });
    }
    /**
     * Quotes identifiers in "order by" expression objects
     *
     * @param \Cake\Database\Expression\IdentifierExpression $expression The identifiers to quote.
     */
    protected function _quote_identifier_expression(Identifier_Expression $expression): void
    {
        $expression->set_identifier($this->quote_identifier($expression->get_identifier()));
    }
}