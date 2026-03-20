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
namespace Cake\Http;

use Cake\Console\Command_Collection;
use Cake\Controller\Controller_Factory;
use Cake\Core\Console_Application_Interface;
use Cake\Core\Container;
use Cake\Core\Container_Application_Interface;
use Cake\Core\Container_Interface;
use Cake\Core\Event_Aware_Application_Interface;
use Cake\Core\Exception\Missing_Plugin_Exception;
use Cake\Core\Http_Application_Interface;
use Cake\Core\Plugin;
use Cake\Core\Plugin_Application_Interface;
use Cake\Core\Plugin_Collection;
use Cake\Core\Plugin_Interface;
use Cake\Event\Event_Dispatcher_Interface;
use Cake\Event\Event_Dispatcher_Trait;
use Cake\Event\Event_Manager;
use Cake\Event\Event_Manager_Interface;
use Cake\Routing\Route_Builder;
use Cake\Routing\Router;
use Cake\Routing\Routing_Application_Interface;
use Closure;
use Psr\Http\Message\Response_Interface;
use Psr\Http\Message\Server_Request_Interface;
/**
 * Base class for full-stack applications
 *
 * This class serves as a base class for applications that are using
 * CakePHP as a full stack framework. If you are only using the Http or Console libraries
 * you should implement the relevant interfaces directly.
 *
 * The application class is responsible for bootstrapping the application,
 * and ensuring that middleware is attached. It is also invoked as the last piece
 * of middleware, and delegates request/response handling to the correct controller.
 *
 * @template TSubject of \Cake\Http\BaseApplication
 * @implements \Cake\Event\EventDispatcherInterface<TSubject>
 * @implements \Cake\Core\PluginApplicationInterface<TSubject>
 */
