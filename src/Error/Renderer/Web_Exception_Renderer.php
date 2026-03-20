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
 * @link          https://cakephp.org CakePHP Project
 * @since         4.4.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Error\Renderer;

use Cake\Controller\Controller;
use Cake\Controller\Controller_Factory;
use Cake\Core\App;
use Cake\Core\Configure;
use Cake\Core\Container;
use function Cake\Core\Deprecation_Warning;
use Cake\Core\Exception\Cake_Exception;
use Cake\Core\Exception\Http_Error_Code_Interface;
use Cake\Core\Exception\Missing_Plugin_Exception;
use function Cake\Core\h;
use function Cake\Core\Namespace_Split;
use Cake\Error\Debugger;
use Cake\Error\Exception_Renderer_Interface;
use Cake\Http\Exception\Http_Exception;
use Cake\Http\Response;
use Cake\Http\Response_Emitter;
use Cake\Http\Server_Request;
use Cake\Http\Server_Request_Factory;
use function Cake\I18n\__d;
use Cake\Log\Log;
use Cake\Routing\Router;
use Cake\Utility\Inflector;
use Cake\View\Exception\Missing_Layout_Exception;
use Cake\View\Exception\Missing_Template_Exception;
use PDOException;
use Psr\Http\Message\Response_Interface;
use ReflectionMethod;
use Throwable;
/**
 * Web Exception Renderer.
 *
 * Captures and handles all unhandled exceptions. Displays helpful framework errors when debug is true.
 * When debug is false, WebExceptionRenderer will render 404 or 500 errors. If an uncaught exception is thrown
 * and it is a type that WebExceptionHandler does not know about it will be treated as a 500 error.
 *
 * ### Implementing application specific exception rendering
 *
 * You can implement application specific exception handling by creating a subclass of
 * WebExceptionRenderer and configure it to be the `exceptionRenderer` in config/error.php
 *
 * #### Using a subclass of WebExceptionRenderer
 *
 * Using a subclass of WebExceptionRenderer gives you full control over how Exceptions are rendered, you
 * can configure your class in your config/app.php.
 */
