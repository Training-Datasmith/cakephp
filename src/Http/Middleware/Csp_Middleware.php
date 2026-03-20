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
 * @license       https://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Http\Middleware;

use Cake\Core\Exception\Cake_Exception;
use Cake\Core\Instance_Config_Trait;
use Paragon_Ie\Csp_Builder\Csp_Builder;
use Psr\Http\Message\Response_Interface;
use Psr\Http\Message\Server_Request_Interface;
use Psr\Http\Server\Middleware_Interface;
use Psr\Http\Server\Request_Handler_Interface;
/**
 * Content Security Policy Middleware
 *
 * ### Options
 *
 * - `scriptNonce` Enable to have a nonce policy added to the script-src directive.
 * - `styleNonce` Enable to have a nonce policy added to the style-src directive.
 */
class Csp_Middleware implements Middleware_Interface
{
    use Instance_Config_Trait;
    /**
     * CSP Builder
     *
     * @var \ParagonIE\CSPBuilder\CSPBuilder $csp CSP Builder or config array
     */
    protected Csp_Builder $csp;
    /**
     * Configuration options.
     *
     * @var array<string, mixed>
     */
    protected array $_default_config = ['scriptNonce' => false, 'styleNonce' => false];
    /**
     * Constructor
     *
     * @param \ParagonIE\CSPBuilder\CSPBuilder|array $csp CSP object or config array
     * @param array<string, mixed> $config Configuration options.
     */
    public function __construct(Csp_Builder|array $csp, array $config = [])
    {
        if (!class_exists(Csp_Builder::class)) {
            throw new Cake_Exception('You must install paragonie/csp-builder to use CspMiddleware');
        }
        $this->set_config($config);
        if (!$csp instanceof Csp_Builder) {
            $csp = new Csp_Builder($csp);
        }
        $this->csp = $csp;
    }
    /**
     * Add nonces (if enabled) to the request and apply the CSP header to the response.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request The request.
     * @param \Psr\Http\Server\RequestHandlerInterface $handler The request handler.
     * @return \Psr\Http\Message\ResponseInterface A response.
     */
    public function process(Server_Request_Interface $request, Request_Handler_Interface $handler): Response_Interface
    {
        if ($this->get_config('scriptNonce')) {
            $request = $request->with_attribute('cspScriptNonce', $this->csp->nonce('script-src'));
        }
        if ($this->get_config('styleNonce')) {
            $request = $request->with_attribute('cspStyleNonce', $this->csp->nonce('style-src'));
        }
        $response = $handler->handle($request);
        /** @var \Psr\Http\Message\ResponseInterface */
        return $this->csp->inject_csp_header($response);
    }
}