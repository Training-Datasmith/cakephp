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
 * @since         3.1.2
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Database\Type;

use Cake\Database\Driver;
use InvalidArgumentException;
use PDO;
use Stringable;
/**
 * String type converter.
 *
 * Use to convert string data between PHP and the database types.
 */
class String_Type extends Base_Type implements Optional_Convert_Interface
{
    /**
     * Convert string data into the database format.
     *
     * @param mixed $value The value to convert.
     * @param \Cake\Database\Driver $driver The driver instance to convert with.
     */
    public function to_database(mixed $value, Driver $driver): ?string
    {
        if ($value === null || is_string($value)) {
            return $value;
        }
        if ($value instanceof Stringable) {
            return (string) $value;
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        throw new InvalidArgumentException(sprintf('Cannot convert value `%s` of type `%s` to string', print_r($value, true), get_debug_type($value)));
    }
    /**
     * Convert string values to PHP strings.
     *
     * @param mixed $value The value to convert.
     * @param \Cake\Database\Driver $driver The driver instance to convert with.
     */
    public function to_php(mixed $value, Driver $driver): ?string
    {
        if ($value === null) {
            return null;
        }
        return (string) $value;
    }
    /**
     * @inheritDoc
     */
    public function to_statement(mixed $value, Driver $driver): int
    {
        return PDO::PARAM_STR;
    }
    /**
     * Marshals request data into PHP strings.
     *
     * @param mixed $value The value to convert.
     * @return string|null Converted value.
     */
    public function marshal(mixed $value): ?string
    {
        if ($value === null || is_array($value)) {
            return null;
        }
        return (string) $value;
    }
    /**
     * {@inheritDoc}
     *
     * @return bool False as database results are returned already as strings
     */
    public function requires_to_php_cast(): bool
    {
        return false;
    }
}