class Web_Exception_Renderer implements Exception_Renderer_Interface
{
    /**
     * Controller instance.
     */
    protected Controller $controller;
    /**
     * Template to render for {@link \Cake\Core\Exception\CakeException}
     */
    protected string $template = '';
    /**
     * The method corresponding to the Exception this object is for.
     */
    protected string $method = '';
    /**
     * Map of exceptions to http status codes.
     *
     * This can be customized for users that don't want specific exceptions to throw 404 errors
     * or want their application exceptions to be automatically converted.
     *
     * @var array<class-string<\Throwable>, int>
     * @deprecated 5.2.0 Exceptions returning HTTP error codes should extend
     *   HttpErrorCodeInterface instead of using this array.
     */
    protected array $exception_http_codes = [];
    /**
     * Creates the controller to perform rendering on the error response.
     *
     * @param \Throwable $error Exception.
     * @param \Cake\Http\ServerRequest|null $request The request if this is set it will be used
     *   instead of creating a new one.
     */
    public function __construct(
        /**
         * The exception being handled.
         */
        protected Throwable $error,
        /**
         * If set, this will be request used to create the controller that will render
         * the error.
         */
        protected ?Server_Request $request = null
    )
    {
        $this->controller = $this->_get_controller();
    }
    /**
     * Get the controller instance to handle the exception.
     * Override this method in subclasses to customize the controller used.
     * This method returns the built in `ErrorController` normally, or if an error is repeated
     * a bare controller will be used.
     *
     * @triggers Controller.startup $controller
     */
    protected function _get_controller(): Controller
    {
        $request = $this->request;
        $router_request = Router::get_request();
        // Fallback to the request in the router or make a new one from
        // $_SERVER
        $request ??= $router_request ?: Server_Request_Factory::from_globals();
        // If the current request doesn't have routing data, but we
        // found a request in the router context copy the params over
        if ($request->get_param('controller') === null && $router_request !== null) {
            $request = $request->with_attribute('params', $router_request->get_attribute('params'));
        }
        $class = '';
        try {
            /** @var array $params */
            $params = $request->get_attribute('params');
            $params['controller'] = 'Error';
            $factory = new Controller_Factory(new Container());
            // Check including plugin + prefix
            $class = $factory->get_controller_class($request->with_attribute('params', $params));
            if (!$class && !empty($params['prefix']) && !empty($params['plugin'])) {
                unset($params['prefix']);
                // Fallback to only plugin
                $class = $factory->get_controller_class($request->with_attribute('params', $params));
            }
            if (!$class) {
                // Fallback to app/core provided controller.
                /** @var string $class */
                $class = App::class_name('Error', 'Controller', '_Controller');
            }
            assert(is_subclass_of($class, Controller::class));
            $controller = new $class($request);
            $controller->startup_process();
        } catch (Throwable $e) {
            Log::warning("Failed to construct or call startup() on the resolved controller class of `{$class}`. " . "Using Fallback Controller instead. Error {$e->get_message()}" . "\nStack Trace\n: {$e->get_trace_as_string()}", 'cake.error');
            $controller = null;
        }
        if ($controller === null) {
            return new Controller($request);
        }
        return $controller;
    }
    /**
     * Clear output buffers so error pages display properly.
     */
    protected function clear_output(): void
    {
        if (in_array(PHP_SAPI, ['cli', 'phpdbg'])) {
            return;
        }
        while (ob_get_level()) {
            ob_end_clean();
        }
    }
    /**
     * Renders the response for the exception.
     *
     * @return \Psr\Http\Message\ResponseInterface The response to be sent.
     */
    public function render(): Response_Interface
    {
        $exception = $this->error;
        $code = $this->get_http_code($exception);
        $method = $this->_method($exception);
        $template = $this->_template($exception, $method, $code);
        $this->clear_output();
        if (method_exists($this, $method)) {
            return $this->_custom_method($method, $exception);
        }
        $message = $this->_message($exception, $code);
        $url = $this->controller->get_request()->get_request_target();
        $response = $this->controller->get_response();
        if ($exception instanceof Http_Exception) {
            foreach ($exception->get_headers() as $name => $value) {
                $response = $response->with_header($name, $value);
            }
        }
        $response = $response->with_status($code);
        $exceptions = [$exception];
        $previous = $exception->get_previous();
        while ($previous !== null) {
            $exceptions[] = $previous;
            $previous = $previous->get_previous();
        }
        $view_vars = ['message' => $message, 'url' => h($url), 'error' => $exception, 'exceptions' => $exceptions, 'code' => $code];
        $serialize = ['message', 'url', 'code'];
        $is_debug = Configure::read('debug');
        if ($is_debug) {
            $trace = (array) Debugger::format_trace($exception->get_trace(), ['format' => 'array', 'args' => true]);
            $origin = ['file' => $exception->get_file() ?: 'null', 'line' => $exception->get_line() ?: 'null'];
            // Traces don't include the origin file/line.
            array_unshift($trace, $origin);
            $view_vars['trace'] = $trace;
            $view_vars += $origin;
            $serialize[] = 'file';
            $serialize[] = 'line';
        }
        $this->controller->set($view_vars);
        $this->controller->view_builder()->set_option('serialize', $serialize);
        if ($exception instanceof Cake_Exception && $is_debug) {
            $this->controller->set($exception->get_attributes());
        }
        $this->controller->set_response($response);
        return $this->_output_message($template);
    }
    /**
     * Emit the response content
     *
     * @param \Psr\Http\Message\ResponseInterface|string $output The response to output.
     */
    public function write(Response_Interface|string $output): void
    {
        if (is_string($output)) {
            echo $output;
            return;
        }
        $emitter = new Response_Emitter();
        $emitter->emit($output);
    }
    /**
     * Render a custom error method/template.
     *
     * @param string $method The method name to invoke.
     * @param \Throwable $exception The exception to render.
     * @return \Cake\Http\Response The response to send.
     */
    protected function _custom_method(string $method, Throwable $exception): Response
    {
        $result = $this->{$method}($exception);
        $this->_shutdown();
        if (is_string($result)) {
            return $this->controller->get_response()->with_string_body($result);
        }
        return $result;
    }
    /**
     * Get method name
     *
     * @param \Throwable $exception Exception instance.
     */
    protected function _method(Throwable $exception): string
    {
        [, $base_class] = namespace_split($exception::class);
        if (str_ends_with($base_class, 'Exception')) {
            $base_class = substr($base_class, 0, -9);
        }
        // $baseClass would be an empty string if the exception class is \Exception.
        $method = $base_class === '' ? 'error500' : Inflector::variable($base_class);
        return $this->method = $method;
    }
    /**
     * Get error message.
     *
     * @param \Throwable $exception Exception.
     * @param int $code Error code.
     * @return string Error message
     */
    protected function _message(Throwable $exception, int $code): string
    {
        $message = $exception->get_message();
        if (!Configure::read('debug') && !$exception instanceof Http_Exception) {
            if ($code < 500) {
                $message = __d('cake', 'Not Found');
            } else {
                $message = __d('cake', 'An Internal Error Has Occurred.');
            }
        }
        return $message;
    }
    /**
     * Get template for rendering exception info.
     *
     * @param \Throwable $exception Exception instance.
     * @param string $method Method name.
     * @param int $code Error code.
     * @return string Template name
     */
    protected function _template(Throwable $exception, string $method, int $code): string
    {
        if ($exception instanceof Http_Exception || !Configure::read('debug')) {
            return $this->template = $code < 500 ? 'error400' : 'error500';
        }
        if ($exception instanceof PDOException) {
            return $this->template = 'pdo_error';
        }
        return $this->template = $method;
    }
    /**
     * Gets the appropriate http status code for exception.
     *
     * @param \Throwable $exception Exception.
     * @return int A valid HTTP status code.
     */
    protected function get_http_code(Throwable $exception): int
    {
        if ($exception instanceof Http_Error_Code_Interface) {
            return $exception->get_code();
        }
        if (isset($this->exception_http_codes[$exception::class])) {
            deprecation_warning('5.2.0', 'Exceptions returning a HTTP error code should implement HttpErrorCodeInterface,' . ' instead of using the WebExceptionRenderer::$exceptionHttpCodes property.');
            return $this->exception_http_codes[$exception::class];
        }
        return 500;
    }
    /**
     * Generate the response using the controller object.
     *
     * @param string $template The template to render.
     * @param bool $skipControllerCheck Skip checking controller for existence of
     *   method matching the exception name.
     * @return \Cake\Http\Response A response object that can be sent.
     */
    protected function _output_message(string $template, bool $skip_controller_check = false): Response
    {
        try {
            $method = $this->method ?: $this->_method($this->error);
            if (!$skip_controller_check && method_exists($this->controller, $method)) {
                $this->controller->view_builder()->set_template($method);
                $reflection_method = new ReflectionMethod($this->controller, $method);
                $result = $reflection_method->invoke($this->controller, $this->error);
                if ($result instanceof Response) {
                    $this->controller->set_response($result);
                } else {
                    $this->controller->render();
                }
            } else {
                $this->controller->render($template);
            }
            return $this->_shutdown();
        } catch (Missing_Template_Exception $e) {
            Log::warning("MissingTemplateException - Failed to render error template `{$template}` . Error: {$e->get_message()}" . "\nStack Trace\n: {$e->get_trace_as_string()}", 'cake.error');
            $attributes = $e->get_attributes();
            if ($e instanceof Missing_Layout_Exception || str_contains($attributes['file'], 'error500')) {
                return $this->_output_message_safe('error500');
            }
            // If we have a prefix/plugin and the template is error400 or error500,
            // try to render from the base Error directory before falling back to error500
            if (($template === 'error400' || $template === 'error500') && ($this->controller->get_request()->get_param('prefix') || $this->controller->get_plugin())) {
                return $this->_output_message_safe($template);
            }
            return $this->_output_message('error500', true);
        } catch (Missing_Plugin_Exception $e) {
            Log::warning("MissingPluginException - Failed to render error template `{$template}`. Error: {$e->get_message()}" . "\nStack Trace\n: {$e->get_trace_as_string()}", 'cake.error');
            $attributes = $e->get_attributes();
            if (isset($attributes['plugin']) && $attributes['plugin'] === $this->controller->get_plugin()) {
                $this->controller->set_plugin(null);
            }
            return $this->_output_message_safe('error500');
        } catch (Throwable $outer) {
            Log::warning("Throwable - Failed to render error template `{$template}`. Error: {$outer->get_message()}" . "\nStack Trace\n: {$outer->get_trace_as_string()}", 'cake.error');
            try {
                return $this->_output_message_safe('error500');
            } catch (Throwable) {
                throw $outer;
            }
        }
    }
    /**
     * A safer way to render error messages, replaces all helpers, with basics
     * and doesn't call component methods.
     *
     * @param string $template The template to render.
     * @return \Cake\Http\Response A response object that can be sent.
     */
    protected function _output_message_safe(string $template): Response
    {
        $builder = $this->controller->view_builder();
        $builder->set_helpers([])->set_layout_path('')->set_template_path('Error');
        $view = $this->controller->create_view('View');
        $response = $this->controller->get_response()->with_type('html')->with_string_body($view->render($template, 'error'));
        $this->controller->set_response($response);
        return $response;
    }
    /**
     * Run the shutdown events.
     *
     * Triggers the afterFilter and afterDispatch events.
     *
     * @return \Cake\Http\Response The response to serve.
     */
    protected function _shutdown(): Response
    {
        $this->controller->dispatch_event('Controller.shutdown');
        return $this->controller->get_response();
    }
    /**
     * Returns an array that can be used to describe the internal state of this
     * object.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['error' => $this->error, 'request' => $this->request, 'controller' => $this->controller, 'template' => $this->template, 'method' => $this->method];
    }
}