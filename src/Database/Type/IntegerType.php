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
namespace Cake\Database\Type;

use Cake\Database\Driver;
use InvalidArgumentException;
use PDO;
/**
 * Integer type converter.
 *
 * Use to convert integer data between PHP and the database types.
 */
class Integer_Type extends Base_Type implements Batch_Casting_Interface
{
    /**
     * Checks if the value is not a numeric value
     *
     * @throws \InvalidArgumentException
     * @param mixed $value Value to check
     */
    protected function check_numeric(mixed $value): void
    {
        if (!is_numeric($value) && !is_bool($value)) {
            throw new InvalidArgumentException(sprintf('Cannot convert value `%s` of type `%s` to int', print_r($value, true), get_debug_type($value)));
        }
    }
    /**
     * Convert integer data into the database format.
     *
     * @param mixed $value The value to convert.
     * @param \Cake\Database\Driver $driver The driver instance to convert with.
     */
    public function to_database(mixed $value, Driver $driver): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $this->check_numeric($value);
        return (int) $value;
    }
    /**
     * {@inheritDoc}
     *
     * @param mixed $value The value to convert.
     * @param \Cake\Database\Driver $driver The driver instance to convert with.
     */
    public function to_php(mixed $value, Driver $driver): ?int
    {
        if ($value === null) {
            return null;
        }
        return (int) $value;
    }
    /**
     * @inheritDoc
     */
    public function many_to_php(array $values, array $fields, Driver $driver): array
    {
        foreach ($fields as $field) {
            if (!isset($values[$field])) {
                continue;
            }
            $this->check_numeric($values[$field]);
            $values[$field] = (int) $values[$field];
        }
        return $values;
    }
    /**
     * @inheritDoc
     */
    public function to_statement(mixed $value, Driver $driver): int
    {
        return PDO::PARAM_INT;
    }
    /**
     * Marshals request data into PHP integers.
     *
     * @param mixed $value The value to convert.
     * @return int|null Converted value.
     */
    public function marshal(mixed $value): ?int
    {
        if ($value === '' || !is_numeric($value)) {
            return null;
        }
        return (int) $value;
    }
}