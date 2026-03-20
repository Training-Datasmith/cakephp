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
 * @since         4.0.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Database;

/**
 * Factory for building database type classes.
 */
class Type_Factory
{
    /**
     * List of supported database types. A human-readable
     * identifier is used as key and a complete namespaced class name as value
     * representing the class that will do actual type conversions.
     *
     * @var array<string, string>
     * @phpstan-var array<string, class-string<\Cake\Database\TypeInterface>>
     */
    protected static array $_types = ['biginteger' => Type\Integer_Type::class, 'binary' => Type\Binary_Type::class, 'binaryuuid' => Type\Binary_Uuid_Type::class, 'boolean' => Type\Bool_Type::class, 'char' => Type\String_Type::class, 'cidr' => Type\String_Type::class, 'citext' => Type\String_Type::class, 'date' => Type\Date_Type::class, 'datetime' => Type\Date_Time_Type::class, 'datetimefractional' => Type\Date_Time_Fractional_Type::class, 'decimal' => Type\Decimal_Type::class, 'float' => Type\Float_Type::class, 'geometry' => Type\String_Type::class, 'integer' => Type\Integer_Type::class, 'inet' => Type\String_Type::class, 'json' => Type\Json_Type::class, 'linestring' => Type\String_Type::class, 'macaddr' => Type\String_Type::class, 'nativeuuid' => Type\Uuid_Type::class, 'point' => Type\String_Type::class, 'polygon' => Type\String_Type::class, 'smallinteger' => Type\Integer_Type::class, 'string' => Type\String_Type::class, 'text' => Type\String_Type::class, 'time' => Type\Time_Type::class, 'timestamp' => Type\Date_Time_Type::class, 'timestampfractional' => Type\Date_Time_Fractional_Type::class, 'timestamptimezone' => Type\Date_Time_Timezone_Type::class, 'tinyinteger' => Type\Integer_Type::class, 'uuid' => Type\Uuid_Type::class, 'year' => Type\Integer_Type::class];
    /**
     * Contains a map of type object instances to be reused if needed.
     *
     * @var array<\Cake\Database\TypeInterface>
     */
    protected static array $_built_types = [];
    /**
     * Returns a Type object capable of converting a type identified by name.
     *
     * @param string $name type identifier
     */
    public static function build(string $name): Type_Interface
    {
        if (isset(static::$_built_types[$name])) {
            return static::$_built_types[$name];
        }
        if (!isset(static::$_types[$name])) {
            return static::$_built_types[$name] = new static::$_types['string']($name);
        }
        return static::$_built_types[$name] = new static::$_types[$name]($name);
    }
    /**
     * Returns an arrays with all the mapped type objects, indexed by name.
     *
     * @return array<\Cake\Database\TypeInterface>
     */
    public static function build_all(): array
    {
        foreach (static::$_types as $name => $type) {
            static::$_built_types[$name] ??= static::build($name);
        }
        return static::$_built_types;
    }
    /**
     * Set TypeInterface instance capable of converting a type identified by $name
     *
     * @param string $name The type identifier you want to set.
     * @param \Cake\Database\TypeInterface $instance The type instance you want to set.
     */
    public static function set(string $name, Type_Interface $instance): void
    {
        static::$_built_types[$name] = $instance;
    }
    /**
     * Registers a new type identifier and maps it to a fully namespaced classname.
     *
     * @param string $type Name of type to map.
     * @param string $className The classname to register.
     * @phpstan-param class-string<\Cake\Database\TypeInterface> $className
     */
    public static function map(string $type, string $class_name): void
    {
        static::$_types[$type] = $class_name;
        unset(static::$_built_types[$type]);
    }
    /**
     * Set type to classname mapping.
     *
     * @param array<string, string> $map List of types to be mapped.
     * @phpstan-param array<string, class-string<\Cake\Database\TypeInterface>> $map
     */
    public static function set_map(array $map): void
    {
        static::$_types = $map;
        static::$_built_types = [];
    }
    /**
     * Get the type mapping array.
     *
     * Deprecated 5.3.0: Argument $type has been deprecated.
     * Use getMap() without arguments to get the full map, or getMapped($type) to get a specific type mapping.
     *
     * @param string|null $type Type name to get mapped class for or null to get map array.
     * @return array<string, class-string<\Cake\Database\TypeInterface>>|string|null Configured class name for given $type or map array.
     */
    public static function get_map(?string $type = null): array|string|null
    {
        if ($type === null) {
            return static::$_types;
        }
        trigger_error('Calling getMap() with a type argument is deprecated. Use getMapped() instead.', E_USER_DEPRECATED);
        return static::$_types[$type] ?? null;
    }
    /**
     * Get mapped class name for a specific type.
     *
     * @param string $type Type name to get mapped class for.
     * @return string|null Configured class name for given $type or null if not found.
     * @phpstan-return class-string<\Cake\Database\TypeInterface>|null
     */
    public static function get_mapped(string $type): ?string
    {
        return static::$_types[$type] ?? null;
    }
    /**
     * Clears out all created instances and mapped types classes, useful for testing
     */
    public static function clear(): void
    {
        static::$_types = [];
        static::$_built_types = [];
    }
}