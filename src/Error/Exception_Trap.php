<?php

declare (strict_types=1);
namespace Cake\Error;

use function Cake\Core\env;
use Cake\Core\Instance_Config_Trait;
use Cake\Error\Renderer\Console_Exception_Renderer;
use Cake\Error\Renderer\Web_Exception_Renderer;
use Cake\Event\Event_Dispatcher_Trait;
use Cake\Routing\Router;
use InvalidArgumentException;
use Psr\Http\Message\Server_Request_Interface;
use Throwable;
/**
 * Entry point to CakePHP's exception handling.
 *
 * Using the `register()` method you can attach an ExceptionTrap to PHP's default exception handler and register
 * a shutdown handler to handle fatal errors.
 *
 * When exceptions are trapped the `Exception.beforeRender` event is triggered.
 * Then exceptions are logged (if enabled) and finally 'rendered' using the defined renderer.
 *
 * Stopping the `Exception.beforeRender` event has no effect, as we always need to render
 * a response to an exception and custom renderers should be used if you want to replace or
 * skip rendering an exception.
 *
 * If undefined, an ExceptionRenderer will be selected based on the current SAPI (CLI or Web).
 */
class Exception_Trap
{
    /**
     * @use \Cake\Event\EventDispatcherTrait<\Cake\Error\ExceptionTrap>
     */
    use Event_Dispatcher_Trait;
    use Instance_Config_Trait;
    /**
     * Configuration options. Generally these will be defined in your config/app.php
     *
     * - `exceptionRenderer` - string - The class responsible for rendering uncaught exceptions.
     *   The chosen class will be used for both CLI and web environments. If you want different
     *   classes used in CLI and web environments you'll need to write that conditional logic as well.
     *   The conventional location for custom renderers is in `src/Error`. Your exception renderer needs to
     *   implement the `render()` method and return either a string or Http\Response.
     * - `log` Set to false to disable logging.
     * - `logger` - string - The class name of the error logger to use.
     * - `trace` - boolean - Whether backtraces should be included in
     *   logged exceptions.
     * - `skipLog` - array - List of exceptions to skip for logging. Exceptions that
     *   extend one of the listed exceptions will also not be logged. E.g.:
     *   ```
     *   'skipLog' => ['Cake\Http\Exception\NotFoundException', 'Cake\Http\Exception\UnauthorizedException']
     *   ```
     *   This option is forwarded to the configured `logger`
     * - `extraFatalErrorMemory` - int - The number of megabytes to increase the memory limit by when a fatal error is
     *   encountered. This allows breathing room to complete logging or error handling.
     * - `stderr` Used in console environments so that renderers have access to the current console output stream.
     *
     * @var array<string, mixed>
     */
    protected array $_default_config = ['exceptionRenderer' => null, 'logger' => Error_Logger::class, 'stderr' => null, 'log' => true, 'skipLog' => [], 'trace' => false, 'extraFatalErrorMemory' => 4];
    /**
     * A list of handling callbacks.
     *
     * Callbacks are invoked for each error that is handled.
     * Callbacks are invoked in the order they are attached.
     *
     * @var array<\Closure>
     */
    protected array $callbacks = [];
    /**
     * The currently registered global exception handler
     *
     * This is best effort as we can't know if/when another
     * exception handler is registered.
     */
    protected static ?Exception_Trap $registered_trap = null;
    /**
     * Track if this trap was removed from the global handler.
     */
    protected bool $disabled = false;
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
     * Get an instance of the renderer.
     *
     * @param \Throwable $exception Exception to render
     * @param \Psr\Http\Message\ServerRequestInterface|null $request The request if possible.
     */
    public function renderer(Throwable $exception, ?Server_Request_Interface $request = null): Exception_Renderer_Interface
    {
        $request ??= Router::get_request();
        /** @var callable|class-string $class */
        $class = $this->get_config('exceptionRenderer') ?: $this->choose_renderer();
        if (is_string($class)) {
            if (!is_subclass_of($class, Exception_Renderer_Interface::class)) {
                throw new InvalidArgumentException("Cannot use `{$class}` as an `exceptionRenderer`. " . 'It must be an instance of `Cake\Error\ExceptionRendererInterface`.');
            }
            /** @var class-string<\Cake\Error\ExceptionRendererInterface> $class */
            return new $class($exception, $request, $this->_config);
        }
        return $class($exception, $request);
    }
    /**
     * Choose an exception renderer based on config or the SAPI
     *
     * @return class-string<\Cake\Error\ExceptionRendererInterface>
     */
    protected function choose_renderer(): string
    {
        return PHP_SAPI === 'cli' ? Console_Exception_Renderer::class : Web_Exception_Renderer::class;
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
    /**
     * Attach this ExceptionTrap to PHP's default exception handler.
     *
     * This will replace the existing exception handler, and the
     * previous exception handler will be discarded.
     */
    public function register(): void
    {
        set_exception_handler($this->handle_exception(...));
        register_shutdown_function($this->handle_shutdown(...));
        static::$registered_trap = $this;
        ini_set('assert.exception', '1');
    }
    /**
     * Remove this instance from the singleton
     *
     * If this instance is not currently the registered singleton
     * nothing happens.
     */
    public function unregister(): void
    {
        if (static::$registered_trap === $this) {
            $this->disabled = true;
            static::$registered_trap = null;
            restore_exception_handler();
        }
    }
    /**
     * Get the registered global instance if set.
     *
     * Keep in mind that the global state contained here
     * is mutable and the object returned by this method
     * could be a stale value.
     *
     * @return \Cake\Error\ExceptionTrap|null The global instance or null.
     */
    public static function instance(): ?self
    {
        return static::$registered_trap;
    }
    /**
     * Handle uncaught exceptions.
     *
     * Uses a template method provided by subclasses to display errors in an
     * environment appropriate way.
     *
     * @param \Throwable $exception Exception instance.
     * @throws \Exception When renderer class not found
     * @see https://secure.php.net/manual/en/function.set-exception-handler.php
     */
    public function handle_exception(Throwable $exception): void
    {
        if ($this->disabled) {
            return;
        }
        $request = Router::get_request();
        $this->log_exception($exception, $request);
        try {
            $event = $this->dispatch_event('Exception.beforeRender', ['exception' => $exception, 'request' => $request]);
            if ($event->is_stopped()) {
                return;
            }
            $exception = $event->get_data('exception');
            assert($exception instanceof Throwable);
            $renderer = $this->renderer($exception, $request);
            $renderer->write($event->get_result() ?: $renderer->render());
        } catch (Throwable $exception) {
            $this->log_internal_error($exception);
        }
        // Use this constant as a proxy for cakephp tests.
        if (PHP_SAPI === 'cli' && !env('FIXTURE_SCHEMA_METADATA')) {
            exit(1);
        }
    }
    /**
     * Shutdown handler
     *
     * Convert fatal errors into exceptions that we can render.
     */
    public function handle_shutdown(): void
    {
        if ($this->disabled) {
            return;
        }
        $megabytes = $this->_config['extraFatalErrorMemory'] ?? 4;
        if ($megabytes > 0) {
            $this->increase_memory_limit($megabytes * 1024);
        }
        $error = error_get_last();
        if (!is_array($error)) {
            return;
        }
        $fatals = [E_USER_ERROR, E_ERROR, E_PARSE, E_COMPILE_ERROR];
        if (!in_array($error['type'], $fatals, true)) {
            return;
        }
        $this->handle_fatal_error($error['type'], $error['message'], $error['file'], $error['line']);
    }
    /**
     * Increases the PHP "memory_limit" ini setting by the specified amount
     * in kilobytes
     *
     * @param int $additionalKb Number in kilobytes
     */
    public function increase_memory_limit(int $additional_kb): void
    {
        $limit = ini_get('memory_limit');
        if (in_array($limit, [false, '', '-1'], true)) {
            return;
        }
        $limit = trim($limit);
        $units = strtoupper(substr($limit, -1));
        $current = (int) substr($limit, 0, -1);
        if ($units === 'M') {
            $current *= 1024;
            $units = 'K';
        }
        if ($units === 'G') {
            $current = $current * 1024 * 1024;
            $units = 'K';
        }
        if ($units === 'K') {
            ini_set('memory_limit', ceil($current + $additional_kb) . 'K');
        }
    }
    /**
     * Display/Log a fatal error.
     *
     * @param int $code Code of error
     * @param string $description Error description
     * @param string $file File on which error occurred
     * @param int $line Line that triggered the error
     */
    public function handle_fatal_error(int $code, string $description, string $file, int $line): void
    {
        $this->handle_exception(new Fatal_Error_Exception('Fatal Error: ' . $description, 500, $file, $line));
    }
    /**
     * Log an exception.
     *
     * Primarily a public function to ensure consistency between global exception handling
     * and the ErrorHandlerMiddleware. This method will apply the `skipLog` filter
     * skipping logging if the exception should not be logged.
     *
     * After logging is attempted the `Exception.beforeRender` event is triggered.
     *
     * @param \Throwable $exception The exception to log
     * @param \Psr\Http\Message\ServerRequestInterface|null $request The optional request
     */
    public function log_exception(Throwable $exception, ?Server_Request_Interface $request = null): void
    {
        $should_log = $this->_config['log'];
        if ($should_log) {
            foreach ($this->get_config('skipLog') as $class) {
                if ($exception instanceof $class) {
                    $should_log = false;
                    break;
                }
            }
        }
        if ($should_log) {
            $this->logger()->log_exception($exception, $request, $this->_config['trace']);
        }
    }
    /**
     * Trigger an error that occurred during rendering an exception.
     *
     * By triggering an E_USER_WARNING we can end up in the default
     * exception handling which will log the rendering failure,
     * and hopefully render an error page.
     *
     * @param \Throwable $exception Exception to log
     */
    public function log_internal_error(Throwable $exception): void
    {
        $message = sprintf(
            '[%s] %s (%s:%s)',
            // Keeping same message format
            $exception::class,
            $exception->get_message(),
            $exception->get_file(),
            $exception->get_line()
        );
        trigger_error($message, E_USER_WARNING);
    }
}