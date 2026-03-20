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
 * @since         4.2.0
 * @license       https://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Http\Middleware;

use ArrayAccess;
use Cake\Core\Exception\Cake_Exception;
use Cake\Http\Exception\Invalid_Csrf_Token_Exception;
use Cake\Http\Server_Request;
use Cake\Http\Session;
use function Cake\I18n\__d;
use Cake\Utility\Hash;
use Cake\Utility\Security;
use Psr\Http\Message\Response_Interface;
use Psr\Http\Message\Server_Request_Interface;
use Psr\Http\Server\Middleware_Interface;
use Psr\Http\Server\Request_Handler_Interface;
/**
 * Provides CSRF protection via session based tokens.
 *
 * This middleware adds a CSRF token to the session. Each request must
 * contain a token in request data, or the X-CSRF-Token header on each PATCH, POST,
 * PUT, or DELETE request. This follows a 'synchronizer token' pattern.
 *
 * If the request data is missing or does not match the session data,
 * an InvalidCsrfTokenException will be raised.
 *
 * This middleware integrates with the FormHelper automatically and when
 * used together your forms will have CSRF tokens automatically added
 * when `$this->Form->create(...)` is used in a view.
 *
 * If you use this middleware *do not* also use CsrfProtectionMiddleware.
 *
 * @see https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html#synchronizer-token-pattern
 */
