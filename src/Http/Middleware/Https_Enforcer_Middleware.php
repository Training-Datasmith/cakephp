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

use Cake\Core\Configure;
use Cake\Http\Exception\Bad_Request_Exception;
use Cake\Http\Server_Request;
use Laminas\Diactoros\Response\Redirect_Response;
use Psr\Http\Message\Response_Interface;
use Psr\Http\Message\Server_Request_Interface;
use Psr\Http\Server\Middleware_Interface;
use Psr\Http\Server\Request_Handler_Interface;
use UnexpectedValueException;
/**
 * Enforces use of HTTPS (SSL) for requests.
 */
class Https_Enforcer_Middleware implements Middleware_Interface
{
    /**
     * Configuration.
     *
     * ### Options
     *
     * - `redirect` - If set to true (default) redirects GET requests to same URL with https.
     * - `statusCode` - Status code to use in case of redirect, defaults to 301 - Permanent redirect.
     * - `headers` - Array of response headers in case of redirect.
     * - `disableOnDebug` - Whether HTTPS check should be disabled when debug is on. Default `true`.
     * - `trustedProxies` - Array of trusted proxies that will be passed to the request. Defaults to `null`.
     * - 'hsts' - Strict-Transport-Security header for HTTPS response configuration. Defaults to `null`.
     *    If enabled, an array of config options:
     *
     *        - 'maxAge' - `max-age` directive value in seconds.
     *        - 'includeSubDomains' - Whether to include `includeSubDomains` directive. Defaults to `false`.
     *        - 'preload' - Whether to include 'preload' directive. Defaults to `false`.
     *
     * @var array<string, mixed>
     */
    protected array $config = ['redirect' => true, 'statusCode' => 301, 'headers' => [], 'disableOnDebug' => true, 'trustedProxies' => null, 'hsts' => null];
    /**
     * Constructor
     *
     * @param array<string, mixed> $config The options to use.
     * @see \Cake\Http\Middleware\HttpsEnforcerMiddleware::$config
     */
    public function __construct(array $config = [])
    {
        $this->config = $config + $this->config;
    }
    /**
     * Check whether request has been made using HTTPS.
     *
     * Depending on the configuration and request method, either redirects to
     * same URL with https or throws an exception.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request The request.
     * @param \Psr\Http\Server\RequestHandlerInterface $handler The request handler.
     * @return \Psr\Http\Message\ResponseInterface A response.
     * @throws \Cake\Http\Exception\BadRequestException
     */
    public function process(Server_Request_Interface $request, Request_Handler_Interface $handler): Response_Interface
    {
        if ($request instanceof Server_Request && is_array($this->config['trustedProxies'])) {
            $request->set_trusted_proxies($this->config['trustedProxies']);
        }
        if ($request->get_uri()->get_scheme() === 'https' || $this->config['disableOnDebug'] && Configure::read('debug')) {
            $response = $handler->handle($request);
            if ($this->config['hsts']) {
                return $this->add_hsts($response);
            }
            return $response;
        }
        if ($this->config['redirect'] && $request->get_method() === 'GET') {
            $uri = $request->get_uri()->with_scheme('https');
            $base = $request->get_attribute('base');
            if ($base) {
                $uri = $uri->with_path($base . $uri->get_path());
            }
            return new Redirect_Response($uri, $this->config['statusCode'], $this->config['headers']);
        }
        throw new Bad_Request_Exception('Requests to this URL must be made with HTTPS.');
    }
    /**
     * Adds Strict-Transport-Security header to response.
     *
     * @param \Psr\Http\Message\ResponseInterface $response Response
     */
    protected function add_hsts(Response_Interface $response): Response_Interface
    {
        $config = $this->config['hsts'];
        if (!is_array($config)) {
            throw new UnexpectedValueException('The `hsts` config must be an array.');
        }
        $value = 'max-age=' . $config['maxAge'];
        if ($config['includeSubDomains'] ?? false) {
            $value .= '; includeSubDomains';
        }
        if ($config['preload'] ?? false) {
            $value .= '; preload';
        }
        return $response->with_header('strict-transport-security', $value);
    }
}