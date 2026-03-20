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

use Cake\Core\Configure;
use Cake\Utility\Hash;
use function Laminas\Diactoros\Normalize_Server;
use function Laminas\Diactoros\Normalize_Uploaded_Files;
use Psr\Http\Message\Server_Request_Factory_Interface;
use Psr\Http\Message\Server_Request_Interface;
/**
 * Factory for making ServerRequest instances.
 *
 * This adds in CakePHP specific behavior to populate the basePath and webroot
 * attributes. Furthermore the Uri's path is corrected to only contain the
 * 'virtual' path for the request.
 */
class Server_Request_Factory implements Server_Request_Factory_Interface
{
    /**
     * Create a request from the supplied superglobal values.
     *
     * If any argument is not supplied, the corresponding superglobal value will
     * be used.
     *
     * @param array|null $server $_SERVER superglobal
     * @param array|null $query $_GET superglobal
     * @param array|null $parsedBody $_POST superglobal
     * @param array|null $cookies $_COOKIE superglobal
     * @param array|null $files $_FILES superglobal
     * @throws \InvalidArgumentException for invalid file values
     */
    public static function from_globals(?array $server = null, ?array $query = null, ?array $parsed_body = null, ?array $cookies = null, ?array $files = null): Server_Request
    {
        $server = normalize_server($server ?? $_SERVER);
        ['uri' => $uri, 'base' => $base, 'webroot' => $webroot] = Uri_Factory::marshal_uri_and_base_from_sapi($server);
        $session_config = (array) Configure::read('Session') + ['defaults' => 'php', 'cookiePath' => $webroot];
        $session = Session::create($session_config);
        $request = new Server_Request(['environment' => $server, 'uri' => $uri, 'cookies' => $cookies ?? $_COOKIE, 'query' => $query ?? $_GET, 'webroot' => $webroot, 'base' => $base, 'session' => $session, 'input' => $server['CAKEPHP_INPUT'] ?? null]);
        $request = static::marshal_body_and_request_method($parsed_body ?? $_POST, $request);
        // This is required as `ServerRequest::scheme()` ignores the value of
        // `HTTP_X_FORWARDED_PROTO` unless `trustProxy` is enabled, while the
        // `Uri` instance initially created always takes values of `HTTP_X_FORWARDED_PROTO`
        // into account.
        $uri = $request->get_uri()->with_scheme($request->scheme());
        $request = $request->with_uri($uri, true);
        return static::marshal_files($files ?? $_FILES, $request);
    }
    /**
     * Sets the REQUEST_METHOD environment variable based on the simulated _method
     * HTTP override value. The 'ORIGINAL_REQUEST_METHOD' is also preserved, if you
     * want the read the non-simulated HTTP method the client used.
     *
     * Request body of content type "application/x-www-form-urlencoded" is parsed
     * into array for PUT/PATCH/DELETE requests.
     *
     * @param array $parsedBody Parsed body.
     * @param \Cake\Http\ServerRequest $request Request instance.
     */
    protected static function marshal_body_and_request_method(array $parsed_body, Server_Request $request): Server_Request
    {
        $method = $request->get_method();
        $override = false;
        if (in_array($method, ['PUT', 'DELETE', 'PATCH'], true) && str_starts_with((string) $request->content_type(), 'application/x-www-form-urlencoded')) {
            $data = (string) $request->get_body();
            parse_str($data, $parsed_body);
        }
        if ($request->has_header('X-Http-Method-Override')) {
            $parsed_body['_method'] = $request->get_header_line('X-Http-Method-Override');
            $override = true;
        }
        $request = $request->with_env('ORIGINAL_REQUEST_METHOD', $method);
        if (isset($parsed_body['_method'])) {
            $request = $request->with_env('REQUEST_METHOD', $parsed_body['_method']);
            unset($parsed_body['_method']);
            $override = true;
        }
        if ($override && !in_array($request->get_method(), ['PUT', 'POST', 'DELETE', 'PATCH'], true)) {
            $parsed_body = [];
        }
        return $request->with_parsed_body($parsed_body);
    }
    /**
     * Process uploaded files and move things onto the parsed body.
     *
     * @param array $files Files array for normalization and merging in parsed body.
     * @param \Cake\Http\ServerRequest $request Request instance.
     */
    protected static function marshal_files(array $files, Server_Request $request): Server_Request
    {
        $files = normalize_uploaded_files($files);
        $request = $request->with_uploaded_files($files);
        $parsed_body = $request->get_parsed_body();
        if (!is_array($parsed_body)) {
            return $request;
        }
        $parsed_body = Hash::merge($parsed_body, $files);
        return $request->with_parsed_body($parsed_body);
    }
    /**
     * Create a new server request.
     *
     * Note that server-params are taken precisely as given - no parsing/processing
     * of the given values is performed, and, in particular, no attempt is made to
     * determine the HTTP method or URI, which must be provided explicitly.
     *
     * @param string $method The HTTP method associated with the request.
     * @param \Psr\Http\Message\UriInterface|string $uri The URI associated with the request. If
     *     the value is a string, the factory MUST create a UriInterface
     *     instance based on it.
     * @param array $serverParams Array of SAPI parameters with which to seed
     *     the generated request instance.
     * @phpcsSuppress SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint
     */
    public function create_server_request(string $method, $uri, array $server_params = []): Server_Request_Interface
    {
        $server_params['REQUEST_METHOD'] = $method;
        $options = ['environment' => $server_params];
        if (is_string($uri)) {
            $uri = (new Uri_Factory())->create_uri($uri);
        }
        $options['uri'] = $uri;
        return new Server_Request($options);
    }
}