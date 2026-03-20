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

use Cake\Core\Exception\Cake_Exception;
use Cake\Core\Instance_Config_Trait;
use Cake\Event\Event_Listener_Interface;
use ReflectionClass;
use ReflectionMethod;
/**
 * Base class for behaviors.
 *
 * Behaviors allow you to simulate mixins, and create
 * reusable blocks of application logic, that can be reused across
 * several models. Behaviors also provide a way to hook into model
 * callbacks and augment their behavior.
 *
 * ### Mixin methods
 *
 * Behaviors can provide mixin like features by declaring public
 * methods. These methods will be accessible on the tables the
 * behavior has been added to.
 *
 * ```
 * function doSomething($arg1, $arg2) {
 *   // do something
 * }
 * ```
 *
 * Would be called like `$table->doSomething($arg1, $arg2);`.
 *
 * ### Callback methods
 *
 * Behaviors can listen to any events fired on a Table. By default,
 * CakePHP provides a number of lifecycle events your behaviors can
 * listen to:
 *
 * - `beforeFind(EventInterface $event, SelectQuery $query, ArrayObject $options, boolean $primary)`
 *   Fired before each find operation. By stopping the event and supplying a
 *   return value you can bypass the find operation entirely. Any changes done
 *   to the $query instance will be retained for the rest of the find. The
 *   $primary parameter indicates whether this is the root query
 *   or an associated query.
 *
 * - `buildValidator(EventInterface $event, Validator $validator, string $name)`
 *   Fired when the validator object identified by $name is being built. You can use this
 *   callback to add validation rules or add validation providers.
 *
 * - `buildRules(EventInterface $event, RulesChecker $rules)`
 *   Fired when the rules checking object for the table is being built. You can use this
 *   callback to add more rules to the set.
 *
 * - `beforeRules(EventInterface $event, EntityInterface $entity, ArrayObject $options, $operation)`
 *   Fired before an entity is validated using by a rules checker. By stopping this event,
 *   you can return the final value of the rules checking operation.
 *
 * - `afterRules(EventInterface $event, EntityInterface $entity, ArrayObject $options, bool $result, $operation)`
 *   Fired after the rules have been checked on the entity. By stopping this event,
 *   you can return the final value of the rules checking operation.
 *
 * - `beforeSave(EventInterface $event, EntityInterface $entity, ArrayObject $options)`
 *   Fired before each entity is saved. Stopping this event will abort the save
 *   operation. When the event is stopped the result of the event will be returned.
 *
 * - `afterSave(EventInterface $event, EntityInterface $entity, ArrayObject $options)`
 *   Fired after an entity is saved.
 *
 * - `beforeDelete(EventInterface $event, EntityInterface $entity, ArrayObject $options)`
 *   Fired before an entity is deleted. By stopping this event you will abort
 *   the delete operation.
 *
 * - `afterDelete(EventInterface $event, EntityInterface $entity, ArrayObject $options)`
 *   Fired after an entity has been deleted.
 *
 * In addition to the core events, behaviors can respond to any
 * event fired from your Table classes including custom application
 * specific ones.
 *
 * You can set the priority of behaviors' callbacks by using the
 * `priority` setting when attaching a behavior. This will set the
 * priority for all the callbacks a behavior provides.
 *
 * ### Finder methods
 *
 * Behaviors can provide finder methods that hook into a Table's
 * find() method. Custom finders are a great way to provide preset
 * queries that relate to your behavior. For example a SluggableBehavior
 * could provide a find('slugged') finder. Behavior finders
 * are implemented the same as other finders. Any method
 * starting with `find` will be setup as a finder. Your finder
 * methods should expect the following arguments:
 *
 * ```
 * findSlugged(SelectQuery $query, array $options)
 * ```
 *
 * @see \Cake\ORM\Table::addBehavior()
 * @see \Cake\Event\EventManager
 */
