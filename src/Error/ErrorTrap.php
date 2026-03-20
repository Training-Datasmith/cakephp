<?php

declare (strict_types=1);
namespace Cake\Error;

use Cake\Core\Configure;
use Cake\Core\Instance_Config_Trait;
use Cake\Error\Renderer\Console_Error_Renderer;
use Cake\Error\Renderer\Html_Error_Renderer;
use Cake\Event\Event_Dispatcher_Trait;
use Cake\Routing\Router;
use Exception;
/**
 * Entry point to CakePHP's error handling.
 *
 * Using the `register()` method you can attach an ErrorTrap to PHP's default error handler.
 *
 * When errors are trapped, errors are logged (if logging is enabled). Then the `Error.beforeRender` event is triggered.
 * Finally, errors are 'rendered' using the defined renderer. If no error renderer is defined in configuration
 * one of the default implementations will be chosen based on the PHP SAPI.
 */
class Error_Trap
{
    /**
     * @use \Cake\Event\EventDispatcherTrait<\Cake\Error\ErrorTrap>
     */
    use Event_Dispatcher_Trait;
    use Instance_Config_Trait;
    /**
     * Configuration options. Generally these are defined in config/app.php
     *
     * - `errorLevel` - int - The level of errors you are interested in capturing.
     * - `errorRenderer` - string - The class name of render errors with. Defaults
     *   to choosing between Html and Console based on the SAPI.
     * - `log` - boolean - Whether you want errors logged.
     * - `logger` - string - The class name of the error logger to use.
     * - `trace` - boolean - Whether backtraces should be included in
     *   logged errors.
     *
     * @var array<string, mixed>
     */
    protected array $_default_config = ['errorLevel' => E_ALL, 'errorRenderer' => null, 'log' => true, 'logger' => Error_Logger::class, 'trace' => false];
    /**
     * Constructor
     *
     * @param array<string, mixed> $options An options array. See $_defaultConfig.
     */
    public function __construct(array $options = [])
    {
        $this->set_config($options);
    }
    /**
     * Choose an error renderer based on config or the SAPI
     *
     * @return class-string<\Cake\Error\ErrorRendererInterface>
     */
    protected function choose_error_renderer(): string
    {
        $config = $this->get_config('errorRenderer');
        if ($config !== null) {
            return $config;
        }
        return PHP_SAPI === 'cli' ? Console_Error_Renderer::class : Html_Error_Renderer::class;
    }
    /**
     * Attach this ErrorTrap to PHP's default error handler.
     *
     * This will replace the existing error handler, and the
     * previous error handler will be discarded.
     *
     * This method will also set the global error level
     * via error_reporting().
     */
    public function register(): void
    {
        $level = $this->_config['errorLevel'] ?? -1;
        error_reporting($level);
        set_error_handler($this->handle_error(...), $level);
    }
    /**
     * Handle an error from PHP set_error_handler
     *
     * Will use the configured renderer to generate output
     * and output it.
     *
     * This method will dispatch the `Error.beforeRender` event which can be listened
     * to on the global event manager.
     *
     * @param int $code Code of error
     * @param string $description Error description
     * @param string|null $file File on which error occurred
     * @param int|null $line Line that triggered the error
     * @return bool True if error was handled
     */
    public function handle_error(int $code, string $description, ?string $file = null, ?int $line = null): bool
    {
        if (!(error_reporting() & $code)) {
            return false;
        }
        if (in_array($code, [E_USER_ERROR, E_ERROR, E_PARSE], true)) {
            throw new Fatal_Error_Exception($description, $code, $file, $line);
        }
        $trace = (array) Debugger::trace(['start' => 0, 'format' => 'points']);
        $error = new Php_Error($code, $description, $file, $line, $trace);
        $ignored_paths = (array) Configure::read('Error.ignoredDeprecationPaths');
        if ($code === E_USER_DEPRECATED && $ignored_paths) {
            $relative_path = str_replace(DIRECTORY_SEPARATOR, '/', substr((string) $file, strlen(ROOT) + 1));
            foreach ($ignored_paths as $pattern) {
                $pattern = str_replace(DIRECTORY_SEPARATOR, '/', $pattern);
                if (fnmatch($pattern, $relative_path)) {
                    return true;
                }
            }
        }
        $debug = Configure::read('debug');
        $renderer = $this->renderer();
        try {
            // Log first in case rendering or event listeners fail
            $this->log_error($error);
            $event = $this->dispatch_event('Error.beforeRender', ['error' => $error]);
            if ($event->is_stopped()) {
                return true;
            }
            $renderer->write($event->get_result() ?: $renderer->render($error, $debug));
        } catch (Exception $e) {
            // Fatal errors always log.
            $this->logger()->log_exception($e);
            return false;
        }
        return true;
    }
    /**
     * Logging helper method.
     *
     * @param \Cake\Error\PhpError $error The error object to log.
     */
    protected function log_error(Php_Error $error): void
    {
        if (!$this->_config['log']) {
            return;
        }
        $this->logger()->log_error($error, Router::get_request(), $this->_config['trace']);
    }
    /**
     * Get an instance of the renderer.
     */
    public function renderer(): Error_Renderer_Interface
    {
        /** @var class-string<\Cake\Error\ErrorRendererInterface> $class */
        $class = $this->get_config('errorRenderer') ?: $this->choose_error_renderer();
        return new $class($this->_config);
    }
    /**
     * Get an instance of the logger.
     */
    public function logger(): Error_Logger_Interface
    {
        /** @var class-string<\Cake\Error\ErrorLoggerInterface> $class */
        $class = $this->get_config('logger', $this->_default_config['logger']);
        return new $class($this->_config);
    }
}