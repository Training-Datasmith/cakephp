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
 * @since         5.3.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Http\Middleware;

use Authentication\Identity_Interface;
use Cake\Cache\Cache;
use Cake\Http\Exception\Too_Many_Requests_Exception;
use Cake\Http\Rate_Limit\Fixed_Window_Rate_Limiter;
use Cake\Http\Rate_Limit\Rate_Limiter_Interface;
use Cake\Http\Rate_Limit\Sliding_Window_Rate_Limiter;
use Cake\Http\Rate_Limit\Token_Bucket_Rate_Limiter;
use Closure;
use Psr\Http\Message\Response_Interface;
use Psr\Http\Message\Server_Request_Interface;
use Psr\Http\Server\Middleware_Interface;
use Psr\Http\Server\Request_Handler_Interface;
/**
 * Rate limiting middleware
 *
 * Provides configurable rate limiting based on various identifiers.
 * Supports multiple strategies including sliding window, token bucket, and fixed window.
 */
class Rate_Limit_Middleware implements Middleware_Interface
{
    /**
     * Identifier type: client IP address
     */
    public const IDENTIFIER_IP = 'ip';
    /**
     * Identifier type: authenticated user
     */
    public const IDENTIFIER_USER = 'user';
    /**
     * Identifier type: route (controller/action)
     */
    public const IDENTIFIER_ROUTE = 'route';
    /**
     * Identifier type: API key from token headers
     */
    public const IDENTIFIER_API_KEY = 'api_key';
    /**
     * Identifier type: token (alias for API key)
     */
    public const IDENTIFIER_TOKEN = 'token';
    /**
     * Strategy: sliding window rate limiting
     */
    public const STRATEGY_SLIDING_WINDOW = 'sliding_window';
    /**
     * Strategy: token bucket rate limiting
     */
    public const STRATEGY_TOKEN_BUCKET = 'token_bucket';
    /**
     * Strategy: fixed window rate limiting
     */
    public const STRATEGY_FIXED_WINDOW = 'fixed_window';
    /**
     * Default configuration
     *
     * - `limit`: Maximum number of requests allowed (default: 60)
     * - `window`: Time window in seconds for rate limiting (default: 60)
     * - `identifier`: How to identify clients - use IDENTIFIER_* constants (default: IDENTIFIER_IP)
     * - `strategy`: Rate limiting strategy - use STRATEGY_* constants (default: STRATEGY_SLIDING_WINDOW)
     * - `strategyClass`: Fully qualified class name of rate limiter strategy. Takes precedence over `strategy` option
     * - `cache`: Cache configuration name to use (default: 'default')
     * - `headers`: Whether to add rate limit headers to response (default: true)
     * - `message`: Error message when rate limit is exceeded
     * - `skipCheck`: Closure|null to determine if rate limiting should be skipped for a request
     * - `costCallback`: Closure|null to calculate custom cost for requests (default: 1 per request)
     * - `identifierCallback`: Closure|null to generate custom identifier, overrides `identifier` option
     * - `limitCallback`: Closure|null to determine dynamic limits based on request/identifier
     * - `ipHeader`: Header name(s) to check for client IP (default: 'x-forwarded-for')
     * - `includeRetryAfter`: Whether to include Retry-After header (default: true)
     * - `keyGenerator`: Closure|null to generate custom cache keys for rate limiting
     * - `tokenHeaders`: Array of headers to check for API tokens (default: ['Authorization', 'X-API-Key'])
     * - `limiters`: Named limiter configurations for different routes/contexts
     * - `limiterResolver`: Closure|null to resolve which named limiter to use for a request
     *
     * @var array<string, mixed>
     */
    protected array $default_config = ['limit' => 60, 'window' => 60, 'identifier' => self::IDENTIFIER_IP, 'strategy' => self::STRATEGY_SLIDING_WINDOW, 'strategyClass' => null, 'cache' => 'default', 'headers' => true, 'message' => 'Rate limit exceeded. Please try again later.', 'skipCheck' => null, 'costCallback' => null, 'identifierCallback' => null, 'limitCallback' => null, 'ipHeader' => 'x-forwarded-for', 'includeRetryAfter' => true, 'keyGenerator' => null, 'tokenHeaders' => ['Authorization', 'X-API-Key'], 'limiters' => [], 'limiterResolver' => null];
    /**
     * Configuration
     *
     * @var array<string, mixed>
     */
    protected array $config;
    /**
     * Constructor
     *
     * @param array<string, mixed> $config Configuration options
     */
    public function __construct(array $config = [])
    {
        $this->config = $config + $this->default_config;
    }
    /**
     * Process the request and add rate limiting
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request The request
     * @param \Psr\Http\Server\RequestHandlerInterface $handler The handler
     */
    public function process(Server_Request_Interface $request, Request_Handler_Interface $handler): Response_Interface
    {
        if ($this->should_skip($request)) {
            return $handler->handle($request);
        }
        $limiter_config = $this->resolve_limiter_config($request);
        $identifier = $this->get_identifier($request);
        $limit = $limiter_config['limit'] ?? $this->get_limit($request, $identifier);
        $window = $limiter_config['window'] ?? $this->config['window'];
        $cost = $this->get_cost($request);
        $key = $this->generate_key($identifier, $request);
        $rate_limiter = $this->get_rate_limiter($limiter_config);
        $result = $rate_limiter->attempt($key, $limit, $window, $cost);
        if (!$result['allowed']) {
            $message = $limiter_config['message'] ?? $this->config['message'];
            $exception = new Too_Many_Requests_Exception($message);
            if ($this->config['includeRetryAfter'] && isset($result['reset'])) {
                $retry_after = max(1, $result['reset'] - time());
                $exception->set_header('Retry-After', (string) $retry_after);
            }
            throw $exception;
        }
        $response = $handler->handle($request);
        if ($this->config['headers']) {
            return $this->add_rate_limit_headers($response, $result);
        }
        return $response;
    }
    /**
     * Resolve limiter configuration for the current request
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request The request
     * @return array<string, mixed>
     */
    protected function resolve_limiter_config(Server_Request_Interface $request): array
    {
        $resolver = $this->config['limiterResolver'];
        if ($resolver instanceof Closure) {
            $name = $resolver($request);
            if ($name && isset($this->config['limiters'][$name])) {
                return $this->config['limiters'][$name];
            }
        }
        $params = $request->get_attribute('params', []);
        if (isset($params['_rateLimiter']) && isset($this->config['limiters'][$params['_rateLimiter']])) {
            return $this->config['limiters'][$params['_rateLimiter']];
        }
        return [];
    }
    /**
     * Check if rate limiting should be skipped for this request
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request The request
     */
    protected function should_skip(Server_Request_Interface $request): bool
    {
        $skip_check = $this->config['skipCheck'];
        if ($skip_check instanceof Closure) {
            return (bool) $skip_check($request);
        }
        return false;
    }
    /**
     * Get the identifier for rate limiting
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request The request
     */
    protected function get_identifier(Server_Request_Interface $request): string
    {
        $callback = $this->config['identifierCallback'];
        if ($callback instanceof Closure) {
            return (string) $callback($request);
        }
        $identifier = $this->config['identifier'];
        if (is_array($identifier)) {
            $parts = [];
            foreach ($identifier as $type) {
                $parts[] = $this->get_identifier_by_type($type, $request);
            }
            return implode('_', $parts);
        }
        return $this->get_identifier_by_type($identifier, $request);
    }
    /**
     * Get identifier by type
     *
     * @param string $type The identifier type
     * @param \Psr\Http\Message\ServerRequestInterface $request The request
     */
    protected function get_identifier_by_type(string $type, Server_Request_Interface $request): string
    {
        return match ($type) {
            self::IDENTIFIER_IP => $this->get_client_ip($request),
            self::IDENTIFIER_USER => $this->get_user_identifier($request),
            self::IDENTIFIER_ROUTE => $this->get_route_identifier($request),
            self::IDENTIFIER_API_KEY, self::IDENTIFIER_TOKEN => $this->get_api_key_identifier($request),
            default => $this->get_client_ip($request),
        };
    }
    /**
     * Get client IP address
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request The request
     */
    protected function get_client_ip(Server_Request_Interface $request): string
    {
        $params = $request->get_server_params();
        if (is_array($this->config['ipHeader'])) {
            foreach ($this->config['ipHeader'] as $header) {
                $header_key = 'HTTP_' . strtoupper(str_replace('-', '_', $header));
                if (!empty($params[$header_key])) {
                    $ips = explode(',', (string) $params[$header_key]);
                    return trim($ips[0]);
                }
            }
        } elseif (is_string($this->config['ipHeader'])) {
            $header_key = 'HTTP_' . strtoupper(str_replace('-', '_', $this->config['ipHeader']));
            if (!empty($params[$header_key])) {
                $ips = explode(',', (string) $params[$header_key]);
                return trim($ips[0]);
            }
        }
        return $params['REMOTE_ADDR'] ?? 'unknown';
    }
    /**
     * Get user identifier
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request The request
     */
    protected function get_user_identifier(Server_Request_Interface $request): string
    {
        $user = $request->get_attribute('identity');
        if ($user) {
            if (interface_exists(Identity_Interface::class) && $user instanceof Identity_Interface) {
                return 'user_' . $user->get_identifier();
            }
            if (isset($user->id)) {
                return 'user_' . $user->id;
            }
        }
        return $this->get_client_ip($request);
    }
    /**
     * Get route identifier
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request The request
     */
    protected function get_route_identifier(Server_Request_Interface $request): string
    {
        $params = $request->get_attribute('params', []);
        $route = sprintf('%s::%s.%s', $params['plugin'] ?? 'app', $params['controller'] ?? 'unknown', $params['action'] ?? 'unknown');
        return $route . '_' . $this->get_client_ip($request);
    }
    /**
     * Generate cache key for rate limiting
     *
     * @param string $identifier The identifier
     * @param \Psr\Http\Message\ServerRequestInterface $request The request
     */
    protected function generate_key(string $identifier, Server_Request_Interface $request): string
    {
        $generator = $this->config['keyGenerator'];
        if ($generator instanceof Closure) {
            return (string) $generator($identifier, $request);
        }
        return 'rate_limit_' . hash('xxh3', $identifier);
    }
    /**
     * Get API key/token identifier
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request The request
     */
    protected function get_api_key_identifier(Server_Request_Interface $request): string
    {
        foreach ($this->config['tokenHeaders'] as $header) {
            $value = $request->get_header_line($header);
            if ($value) {
                if ($header === 'Authorization') {
                    $parts = explode(' ', $value, 2);
                    if (count($parts) === 2) {
                        $scheme = strtolower($parts[0]);
                        $token = $parts[1];
                        return sprintf('%s_%s', $scheme, hash('xxh3', $token));
                    }
                }
                return 'token_' . hash('xxh3', $value);
            }
        }
        return $this->get_client_ip($request);
    }
    /**
     * Get rate limit for the request
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request The request
     * @param string $identifier The identifier
     */
    protected function get_limit(Server_Request_Interface $request, string $identifier): int
    {
        $callback = $this->config['limitCallback'];
        if ($callback instanceof Closure) {
            return (int) $callback($request, $identifier);
        }
        return (int) $this->config['limit'];
    }
    /**
     * Get the cost of the request
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request The request
     */
    protected function get_cost(Server_Request_Interface $request): int
    {
        $callback = $this->config['costCallback'];
        if ($callback instanceof Closure) {
            return (int) $callback($request);
        }
        return 1;
    }
    /**
     * Get rate limiter instance based on strategy
     *
     * @param array<string, mixed> $limiterConfig Optional limiter configuration override
     */
    protected function get_rate_limiter(array $limiter_config = []): Rate_Limiter_Interface
    {
        $cache = Cache::pool($this->config['cache']);
        // Check if strategyClass is provided (takes precedence)
        /** @var class-string<\Cake\Http\RateLimit\RateLimiterInterface>|null $strategyClass */
        $strategy_class = $limiter_config['strategyClass'] ?? $this->config['strategyClass'];
        if ($strategy_class !== null && class_exists($strategy_class)) {
            return new $strategy_class($cache);
        }
        // Fall back to strategy string mapping for backward compatibility
        $strategy = $limiter_config['strategy'] ?? $this->config['strategy'];
        return match ($strategy) {
            self::STRATEGY_TOKEN_BUCKET => new Token_Bucket_Rate_Limiter($cache),
            self::STRATEGY_FIXED_WINDOW => new Fixed_Window_Rate_Limiter($cache),
            self::STRATEGY_SLIDING_WINDOW => new Sliding_Window_Rate_Limiter($cache),
            default => new Sliding_Window_Rate_Limiter($cache),
        };
    }
    /**
     * Add rate limit headers to response
     *
     * @param \Psr\Http\Message\ResponseInterface $response The response
     * @param array<string, mixed> $result Rate limit result
     */
    protected function add_rate_limit_headers(Response_Interface $response, array $result): Response_Interface
    {
        return $response->with_header('X-RateLimit-Limit', (string) $result['limit'])->with_header('X-RateLimit-Remaining', (string) $result['remaining'])->with_header('X-RateLimit-Reset', (string) $result['reset'])->with_header('X-RateLimit-Reset-Date', date('c', $result['reset']));
    }
}