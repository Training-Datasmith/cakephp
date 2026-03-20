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
 * @since         4.4.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Error\Renderer;

use Cake\Console\Console_Output;
use Cake\Core\Configure;
use Cake\Core\Exception\Cake_Exception;
use Cake\Error\Debugger;
use Cake\Error\Exception_Renderer_Interface;
use Psr\Http\Message\Response_Interface;
use Psr\Http\Message\Server_Request_Interface;
use Throwable;
/**
 * Plain text exception rendering with a stack trace.
 *
 * Useful in CI or plain text environments.
 */
class Console_Exception_Renderer implements Exception_Renderer_Interface
{
    private readonly Console_Output $output;
    private readonly bool $trace;
    /**
     * Constructor.
     *
     * @param \Throwable $error The error to render.
     * @param \Psr\Http\Message\ServerRequestInterface|null $request Not used.
     * @param array $config Error handling configuration.
     */
    public function __construct(private readonly Throwable $error, ?Server_Request_Interface $request, array $config)
    {
        $this->output = $config['stderr'] ?? new Console_Output('php://stderr');
        $this->trace = $config['trace'] ?? true;
    }
    /**
     * Render an exception into a plain text message.
     */
    public function render(): Response_Interface|string
    {
        $exceptions = [$this->error];
        $previous = $this->error->get_previous();
        while ($previous !== null) {
            $exceptions[] = $previous;
            $previous = $previous->get_previous();
        }
        $out = [];
        foreach ($exceptions as $i => $error) {
            $parent = $i > 0 ? $exceptions[$i - 1] : null;
            $out = array_merge($out, $this->render_exception($error, $parent));
        }
        return implode("\n", $out);
    }
    /**
     * Render an individual exception
     *
     * @param \Throwable $exception The exception to render.
     * @param \Throwable|null $parent The Exception index in the chain
     */
    protected function render_exception(Throwable $exception, ?Throwable $parent): array
    {
        $out = [sprintf('<error>%s[%s] %s</error> in %s on line %s', $parent ? 'Caused by ' : '', $exception::class, $exception->get_message(), $exception->get_file(), $exception->get_line())];
        $debug = Configure::read('debug');
        if ($debug && $exception instanceof Cake_Exception) {
            $attributes = $exception->get_attributes();
            if ($attributes) {
                $out[] = '';
                $out[] = '<info>Exception Attributes</info>';
                $out[] = '';
                $out[] = var_export($exception->get_attributes(), true);
            }
        }
        if ($this->trace) {
            $stacktrace = Debugger::get_unique_frames($exception, $parent);
            $out[] = '';
            $out[] = '<info>Stack Trace:</info>';
            $out[] = '';
            $out[] = Debugger::format_trace($stacktrace, ['format' => 'text']);
            $out[] = '';
        }
        return $out;
    }
    /**
     * Write output to the output stream
     *
     * @param \Psr\Http\Message\ResponseInterface|string $output The output to print.
     */
    public function write(Response_Interface|string $output): void
    {
        if (is_string($output)) {
            $this->output->write($output);
        }
    }
}