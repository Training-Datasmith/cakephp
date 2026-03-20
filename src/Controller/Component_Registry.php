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
 * @since         2.0.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Controller;

use Cake\Controller\Exception\Missing_Component_Exception;
use Cake\Core\App;
use Cake\Core\Container_Interface;
use Cake\Core\Exception\Cake_Exception;
use Cake\Core\Object_Registry;
use Cake\Event\Event_Dispatcher_Interface;
use Cake\Event\Event_Dispatcher_Trait;
use League\Container\Argument\Argument_Reflector_Trait;
use League\Container\Argument\Argument_Resolver_Trait;
use League\Container\Argument\Literal_Argument;
use League\Container\Argument\Resolvable_Argument;
use League\Container\Exception\Not_Found_Exception;
use League\Container\Reflection_Container;
use ReflectionClass;
use Reflection_Function_Abstract;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;
/**
 * ComponentRegistry is a registry for loaded components
 *
 * Handles loading, constructing and binding events for component class objects.
 *
 * @template TSubject of \Cake\Controller\Controller
 * @extends \Cake\Core\ObjectRegistry<\Cake\Controller\Component>
 * @implements \Cake\Event\EventDispatcherInterface<TSubject>
 */
class Component_Registry extends Object_Registry implements Event_Dispatcher_Interface
{
    /**
     * @use \Cake\Event\EventDispatcherTrait<TSubject>
     */
    use Event_Dispatcher_Trait;
    use Argument_Resolver_Trait;
    use Argument_Reflector_Trait;
    /**
     * The controller that this collection is associated with.
     */
    protected ?Controller $_Controller = null;
    /**
     * Constructor.
     *
     * @param \Cake\Controller\Controller|null $controller Controller instance.
     * @param \Cake\Core\ContainerInterface|null $container Container instance.
     */
    public function __construct(?Controller $controller = null, protected ?Container_Interface $container = null)
    {
        if ($controller !== null) {
            $this->set_controller($controller);
        }
    }
    /**
     * Set the controller associated with the collection.
     *
     * @param \Cake\Controller\Controller $controller Controller instance.
     * @return $this
     */
    public function set_controller(Controller $controller): static
    {
        $this->_Controller = $controller;
        $this->set_event_manager($controller->get_event_manager());
        return $this;
    }
    /**
     * Get the controller associated with the collection.
     *
     * @return \Cake\Controller\Controller Controller instance.
     */
    public function get_controller(): Controller
    {
        if ($this->_Controller === null) {
            throw new RuntimeException('Controller must be set first.');
        }
        return $this->_Controller;
    }
    /**
     * Resolve a component classname.
     *
     * Part of the template method for {@link \Cake\Core\ObjectRegistry::load()}.
     *
     * @param string $class Partial classname to resolve.
     * @return class-string<\Cake\Controller\Component>|null Either the correct class name or null.
     */
    protected function _resolve_class_name(string $class): ?string
    {
        /** @var class-string<\Cake\Controller\Component>|null */
        return App::class_name($class, 'Controller/Component', 'Component');
    }
    /**
     * Throws an exception when a component is missing.
     *
     * Part of the template method for {@link \Cake\Core\ObjectRegistry::load()}
     * and {@link \Cake\Core\ObjectRegistry::unload()}
     *
     * @param string $class The classname that is missing.
     * @param string|null $plugin The plugin the component is missing in.
     * @throws \Cake\Controller\Exception\MissingComponentException
     */
    protected function _throw_missing_class_error(string $class, ?string $plugin): void
    {
        throw new Missing_Component_Exception(['class' => $class . 'Component', 'plugin' => $plugin]);
    }
    /**
     * Create the component instance.
     *
     * Part of the template method for {@link \Cake\Core\ObjectRegistry::load()}
     * Enabled components will be registered with the event manager.
     *
     * ## Container Resolution
     *
     * When a container is available, this method attempts to resolve components from it.
     * Components registered in the container will be resolved using dependency injection.
     * If not registered, a new definition will be created with auto-wired constructor arguments.
     *
     * ## Edge Cases
     *
     * - **Shared instances**: Components registered as shared instances in the container
     *   will have their config merged via setConfig(). This means multiple controller
     *   instances may share the same component instance, which could lead to unexpected
     *   state sharing between requests.
     * - **Manual registration**: Components manually registered in the container with
     *   specific constructor arguments will use those arguments. The `$config` parameter
     *   will be merged into the component after instantiation using setConfig().
     *
     * @param \Cake\Controller\Component|class-string<\Cake\Controller\Component> $class The classname to create.
     * @param string $alias The alias of the component.
     * @param array<string, mixed> $config An array of config to use for the component.
     * @return \Cake\Controller\Component The constructed component class.
     */
    protected function _create(object|string $class, string $alias, array $config): Component
    {
        if (is_object($class)) {
            return $class;
        }
        if ($this->container?->has($class)) {
            // Check if definition already exists - if so, user has manually configured it
            $has_definition = false;
            try {
                $this->container->extend($class);
                $has_definition = true;
            } catch (Not_Found_Exception) {
                // No definition exists yet
            }
            if (!$has_definition) {
                // No user-defined configuration - add auto-wired arguments
                $constructor = (new ReflectionClass($class))->get_constructor();
                if ($constructor !== null) {
                    $args = $this->reflect_arguments($constructor, ['config' => $config]);
                    $this->container->add($class)->add_arguments($args);
                }
            }
            /** @var \Cake\Controller\Component $instance */
            $instance = $this->container->get($class);
            // For manually configured components, merge runtime config
            if ($has_definition && $config) {
                $instance->set_config($config);
            }
        } else {
            $instance = new $class($this, $config);
        }
        if ($config['enabled'] ?? true) {
            $this->get_event_manager()->on($instance);
        }
        return $instance;
    }
    /**
     * Get container instance.
     */
    protected function get_container(): Container_Interface
    {
        if ($this->container === null) {
            throw new Cake_Exception('Container not set.');
        }
        return $this->container;
    }
    /**
     * Reflect on constructor arguments and build argument list for container.
     *
     * This method inspects a constructor's parameters and builds a list of
     * arguments that can be passed to the container's add() or extend() methods.
     *
     * @param \ReflectionFunctionAbstract $method The constructor to reflect on
     * @param array<string, mixed> $args Named arguments to pass as literals (e.g., ['config' => []])
     * @return array<\League\Container\Argument\LiteralArgument|\League\Container\Argument\ResolvableArgument>
     */
    protected function reflect_arguments(Reflection_Function_Abstract $method, array $args = []): array
    {
        $arguments = [];
        $params = $method->get_parameters();
        foreach ($params as $param) {
            $name = $param->get_name();
            // If we have a literal value for this parameter, use it
            if (array_key_exists($name, $args)) {
                $arguments[] = new Literal_Argument($args[$name]);
                continue;
            }
            // Check if parameter has a type hint
            $type = $param->get_type();
            if ($type instanceof ReflectionNamedType && !$type->is_builtin()) {
                // Type-hinted parameter - resolve from container
                $arguments[] = new Resolvable_Argument($type->get_name());
                continue;
            }
            // Check for default value
            if ($param->is_default_value_available()) {
                $arguments[] = new Literal_Argument($param->get_default_value());
                continue;
            }
            // No type hint, no default, no provided value - this will fail at runtime
            $declaring_class = $method instanceof ReflectionMethod ? $method->get_declaring_class()->get_name() : 'unknown';
            throw new Cake_Exception(sprintf('Cannot auto-wire parameter $%s in %s - no type hint or default value', $name, $declaring_class));
        }
        return $this->resolve_arguments($arguments);
    }
    /**
     * Get the mode of the container.
     *
     * This method is used to determine how the container should resolve
     * dependencies and arguments.
     *
     * @return int The mode of the container.
     * @internal
     */
    protected function get_mode(): int
    {
        return Reflection_Container::AUTO_WIRING;
    }
}