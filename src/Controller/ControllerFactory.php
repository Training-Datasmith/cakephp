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
 * @since         3.3.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Controller;

use Cake\Controller\Exception\Invalid_Parameter_Exception;
use Cake\Core\App;
use Cake\Core\Container_Interface;
use function Cake\Core\To_Bool;
use function Cake\Core\To_Float;
use function Cake\Core\To_Int;
use Cake\Http\Controller_Factory_Interface;
use Cake\Http\Exception\Missing_Controller_Exception;
use Cake\Http\Middleware_Queue;
use Cake\Http\Runner;
use Cake\Http\Server_Request;
use Closure;
use Psr\Http\Message\Response_Interface;
use Psr\Http\Message\Server_Request_Interface;
use Psr\Http\Server\Request_Handler_Interface;
use ReflectionClass;
use ReflectionFunction;
use ReflectionNamedType;
/**
 * Factory method for building controllers for request.
 *
 * @implements \Cake\Http\ControllerFactoryInterface<\Cake\Controller\Controller>
 */
class Controller_Factory implements Controller_Factory_Interface, Request_Handler_Interface
{
    protected Controller $controller;
    /**
     * Constructor
     *
     * @param \Cake\Core\ContainerInterface $container The container to build controllers with.
     */
    public function __construct(protected Container_Interface $container)
    {
    }
    /**
     * Create a controller for a given request.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request The request to build a controller for.
     * @throws \Cake\Http\Exception\MissingControllerException
     */
    public function create(Server_Request_Interface $request): Controller
    {
        assert($request instanceof Server_Request);
        $class_name = $this->get_controller_class($request);
        if ($class_name === null) {
            throw $this->missing_controller($request);
        }
        $reflection = new ReflectionClass($class_name);
        if ($reflection->is_abstract()) {
            throw $this->missing_controller($request);
        }
        $this->container->add_shared(Component_Registry::class, new Component_Registry(container: $this->container));
        // Get the controller from the container if defined.
        // The request is in the container by default.
        if ($this->container->has($class_name)) {
            $controller = $this->container->get($class_name);
        } else {
            $components = $this->container->get(Component_Registry::class);
            $constructor = $reflection->get_constructor();
            assert($constructor !== null);
            $has_components = false;
            foreach ($constructor->get_parameters() as $parameter) {
                $param_type = $parameter->get_type();
                // TODO: In a future minor release it would be good to start requiring the components parameter
                if ($parameter->get_name() === 'components' && $param_type instanceof ReflectionNamedType && $param_type->get_name() === Component_Registry::class) {
                    $has_components = true;
                    break;
                }
            }
            if ($has_components) {
                $controller = $reflection->new_instance(request: $request, components: $components);
            } else {
                $controller = $reflection->new_instance($request);
            }
        }
        return $controller;
    }
    /**
     * Invoke a controller's action and wrapping methods.
     *
     * @param \Cake\Controller\Controller $controller The controller to invoke.
     * @return \Psr\Http\Message\ResponseInterface The response
     * @throws \Cake\Controller\Exception\MissingActionException If controller action is not found.
     * @throws \UnexpectedValueException If return value of action method is not null or ResponseInterface instance.
     */
    public function invoke(mixed $controller): Response_Interface
    {
        $this->controller = $controller;
        $middlewares = $controller->get_middleware();
        if ($middlewares) {
            $middleware_queue = new Middleware_Queue($middlewares, $this->container);
            $runner = new Runner();
            return $runner->run($middleware_queue, $controller->get_request(), $this);
        }
        return $this->handle($controller->get_request());
    }
    /**
     * Invoke the action.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request Request instance.
     */
    public function handle(Server_Request_Interface $request): Response_Interface
    {
        assert($request instanceof Server_Request);
        $controller = $this->controller;
        $controller->set_request($request);
        $result = $controller->startup_process();
        if ($result !== null) {
            return $result;
        }
        $action = $controller->get_action();
        $args = $this->get_action_args($action, array_values((array) $controller->get_request()->get_param('pass')));
        $controller->invoke_action($action, $args);
        $result = $controller->shutdown_process();
        if ($result !== null) {
            return $result;
        }
        return $controller->get_response();
    }
    /**
     * Get the arguments for the controller action invocation.
     *
     * @param \Closure $action Controller action.
     * @param array $passedParams Params passed by the router.
     */
    protected function get_action_args(Closure $action, array $passed_params): array
    {
        $resolved = [];
        $function = new ReflectionFunction($action);
        foreach ($function->get_parameters() as $parameter) {
            $type = $parameter->get_type();
            // Check for dependency injection for classes
            if ($type instanceof ReflectionNamedType && !$type->is_builtin()) {
                $type_name = $type->get_name();
                if ($this->container->has($type_name)) {
                    $resolved[] = $this->container->get($type_name);
                    continue;
                }
                // Use passedParams as a source of typed dependencies.
                // The accepted types for passedParams was never defined and userland code relies on that.
                if ($passed_params && $passed_params[0] instanceof $type_name) {
                    $resolved[] = array_shift($passed_params);
                    continue;
                }
                // Add default value if provided
                // Do not allow positional arguments for classes
                if ($parameter->is_default_value_available()) {
                    $resolved[] = $parameter->get_default_value();
                    continue;
                }
                throw new Invalid_Parameter_Exception(['template' => 'missing_dependency', 'parameter' => $parameter->get_name(), 'type' => $type_name, 'controller' => $this->controller->get_name(), 'action' => $this->controller->get_request()->get_param('action'), 'prefix' => $this->controller->get_request()->get_param('prefix'), 'plugin' => $this->controller->get_request()->get_param('plugin')]);
            }
            // Use any passed params as positional arguments
            if ($passed_params) {
                $argument = array_shift($passed_params);
                if (is_string($argument) && $type instanceof ReflectionNamedType) {
                    $typed_argument = $this->coerce_string_to_type($argument, $type);
                    if ($typed_argument === null) {
                        throw new Invalid_Parameter_Exception(['template' => 'failed_coercion', 'passed' => $argument, 'type' => $type->get_name(), 'parameter' => $parameter->get_name(), 'controller' => $this->controller->get_name(), 'action' => $this->controller->get_request()->get_param('action'), 'prefix' => $this->controller->get_request()->get_param('prefix'), 'plugin' => $this->controller->get_request()->get_param('plugin')]);
                    }
                    $argument = $typed_argument;
                }
                $resolved[] = $argument;
                continue;
            }
            // Add default value if provided
            if ($parameter->is_default_value_available()) {
                $resolved[] = $parameter->get_default_value();
                continue;
            }
            // Variadic parameter can have 0 arguments
            if ($parameter->is_variadic()) {
                continue;
            }
            throw new Invalid_Parameter_Exception(['template' => 'missing_parameter', 'parameter' => $parameter->get_name(), 'controller' => $this->controller->get_name(), 'action' => $this->controller->get_request()->get_param('action'), 'prefix' => $this->controller->get_request()->get_param('prefix'), 'plugin' => $this->controller->get_request()->get_param('plugin')]);
        }
        return array_merge($resolved, $passed_params);
    }
    /**
     * Coerces string argument to primitive type.
     *
     * @param string $argument Argument to coerce
     * @param \ReflectionNamedType $type Parameter type
     */
    protected function coerce_string_to_type(string $argument, ReflectionNamedType $type): array|string|float|int|bool|null
    {
        return match ($type->get_name()) {
            'string' => $argument,
            'float' => to_float($argument),
            'int' => to_int($argument),
            'bool' => to_bool($argument),
            'array' => $argument === '' ? [] : explode(',', $argument),
            default => null,
        };
    }
    /**
     * Determine the controller class name based on current request and controller param
     *
     * @param \Cake\Http\ServerRequest $request The request to build a controller for.
     * @return class-string<\Cake\Controller\Controller>|null
     */
    public function get_controller_class(Server_Request $request): ?string
    {
        $plugin_path = '';
        $namespace = 'Controller';
        $controller = $request->get_param('controller', '');
        if ($request->get_param('plugin')) {
            $plugin_path = $request->get_param('plugin') . '.';
        }
        if ($request->get_param('prefix')) {
            $prefix = $request->get_param('prefix');
            $namespace .= '/' . $prefix;
        }
        $first_char = substr((string) $controller, 0, 1);
        // Disallow plugin short forms, / and \\ from
        // controller names as they allow direct references to
        // be created.
        if (str_contains((string) $controller, '\\') || str_contains((string) $controller, '/') || str_contains((string) $controller, '.') || $first_char === strtolower($first_char)) {
            throw $this->missing_controller($request);
        }
        /** @var class-string<\Cake\Controller\Controller>|null */
        return App::class_name($plugin_path . $controller, $namespace, 'Controller');
    }
    /**
     * Throws an exception when a controller is missing.
     *
     * @param \Cake\Http\ServerRequest $request The request.
     */
    protected function missing_controller(Server_Request $request): Missing_Controller_Exception
    {
        return new Missing_Controller_Exception(['controller' => $request->get_param('controller'), 'plugin' => $request->get_param('plugin'), 'prefix' => $request->get_param('prefix'), '_ext' => $request->get_param('_ext')]);
    }
}
// phpcs:disable
class_alias(\Cake\Controller\Controller_Factory::class, 'Cake\Http\ControllerFactory');
// phpcs:enable