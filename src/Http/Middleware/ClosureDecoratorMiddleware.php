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
 * @since         4.0.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Http\Middleware;

use Closure;
use Psr\Http\Message\Response_Interface;
use Psr\Http\Message\Server_Request_Interface;
use Psr\Http\Server\Middleware_Interface;
use Psr\Http\Server\Request_Handler_Interface;
/**
 * Decorate closures as PSR-15 middleware.
 *
 * Decorates closures with the following signature:
 *
 * ```
 * function (
 *     ServerRequestInterface $request,
 *     RequestHandlerInterface $handler
 * ): ResponseInterface
 * ```
 *
 * such that it will operate as PSR-15 middleware.
 */
class Closure_Decorator_Middleware implements Middleware_Interface
{
    /**
     * Constructor
     *
     * @param \Closure $callable A closure.
     */
    public function __construct(
        /**
         * A Closure.
         */
        protected Closure $callable
    )
    {
    }
    /**
     * Run the callable to process an incoming server request.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request Request instance.
     * @param \Psr\Http\Server\RequestHandlerInterface $handler Request handler instance.
     */
    public function process(Server_Request_Interface $request, Request_Handler_Interface $handler): Response_Interface
    {
        return ($this->callable)($request, $handler);
    }
    /**
     * @internal
     */
    public function get_callable(): Closure
    {
        return $this->callable;
    }
}