<?php

declare (strict_types=1);
/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @since         4.3.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Http\Client\Adapter;

use Cake\Http\Client\Adapter_Interface;
use Cake\Http\Client\Exception\Missing_Response_Exception;
use Cake\Http\Client\Response;
use Closure;
use InvalidArgumentException;
use Psr\Http\Message\Request_Interface;
/**
 * Implements sending requests to an array of stubbed responses
 *
 * This adapter is not intended for production use. Instead
 * it is the backend used by `Client::addMockResponse()`
 *
 * @internal
 */
class Mock implements Adapter_Interface
{
    /**
     * List of mocked responses.
     */
    protected array $responses = [];
    /**
     * Add a mocked response.
     *
     * ### Options
     *
     * - `match` An additional closure to match requests with.
     *
     * @param \Psr\Http\Message\RequestInterface $request A partial request to use for matching.
     * @param \Cake\Http\Client\Response $response The response that matches the request.
     * @param array<string, mixed> $options See above.
     */
    public function add_response(Request_Interface $request, Response $response, array $options): void
    {
        if (isset($options['match']) && !$options['match'] instanceof Closure) {
            $type = get_debug_type($options['match']);
            throw new InvalidArgumentException(sprintf('The `match` option must be a `Closure`. Got `%s`.', $type));
        }
        $this->responses[] = ['request' => $request, 'response' => $response, 'options' => $options];
    }
    /**
     * Find a response if one exists.
     *
     * @param \Psr\Http\Message\RequestInterface $request The request to match
     * @param array<string, mixed> $options The options are passed to match callbacks.
     * @return array<\Cake\Http\Client\Response> The matched response.
     * @throws \Cake\Http\Client\Exception\MissingResponseException When no mock response matches.
     */
    public function send(Request_Interface $request, array $options): array
    {
        $found = null;
        $method = $request->get_method();
        $request_uri = (string) $request->get_uri();
        foreach ($this->responses as $index => $mock) {
            /** @var \Psr\Http\Message\RequestInterface $mockRequest */
            $mock_request = $mock['request'];
            if ($method !== $mock_request->get_method()) {
                continue;
            }
            if (!$this->url_matches($request_uri, $mock_request)) {
                continue;
            }
            if (isset($mock['options']['match'])) {
                $match = $mock['options']['match']($request, $options);
                if (!is_bool($match)) {
                    throw new InvalidArgumentException('Match callback must return a boolean value.');
                }
                if (!$match) {
                    continue;
                }
            }
            $found = $index;
            break;
        }
        if ($found !== null) {
            // Move the current mock to the end so that when there are multiple
            // matches for a URL the next match is used on subsequent requests.
            $mock = $this->responses[$found];
            unset($this->responses[$found]);
            $this->responses[] = $mock;
            return [$mock['response']];
        }
        throw new Missing_Response_Exception(['method' => $method, 'url' => $request_uri]);
    }
    /**
     * Check if the request URI matches the mock URI.
     *
     * @param string $requestUri The request being sent.
     * @param \Psr\Http\Message\RequestInterface $mock The request being mocked.
     */
    protected function url_matches(string $request_uri, Request_Interface $mock): bool
    {
        $mock_uri = (string) $mock->get_uri();
        if ($request_uri === $mock_uri) {
            return true;
        }
        $star_position = strrpos($mock_uri, '/%2A');
        if ($star_position === strlen($mock_uri) - 4) {
            $mock_uri = substr($mock_uri, 0, $star_position);
            return str_starts_with($request_uri, $mock_uri);
        }
        return false;
    }
}