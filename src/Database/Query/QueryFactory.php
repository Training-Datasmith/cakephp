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
 * @since         5.0.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Database\Query;

use Cake\Database\Connection;
use Cake\Database\Expression_Interface;
use Closure;
/**
 * Factory class for generating instances of Select, Insert, Update, Delete queries.
 */
class Query_Factory
{
    /**
     * Constructor/
     *
     * @param \Cake\Database\Connection $connection Connection instance.
     */
    public function __construct(protected Connection $connection)
    {
    }
    /**
     * Create a new SelectQuery instance.
     *
     * @param \Cake\Database\ExpressionInterface|\Closure|array|string|float|int $fields Fields/columns list for the query.
     * @param array|string $table List of tables to query.
     * @param array<string, string> $types Associative array containing the types to be used for casting.
     * @return \Cake\Database\Query\SelectQuery<mixed>
     */
    public function select(Expression_Interface|Closure|array|string|float|int $fields = [], array|string $table = [], array $types = []): Select_Query
    {
        $query = new Select_Query($this->connection);
        $query->select($fields)->from($table)->set_default_types($types);
        return $query;
    }
    /**
     * Create a new InsertQuery instance.
     *
     * @param string|null $table The table to insert rows into.
     * @param array $values Associative array of column => value to be inserted.
     * @param array<int|string, string> $types Associative array containing the types to be used for casting.
     */
    public function insert(?string $table = null, array $values = [], array $types = []): Insert_Query
    {
        $query = new Insert_Query($this->connection);
        if ($table) {
            $query->into($table);
        }
        if ($values) {
            $columns = array_keys($values);
            $query->insert($columns, $types)->values($values);
        }
        return $query;
    }
    /**
     * Create a new UpdateQuery instance.
     *
     * @param \Cake\Database\ExpressionInterface|string|null $table The table to update rows of.
     * @param array $values Values to be updated.
     * @param array $conditions Conditions to be set for the update statement.
     * @param array<string, string> $types Associative array containing the types to be used for casting.
     */
    public function update(Expression_Interface|string|null $table = null, array $values = [], array $conditions = [], array $types = []): Update_Query
    {
        $query = new Update_Query($this->connection);
        if ($table) {
            $query->update($table);
        }
        if ($values) {
            $query->set($values, $types);
        }
        if ($conditions) {
            $query->where($conditions, $types);
        }
        return $query;
    }
    /**
     * Create a new DeleteQuery instance.
     *
     * @param string|null $table The table to delete rows from.
     * @param array $conditions Conditions to be set for the delete statement.
     * @param array<string, string> $types Associative array containing the types to be used for casting.
     */
    public function delete(?string $table = null, array $conditions = [], array $types = []): Delete_Query
    {
        $query = (new Delete_Query($this->connection))->delete($table);
        if ($conditions) {
            $query->where($conditions, $types);
        }
        return $query;
    }
}