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
use Cake\Database\Query;
use Cake\Database\Type\Expression_Type_Caster_Trait;
use Cake\Database\Type_Map;
use Cake\Database\Type_Map_Trait;
use Cake\Database\Value_Binder;
use Closure;
/**
 * An expression object to contain values being inserted.
 *
 * Helps generate SQL with the correct number of placeholders and bind
 * values correctly into the statement.
 */
class Values_Expression implements Expression_Interface
{
    use Expression_Type_Caster_Trait;
    use Type_Map_Trait;
    /**
     * Array of values to insert.
     */
    protected array $_values = [];
    /**
     * The Query object to use as a values expression
     */
    protected ?Query $_query = null;
    /**
     * Whether values have been casted to expressions
     * already.
     */
    protected bool $_casted_expressions = false;
    /**
     * Constructor
     *
     * @param array $_columns The list of columns that are going to be part of the values.
     * @param \Cake\Database\TypeMap $typeMap A dictionary of column -> type names
     */
    public function __construct(
        /**
         * List of columns to ensure are part of the insert.
         */
        protected array $_columns,
        Type_Map $type_map
    )
    {
        $this->set_type_map($type_map);
    }
    /**
     * Add a row of data to be inserted.
     *
     * @param \Cake\Database\Query|array $values Array of data to append into the insert, or
     *   a query for doing INSERT INTO .. SELECT style commands
     * @throws \Cake\Database\Exception\DatabaseException When mixing array and Query data types.
     */
    public function add(Query|array $values): void
    {
        if (count($this->_values) && $values instanceof Query || $this->_query && is_array($values)) {
            throw new Database_Exception('You cannot mix subqueries and array values in inserts.');
        }
        if ($values instanceof Query) {
            $this->set_query($values);
            return;
        }
        $this->_values[] = $values;
        $this->_casted_expressions = false;
    }
    /**
     * Sets the columns to be inserted.
     *
     * @param array $columns Array with columns to be inserted.
     * @return $this
     */
    public function set_columns(array $columns): static
    {
        $this->_columns = $columns;
        $this->_casted_expressions = false;
        return $this;
    }
    /**
     * Gets the columns to be inserted.
     */
    public function get_columns(): array
    {
        return $this->_columns;
    }
    /**
     * Get the bare column names.
     *
     * Because column names could be identifier quoted, we
     * need to strip the identifiers off of the columns.
     */
    protected function _column_names(): array
    {
        $columns = [];
        foreach ($this->_columns as $col) {
            if (is_string($col)) {
                $col = trim($col, '`[]"');
            }
            $columns[] = $col;
        }
        return $columns;
    }
    /**
     * Sets the values to be inserted.
     *
     * @param array $values Array with values to be inserted.
     * @return $this
     */
    public function set_values(array $values): static
    {
        $this->_values = $values;
        $this->_casted_expressions = false;
        return $this;
    }
    /**
     * Gets the values to be inserted.
     */
    public function get_values(): array
    {
        if (!$this->_casted_expressions) {
            $this->_process_expressions();
        }
        return $this->_values;
    }
    /**
     * Sets the query object to be used as the values expression to be evaluated
     * to insert records in the table.
     *
     * @param \Cake\Database\Query $query The query to set
     * @return $this
     */
    public function set_query(Query $query): static
    {
        $this->_query = $query;
        return $this;
    }
    /**
     * Gets the query object to be used as the values expression to be evaluated
     * to insert records in the table.
     */
    public function get_query(): ?Query
    {
        return $this->_query;
    }
    /**
     * @inheritDoc
     */
    public function sql(Value_Binder $binder): string
    {
        if (!$this->_values && $this->_query === null) {
            return '';
        }
        if (!$this->_casted_expressions) {
            $this->_process_expressions();
        }
        $columns = $this->_column_names();
        $defaults = array_fill_keys($columns, null);
        $placeholders = [];
        $types = [];
        $type_map = $this->get_type_map();
        foreach ($defaults as $col => $v) {
            $types[$col] = $type_map->type($col);
        }
        foreach ($this->_values as $row) {
            $row += $defaults;
            $row_placeholders = [];
            foreach ($columns as $column) {
                $value = $row[$column];
                if ($value instanceof Expression_Interface) {
                    $row_placeholders[] = '(' . $value->sql($binder) . ')';
                    continue;
                }
                $placeholder = $binder->placeholder('c');
                $row_placeholders[] = $placeholder;
                $binder->bind($placeholder, $value, $types[$column]);
            }
            $placeholders[] = implode(', ', $row_placeholders);
        }
        $query = $this->get_query();
        if ($query) {
            return ' ' . $query->sql($binder);
        }
        return sprintf(' VALUES (%s)', implode('), (', $placeholders));
    }
    /**
     * @inheritDoc
     */
    public function traverse(Closure $callback): static
    {
        if ($this->_query) {
            return $this;
        }
        if (!$this->_casted_expressions) {
            $this->_process_expressions();
        }
        foreach ($this->_values as $v) {
            if ($v instanceof Expression_Interface) {
                $v->traverse($callback);
            }
            if (!is_array($v)) {
                continue;
            }
            foreach ($v as $field) {
                if ($field instanceof Expression_Interface) {
                    $callback($field);
                    $field->traverse($callback);
                }
            }
        }
        return $this;
    }
    /**
     * Converts values that need to be casted to expressions
     */
    protected function _process_expressions(): void
    {
        $types = [];
        $type_map = $this->get_type_map();
        $columns = $this->_column_names();
        foreach ($columns as $c) {
            if (!is_string($c) && !is_int($c)) {
                continue;
            }
            $types[$c] = $type_map->type($c);
        }
        $types = $this->_requires_to_expression_casting($types);
        if (!$types) {
            return;
        }
        foreach ($this->_values as $row => $values) {
            foreach ($types as $col => $type) {
                /** @var \Cake\Database\Type\ExpressionTypeInterface $type */
                $this->_values[$row][$col] = $type->to_expression($values[$col]);
            }
        }
        $this->_casted_expressions = true;
    }
}