abstract class Base_Application implements Console_Application_Interface, Container_Application_Interface, Event_Aware_Application_Interface, Event_Dispatcher_Interface, Http_Application_Interface, Plugin_Application_Interface, Routing_Application_Interface
{
    /**
     * @use \Cake\Event\EventDispatcherTrait<TSubject>
     */
    use Event_Dispatcher_Trait;
    /**
     * @var string Contains the path of the config directory
     */
    protected string $config_dir;
    /**
     * Plugin Collection
     */
    protected Plugin_Collection $plugins;
    /**
     * Container
     */
    protected ?Container_Interface $container = null;
    /**
     * Constructor
     *
     * @param string $configDir The directory the bootstrap configuration is held in.
     * @param \Cake\Event\EventManagerInterface|null $eventManager Application event manager instance.
     * @param \Cake\Http\ControllerFactoryInterface<\Cake\Controller\Controller>|null $controllerFactory Controller factory.
     */
    public function __construct(
        string $config_dir,
        ?Event_Manager_Interface $event_manager = null,
        /**
         * Controller factory
         */
        protected ?Controller_Factory_Interface $controller_factory = null
    )
    {
        $this->config_dir = rtrim($config_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $this->plugins = new Plugin_Collection();
        $this->_event_manager = $event_manager ?: Event_Manager::instance();
        Plugin::set_collection($this->plugins);
    }
    /**
     * @param \Cake\Http\MiddlewareQueue $middlewareQueue The middleware queue to set in your App Class
     */
    abstract public function middleware(Middleware_Queue $middleware_queue): Middleware_Queue;
    /**
     * @inheritDoc
     */
    public function plugin_middleware(Middleware_Queue $middleware): Middleware_Queue
    {
        foreach ($this->plugins->with('middleware') as $plugin) {
            $middleware = $plugin->middleware($middleware);
        }
        return $middleware;
    }
    /**
     * @inheritDoc
     */
    public function add_plugin($name, array $config = [])
    {
        if (is_string($name)) {
            $plugin = $this->plugins->create($name, $config);
        } else {
            $plugin = $name;
        }
        $this->plugins->add($plugin);
        return $this;
    }
    /**
     * Add an optional plugin
     *
     * If it isn't available, ignore it.
     *
     * @param \Cake\Core\PluginInterface|string $name The plugin name or plugin object.
     * @param array<string, mixed> $config The configuration data for the plugin if using a string for $name
     * @return $this
     */
    public function add_optional_plugin(Plugin_Interface|string $name, array $config = [])
    {
        try {
            $this->add_plugin($name, $config);
        } catch (Missing_Plugin_Exception) {
            // Do not halt if the plugin is missing
        }
        return $this;
    }
    /**
     * Get the plugin collection in use.
     */
    public function get_plugins(): Plugin_Collection
    {
        return $this->plugins;
    }
    /**
     * @inheritDoc
     */
    public function bootstrap(): void
    {
        require_once $this->config_dir . 'bootstrap.php';
        // phpcs:ignore
        $plugins = @include $this->config_dir . 'plugins.php';
        if (is_array($plugins)) {
            $this->plugins->add_from_config($plugins);
        }
    }
    /**
     * @inheritDoc
     */
    public function plugin_bootstrap(): void
    {
        foreach ($this->plugins->with('bootstrap') as $plugin) {
            $plugin->bootstrap($this);
        }
    }
    /**
     * {@inheritDoc}
     *
     * By default, this will load `config/routes.php` for ease of use and backwards compatibility.
     *
     * @param \Cake\Routing\RouteBuilder $routes A route builder to add routes into.
     */
    public function routes(Route_Builder $routes): void
    {
        // Only load routes if the router is empty
        if (!Router::routes()) {
            $return = require $this->config_dir . 'routes.php';
            if ($return instanceof Closure) {
                $return($routes);
            }
        }
    }
    /**
     * @inheritDoc
     */
    public function plugin_routes(Route_Builder $routes): Route_Builder
    {
        foreach ($this->plugins->with('routes') as $plugin) {
            $plugin->routes($routes);
        }
        return $routes;
    }
    /**
     * Define the console commands for an application.
     *
     * By default, all commands in CakePHP, plugins and the application will be
     * loaded using conventions based names.
     *
     * @param \Cake\Console\CommandCollection $commands The CommandCollection to add commands into.
     * @return \Cake\Console\CommandCollection The updated collection.
     */
    public function console(Command_Collection $commands): Command_Collection
    {
        return $commands->add_many($commands->auto_discover());
    }
    /**
     * @inheritDoc
     */
    public function plugin_console(Command_Collection $commands): Command_Collection
    {
        foreach ($this->plugins->with('console') as $plugin) {
            $commands = $plugin->console($commands);
        }
        return $commands;
    }
    /**
     * @param \Cake\Event\EventManagerInterface $eventManager The global event manager to register listeners on
     */
    public function plugin_events(Event_Manager_Interface $event_manager): Event_Manager_Interface
    {
        foreach ($this->plugins->with('events') as $plugin) {
            $event_manager = $plugin->events($event_manager);
        }
        return $event_manager;
    }
    /**
     * Get the dependency injection container for the application.
     *
     * The first time the container is fetched it will be constructed
     * and stored for future calls.
     */
    public function get_container(): Container_Interface
    {
        return $this->container ??= $this->build_container();
    }
    /**
     * Build the service container
     *
     * Override this method if you need to use a custom container or
     * want to change how the container is built.
     */
    protected function build_container(): Container_Interface
    {
        $container = new Container();
        $this->services($container);
        foreach ($this->plugins->with('services') as $plugin) {
            $plugin->services($container);
        }
        $event = $this->dispatch_event('Application.buildContainer', ['container' => $container]);
        if ($event->get_result() instanceof Container_Interface) {
            return $event->get_result();
        }
        return $container;
    }
    /**
     * Register application container services.
     *
     * @param \Cake\Core\ContainerInterface $container The Container to update.
     */
    public function services(Container_Interface $container): void
    {
    }
    /**
     * Register application events.
     *
     * @param \Cake\Event\EventManagerInterface $eventManager The global event manager to register listeners on
     */
    public function events(Event_Manager_Interface $event_manager): Event_Manager_Interface
    {
        return $event_manager;
    }
    /**
     * Invoke the application.
     *
     * - Add the request to the container, enabling its injection into other services.
     * - Create the controller that will handle this request.
     * - Invoke the controller.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request The request
     */
    public function handle(Server_Request_Interface $request): Response_Interface
    {
        $container = $this->get_container();
        $container->add(Server_Request::class, $request);
        $container->add(Container_Interface::class, $container);
        $event_manager = $this->events($this->get_event_manager());
        $this->set_event_manager($this->plugin_events($event_manager));
        $this->controller_factory ??= new Controller_Factory($container);
        if (Router::get_request() !== $request) {
            assert($request instanceof Server_Request);
            Router::set_request($request);
        }
        $controller = $this->controller_factory->create($request);
        return $this->controller_factory->invoke($controller);
    }
}