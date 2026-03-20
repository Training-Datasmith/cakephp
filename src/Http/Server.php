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

use Cake\Core\Container_Application_Interface;
use Cake\Core\Http_Application_Interface;
use Cake\Core\Plugin_Application_Interface;
use Cake\Event\Event_Dispatcher_Interface;
use Cake\Event\Event_Dispatcher_Trait;
use Cake\Event\Event_Manager;
use Cake\Event\Event_Manager_Interface;
use Cake\Routing\Router;
use InvalidArgumentException;
use Psr\Http\Message\Response_Interface;
use Psr\Http\Message\Server_Request_Interface;
/**
 * Runs an application invoking all the PSR7 middleware and the registered application.
 *
 * @implements \Cake\Event\EventDispatcherInterface<\Cake\Core\HttpApplicationInterface>
 */
class Server implements Event_Dispatcher_Interface
{
    /**
     * @use \Cake\Event\EventDispatcherTrait<\Cake\Core\HttpApplicationInterface>
     */
    use Event_Dispatcher_Trait;
    /**
     * Constructor
     *
     * @param \Cake\Core\HttpApplicationInterface $app The application to use.
     * @param \Cake\Http\Runner $runner Application runner.
     */
    public function __construct(protected Http_Application_Interface $app, protected Runner $runner = new Runner())
    {
    }
    /**
     * Run the request/response through the Application and its middleware.
     *
     * This will invoke the following methods:
     *
     * - App->bootstrap() - Perform any bootstrapping logic for your application here.
     * - App->middleware() - Attach any application middleware here.
     * - Trigger the 'Server.buildMiddleware' event. You can use this to modify the
     *   from event listeners.
     * - Run the middleware queue including the application.
     *
     * @param \Psr\Http\Message\ServerRequestInterface|null $request The request to use or null.
     * @param \Cake\Http\MiddlewareQueue|null $middlewareQueue MiddlewareQueue or null.
     * @throws \RuntimeException When the application does not make a response.
     */
    public function run(?Server_Request_Interface $request = null, ?Middleware_Queue $middleware_queue = null): Response_Interface
    {
        $this->bootstrap();
        $request = $request ?: Server_Request_Factory::from_globals();
        if ($middleware_queue === null) {
            if ($this->app instanceof Container_Application_Interface) {
                $middleware_queue = new Middleware_Queue([], $this->app->get_container());
            } else {
                $middleware_queue = new Middleware_Queue();
            }
        }
        $middleware = $this->app->middleware($middleware_queue);
        if ($this->app instanceof Plugin_Application_Interface) {
            $middleware = $this->app->plugin_middleware($middleware);
        }
        $this->dispatch_event('Server.buildMiddleware', ['middleware' => $middleware]);
        $response = $this->runner->run($middleware, $request, $this->app);
        if ($request instanceof Server_Request) {
            $request->get_session()->close();
        }
        return $response;
    }
    /**
     * Application bootstrap wrapper.
     *
     * Calls the application's `bootstrap()` hook. After the application the
     * plugins are bootstrapped.
     */
    protected function bootstrap(): void
    {
        $this->app->bootstrap();
        if ($this->app instanceof Plugin_Application_Interface) {
            $this->app->plugin_bootstrap();
        }
    }
    /**
     * Emit the response using the PHP SAPI.
     *
     * After the response has been emitted, the `Server.terminate` event will be triggered.
     *
     * The `Server.terminate` event can be used to do potentially heavy tasks after the
     * response is sent to the client. Only the PHP FPM server API is able to send a
     * response to the client while the server's PHP process still performs some tasks.
     * For other environments the event will be triggered before the response is flushed
     * to the client and will have no benefit.
     *
     * @param \Psr\Http\Message\ResponseInterface $response The response to emit
     * @param \Cake\Http\ResponseEmitter|null $emitter The emitter to use.
     *   When null, a SAPI Stream Emitter will be used.
     */
    public function emit(Response_Interface $response, ?Response_Emitter $emitter = null): void
    {
        $emitter ??= new Response_Emitter();
        $emitter->emit($response);
        $request = null;
        if ($this->app instanceof Container_Application_Interface) {
            $container = $this->app->get_container();
            if ($container->has(Server_Request::class)) {
                $request = $container->get(Server_Request::class);
            }
        }
        if (!$request) {
            $request = Router::get_request();
        }
        $this->dispatch_event('Server.terminate', compact('request', 'response'));
    }
    /**
     * Get the current application.
     *
     * @return \Cake\Core\HttpApplicationInterface The application that will be run.
     */
    public function get_app(): Http_Application_Interface
    {
        return $this->app;
    }
    /**
     * Get the application's event manager or the global one.
     */
    public function get_event_manager(): Event_Manager_Interface
    {
        if ($this->app instanceof Event_Dispatcher_Interface) {
            return $this->app->get_event_manager();
        }
        return Event_Manager::instance();
    }
    /**
     * Set the application's event manager.
     *
     * If the application does not support events, an exception will be raised.
     *
     * @param \Cake\Event\EventManagerInterface $eventManager The event manager to set.
     * @return $this
     * @throws \InvalidArgumentException
     */
    public function set_event_manager(Event_Manager_Interface $event_manager): static
    {
        if ($this->app instanceof Event_Dispatcher_Interface) {
            $this->app->set_event_manager($event_manager);
            return $this;
        }
        throw new InvalidArgumentException('Cannot set the event manager, the application does not support events.');
    }
}