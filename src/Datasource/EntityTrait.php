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
namespace Cake\Datasource;

use Cake\Collection\Collection;
use function Cake\Core\Deprecation_Warning;
use Cake\Datasource\Exception\Missing_Property_Exception;
use Cake\ORM\Entity;
use Cake\Utility\Hash;
use Cake\Utility\Inflector;
use InvalidArgumentException;
/**
 * An entity represents a single result row from a repository. It exposes the
 * methods for retrieving and storing fields associated in this row.
 *
 * @require-implements \Cake\Datasource\EntityInterface
 */
trait Entity_Trait
{
    /**
     * Holds all fields and their values for this entity.
     *
     * @var array<string, mixed>
     */
    protected array $_fields = [];
    /**
     * Holds all fields that have been changed and their original values for this entity.
     *
     * @var array<string, mixed>
     */
    protected array $_original = [];
    /**
     * Holds all fields that have been initially set on instantiation, or after marking as clean
     *
     * @var array<string>
     */
    protected array $_original_fields = [];
    /**
     * List of field names that should **not** be included in JSON or Array
     * representations of this Entity.
     *
     * @var array<string>
     */
    protected array $_hidden = [];
    /**
     * List of computed or virtual fields that **should** be included in JSON or array
     * representations of this Entity. If a field is present in both _hidden and _virtual
     * the field will **not** be in the array/JSON versions of the entity.
     *
     * @var array<string>
     */
    protected array $_virtual = [];
    /**
     * Holds a list of the fields that were modified or added after this object
     * was originally created.
     *
     * @var array<string, bool>
     */
    protected array $_dirty = [];
    /**
     * Holds a cached list of getters/setters per class
     *
     * @var array<string, array<string, array<string, string>>>
     */
    protected static array $_accessors = [];
    /**
     * Indicates whether this entity is yet to be persisted.
     * Entities default to assuming they are new. You can use Table::persisted()
     * to set the new flag on an entity based on records in the database.
     */
    protected bool $_new = true;
    /**
     * List of errors per field as stored in this object.
     *
     * @var array<string, mixed>
     */
    protected array $_errors = [];
    /**
     * List of invalid fields and their data for errors upon validation/patching.
     *
     * @var array<string, mixed>
     */
    protected array $_invalid = [];
    /**
     * Map of fields in this entity that can be safely mass assigned, each
     * field name points to a boolean indicating its status. An empty array
     * means no fields are accessible for mass assignment.
     *
     * The special field '\*' can also be mapped, meaning that any other field
     * not defined in the map will take its value. For example, `'*' => true`
     * means that any field not defined in the map will be accessible for mass
     * assignment by default.
     *
     * @var array<string, bool>
     */
    protected array $_accessible = ['*' => true];
    /**
     * The alias of the repository this entity came from
     */
    protected string $_registry_alias = '';
    /**
     * Storing the current visitation status while recursing through entities getting errors.
     */
    protected bool $_has_been_visited = false;
    /**
     * Whether the presence of a field is checked when accessing a property.
     *
     * If enabled an exception will be thrown when trying to access a non-existent property.
     */
    protected bool $require_field_presence = false;
    /**
     * Magic getter to access fields that have been set in this entity
     *
     * @param string $field Name of the field to access
     */
    public function &__get(string $field): mixed
    {
        return $this->get_required_or_fail($field, $this->require_field_presence);
    }
    /**
     * Magic setter to add or edit a field in this entity
     *
     * @param string $field The name of the field to set
     * @param mixed $value The value to set to the field
     */
    public function __set(string $field, mixed $value): void
    {
        $this->set($field, $value);
    }
    /**
     * Returns whether this entity contains a field named $field
     * and is not set to null.
     *
     * @param string $field The field to check.
     */
    public function __isset(string $field): bool
    {
        return $this->has($field) && $this->get($field) !== null;
    }
    /**
     * Removes a field from this entity
     *
     * @param string $field The field to unset
     */
    public function __unset(string $field): void
    {
        $this->unset($field);
    }
    /**
     * Sets a single field inside this entity.
     *
     * ### Example:
     *
     * ```
     * $entity->set('name', 'Andrew');
     * ```
     *
     * Some times it is handy to bypass setter functions in this entity when assigning
     * fields. You can achieve this by disabling the `setter` option using the
     * `$options` parameter:
     *
     * ```
     * $entity->set('name', 'Andrew', ['setter' => false]);
     * ```
     *
     * You can use the `asOriginal` option to set the given field as original, if it wasn't
     * present when the entity was instantiated.
     *
     * ```
     * $entity = new Entity(['name' => 'andrew', 'id' => 1]);
     *
     * $entity->set('phone_number', '555-0134');
     * print_r($entity->getOriginalFields()) // prints ['name', 'id']
     *
     * $entity->set('phone_number', '555-0134', ['asOriginal' => true]);
     * print_r($entity->getOriginalFields()) // prints ['name', 'id', 'phone_number']
     * ```
     *
     * @param array|string $field The name of field to set.
     * @param mixed $value The value to set to the field.
     * @param array<string, mixed> $options Options to be used for setting the field. Allowed option
     * keys are `setter`, `guard` and `asOriginal`
     * @return $this
     * @throws \InvalidArgumentException when an empty field name is provided
     */
    public function set(array|string $field, mixed $value = null, array $options = [])
    {
        if (is_string($field)) {
            $options += ['guard' => false];
            return $this->patch([$field => $value], $options);
        }
        deprecation_warning('5.2.0', sprintf('Passing an array as the first argument to `%s::set()` is deprecated. ' . 'Use `%s::patch()` instead.', static::class, static::class));
        return $this->patch($field, (array) $value);
    }
    /**
     * Patch (mass-assign) multiple fields to this entity.
     *
     * ### Example:
     *
     * ```
     * $entity->patch(['name' => 'andrew', 'id' => 1]);
     * echo $entity->name // prints andrew
     * echo $entity->id // prints 1
     * ```
     *
     * Some times it is handy to bypass setter functions in this entity when assigning
     * fields. You can achieve this by disabling the `setter` option using the
     * `$options` parameter:
     *
     * ```
     * $entity->patch(['name' => 'Andrew', 'id' => 1], ['setter' => false]);
     * ```
     *
     * Mass assignment should be treated carefully when accepting user input, by default
     * entities will guard all fields when fields are assigned in bulk. You can disable
     * the guarding for a single set call with the `guard` option:
     *
     * ```
     * $entity->patch(['name' => 'Andrew', 'id' => 1], ['guard' => false]);
     * ```
     *
     * You can use the `asOriginal` option to set the given field as original, if it wasn't
     * present when the entity was instantiated.
     *
     * ```
     * $entity = new Entity(['name' => 'andrew', 'id' => 1]);
     *
     * $entity->patch(['phone_number' => '555-0134']);
     * print_r($entity->getOriginalFields()) // prints ['name', 'id']
     *
     * $entity->patch(['phone_number' => '555-0134'], ['asOriginal' => true]);
     * print_r($entity->getOriginalFields()) // prints ['name', 'id', 'phone_number']
     * ```
     *
     * @param array<string, mixed> $values Map of fields with their respective values.
     * @param array<string, mixed> $options Options to be used for setting the field. Allowed option
     * keys are `setter`, `guard` and `asOriginal`
     * @return $this
     * @throws \InvalidArgumentException
     */
    public function patch(array $values, array $options = [])
    {
        $options += ['setter' => true, 'guard' => true, 'asOriginal' => false];
        if ($options['asOriginal'] === true) {
            $this->set_original_field(array_keys($values));
        }
        foreach ($values as $name => $value) {
            $name = (string) $name;
            if ($name === '') {
                throw new InvalidArgumentException('Cannot set an empty field');
            }
            if ($options['guard'] === true && !$this->is_accessible($name)) {
                continue;
            }
            if ($options['asOriginal'] || $this->is_modified($name, $value)) {
                $this->set_dirty($name, true);
            } else {
                continue;
            }
            if ($options['setter']) {
                $setter = static::_accessor($name, 'set');
                if ($setter) {
                    $value = $this->{$setter}($value);
                }
            }
            if ($this->is_original_field($name) && !array_key_exists($name, $this->_original) && array_key_exists($name, $this->_fields) && $value !== $this->_fields[$name]) {
                $this->_original[$name] = $this->_fields[$name];
            }
            $this->_fields[$name] = $value;
        }
        return $this;
    }
    /**
     * Check if the provided value is same as existing value for a field.
     *
     * This check is used to determine if a field should be set as dirty or not.
     * It will return `false` for scalar values and objects which haven't changed.
     * For arrays `true` will be returned always because the original/updated list
     * could contain references to the same objects, even though those objects
     * may have changed internally.
     *
     * @param string $field The field to check.
     */
    protected function is_modified(string $field, mixed $value): bool
    {
        if (!array_key_exists($field, $this->_fields)) {
            return true;
        }
        $existing = $this->_fields[$field] ?? null;
        if (($value === null || is_scalar($value)) && $existing === $value) {
            return false;
        }
        if (is_object($value) && is_object($existing) && !$value instanceof Entity_Interface && $existing == $value) {
            return false;
        }
        return true;
    }
    /**
     * Returns the value of a field by name
     *
     * @param string $field the name of the field to retrieve
     * @throws \InvalidArgumentException if an empty field name is passed
     * @throws \Cake\Datasource\Exception\MissingPropertyException when field does not exist and requireFieldPresence is enabled
     */
    public function &get(string $field): mixed
    {
        return $this->get_required_or_fail($field, false);
    }
    /**
     * Get field with option for requireFieldPresence.
     *
     * Note: The returned value might be null if the field is set to null.
     *
     * @param string $field the name of the field to retrieve
     * @param bool $requireFieldPresence Whether to throw an exception if the field is not present
     * @throws \InvalidArgumentException if an empty field name is passed
     * @throws \Cake\Datasource\Exception\MissingPropertyException If property does not exist and $requireFieldPresence
     */
    public function &get_required_or_fail(string $field, bool $require_field_presence = true): mixed
    {
        if ($field === '') {
            throw new InvalidArgumentException('Cannot get an empty field');
        }
        $value = null;
        $field_is_present = false;
        if (array_key_exists($field, $this->_fields)) {
            $field_is_present = true;
            $value =& $this->_fields[$field];
        }
        $method = static::_accessor($field, 'get');
        if ($method) {
            // Must be variable before returning: Only variable references should be returned by reference.
            $result = $this->{$method}($value);
            return $result;
        }
        if (!$field_is_present && $require_field_presence) {
            throw new Missing_Property_Exception(['property' => $field, 'entity' => $this::class]);
        }
        return $value;
    }
    /**
     * Enable/disable field presence check when accessing a property.
     *
     * If enabled an exception will be thrown when trying to access a non-existent property.
     *
     * @param bool $value `true` to enable, `false` to disable.
     */
    public function require_field_presence(bool $value = true): void
    {
        $this->require_field_presence = $value;
    }
    /**
     * Returns whether a field has an original value
     */
    public function has_original(string $field): bool
    {
        return array_key_exists($field, $this->_original);
    }
    /**
     * Returns the value of an original field by name
     *
     * @param string $field the name of the field for which original value is retrieved.
     * @param bool $allowFallback whether to allow falling back to the current field value if no original exists
     * @throws \InvalidArgumentException if an empty field name is passed or if the field has no original value and $allowFallback is false
     */
    public function get_original(string $field, bool $allow_fallback = true): mixed
    {
        if ($field === '') {
            throw new InvalidArgumentException('Cannot get an empty field');
        }
        if (array_key_exists($field, $this->_original)) {
            return $this->_original[$field];
        }
        if (!$allow_fallback) {
            throw new InvalidArgumentException(sprintf('Cannot retrieve original value for field `%s`', $field));
        }
        return $this->get($field);
    }
    /**
     * Gets all original values of the entity.
     */
    public function get_original_values(): array
    {
        $originals = $this->_original;
        $original_keys = array_keys($originals);
        foreach ($this->_fields as $key => $value) {
            if (!in_array($key, $original_keys, true) && $this->is_original_field($key)) {
                $originals[$key] = $value;
            }
        }
        return $originals;
    }
    /**
     * Returns whether this entity contains a field named $field.
     *
     * It will return `true` even for fields set to `null`.
     *
     * ### Example:
     *
     * ```
     * $entity = new Entity(['id' => 1, 'name' => null]);
     * $entity->has('id'); // true
     * $entity->has('name'); // true
     * $entity->has('last_name'); // false
     * ```
     *
     * You can check multiple fields by passing an array:
     *
     * ```
     * $entity->has(['name', 'last_name']);
     * ```
     *
     * When checking multiple fields all fields must have a value (even `null`)
     * present for the method to return `true`.
     *
     * @param array<string>|string $field The field or fields to check.
     */
    public function has(array|string $field): bool
    {
        foreach ((array) $field as $prop) {
            if (!array_key_exists($prop, $this->_fields) && !static::_accessor($prop, 'get')) {
                return false;
            }
        }
        return true;
    }
    /**
     * Checks that a field is empty
     *
     * This is not working like the PHP `empty()` function. The method will
     * return true for:
     *
     * - `''` (empty string)
     * - `null`
     * - `[]`
     *
     * and false in all other cases.
     *
     * @param string $field The field to check.
     * @deprecated 5.3.0 Use hasValue() instead.
     */
    public function is_empty(string $field): bool
    {
        deprecation_warning('5.3.0', 'isEmpty() is deprecated. Use hasValue() instead.');
        return !$this->has_value($field);
    }
    /**
     * Checks that a field has a value.
     *
     * This method will return true for
     *
     * - Non-empty strings
     * - Non-empty arrays
     * - Any object
     * - Integer, even `0`
     * - Float, even 0.0
     * - Boolean, both `true` and `false`
     *
     * and false in all other cases.
     *
     * @param string $field The field to check.
     */
    public function has_value(string $field): bool
    {
        $value = $this->get($field);
        if ($value === null || ($value === [] || $value === '')) {
            return false;
        }
        return true;
    }
    /**
     * Removes a field or list of fields from this entity
     *
     * ### Examples:
     *
     * ```
     * $entity->unset('name');
     * $entity->unset(['name', 'last_name']);
     * ```
     *
     * @param array<string>|string $field The field to unset.
     * @return $this
     */
    public function unset(array|string $field)
    {
        $field = (array) $field;
        foreach ($field as $p) {
            unset($this->_fields[$p], $this->_dirty[$p]);
        }
        return $this;
    }
    /**
     * Sets hidden fields.
     *
     * @param array<string> $fields An array of fields to hide from array exports.
     * @param bool $merge Merge the new fields with the existing. By default false.
     * @return $this
     */
    public function set_hidden(array $fields, bool $merge = false)
    {
        if ($merge === false) {
            $this->_hidden = $fields;
            return $this;
        }
        $fields = array_merge($this->_hidden, $fields);
        $this->_hidden = array_unique($fields);
        return $this;
    }
    /**
     * Gets the hidden fields.
     *
     * @return array<string>
     */
    public function get_hidden(): array
    {
        return $this->_hidden;
    }
    /**
     * Sets the virtual fields on this entity.
     *
     * @param array<string> $fields An array of fields to treat as virtual.
     * @param bool $merge Merge the new fields with the existing. By default false.
     * @return $this
     */
    public function set_virtual(array $fields, bool $merge = false)
    {
        if ($merge === false) {
            $this->_virtual = $fields;
            return $this;
        }
        $fields = array_merge($this->_virtual, $fields);
        $this->_virtual = array_unique($fields);
        return $this;
    }
    /**
     * Gets the virtual fields on this entity.
     *
     * @return array<string>
     */
    public function get_virtual(): array
    {
        return $this->_virtual;
    }
    /**
     * Gets the list of visible fields.
     *
     * The list of visible fields is all standard fields
     * plus virtual fields minus hidden fields.
     *
     * @return array<string> A list of fields that are 'visible' in all
     *     representations.
     */
    public function get_visible(): array
    {
        $fields = array_keys($this->_fields);
        $fields = array_merge($fields, $this->_virtual);
        return array_diff($fields, $this->_hidden);
    }
    /**
     * Returns an array with all the fields that have been set
     * to this entity
     *
     * This method will recursively transform entities assigned to fields
     * into arrays as well.
     *
     * @return array<string, mixed>
     */
    public function to_array(): array
    {
        $result = [];
        foreach ($this->get_visible() as $field) {
            $value = $this->get($field);
            if (is_array($value)) {
                $result[$field] = [];
                foreach ($value as $k => $entity) {
                    if ($entity instanceof Entity_Interface) {
                        $result[$field][$k] = $entity->to_array();
                    } else {
                        $result[$field][$k] = $entity;
                    }
                }
            } elseif ($value instanceof Entity_Interface) {
                $result[$field] = $value->to_array();
            } else {
                $result[$field] = $value;
            }
        }
        return $result;
    }
    /**
     * Returns the fields that will be serialized as JSON
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->extract($this->get_visible());
    }
    /**
     * Implements isset($entity);
     *
     * @param string $offset The offset to check.
     * @return bool Success
     */
    public function offsetExists(mixed $offset): bool
    {
        return $this->__isset($offset);
    }
    /**
     * Implements $entity[$offset];
     *
     * @param string $offset The offset to get.
     */
    public function &offsetGet(mixed $offset): mixed
    {
        return $this->get($offset);
    }
    /**
     * Implements $entity[$offset] = $value;
     *
     * @param string $offset The offset to set.
     * @param mixed $value The value to set.
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->set($offset, $value);
    }
    /**
     * Implements unset($result[$offset]);
     *
     * @param string $offset The offset to remove.
     */
    public function offsetUnset(mixed $offset): void
    {
        $this->unset($offset);
    }
    /**
     * Fetch accessor method name
     * Accessor methods (available or not) are cached in $_accessors
     *
     * @param string $property the field name to derive getter name from
     * @param string $type the accessor type ('get' or 'set')
     * @return string method name or empty string (no method available)
     */
    protected static function _accessor(string $property, string $type): string
    {
        $class = static::class;
        if (isset(static::$_accessors[$class][$type][$property])) {
            return static::$_accessors[$class][$type][$property];
        }
        if (isset(static::$_accessors[$class])) {
            return static::$_accessors[$class][$type][$property] = '';
        }
        if (static::class === Entity::class) {
            return '';
        }
        foreach (get_class_methods($class) as $method) {
            $prefix = substr($method, 1, 3);
            if (!str_starts_with($method, '_')) {
                continue;
            }
            if ($prefix !== 'get' && $prefix !== 'set') {
                continue;
            }
            $field = lcfirst(substr($method, 4));
            $snake_field = Inflector::underscore($field);
            $title_field = ucfirst($field);
            static::$_accessors[$class][$prefix][$snake_field] = $method;
            static::$_accessors[$class][$prefix][$field] = $method;
            static::$_accessors[$class][$prefix][$title_field] = $method;
        }
        if (!isset(static::$_accessors[$class][$type][$property])) {
            static::$_accessors[$class][$type][$property] = '';
        }
        return static::$_accessors[$class][$type][$property];
    }
    /**
     * Returns an array with the requested fields
     * stored in this entity, indexed by field name
     *
     * @param array<string> $fields list of fields to be returned
     * @param bool $onlyDirty Return the requested field only if it is dirty
     * @return array<string, mixed>
     */
    public function extract(array $fields, bool $only_dirty = false): array
    {
        $result = [];
        foreach ($fields as $field) {
            if (!$only_dirty || $this->is_dirty($field)) {
                $result[$field] = $this->has($field) ? $this->get($field) : null;
            }
        }
        return $result;
    }
    /**
     * Returns an array with the requested original fields
     * stored in this entity, indexed by field name, if they exist.
     *
     * Fields that are unchanged from their original value will be included in the
     * return of this method.
     *
     * @param array<string> $fields List of fields to be returned
     * @return array<string, mixed>
     */
    public function extract_original(array $fields): array
    {
        $result = [];
        foreach ($fields as $field) {
            if ($this->has_original($field)) {
                $result[$field] = $this->get_original($field);
            } elseif ($this->is_original_field($field)) {
                $result[$field] = $this->get($field);
            }
        }
        return $result;
    }
    /**
     * Returns an array with only the original fields
     * stored in this entity, indexed by field name, if they exist.
     *
     * This method will only return fields that have been modified since
     * the entity was built. Unchanged fields will be omitted.
     *
     * @param array<string> $fields List of fields to be returned
     * @return array<string, mixed>
     */
    public function extract_original_changed(array $fields): array
    {
        $result = [];
        foreach ($fields as $field) {
            if (!$this->has_original($field)) {
                continue;
            }
            $original = $this->get_original($field);
            if ($original !== $this->get($field)) {
                $result[$field] = $original;
            }
        }
        return $result;
    }
    /**
     * Returns whether a field is an original one
     */
    public function is_original_field(string $name): bool
    {
        return in_array($name, $this->_original_fields, true);
    }
    /**
     * Returns an array of original fields.
     * Original fields are those that the entity was initialized with.
     *
     * @return array<string>
     */
    public function get_original_fields(): array
    {
        return $this->_original_fields;
    }
    /**
     * Sets the given field or a list of fields to as original.
     * Normally there is no need to call this method manually.
     *
     * @param array<string>|string $field the name of a field or a list of fields to set as original
     * @return $this
     */
    protected function set_original_field(string|array $field, bool $merge = true)
    {
        if (!$merge) {
            $this->_original_fields = (array) $field;
            return $this;
        }
        $fields = (array) $field;
        foreach ($fields as $field) {
            $field = (string) $field;
            if (!$this->is_original_field($field)) {
                $this->_original_fields[] = $field;
            }
        }
        return $this;
    }
    /**
     * Sets the dirty status of a single field.
     *
     * @param string $field the field to set or check status for
     * @param bool $isDirty true means the field was changed, false means
     * it was not changed. Defaults to true.
     * @return $this
     */
    public function set_dirty(string $field, bool $is_dirty = true)
    {
        if ($is_dirty === false) {
            $this->set_original_field($field);
            unset($this->_dirty[$field], $this->_original[$field]);
            return $this;
        }
        $this->_dirty[$field] = true;
        unset($this->_errors[$field], $this->_invalid[$field]);
        return $this;
    }
    /**
     * Checks if the entity is dirty or if a single field of it is dirty.
     *
     * @param string|null $field The field to check the status for. Null for the whole entity.
     * @return bool Whether the field was changed or not
     */
    public function is_dirty(?string $field = null): bool
    {
        return $field === null ? $this->_dirty !== [] : isset($this->_dirty[$field]);
    }
    /**
     * Gets the dirty fields.
     *
     * @return array<string>
     */
    public function get_dirty(): array
    {
        return array_keys($this->_dirty);
    }
    /**
     * Sets the entire entity as clean, which means that it will appear as
     * no fields being modified or added at all. This is an useful call
     * for an initial object hydration
     */
    public function clean(): void
    {
        $this->_dirty = [];
        $this->_errors = [];
        $this->_invalid = [];
        $this->_original = [];
        $this->set_original_field(array_keys($this->_fields), false);
    }
    /**
     * Set the status of this entity.
     *
     * Using `true` means that the entity has not been persisted in the database,
     * `false` that it already is.
     *
     * @param bool $new Indicate whether this entity has been persisted.
     * @return $this
     */
    public function set_new(bool $new)
    {
        if ($new) {
            foreach ($this->_fields as $k => $p) {
                $this->_dirty[$k] = true;
            }
        }
        $this->_new = $new;
        return $this;
    }
    /**
     * Returns whether this entity has already been persisted.
     *
     * @return bool Whether the entity has been persisted.
     */
    public function is_new(): bool
    {
        return $this->_new;
    }
    /**
     * Returns whether this entity has errors.
     *
     * @param bool $includeNested true will check nested entities for hasErrors()
     */
    public function has_errors(bool $include_nested = true): bool
    {
        if ($this->_has_been_visited) {
            // While recursing through entities, each entity should only be visited once. See https://github.com/cakephp/cakephp/issues/17318
            return false;
        }
        if (Hash::filter($this->_errors)) {
            return true;
        }
        if ($include_nested === false) {
            return false;
        }
        $this->_has_been_visited = true;
        try {
            foreach ($this->_fields as $field) {
                if ($this->_read_has_errors($field)) {
                    return true;
                }
            }
        } finally {
            $this->_has_been_visited = false;
        }
        return false;
    }
    /**
     * Returns all validation errors.
     */
    public function get_errors(): array
    {
        if ($this->_has_been_visited) {
            // While recursing through entities, each entity should only be visited once. See https://github.com/cakephp/cakephp/issues/17318
            return [];
        }
        $diff = array_diff_key($this->_fields, $this->_errors);
        $this->_has_been_visited = true;
        try {
            $errors = $this->_errors + (new Collection($diff))->filter(fn($value) => is_array($value) || $value instanceof Entity_Interface)->map(fn($value) => $this->_read_error($value))->filter()->to_array();
        } finally {
            $this->_has_been_visited = false;
        }
        return $errors;
    }
    /**
     * Returns validation errors of a field
     *
     * @param string $field Field name to get the errors from
     */
    public function get_error(string $field): array
    {
        return $this->_errors[$field] ?? $this->_nested_errors($field);
    }
    /**
     * Sets error messages to the entity
     *
     * ## Example
     *
     * ```
     * // Sets the error messages for multiple fields at once
     * $entity->setErrors(['salary' => ['message'], 'name' => ['another message']]);
     * ```
     *
     * @param array $errors The array of errors to set.
     * @param bool $overwrite Whether to overwrite pre-existing errors for $fields
     * @return $this
     */
    public function set_errors(array $errors, bool $overwrite = false)
    {
        if ($overwrite) {
            foreach ($errors as $f => $error) {
                $this->_errors[$f] = (array) $error;
            }
            return $this;
        }
        foreach ($errors as $f => $error) {
            $this->_errors += [$f => []];
            // String messages are appended to the list,
            // while more complex error structures need their
            // keys preserved for nested validator.
            if (is_string($error)) {
                $this->_errors[$f][] = $error;
            } else {
                foreach ($error as $k => $v) {
                    $this->_errors[$f][$k] = $v;
                }
            }
        }
        return $this;
    }
    /**
     * Sets errors for a single field
     *
     * ### Example
     *
     * ```
     * // Sets the error messages for a single field
     * $entity->setError('salary', ['must be numeric', 'must be a positive number']);
     * ```
     *
     * @param string $field The field to get errors for, or the array of errors to set.
     * @param array|string $errors The errors to be set for $field
     * @param bool $overwrite Whether to overwrite pre-existing errors for $field
     * @return $this
     */
    public function set_error(string $field, array|string $errors, bool $overwrite = false)
    {
        if (is_string($errors)) {
            $errors = [$errors];
        }
        // Handle dotted field paths by creating nested error structure
        if (str_contains($field, '.')) {
            $nested = Hash::insert([], $field, $errors);
            return $this->set_errors($nested, $overwrite);
        }
        return $this->set_errors([$field => $errors], $overwrite);
    }
    /**
     * Auxiliary method for getting errors in nested entities
     *
     * @param string $field the field in this entity to check for errors
     * @return array Errors in nested entity if any
     */
    protected function _nested_errors(string $field): array
    {
        // Only one path element, check for nested entity with error.
        if (!str_contains($field, '.')) {
            if (!$this->has($field)) {
                return [];
            }
            $entity = $this->get($field);
            if ($entity instanceof Entity_Interface || is_iterable($entity)) {
                return $this->_read_error($entity);
            }
            return [];
        }
        // Try reading the errors data with field as a simple path
        $error = Hash::get($this->_errors, $field);
        if ($error !== null) {
            return $error;
        }
        $path = explode('.', $field);
        // Traverse down the related entities/arrays for
        // the relevant entity.
        $entity = $this;
        $len = count($path);
        while ($len) {
            /** @var string $part */
            $part = array_shift($path);
            $len = count($path);
            $val = null;
            if ($entity instanceof Entity_Interface) {
                if ($entity->has($part)) {
                    $val = $entity->get($part);
                }
            } elseif (is_array($entity)) {
                $val = $entity[$part] ?? false;
            }
            if (is_iterable($val) || $val instanceof Entity_Interface) {
                $entity = $val;
            } else {
                $path[] = $part;
                break;
            }
        }
        if (count($path) <= 1) {
            return $this->_read_error($entity, array_pop($path));
        }
        return [];
    }
    /**
     * Reads if there are errors for one or many objects.
     *
     * @param \Cake\Datasource\EntityInterface|array $object The object to read errors from.
     */
    protected function _read_has_errors(mixed $object): bool
    {
        if ($object instanceof Entity_Interface && $object->has_errors()) {
            return true;
        }
        if (is_array($object)) {
            foreach ($object as $value) {
                if ($this->_read_has_errors($value)) {
                    return true;
                }
            }
        }
        return false;
    }
    /**
     * Read the error(s) from one or many objects.
     *
     * @param \Cake\Datasource\EntityInterface|iterable $object The object to read errors from.
     * @param string|null $path The field name for errors.
     */
    protected function _read_error(Entity_Interface|iterable $object, ?string $path = null): array
    {
        if ($path !== null && $object instanceof Entity_Interface) {
            return $object->get_error($path);
        }
        if ($object instanceof Entity_Interface) {
            return $object->get_errors();
        }
        $array = array_map(function ($val) {
            if ($val instanceof Entity_Interface) {
                return $val->get_errors();
            }
        }, (array) $object);
        return array_filter($array);
    }
    /**
     * Get a list of invalid fields and their data for errors upon validation/patching
     *
     * @return array<string, mixed>
     */
    public function get_invalid(): array
    {
        return $this->_invalid;
    }
    /**
     * Get a single value of an invalid field. Returns null if not set.
     *
     * @param string $field The name of the field.
     * @return mixed|null
     */
    public function get_invalid_field(string $field): mixed
    {
        return $this->_invalid[$field] ?? null;
    }
    /**
     * Set fields as invalid and not patchable into the entity.
     *
     * This is useful for batch operations when one needs to get the original value for an error message after patching.
     * This value could not be patched into the entity and is simply copied into the _invalid property for debugging
     * purposes or to be able to log it away.
     *
     * @param array<string, mixed> $fields The values to set.
     * @param bool $overwrite Whether to overwrite pre-existing values for $field.
     * @return $this
     */
    public function set_invalid(array $fields, bool $overwrite = false)
    {
        foreach ($fields as $field => $value) {
            if ($overwrite) {
                $this->_invalid[$field] = $value;
                continue;
            }
            $this->_invalid += [$field => $value];
        }
        return $this;
    }
    /**
     * Sets a field as invalid and not patchable into the entity.
     *
     * @param string $field The value to set.
     * @param mixed $value The invalid value to be set for $field.
     * @return $this
     */
    public function set_invalid_field(string $field, mixed $value)
    {
        $this->_invalid[$field] = $value;
        return $this;
    }
    /**
     * Stores whether a field value can be changed or set in this entity.
     * The special field `*` can also be marked as accessible or protected, meaning
     * that any other field specified before will take its value. For example
     * `$entity->setAccess('*', true)` means that any field not specified already
     * will be accessible by default.
     *
     * You can also call this method with an array of fields, in which case they
     * will each take the accessibility value specified in the second argument.
     *
     * ### Example:
     *
     * ```
     * $entity->setAccess('id', true); // Mark id as not protected
     * $entity->setAccess('author_id', false); // Mark author_id as protected
     * $entity->setAccess(['id', 'user_id'], true); // Mark both fields as accessible
     * $entity->setAccess('*', false); // Mark all fields as protected
     * ```
     *
     * @param array<string>|string $field Single or list of fields to change its accessibility
     * @param bool $set True marks the field as accessible, false will
     * mark it as protected.
     * @return $this
     */
    public function set_access(array|string $field, bool $set)
    {
        if ($field === '*') {
            $this->_accessible = array_map(fn(): bool => $set, $this->_accessible);
            $this->_accessible['*'] = $set;
            return $this;
        }
        foreach ((array) $field as $prop) {
            $this->_accessible[$prop] = $set;
        }
        return $this;
    }
    /**
     * Returns the raw accessible configuration for this entity.
     * The `*` wildcard refers to all fields.
     *
     * @return array<bool>
     */
    public function get_accessible(): array
    {
        return $this->_accessible;
    }
    /**
     * Checks if a field is accessible
     *
     * ### Example:
     *
     * ```
     * $entity->isAccessible('id'); // Returns whether it can be set or not
     * ```
     *
     * @param string $field Field name to check
     */
    public function is_accessible(string $field): bool
    {
        $value = $this->_accessible[$field] ?? null;
        return $value === null && !empty($this->_accessible['*']) || $value;
    }
    /**
     * Returns the alias of the repository from which this entity came from.
     */
    public function get_source(): string
    {
        return $this->_registry_alias;
    }
    /**
     * Sets the source alias
     *
     * @param string $alias the alias of the repository
     * @return $this
     */
    public function set_source(string $alias)
    {
        $this->_registry_alias = $alias;
        return $this;
    }
    /**
     * Returns a string representation of this object in a human-readable format.
     *
     * @deprecated 5.2.0 Casting an entity to string is deprecated. Use json_encode() instead to get a string representation of the entity.
     */
    public function __toString(): string
    {
        deprecation_warning('5.2.0', 'Casting an entity to string is deprecated. ' . 'Use json_encode() instead to get a string representation of the entity.');
        return (string) json_encode($this, JSON_PRETTY_PRINT);
    }
    /**
     * Returns an array that can be used to describe the internal state of this
     * object.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        $fields = $this->_fields;
        foreach ($this->_virtual as $field) {
            $fields[$field] = $this->{$field};
        }
        return $fields + ['[new]' => $this->is_new(), '[accessible]' => $this->_accessible, '[dirty]' => $this->_dirty, '[original]' => $this->_original, '[originalFields]' => $this->_original_fields, '[virtual]' => $this->_virtual, '[hasErrors]' => $this->has_errors(), '[errors]' => $this->_errors, '[invalid]' => $this->_invalid, '[repository]' => $this->_registry_alias];
    }
}