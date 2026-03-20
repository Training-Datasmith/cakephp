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
namespace Cake\ORM;

use BadMethodCallException;
use Cake\Core\App;
use function Cake\Core\Deprecation_Warning;
use Cake\Core\Object_Registry;
use Cake\Event\Event_Dispatcher_Interface;
use Cake\Event\Event_Dispatcher_Trait;
use Cake\ORM\Exception\Missing_Behavior_Exception;
use Cake\ORM\Query\Select_Query;
use LogicException;
/**
 * BehaviorRegistry is used as a registry for loaded behaviors and handles loading
 * and constructing behavior objects.
 *
 * This class also provides method for checking and dispatching behavior methods.
 *
 * @extends \Cake\Core\ObjectRegistry<\Cake\ORM\Behavior>
 * @implements \Cake\Event\EventDispatcherInterface<\Cake\ORM\Table>
 */
class Behavior_Registry extends Object_Registry implements Event_Dispatcher_Interface
{
    /**
     * @use \Cake\Event\EventDispatcherTrait<\Cake\ORM\Table>
     */
    use Event_Dispatcher_Trait;
    /**
     * The table using this registry.
     */
    protected Table $_table;
    /**
     * Method mappings.
     *
     * @var array<string, array>
     */
    protected array $_method_map = [];
    /**
     * Finder method mappings.
     *
     * @var array<string, array>
     */
    protected array $_finder_map = [];
    /**
     * Constructor
     *
     * @param \Cake\ORM\Table|null $table The table this registry is attached to.
     */
    public function __construct(?Table $table = null)
    {
        if ($table !== null) {
            $this->set_table($table);
        }
    }
    /**
     * Attaches a table instance to this registry.
     *
     * @param \Cake\ORM\Table $table The table this registry is attached to.
     */
    public function set_table(Table $table): void
    {
        $this->_table = $table;
        $this->set_event_manager($table->get_event_manager());
    }
    /**
     * Resolve a behavior classname.
     *
     * @param string $class Partial classname to resolve.
     * @return string|null Either the correct classname or null.
     * @phpstan-return class-string|null
     */
    public static function class_name(string $class): ?string
    {
        return App::class_name($class, 'Model/Behavior', '_Behavior') ?: App::class_name($class, 'ORM/Behavior', '_Behavior');
    }
    /**
     * Resolve a behavior classname.
     *
     * Part of the template method for Cake\Core\ObjectRegistry::load()
     *
     * @param string $class Partial classname to resolve.
     * @return class-string<\Cake\ORM\Behavior>|null Either the correct class name or null.
     */
    protected function _resolve_class_name(string $class): ?string
    {
        /** @var class-string<\Cake\ORM\Behavior>|null */
        return static::class_name($class);
    }
    /**
     * Throws an exception when a behavior is missing.
     *
     * Part of the template method for Cake\Core\ObjectRegistry::load()
     * and Cake\Core\ObjectRegistry::unload()
     *
     * @param string $class The classname that is missing.
     * @param string|null $plugin The plugin the behavior is missing in.
     * @throws \Cake\ORM\Exception\MissingBehaviorException
     */
    protected function _throw_missing_class_error(string $class, ?string $plugin): void
    {
        throw new Missing_Behavior_Exception(['class' => $class . 'Behavior', 'plugin' => $plugin]);
    }
    /**
     * Create the behavior instance.
     *
     * Part of the template method for Cake\Core\ObjectRegistry::load()
     * Enabled behaviors will be registered with the event manager.
     *
     * @param \Cake\ORM\Behavior|class-string<\Cake\ORM\Behavior> $class The classname that is missing.
     * @param string $alias The alias of the object.
     * @param array<string, mixed> $config An array of config to use for the behavior.
     * @return \Cake\ORM\Behavior The constructed behavior class.
     */
    protected function _create(object|string $class, string $alias, array $config): Behavior
    {
        if (is_object($class)) {
            return $class;
        }
        $instance = new $class($this->_table, $config);
        $enable = $config['enabled'] ?? true;
        if ($enable) {
            $this->get_event_manager()->on($instance);
        }
        $methods = $this->_get_methods($instance, $class, $alias);
        $this->_method_map += $methods['methods'];
        $this->_finder_map += $methods['finders'];
        return $instance;
    }
    /**
     * Get the behavior methods and ensure there are no duplicates.
     *
     * Use the implementedEvents() method to exclude callback methods.
     * Methods starting with `_` will be ignored, as will methods
     * declared on Cake\ORM\Behavior
     *
     * @param \Cake\ORM\Behavior $instance The behavior to get methods from.
     * @param string $class The classname that is missing.
     * @param string $alias The alias of the object.
     * @return array A list of implemented finders and methods.
     * @throws \LogicException when duplicate methods are connected.
     */
    protected function _get_methods(Behavior $instance, string $class, string $alias): array
    {
        $finders = array_change_key_case($instance->implemented_finders());
        $methods = array_change_key_case($instance->implemented_methods());
        foreach ($finders as $finder => $method_name) {
            if (isset($this->_finder_map[$finder]) && $this->has($this->_finder_map[$finder][0])) {
                $duplicate = $this->_finder_map[$finder];
                $error = sprintf('`%s` contains duplicate finder `%s` which is already provided by `%s`.', $class, $finder, $duplicate[0]);
                throw new LogicException($error);
            }
            $finders[$finder] = [$alias, $method_name];
        }
        foreach ($methods as $method => $method_name) {
            if (isset($this->_method_map[$method]) && $this->has($this->_method_map[$method][0])) {
                $duplicate = $this->_method_map[$method];
                $error = sprintf('`%s` contains duplicate method `%s` which is already provided by `%s`.', $class, $method, $duplicate[0]);
                throw new LogicException($error);
            }
            $methods[$method] = [$alias, $method_name];
        }
        return compact('methods', 'finders');
    }
    /**
     * Set an object directly into the registry by name.
     *
     * @param string $name The name of the object to set in the registry.
     * @param \Cake\ORM\Behavior $object instance to store in the registry
     * @return $this
     */
    public function set(string $name, object $object): static
    {
        parent::set($name, $object);
        $methods = $this->_get_methods($object, $object::class, $name);
        $this->_method_map += $methods['methods'];
        $this->_finder_map += $methods['finders'];
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
        $instance = $this->get($name);
        $result = parent::unload($name);
        $methods = array_map(strtolower(...), array_keys($instance->implemented_methods()));
        foreach ($methods as $method) {
            unset($this->_method_map[$method]);
        }
        $finders = array_map(strtolower(...), array_keys($instance->implemented_finders()));
        foreach ($finders as $finder) {
            unset($this->_finder_map[$finder]);
        }
        return $result;
    }
    /**
     * Check if any loaded behavior implements a method.
     *
     * Will return true if any behavior provides a public non-finder method
     * with the chosen name.
     *
     * @param string $method The method to check for.
     * @deprecated 5.3.0 Calling behavior methods on the table instance is deprecated.
     */
    public function has_method(string $method): bool
    {
        $method = strtolower($method);
        return isset($this->_method_map[$method]);
    }
    /**
     * Check if any loaded behavior implements the named finder.
     *
     * Will return true if any behavior provides a public method with
     * the chosen name.
     *
     * @param string $method The method to check for.
     */
    public function has_finder(string $method): bool
    {
        $method = strtolower($method);
        return isset($this->_finder_map[$method]);
    }
    /**
     * Invoke a method on a behavior.
     *
     * @param string $method The method to invoke.
     * @param array $args The arguments you want to invoke the method with.
     * @return mixed The return value depends on the underlying behavior method.
     * @throws \BadMethodCallException When the method is unknown.
     * @deprecated 5.3.0 Calling behavior methods on the table instance is deprecated.
     */
    public function call(string $method, array $args = []): mixed
    {
        deprecation_warning('5.3.0', sprintf('Calling behavior methods on the table instance is deprecated.' . '  Use `$table->getBehavior(\'YourBehavior\')->%s()` instead.', $method));
        $method = strtolower($method);
        if ($this->has_method($method) && $this->has($this->_method_map[$method][0])) {
            [$behavior, $call_method] = $this->_method_map[$method];
            return $this->_loaded[$behavior]->{$call_method}(...$args);
        }
        throw new BadMethodCallException(sprintf('Cannot call `%s`, it does not belong to any attached behavior.', $method));
    }
    /**
     * Invoke a finder on a behavior.
     *
     * @internal
     * @template TSubject of \Cake\Datasource\EntityInterface|array
     * @param string $type The finder type to invoke.
     * @param \Cake\ORM\Query\SelectQuery<TSubject> $query The query object to apply the finder options to.
     * @param mixed ...$args Arguments that match up to finder-specific parameters
     * @return \Cake\ORM\Query\SelectQuery<TSubject> The return value depends on the underlying behavior method.
     * @throws \BadMethodCallException When the method is unknown.
     */
    public function call_finder(string $type, Select_Query $query, mixed ...$args): Select_Query
    {
        $type = strtolower($type);
        if ($this->has_finder($type)) {
            [$behavior, $call_method] = $this->_finder_map[$type];
            $callable = $this->_loaded[$behavior]->{$call_method}(...);
            return $this->_table->invoke_finder($callable, $query, $args);
        }
        throw new BadMethodCallException(sprintf('Cannot call finder `%s`, it does not belong to any attached behavior.', $type));
    }
}