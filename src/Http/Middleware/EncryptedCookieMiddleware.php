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
 * @since         3.5.0
 * @license       https://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Http\Middleware;

use Cake\Http\Cookie\Cookie_Collection;
use Cake\Http\Response;
use Cake\Utility\Cookie_Crypt_Trait;
use Psr\Http\Message\Response_Interface;
use Psr\Http\Message\Server_Request_Interface;
use Psr\Http\Server\Middleware_Interface;
use Psr\Http\Server\Request_Handler_Interface;
/**
 * Middleware for encrypting & decrypting cookies.
 *
 * This middleware layer will encrypt/decrypt the named cookies with the given key
 * and cipher type. To support multiple keys/cipher types use this middleware multiple
 * times.
 *
 * Cookies in request data will be decrypted, while cookies in response headers will
 * be encrypted automatically. If the response is a {@link \Cake\Http\Response}, the cookie
 * data set with `withCookie()` and `cookie()` will also be encrypted.
 *
 * The encryption types and padding are compatible with those used by CookieComponent
 * for backwards compatibility.
 */
class Encrypted_Cookie_Middleware implements Middleware_Interface
{
    use Cookie_Crypt_Trait;
    /**
     * Constructor
     *
     * @param array<string> $cookieNames The list of cookie names that should have their values encrypted.
     * @param string $key The encryption key to use.
     * @param string $cipherType The cipher type to use. Defaults to 'aes'.
     */
    public function __construct(
        /**
         * The list of cookies to encrypt/decrypt
         */
        protected array $cookie_names,
        /**
         * Encryption key to use.
         */
        protected string $key,
        /**
         * Encryption type.
         */
        protected string $cipher_type = 'aes'
    )
    {
    }
    /**
     * Apply cookie encryption/decryption.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request The request.
     * @param \Psr\Http\Server\RequestHandlerInterface $handler The request handler.
     * @return \Psr\Http\Message\ResponseInterface A response.
     */
    public function process(Server_Request_Interface $request, Request_Handler_Interface $handler): Response_Interface
    {
        if ($request->get_cookie_params()) {
            $request = $this->decode_cookies($request);
        }
        $response = $handler->handle($request);
        if ($response->has_header('Set-Cookie')) {
            $response = $this->encode_set_cookie_header($response);
        }
        if ($response instanceof Response) {
            return $this->encode_cookies($response);
        }
        return $response;
    }
    /**
     * Fetch the cookie encryption key.
     *
     * Part of the CookieCryptTrait implementation.
     */
    protected function _get_cookie_encryption_key(): string
    {
        return $this->key;
    }
    /**
     * Decode cookies from the request.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request The request to decode cookies from.
     * @return \Psr\Http\Message\ServerRequestInterface Updated request with decoded cookies.
     */
    protected function decode_cookies(Server_Request_Interface $request): Server_Request_Interface
    {
        $cookies = $request->get_cookie_params();
        foreach ($this->cookie_names as $name) {
            if (isset($cookies[$name])) {
                $cookies[$name] = $this->_decrypt($cookies[$name], $this->cipher_type, $this->key);
            }
        }
        return $request->with_cookie_params($cookies);
    }
    /**
     * Encode cookies from a response's CookieCollection.
     *
     * @param \Cake\Http\Response $response The response to encode cookies in.
     * @return \Cake\Http\Response Updated response with encoded cookies.
     */
    protected function encode_cookies(Response $response): Response
    {
        foreach ($response->get_cookie_collection() as $cookie) {
            if (in_array($cookie->get_name(), $this->cookie_names, true)) {
                $value = $this->_encrypt($cookie->get_value(), $this->cipher_type);
                $response = $response->with_cookie($cookie->with_value($value));
            }
        }
        return $response;
    }
    /**
     * Encode cookies from a response's Set-Cookie header
     *
     * @param \Psr\Http\Message\ResponseInterface $response The response to encode cookies in.
     * @return \Psr\Http\Message\ResponseInterface Updated response with encoded cookies.
     */
    protected function encode_set_cookie_header(Response_Interface $response): Response_Interface
    {
        $cookies = Cookie_Collection::create_from_header($response->get_header('Set-Cookie'));
        $header = [];
        foreach ($cookies as $cookie) {
            if (in_array($cookie->get_name(), $this->cookie_names, true)) {
                $value = $this->_encrypt($cookie->get_value(), $this->cipher_type);
                $cookie = $cookie->with_value($value);
            }
            $header[] = $cookie->to_header_value();
        }
        return $response->with_header('Set-Cookie', $header);
    }
}