class Behavior implements Event_Listener_Interface
{
    use Instance_Config_Trait;
    /**
     * Reflection method cache for behaviors.
     *
     * Stores the reflected method + finder methods per class.
     * This prevents reflecting the same class multiple times in a single process.
     *
     * @var array<string, array>
     */
    protected static array $_reflection_cache = [];
    /**
     * Default configuration
     *
     * These are merged with user-provided configuration when the behavior is used.
     *
     * @var array<string, mixed>
     */
    protected array $_default_config = [];
    /**
     * Constructor
     *
     * Merges config with the default and store in the config property
     *
     * @param \Cake\ORM\Table $_table The table this behavior is attached to.
     * @param array<string, mixed> $config The config for this behavior.
     */
    public function __construct(
        /**
         * Table instance.
         */
        protected Table $_table,
        array $config = []
    )
    {
        $config = $this->_resolve_method_aliases('implementedFinders', $this->_default_config, $config);
        $config = $this->_resolve_method_aliases('implementedMethods', $this->_default_config, $config);
        $this->set_config($config);
        $this->initialize($config);
    }
    /**
     * Constructor hook method.
     *
     * Implement this method to avoid having to overwrite
     * the constructor and call parent.
     *
     * @param array<string, mixed> $config The configuration settings provided to this behavior.
     */
    public function initialize(array $config): void
    {
    }
    /**
     * Get the table instance this behavior is bound to.
     *
     * @return \Cake\ORM\Table The bound table instance.
     */
    public function table(): Table
    {
        return $this->_table;
    }
    /**
     * Removes aliased methods that would otherwise be duplicated by userland configuration.
     *
     * @param string $key The key to filter.
     * @param array<string, mixed> $defaults The default method mappings.
     * @param array<string, mixed> $config The customized method mappings.
     * @return array A de-duped list of config data.
     */
    protected function _resolve_method_aliases(string $key, array $defaults, array $config): array
    {
        if (!isset($defaults[$key], $config[$key])) {
            return $config;
        }
        if ($config[$key] === []) {
            $this->set_config($key, [], false);
            unset($config[$key]);
            return $config;
        }
        $indexed = array_flip($defaults[$key]);
        $indexed_custom = array_flip($config[$key]);
        foreach ($indexed as $method => $alias) {
            $indexed_custom[$method] ??= $alias;
        }
        $this->set_config($key, array_flip($indexed_custom), false);
        unset($config[$key]);
        return $config;
    }
    /**
     * verifyConfig
     *
     * Checks that implemented keys contain values pointing at callable.
     *
     * @throws \Cake\Core\Exception\CakeException if config are invalid
     */
    public function verify_config(): void
    {
        $keys = ['implementedFinders', 'implementedMethods'];
        foreach ($keys as $key) {
            if (!isset($this->_config[$key])) {
                continue;
            }
            foreach ($this->_config[$key] as $method) {
                if (!is_callable([$this, $method])) {
                    throw new Cake_Exception(sprintf('The method `%s` is not callable on class `%s`.', $method, static::class));
                }
            }
        }
    }
    /**
     * Gets the Model callbacks this behavior is interested in.
     *
     * By defining one of the callback methods a behavior is assumed
     * to be interested in the related event.
     *
     * Override this method if you need to add non-conventional event listeners.
     * Or if you want your behavior to listen to non-standard events.
     *
     * @return array<string, mixed>
     */
    public function implemented_events(): array
    {
        $event_map = ['Model.beforeMarshal' => 'beforeMarshal', 'Model.afterMarshal' => 'afterMarshal', 'Model.beforeFind' => 'beforeFind', 'Model.beforeSave' => 'beforeSave', 'Model.afterSave' => 'afterSave', 'Model.afterSaveCommit' => 'afterSaveCommit', 'Model.beforeDelete' => 'beforeDelete', 'Model.afterDelete' => 'afterDelete', 'Model.afterDeleteCommit' => 'afterDeleteCommit', 'Model.buildValidator' => 'buildValidator', 'Model.buildRules' => 'buildRules', 'Model.beforeRules' => 'beforeRules', 'Model.afterRules' => 'afterRules'];
        $config = $this->get_config();
        $priority = $config['priority'] ?? null;
        $events = [];
        foreach ($event_map as $event => $method) {
            if (!method_exists($this, $method)) {
                continue;
            }
            if ($priority === null) {
                $events[$event] = $method;
            } else {
                $events[$event] = ['callable' => $method, 'priority' => $priority];
            }
        }
        return $events;
    }
    /**
     * implementedFinders
     *
     * Provides an alias->methodname map of which finders a behavior implements. Example:
     *
     * ```
     *  [
     *    'this' => 'findThis',
     *    'alias' => 'findMethodName'
     *  ]
     * ```
     *
     * With the above example, a call to `$table->find('this')` will call `$behavior->findThis()`
     * and a call to `$table->find('alias')` will call `$behavior->findMethodName()`
     *
     * It is recommended, though not required, to define implementedFinders in the config property
     * of child classes such that it is not necessary to use reflections to derive the available
     * method list. See core behaviors for examples
     *
     * @throws \ReflectionException
     */
    public function implemented_finders(): array
    {
        $methods = $this->get_config('implementedFinders');
        if ($methods !== null) {
            return $methods;
        }
        return $this->_reflection_cache()['finders'];
    }
    /**
     * implementedMethods
     *
     * Provides an alias->methodname map of which methods a behavior implements. Example:
     *
     * ```
     *  [
     *    'method' => 'method',
     *    'aliasedMethod' => 'somethingElse'
     *  ]
     * ```
     *
     * With the above example, a call to `$table->method()` will call `$behavior->method()`
     * and a call to `$table->aliasedMethod()` will call `$behavior->somethingElse()`
     *
     * It is recommended, though not required, to define implementedFinders in the config property
     * of child classes such that it is not necessary to use reflections to derive the available
     * method list. See core behaviors for examples
     *
     * @throws \ReflectionException
     * @deprecated 5.3.0 Calling behavior methods on the table instance is deprecated.
     */
    public function implemented_methods(): array
    {
        $methods = $this->get_config('implementedMethods');
        if ($methods !== null) {
            return $methods;
        }
        return $this->_reflection_cache()['methods'];
    }
    /**
     * Gets the methods implemented by this behavior
     *
     * Uses the implementedEvents() method to exclude callback methods.
     * Methods starting with `_` will be ignored, as will methods
     * declared on Cake\ORM\Behavior
     *
     * @throws \ReflectionException
     */
    protected function _reflection_cache(): array
    {
        $class = static::class;
        if (isset(self::$_reflection_cache[$class])) {
            return self::$_reflection_cache[$class];
        }
        $events = $this->implemented_events();
        $event_methods = [];
        foreach ($events as $binding) {
            if (is_array($binding) && isset($binding['callable'])) {
                $callable = $binding['callable'];
                assert(is_string($callable));
                $binding = $callable;
            }
            $event_methods[$binding] = true;
        }
        $base_class = self::class;
        if (isset(self::$_reflection_cache[$base_class])) {
            $base_methods = self::$_reflection_cache[$base_class];
        } else {
            $base_methods = get_class_methods($base_class);
            self::$_reflection_cache[$base_class] = $base_methods;
        }
        $return = ['finders' => [], 'methods' => []];
        $reflection = new ReflectionClass($class);
        foreach ($reflection->get_methods(ReflectionMethod::IS_PUBLIC) as $method) {
            $method_name = $method->get_name();
            if (in_array($method_name, $base_methods, true)) {
                continue;
            }
            if (isset($event_methods[$method_name])) {
                continue;
            }
            if (str_starts_with($method_name, 'find')) {
                $return['finders'][lcfirst(substr($method_name, 4))] = $method_name;
            } else {
                $return['methods'][$method_name] = $method_name;
            }
        }
        return self::$_reflection_cache[$class] = $return;
    }
}