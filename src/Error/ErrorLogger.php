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
namespace Cake\Error;

use Cake\Core\Configure;
use Cake\Core\Exception\Cake_Exception;
use Cake\Core\Instance_Config_Trait;
use Cake\Http\Server_Request;
use Cake\Log\Log;
use Psr\Http\Message\Server_Request_Interface;
use Psr\Log\Logger_Trait;
use Stringable;
use Throwable;
/**
 * Log errors and unhandled exceptions to `Cake\Log\Log`
 */
class Error_Logger implements Error_Logger_Interface
{
    use Instance_Config_Trait;
    use Logger_Trait;
    /**
     * Default configuration values.
     *
     * - `trace` Should error logs include stack traces?
     *
     * @var array<string, mixed>
     */
    protected array $_default_config = ['trace' => false];
    /**
     * Constructor
     *
     * @param array<string, mixed> $config Config array.
     */
    public function __construct(array $config = [])
    {
        $this->set_config($config);
    }
    /**
     * @inheritDoc
     */
    public function log($level, Stringable|string $message, array $context = []): void
    {
        Log::write($level, $message, $context);
    }
    /**
     * @inheritDoc
     */
    public function log_error(Php_Error $error, ?Server_Request_Interface $request = null, bool $include_trace = false): void
    {
        $message = $this->get_error_message($error, $include_trace);
        if ($request instanceof Server_Request_Interface) {
            $message .= $this->get_request_context($request);
        }
        $label = $error->get_label();
        $level = match ($label) {
            'strict' => LOG_NOTICE,
            'deprecated' => LOG_DEBUG,
            default => $label,
        };
        $this->log($level, $message);
    }
    /**
     * Generate the message for the error
     *
     * @param \Cake\Error\PhpError $error The exception to log a message for.
     * @param bool $includeTrace Whether to include a stack trace.
     * @return string Error message
     */
    protected function get_error_message(Php_Error $error, bool $include_trace = false): string
    {
        $message = sprintf('%s in %s on line %s', $error->get_message(), $error->get_file(), $error->get_line());
        if (!$include_trace) {
            return $message;
        }
        return $message . ("\nTrace:\n" . $error->get_trace_as_string() . "\n");
    }
    /**
     * @inheritDoc
     */
    public function log_exception(Throwable $exception, ?Server_Request_Interface $request = null, bool $include_trace = false): void
    {
        $message = $this->get_message($exception, false, $include_trace);
        if ($request !== null) {
            $message .= $this->get_request_context($request);
        }
        $this->error($message);
    }
    /**
     * Generate the message for the exception
     *
     * @param \Throwable $exception The exception to log a message for.
     * @param bool $isPrevious False for original exception, true for previous
     * @param bool $includeTrace Whether to include a stack trace.
     * @return string Error message
     */
    protected function get_message(Throwable $exception, bool $is_previous = false, bool $include_trace = false): string
    {
        $message = sprintf('%s[%s] %s in %s on line %s', $is_previous ? "\nCaused by: " : '', $exception::class, $exception->get_message(), $exception->get_file(), $exception->get_line());
        $debug = Configure::read('debug');
        if ($debug && $exception instanceof Cake_Exception) {
            $attributes = $exception->get_attributes();
            if ($attributes) {
                $message .= "\nException Attributes: " . var_export($exception->get_attributes(), true);
            }
        }
        if ($include_trace) {
            $trace = Debugger::format_trace($exception, ['format' => Configure::read('Error.traceFormat', 'shortPoints')]);
            assert(is_array($trace));
            $message .= "\nStack Trace:\n";
            foreach ($trace as $line) {
                if (is_string($line)) {
                    $message .= '- ' . $line;
                } else {
                    $message .= "- {$line['file']}:{$line['line']}\n";
                }
            }
        }
        $previous = $exception->get_previous();
        if ($previous) {
            $message .= $this->get_message($previous, true, $include_trace);
        }
        return $message;
    }
    /**
     * Get the request context for an error/exception trace.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request The request to read from.
     */
    public function get_request_context(Server_Request_Interface $request): string
    {
        $message = "\nRequest URL: " . $request->get_request_target();
        $referer = $request->get_header_line('Referer');
        if ($referer) {
            $message .= "\nReferer URL: " . $referer;
        }
        if ($request instanceof Server_Request) {
            $client_ip = $request->client_ip();
            if ($client_ip && $client_ip !== '::1') {
                $message .= "\nClient IP: " . $client_ip;
            }
        }
        return $message;
    }
}