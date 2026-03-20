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
namespace Cake\Error\Middleware;

use Cake\Core\App;
use Cake\Core\Configure;
use Cake\Core\Instance_Config_Trait;
use Cake\Core\Plugin_Application_Interface;
use function Cake\Core\Trigger_Warning;
use Cake\Error\Exception_Trap;
use Cake\Error\Renderer\Web_Exception_Renderer;
use Cake\Event\Event_Dispatcher_Trait;
use Cake\Http\Exception\Redirect_Exception;
use Cake\Http\Response;
use Cake\Routing\Router;
use Cake\Routing\Routing_Application_Interface;
use Laminas\Diactoros\Response\Redirect_Response;
use Psr\Http\Message\Response_Interface;
use Psr\Http\Message\Server_Request_Interface;
use Psr\Http\Server\Middleware_Interface;
use Psr\Http\Server\Request_Handler_Interface;
use Throwable;
/**
 * Error handling middleware.
 *
 * Traps exceptions and converts them into HTML or content-type appropriate
 * error pages using the CakePHP ExceptionRenderer.
 */
class Error_Handler_Middleware implements Middleware_Interface
{
    use Instance_Config_Trait;
    /**
     * @use \Cake\Event\EventDispatcherTrait<\Cake\Error\ExceptionTrap>
     */
    use Event_Dispatcher_Trait;
    /**
     * Default configuration values.
     *
     * Ignored if constructor is passed an ExceptionTrap instance.
     *
     * Configuration keys and values are shared with `ExceptionTrap`.
     * This class will pass its configuration onto the ExceptionTrap
     * class if you are using the array style constructor.
     *
     * @var array<string, mixed>
     * @see \Cake\Error\ExceptionTrap
     */
    protected array $_default_config = ['exceptionRenderer' => Web_Exception_Renderer::class];
    /**
     * ExceptionTrap instance
     */
    protected ?Exception_Trap $exception_trap = null;
    /**
     * Constructor
     *
     * @param \Cake\Error\ExceptionTrap|array $config The error handler instance
     *  or config array.
     * @param \Cake\Routing\RoutingApplicationInterface|null $app Application instance.
     */
    public function __construct(Exception_Trap|array $config = [], protected ?Routing_Application_Interface $app = null)
    {
        if (Configure::read('debug')) {
            ini_set('zend.exception_ignore_args', '0');
        }
        if (is_array($config)) {
            $this->set_config($config);
            return;
        }
        $this->exception_trap = $config;
    }
    /**
     * Wrap the remaining middleware with error handling.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request The request.
     * @param \Psr\Http\Server\RequestHandlerInterface $handler The request handler.
     * @return \Psr\Http\Message\ResponseInterface A response
     */
    public function process(Server_Request_Interface $request, Request_Handler_Interface $handler): Response_Interface
    {
        try {
            return $handler->handle($request);
        } catch (Redirect_Exception $exception) {
            return $this->handle_redirect($exception);
        } catch (Throwable $exception) {
            return $this->handle_exception($exception, Router::get_request() ?? $request);
        }
    }
    /**
     * Handle an exception and generate an error response
     *
     * @param \Throwable $exception The exception to handle.
     * @param \Psr\Http\Message\ServerRequestInterface $request The request.
     * @return \Psr\Http\Message\ResponseInterface A response.
     */
    public function handle_exception(Throwable $exception, Server_Request_Interface $request): Response_Interface
    {
        $this->load_routes();
        $trap = $this->get_exception_trap();
        $trap->log_exception($exception, $request);
        $event = $this->dispatch_event('Exception.beforeRender', ['exception' => $exception, 'request' => $request], $trap);
        $response = $event->get_result();
        if ($response === null) {
            $renderer = $trap->renderer($event->get_data('exception'), $request);
        }
        try {
            $response ??= $renderer->render();
            if (is_string($response)) {
                return new Response(['body' => $response, 'status' => 500]);
            }
            return $response;
        } catch (Throwable $internal_exception) {
            $trap->log_exception($internal_exception, $request);
            return $this->handle_internal_error();
        }
    }
    /**
     * Convert a redirect exception into a response.
     *
     * @param \Cake\Http\Exception\RedirectException $exception The exception to handle
     * @return \Psr\Http\Message\ResponseInterface Response created from the redirect.
     */
    public function handle_redirect(Redirect_Exception $exception): Response_Interface
    {
        return new Redirect_Response($exception->get_message(), $exception->get_code(), $exception->get_headers());
    }
    /**
     * Handle internal errors.
     *
     * @return \Psr\Http\Message\ResponseInterface A response
     */
    protected function handle_internal_error(): Response_Interface
    {
        return new Response(['body' => 'An Internal Server Error Occurred', 'status' => 500]);
    }
    /**
     * Get a exception trap instance
     *
     * @return \Cake\Error\ExceptionTrap The exception trap.
     */
    protected function get_exception_trap(): Exception_Trap
    {
        if ($this->exception_trap === null) {
            /** @var class-string<\Cake\Error\ExceptionTrap> $className */
            $class_name = App::class_name('ExceptionTrap', 'Error');
            $this->exception_trap = new $class_name($this->get_config());
        }
        return $this->exception_trap;
    }
    /**
     * Ensure that the application's routes are loaded.
     */
    protected function load_routes(): void
    {
        if (!$this->app instanceof Routing_Application_Interface || Router::routes()) {
            return;
        }
        try {
            $builder = Router::create_route_builder('/');
            $this->app->routes($builder);
            if ($this->app instanceof Plugin_Application_Interface) {
                $this->app->plugin_routes($builder);
            }
        } catch (Throwable $e) {
            trigger_warning(sprintf("Exception loading routes when rendering an error page: \n %s - %s", $e::class, $e->get_message()));
        }
    }
}