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
namespace Cake\Database\Statement;

use Cake\Database\Driver;
use Cake\Database\Statement_Interface;
use Cake\Database\Type_Factory;
use Cake\Database\Type_Interface;
use Generator;
use InvalidArgumentException;
use PDO;
use PDOStatement;
class Statement implements Statement_Interface
{
    /**
     * @var array<string, int>
     */
    protected const MODE_NAME_MAP = [self::FETCH_TYPE_ASSOC => PDO::FETCH_ASSOC, self::FETCH_TYPE_NUM => PDO::FETCH_NUM, self::FETCH_TYPE_OBJ => PDO::FETCH_OBJ];
    /**
     * Cached bound parameters used for logging
     *
     * @var array<mixed>
     */
    protected array $params = [];
    /**
     * @param \PDOStatement $statement PDO statement
     * @param \Cake\Database\Driver $_driver Database driver
     * @param array<\Closure> $resultDecorators Results decorators
     */
    public function __construct(protected PDOStatement $statement, protected Driver $_driver, protected array $result_decorators = [])
    {
    }
    /**
     * @inheritDoc
     */
    public function bind(array $params, array $types): void
    {
        if (!$params) {
            return;
        }
        $anonymous_params = is_int(key($params));
        $offset = 1;
        foreach ($params as $index => $value) {
            $type = $types[$index] ?? null;
            if ($anonymous_params) {
                $index += $offset;
            }
            $this->bind_value($index, $value, $type);
        }
    }
    /**
     * @inheritDoc
     */
    public function bind_value(string|int $column, mixed $value, string|int|null $type = 'string'): void
    {
        $type ??= 'string';
        if (!is_int($type)) {
            [$value, $type] = $this->cast($value, $type);
        }
        $this->params[$column] = $value;
        $this->perform_bind($column, $value, $type);
    }
    /**
     * Converts a given value to a suitable database value based on type and
     * return relevant internal statement type.
     *
     * @param mixed $value The value to cast.
     * @param \Cake\Database\TypeInterface|string|int $type The type name or type instance to use.
     * @return array List containing converted value and internal type.
     * @phpstan-return array{0:mixed, 1:int}
     */
    protected function cast(mixed $value, Type_Interface|string|int $type = 'string'): array
    {
        if (is_string($type)) {
            $type = Type_Factory::build($type);
        }
        if ($type instanceof Type_Interface) {
            $value = $type->to_database($value, $this->_driver);
            $type = $type->to_statement($value, $this->_driver);
        }
        return [$value, $type];
    }
    /**
     * @inheritDoc
     */
    public function get_bound_params(): array
    {
        return $this->params;
    }
    protected function perform_bind(string|int $column, mixed $value, int $type): void
    {
        $this->statement->bind_value($column, $value, $type);
    }
    /**
     * @inheritDoc
     */
    public function execute(?array $params = null): bool
    {
        return $this->statement->execute($params);
    }
    /**
     * @inheritDoc
     */
    public function fetch(string|int $mode = PDO::FETCH_NUM): mixed
    {
        $mode = $this->convert_mode($mode);
        $row = $this->statement->fetch($mode);
        if ($row === false) {
            return false;
        }
        foreach ($this->result_decorators as $decorator) {
            $row = $decorator($row);
        }
        return $row;
    }
    /**
     * @inheritDoc
     */
    public function fetch_assoc(): array
    {
        return $this->fetch(PDO::FETCH_ASSOC) ?: [];
    }
    /**
     * @inheritDoc
     */
    public function fetch_column(int $position): mixed
    {
        $row = $this->fetch(PDO::FETCH_NUM);
        if ($row && isset($row[$position])) {
            return $row[$position];
        }
        return false;
    }
    /**
     * @inheritDoc
     */
    public function fetch_all(string|int $mode = PDO::FETCH_NUM): array
    {
        $mode = $this->convert_mode($mode);
        $rows = $this->statement->fetch_all($mode);
        foreach ($this->result_decorators as $decorator) {
            $rows = array_map($decorator, $rows);
        }
        return $rows;
    }
    /**
     * Converts mode name to PDO constant.
     *
     * @param string|int $mode Mode name or PDO constant
     * @throws \InvalidArgumentException
     */
    protected function convert_mode(string|int $mode): int
    {
        if (is_int($mode)) {
            // We don't try to validate the PDO constants
            return $mode;
        }
        return static::MODE_NAME_MAP[$mode] ?? throw new InvalidArgumentException("Invalid fetch mode requested. Expected 'assoc', 'num' or 'obj'.");
    }
    /**
     * @inheritDoc
     */
    public function close_cursor(): void
    {
        $this->statement->close_cursor();
    }
    /**
     * @inheritDoc
     */
    public function row_count(): int
    {
        return $this->statement->row_count();
    }
    /**
     * @inheritDoc
     */
    public function column_count(): int
    {
        return $this->statement->column_count();
    }
    /**
     * @inheritDoc
     */
    public function error_code(): string
    {
        return $this->statement->error_code() ?: '';
    }
    /**
     * @inheritDoc
     */
    public function error_info(): array
    {
        return $this->statement->error_info();
    }
    /**
     * @inheritDoc
     */
    public function last_insert_id(?string $table = null, ?string $column = null): string|int
    {
        if ($column && $this->column_count()) {
            $row = $this->fetch(static::FETCH_TYPE_ASSOC);
            if ($row && isset($row[$column])) {
                return $row[$column];
            }
        }
        return $this->_driver->last_insert_id($table);
    }
    /**
     * Returns prepared query string stored in PDOStatement.
     */
    public function query_string(): string
    {
        return $this->statement->query_string;
    }
    /**
     * Get the inner iterator
     */
    public function getIterator(): Generator
    {
        $this->statement->set_fetch_mode(PDO::FETCH_ASSOC);
        foreach ($this->statement as $row) {
            foreach ($this->result_decorators as $decorator) {
                $row = $decorator($row);
            }
            yield $row;
        }
        $this->close_cursor();
    }
}