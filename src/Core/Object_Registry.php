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
namespace Cake\Core;

use ArrayIterator;
use Cake\Core\Exception\Cake_Exception;
use Cake\Event\Event_Dispatcher_Interface;
use Cake\Event\Event_Listener_Interface;
use Countable;
use IteratorAggregate;
use Traversable;
/**
 * Acts as a registry/factory for objects.
 *
 * Provides registry & factory functionality for object types. Used
 * as a super class for various composition based re-use features in CakePHP.
 *
 * Each subclass needs to implement the various abstract methods to complete
 * the template method load().
 *
 * The ObjectRegistry is EventManager aware, but each extending class will need to use
 * \Cake\Event\EventDispatcherTrait to attach and detach on set and bind
 *
 * @see \Cake\Controller\ComponentRegistry
 * @see \Cake\View\HelperRegistry
 * @see \Cake\Console\TaskRegistry
 * @template TObject of object
 * @template-implements \IteratorAggregate<string, TObject>
 */
abstract class Object_Registry implements Countable, IteratorAggregate
{
    /**
     * Map of loaded objects.
     *
     * @var array<string, TObject>
     */
    protected array $_loaded = [];
    /**
     * Loads/constructs an object instance.
     *
     * Will return the instance in the registry if it already exists.
     * If a subclass provides event support, you can use `$config['enabled'] = false`
     * to exclude constructed objects from being registered for events.
     *
     * Using {@link \Cake\Controller\Component::$components} as an example. You can alias
     * an object by setting the 'className' key, i.e.,
     *
     * ```
     * protected $components = [
     *   'Email' => [
     *     'className' => 'App\Controller\Component\AliasedEmailComponent'
     *   ];
     * ];
     * ```
     *
     * All calls to the `Email` component would use `AliasedEmail` instead.
     *
     * @param string $name The name/class of the object to load.
     * @param array<string, mixed> $config Additional settings to use when loading the object.
     * @return TObject
     * @throws \Exception If the class cannot be found.
     */
    public function load(string $name, array $config = []): object
    {
        if (isset($config['className'])) {
            if ($name === $config['className']) {
                [, $obj_name] = plugin_split($name);
            } else {
                $obj_name = $name;
            }
            $name = $config['className'];
        } else {
            [$plugin, $obj_name] = plugin_split($name);
            if ($plugin) {
                $config['className'] = $name;
            }
        }
        $loaded = isset($this->_loaded[$obj_name]);
        if ($loaded && $config !== []) {
            $this->_check_duplicate($obj_name, $config);
        }
        if ($loaded) {
            return $this->_loaded[$obj_name];
        }
        $class_name = $name;
        if (is_string($name)) {
            $class_name = $this->_resolve_class_name($name);
            if ($class_name === null) {
                [$plugin, $name] = plugin_split($name);
                $this->_throw_missing_class_error($name, $plugin);
            }
        }
        $instance = $this->_create($class_name, $obj_name, $config);
        $this->_loaded[$obj_name] = $instance;
        return $instance;
    }
    /**
     * Check for duplicate object loading.
     *
     * If a duplicate is being loaded and has different configuration, that is
     * bad and an exception will be raised.
     *
     * An exception is raised, as replacing the object will not update any
     * references other objects may have. Additionally, simply updating the runtime
     * configuration is not a good option as we may be missing important constructor
     * logic dependent on the configuration.
     *
     * @param string $name The name of the alias in the registry.
     * @param array<string, mixed> $config The config data for the new instance.
     * @throws \Cake\Core\Exception\CakeException When a duplicate is found.
     */
    protected function _check_duplicate(string $name, array $config): void
    {
        $existing = $this->_loaded[$name];
        $msg = sprintf('The `%s` alias has already been loaded.', $name);
        $has_config = method_exists($existing, 'getConfig');
        if (!$has_config) {
            throw new Cake_Exception($msg);
        }
        if (!$config) {
            return;
        }
        $existing_config = $existing->get_config();
        unset($config['enabled'], $existing_config['enabled']);
        $failure = null;
        foreach ($config as $key => $value) {
            if (!array_key_exists($key, $existing_config)) {
                $failure = " The `{$key}` was not defined in the previous configuration data.";
                break;
            }
            if (isset($existing_config[$key]) && $existing_config[$key] !== $value) {
                $failure = sprintf(' The `%s` key has a value of `%s` but previously had a value of `%s`', $key, json_encode($value, JSON_THROW_ON_ERROR), json_encode($existing_config[$key], JSON_THROW_ON_ERROR));
                break;
            }
        }
        if ($failure) {
            throw new Cake_Exception($msg . $failure);
        }
    }
    /**
     * Should resolve the classname for a given object type.
     *
     * @param string $class The class to resolve.
     * @return class-string<TObject>|null The resolved name or null for failure.
     */
    abstract protected function _resolve_class_name(string $class): ?string;
    /**
     * Throw an exception when the requested object name is missing.
     *
     * @param string $class The class that is missing.
     * @param string|null $plugin The plugin $class is missing from.
     * @throws \Exception
     */
    abstract protected function _throw_missing_class_error(string $class, ?string $plugin): void;
    /**
     * Create an instance of a given classname.
     *
     * This method should construct and do any other initialization logic
     * required.
     *
     * @param TObject|class-string<TObject> $class The class to build.
     * @param string $alias The alias of the object.
     * @param array<string, mixed> $config The Configuration settings for construction
     * @return TObject
     */
    abstract protected function _create(object|string $class, string $alias, array $config): object;
    /**
     * Get the list of loaded objects.
     *
     * @return array<string> List of object names.
     */
    public function loaded(): array
    {
        return array_keys($this->_loaded);
    }
    /**
     * Check whether a given object is loaded.
     *
     * @param string $name The object name to check for.
     * @return bool True if object is loaded else false.
     */
    public function has(string $name): bool
    {
        return isset($this->_loaded[$name]);
    }
    /**
     * Get loaded object instance.
     *
     * @param string $name Name of object.
     * @return TObject Object instance.
     * @throws \Cake\Core\Exception\CakeException If not loaded or found.
     */
    public function get(string $name): object
    {
        if (!isset($this->_loaded[$name])) {
            throw new Cake_Exception(sprintf('Unknown object `%s`.', $name));
        }
        return $this->_loaded[$name];
    }
    /**
     * Provide public read access to the loaded objects
     *
     * @param string $name Name of property to read
     * @return TObject|null
     */
    public function __get(string $name): ?object
    {
        return $this->_loaded[$name] ?? null;
    }
    /**
     * Provide isset access to _loaded
     *
     * @param string $name Name of object being checked.
     */
    public function __isset(string $name): bool
    {
        return $this->has($name);
    }
    /**
     * Sets an object.
     *
     * @param string $name Name of a property to set.
     * @param TObject $object Object to set.
     */
    public function __set(string $name, object $object): void
    {
        $this->set($name, $object);
    }
    /**
     * Unsets an object.
     *
     * @param string $name Name of a property to unset.
     */
    public function __unset(string $name): void
    {
        $this->unload($name);
    }
    /**
     * Normalizes an object configuration array into associative form for making
     * lazy loading easier.
     *
     * @param array $objects Array of child objects to normalize.
     * @return array<string, array> Array of normalized objects.
     */
    public function normalize_array(array $objects): array
    {
        $normal = [];
        foreach ($objects as $object_name => $config) {
            if (is_int($object_name)) {
                $object_name = $config;
                $config = [];
            }
            [$plugin, $name] = plugin_split($object_name);
            if ($plugin) {
                $config['className'] = $object_name;
            }
            $normal[$name] = $config;
        }
        return $normal;
    }
    /**
     * Clear loaded instances in the registry.
     *
     * If the registry subclass has an event manager, the objects will be detached from events as well.
     *
     * @return $this
     */
    public function reset()
    {
        foreach (array_keys($this->_loaded) as $name) {
            $this->unload((string) $name);
        }
        return $this;
    }
    /**
     * Set an object directly into the registry by name.
     *
     * If this collection implements events, the passed object will
     * be attached into the event manager
     *
     * @param string $name The name of the object to set in the registry.
     * @param TObject $object instance to store in the registry
     * @return $this
     */
    public function set(string $name, object $object)
    {
        // Just call unload if the object was loaded before
        if (array_key_exists($name, $this->_loaded)) {
            $this->unload($name);
        }
        if ($this instanceof Event_Dispatcher_Interface && $object instanceof Event_Listener_Interface) {
            $this->get_event_manager()->on($object);
        }
        $this->_loaded[$name] = $object;
        return $this;
    }
    /**
     * Remove an object from the registry.
     *
     * If this registry has an event manager, the object will be detached from any events as well.
     *
     * @param string $name The name of the object to remove from the registry.
     * @return $this
     */
    public function unload(string $name)
    {
        if (!isset($this->_loaded[$name])) {
            throw new Cake_Exception(sprintf('Object named `%s` is not loaded.', $name));
        }
        $object = $this->_loaded[$name];
        if ($this instanceof Event_Dispatcher_Interface && $object instanceof Event_Listener_Interface) {
            $this->get_event_manager()->off($object);
        }
        unset($this->_loaded[$name]);
        return $this;
    }
    /**
     * Returns an array iterator.
     *
     * @return \Traversable<string, TObject>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->_loaded);
    }
    /**
     * Returns the number of loaded objects.
     */
    public function count(): int
    {
        return count($this->_loaded);
    }
    /**
     * Debug friendly object properties.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        $properties = get_object_vars($this);
        if (isset($properties['_loaded'])) {
            $properties['_loaded'] = array_keys($properties['_loaded']);
        }
        return $properties;
    }
}