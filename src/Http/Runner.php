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

use Cake\Routing\Router;
use Cake\Routing\Routing_Application_Interface;
use Psr\Http\Message\Response_Interface;
use Psr\Http\Message\Server_Request_Interface;
use Psr\Http\Server\Request_Handler_Interface;
/**
 * Executes the middleware queue and provides the `next` callable
 * that allows the queue to be iterated.
 */
class Runner implements Request_Handler_Interface
{
    /**
     * The middleware queue being run.
     */
    protected Middleware_Queue $queue;
    /**
     * Fallback handler to use if middleware queue does not generate response.
     */
    protected ?Request_Handler_Interface $fallback_handler = null;
    /**
     * @param \Cake\Http\MiddlewareQueue $queue The middleware queue
     * @param \Psr\Http\Message\ServerRequestInterface $request The Server Request
     * @param \Psr\Http\Server\RequestHandlerInterface|null $fallbackHandler Fallback request handler.
     * @return \Psr\Http\Message\ResponseInterface A response object
     */
    public function run(Middleware_Queue $queue, Server_Request_Interface $request, ?Request_Handler_Interface $fallback_handler = null): Response_Interface
    {
        $this->queue = $queue;
        $this->queue->rewind();
        $this->fallback_handler = $fallback_handler;
        return $this->handle($request);
    }
    /**
     * Handle incoming server request and return a response.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request The server request
     * @return \Psr\Http\Message\ResponseInterface An updated response
     */
    public function handle(Server_Request_Interface $request): Response_Interface
    {
        if ($this->fallback_handler instanceof Routing_Application_Interface && $request instanceof Server_Request) {
            Router::set_request($request);
        }
        if ($this->queue->valid()) {
            $middleware = $this->queue->current();
            $this->queue->next();
            return $middleware->process($request, $this);
        }
        if ($this->fallback_handler) {
            return $this->fallback_handler->handle($request);
        }
        return new Response(['body' => 'Middleware queue was exhausted without returning a response ' . 'and no fallback request handler was set for Runner', 'status' => 500]);
    }
}