class Session_Csrf_Protection_Middleware implements Middleware_Interface
{
    /**
     * Config for the CSRF handling.
     *
     *  - `key` The session key to use. Defaults to `csrfToken`
     *  - `field` The form field to check. Changing this will also require configuring
     *    FormHelper.
     *
     * @var array<string, mixed>
     */
    protected array $_config = ['key' => 'csrfToken', 'field' => '_csrfToken'];
    /**
     * Callback for deciding whether to skip the token check for particular request.
     *
     * CSRF protection token check will be skipped if the callback returns `true`.
     *
     * @var callable|null
     */
    protected $skip_check_callback;
    /**
     * @var int
     */
    public const TOKEN_VALUE_LENGTH = 32;
    /**
     * Constructor
     *
     * @param array<string, mixed> $config Config options. See $_config for valid keys.
     */
    public function __construct(array $config = [])
    {
        $this->_config = $config + $this->_config;
    }
    /**
     * Checks and sets the CSRF token depending on the HTTP verb.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request The request.
     * @param \Psr\Http\Server\RequestHandlerInterface $handler The request handler.
     * @return \Psr\Http\Message\ResponseInterface A response.
     */
    public function process(Server_Request_Interface $request, Request_Handler_Interface $handler): Response_Interface
    {
        $method = $request->get_method();
        $has_data = in_array($method, ['PUT', 'POST', 'DELETE', 'PATCH'], true) || $request->get_parsed_body();
        if ($has_data && $this->skip_check_callback !== null && call_user_func($this->skip_check_callback, $request) === true) {
            $request = $this->unset_token_field($request);
            return $handler->handle($request);
        }
        $session = $request->get_attribute('session');
        if (!$session instanceof Session) {
            throw new Cake_Exception('You must have a `session` attribute to use session based CSRF tokens');
        }
        $token = $session->read($this->_config['key']);
        if ($token === null) {
            $token = $this->create_token();
            $session->write($this->_config['key'], $token);
        }
        $request = $request->with_attribute('csrfToken', $this->salt_token($token));
        if ($method === 'GET') {
            return $handler->handle($request);
        }
        if ($has_data) {
            $this->validate_token($request, $session);
            $request = $this->unset_token_field($request);
        }
        return $handler->handle($request);
    }
    /**
     * Set callback for allowing to skip token check for particular request.
     *
     * The callback will receive request instance as argument and must return
     * `true` if you want to skip token check for the current request.
     *
     * @param callable $callback A callable.
     * @return $this
     */
    public function skip_check_callback(callable $callback): static
    {
        $this->skip_check_callback = $callback;
        return $this;
    }
    /**
     * Apply entropy to a CSRF token
     *
     * To avoid BREACH apply a random salt value to a token
     * When the token is compared to the session the token needs
     * to be unsalted.
     *
     * @param string $token The token to salt.
     * @return string The salted token with the salt appended.
     */
    public function salt_token(string $token): string
    {
        $decoded = base64_decode($token);
        $length = strlen($decoded);
        $salt = Security::random_bytes($length);
        $salted = '';
        for ($i = 0; $i < $length; $i++) {
            // XOR the token and salt together so that we can reverse it later.
            $salted .= chr(ord($decoded[$i]) ^ ord($salt[$i]));
        }
        return base64_encode($salted . $salt);
    }
    /**
     * Remove the salt from a CSRF token.
     *
     * If the token is not TOKEN_VALUE_LENGTH * 2 it is an old
     * unsalted value that is supported for backwards compatibility.
     *
     * @param string $token The token that could be salty.
     * @return string An unsalted token.
     */
    protected function unsalt_token(string $token): string
    {
        $decoded = base64_decode($token, true);
        if ($decoded === false || strlen($decoded) !== static::TOKEN_VALUE_LENGTH * 2) {
            return $token;
        }
        $salted = substr($decoded, 0, static::TOKEN_VALUE_LENGTH);
        $salt = substr($decoded, static::TOKEN_VALUE_LENGTH);
        $unsalted = '';
        for ($i = 0; $i < static::TOKEN_VALUE_LENGTH; $i++) {
            // Reverse the XOR to desalt.
            $unsalted .= chr(ord($salted[$i]) ^ ord($salt[$i]));
        }
        return base64_encode($unsalted);
    }
    /**
     * Remove CSRF protection token from request data.
     *
     * This ensures that the token does not cause failures during
     * form tampering protection.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request The request object.
     */
    protected function unset_token_field(Server_Request_Interface $request): Server_Request_Interface
    {
        $body = $request->get_parsed_body();
        if (is_array($body)) {
            unset($body[$this->_config['field']]);
            $request = $request->with_parsed_body($body);
        }
        return $request;
    }
    /**
     * Create a new token to be used for CSRF protection
     *
     * This token is a simple unique random value as the compare
     * value is stored in the session where it cannot be tampered with.
     */
    public function create_token(): string
    {
        return base64_encode(Security::random_bytes(static::TOKEN_VALUE_LENGTH));
    }
    /**
     * Validate the request data against the cookie token.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request The request to validate against.
     * @param \Cake\Http\Session $session The session instance.
     * @throws \Cake\Http\Exception\InvalidCsrfTokenException When the CSRF token is invalid or missing.
     */
    protected function validate_token(Server_Request_Interface $request, Session $session): void
    {
        $token = $session->read($this->_config['key']);
        if (!$token || !is_string($token)) {
            throw new Invalid_Csrf_Token_Exception(__d('cake', 'Missing or incorrect CSRF session key'));
        }
        $body = $request->get_parsed_body();
        if (is_array($body) || $body instanceof ArrayAccess) {
            $post = (string) Hash::get($body, $this->_config['field']);
            $post = $this->unsalt_token($post);
            if (hash_equals($post, $token)) {
                return;
            }
        }
        $header = $request->get_header_line('X-CSRF-Token');
        $header = $this->unsalt_token($header);
        if (hash_equals($header, $token)) {
            return;
        }
        throw new Invalid_Csrf_Token_Exception(__d('cake', 'CSRF token from either the request body or request headers did not match or is missing.'));
    }
    /**
     * Replace the token in the provided request.
     *
     * Replace the token in the session and request attribute. Replacing
     * tokens is a good idea during privilege escalation or privilege reduction.
     *
     * @param \Cake\Http\ServerRequest $request The request to update
     * @param string $key The session key/attribute to set.
     * @return \Cake\Http\ServerRequest An updated request.
     */
    public static function replace_token(Server_Request $request, string $key = 'csrfToken'): Server_Request
    {
        $middleware = new Session_Csrf_Protection_Middleware(['key' => $key]);
        $token = $middleware->create_token();
        $request->get_session()->write($key, $token);
        return $request->with_attribute($key, $middleware->salt_token($token));
    }
}