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
namespace Cake\Database\Type;

use Backed_Enum;
use Cake\Database\Driver;
use Cake\Database\Exception\Database_Exception;
use Cake\Database\Type_Factory;
use Cake\Utility\Text;
use InvalidArgumentException;
use PDO;
use Reflection_Enum;
use Reflection_Exception;
use TypeError;
use Value_Error;
/**
 * Enum type converter.
 *
 * Use to convert string data between PHP and the database types.
 */
class Enum_Type extends Base_Type
{
    /**
     * The type of the enum which is either string or int
     */
    protected string $backing_type;
    /**
     * @param string $name The name identifying this type
     * @param class-string<\BackedEnum> $enumClassName The associated enum class name
     */
    public function __construct(
        string $name,
        /**
         * The enum classname which is associated to the type instance
         */
        protected string $enum_class_name
    )
    {
        parent::__construct($name);
        try {
            $reflection_enum = new Reflection_Enum($this->enum_class_name);
        } catch (Reflection_Exception $e) {
            throw new Database_Exception(sprintf('Unable to use `%s` for type `%s`. %s.', $this->enum_class_name, $name, $e->get_message()));
        }
        $named_type = $reflection_enum->get_backing_type();
        if ($named_type === null) {
            throw new Database_Exception(sprintf('Unable to use enum `%s` for type `%s`, must be a backed enum.', $this->enum_class_name, $name));
        }
        $this->backing_type = (string) $named_type;
    }
    /**
     * Convert enum instances into the database format.
     *
     * @param mixed $value The value to convert.
     * @param \Cake\Database\Driver $driver The driver instance to convert with.
     * @throws \InvalidArgumentException When the given value is not a valid value for the associated enum
     */
    public function to_database(mixed $value, Driver $driver): string|int|null
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof $this->enum_class_name) {
            return $value->value;
        }
        if ($this->backing_type === 'int' && is_string($value)) {
            $int_val = filter_var($value, FILTER_VALIDATE_INT);
            if ($int_val !== false) {
                $value = $int_val;
            }
        }
        try {
            return $this->enum_class_name::from($value)->value;
        } catch (Value_Error|TypeError $exception) {
            if ($exception instanceof TypeError) {
                throw new InvalidArgumentException(sprintf('Given value `%s` of type `%s` does not match associated `%s` backed enum in `%s`', print_r($value, true), get_debug_type($value), $this->backing_type, $this->enum_class_name));
            }
            throw new InvalidArgumentException(sprintf('`%s` is not a valid value for `%s`', $value, $this->enum_class_name));
        }
    }
    /**
     * Transform DB value to backed enum instance
     *
     * @param mixed $value The value to convert.
     * @param \Cake\Database\Driver $driver The driver instance to convert with.
     */
    public function to_php(mixed $value, Driver $driver): ?Backed_Enum
    {
        if ($value === null) {
            return null;
        }
        if ($this->backing_type === 'int' && is_string($value)) {
            $int_val = filter_var($value, FILTER_VALIDATE_INT);
            if ($int_val !== false) {
                $value = $int_val;
            }
        }
        return $this->enum_class_name::from($value);
    }
    /**
     * @inheritDoc
     */
    public function to_statement(mixed $value, Driver $driver): int
    {
        if ($this->backing_type === 'int') {
            return PDO::PARAM_INT;
        }
        return PDO::PARAM_STR;
    }
    /**
     * Marshals request data
     *
     * @param mixed $value The value to convert.
     * @return \BackedEnum|null Converted value.
     */
    public function marshal(mixed $value): ?Backed_Enum
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof $this->enum_class_name) {
            return $value;
        }
        if ($this->backing_type === 'int') {
            if ($value === '') {
                return null;
            }
            if (is_numeric($value)) {
                $value = (int) $value;
            }
        }
        try {
            return $this->enum_class_name::from($value);
        } catch (Value_Error|TypeError) {
            return null;
        }
    }
    /**
     * Create an `EnumType` that is paired with the provided `$enumClassName`.
     *
     * ### Usage
     *
     * ```
     * // In a table class
     * $this->getSchema()->setColumnType('status', EnumType::from(StatusEnum::class));
     * ```
     *
     * @param class-string<\BackedEnum> $enumClassName The enum class name
     */
    public static function from(string $enum_class_name): string
    {
        $type_name = 'enum-' . strtolower(Text::slug($enum_class_name));
        $instance = new Enum_Type($type_name, $enum_class_name);
        Type_Factory::set($type_name, $instance);
        return $type_name;
    }
    /**
     * @return class-string<\BackedEnum>
     */
    public function get_enum_class_name(): string
    {
        return $this->enum_class_name;
    }
}