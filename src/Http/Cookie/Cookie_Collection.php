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
 * @since         3.5.0
 * @license       https://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Http\Cookie;

use ArrayIterator;
use function Cake\Core\Trigger_Warning;
use Countable;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use InvalidArgumentException;
use IteratorAggregate;
use Psr\Http\Message\Request_Interface;
use Psr\Http\Message\Response_Interface;
use Psr\Http\Message\Server_Request_Interface;
use Traversable;
use TypeError;
/**
 * Cookie Collection
 *
 * Provides an immutable collection of cookies objects. Adding or removing
 * to a collection returns a *new* collection that you must retain.
 *
 * @template-implements \IteratorAggregate<string, \Cake\Http\Cookie\CookieInterface>
 */
class Cookie_Collection implements IteratorAggregate, Countable
{
    /**
     * Cookie objects
     *
     * @var array<string, \Cake\Http\Cookie\CookieInterface>
     */
    protected array $cookies = [];
    /**
     * Constructor
     *
     * @param array<\Cake\Http\Cookie\CookieInterface> $cookies Array of cookie objects
     */
    public function __construct(array $cookies = [])
    {
        $this->check_cookies($cookies);
        foreach ($cookies as $cookie) {
            $this->cookies[$cookie->get_id()] = $cookie;
        }
    }
    /**
     * Create a Cookie Collection from an array of Set-Cookie Headers
     *
     * @param array<string> $header The array of set-cookie header values.
     * @param array<string, mixed> $defaults The defaults attributes.
     */
    public static function create_from_header(array $header, array $defaults = []): static
    {
        $cookies = [];
        foreach ($header as $value) {
            try {
                $cookies[] = Cookie::create_from_header_string($value, $defaults);
            } catch (Exception|TypeError) {
                // Don't blow up on invalid cookies
            }
        }
        return new static($cookies);
    }
    /**
     * Create a new collection from the cookies in a ServerRequest
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request The request to extract cookie data from
     */
    public static function create_from_server_request(Server_Request_Interface $request): static
    {
        $data = $request->get_cookie_params();
        $cookies = [];
        foreach ($data as $name => $value) {
            $cookies[] = new Cookie((string) $name, $value);
        }
        return new static($cookies);
    }
    /**
     * Get the number of cookies in the collection.
     */
    public function count(): int
    {
        return count($this->cookies);
    }
    /**
     * Add a cookie and get an updated collection.
     *
     * Cookies are stored by id. This means that there can be duplicate
     * cookies if a cookie collection is used for cookies across multiple
     * domains. This can impact how get(), has() and remove() behave.
     *
     * @param \Cake\Http\Cookie\CookieInterface $cookie Cookie instance to add.
     */
    public function add(Cookie_Interface $cookie): static
    {
        $new = clone $this;
        $new->cookies[$cookie->get_id()] = $cookie;
        return $new;
    }
    /**
     * Get the first cookie by name.
     *
     * @param string $name The name of the cookie.
     * @throws \InvalidArgumentException If cookie not found.
     */
    public function get(string $name): Cookie_Interface
    {
        $cookie = $this->__get($name);
        if ($cookie === null) {
            throw new InvalidArgumentException(sprintf('Cookie `%s` not found. Use `has()` to check first for existence.', $name));
        }
        return $cookie;
    }
    /**
     * Check if a cookie with the given name exists
     *
     * @param string $name The cookie name to check.
     * @return bool True if the cookie exists, otherwise false.
     */
    public function has(string $name): bool
    {
        return $this->__get($name) !== null;
    }
    /**
     * Get the first cookie by name if cookie with provided name exists
     *
     * @param string $name The name of the cookie.
     */
    public function __get(string $name): ?Cookie_Interface
    {
        $key = mb_strtolower($name);
        foreach ($this->cookies as $cookie) {
            if (mb_strtolower($cookie->get_name()) === $key) {
                return $cookie;
            }
        }
        return null;
    }
    /**
     * Check if a cookie with the given name exists
     *
     * @param string $name The cookie name to check.
     * @return bool True if the cookie exists, otherwise false.
     */
    public function __isset(string $name): bool
    {
        return $this->__get($name) !== null;
    }
    /**
     * Create a new collection with all cookies matching $name removed.
     *
     * If the cookie is not in the collection, this method will do nothing.
     *
     * @param string $name The name of the cookie to remove.
     */
    public function remove(string $name): static
    {
        $new = clone $this;
        $key = mb_strtolower($name);
        foreach ($new->cookies as $i => $cookie) {
            if (mb_strtolower($cookie->get_name()) === $key) {
                unset($new->cookies[$i]);
            }
        }
        return $new;
    }
    /**
     * Checks if only valid cookie objects are in the array
     *
     * @param array<\Cake\Http\Cookie\CookieInterface> $cookies Array of cookie objects
     * @throws \InvalidArgumentException
     */
    protected function check_cookies(array $cookies): void
    {
        foreach ($cookies as $index => $cookie) {
            if (!$cookie instanceof Cookie_Interface) {
                throw new InvalidArgumentException(sprintf('Expected `%s[]` as $cookies but instead got `%s` at index %d', static::class, get_debug_type($cookie), $index));
            }
        }
    }
    /**
     * Gets the iterator
     *
     * @return \Traversable<string, \Cake\Http\Cookie\CookieInterface>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->cookies);
    }
    /**
     * Add cookies that match the path/domain/expiration to the request.
     *
     * This allows CookieCollections to be used as a 'cookie jar' in an HTTP client
     * situation. Cookies that match the request's domain + path that are not expired
     * when this method is called will be applied to the request.
     *
     * @param \Psr\Http\Message\RequestInterface $request The request to update.
     * @param array $extraCookies Associative array of additional cookies to add into the request. This
     *   is useful when you have cookie data from outside the collection you want to send.
     * @return \Psr\Http\Message\RequestInterface An updated request.
     */
    public function add_to_request(Request_Interface $request, array $extra_cookies = []): Request_Interface
    {
        $uri = $request->get_uri();
        $cookies = $this->find_matching_cookies($uri->get_scheme(), $uri->get_host(), $uri->get_path() ?: '/');
        $cookies = $extra_cookies + $cookies;
        $cookie_pairs = [];
        foreach ($cookies as $key => $value) {
            $cookie = sprintf('%s=%s', rawurlencode((string) $key), rawurlencode((string) $value));
            $size = strlen($cookie);
            if ($size > 4096) {
                trigger_warning(sprintf('The cookie `%s` exceeds the recommended maximum cookie length of 4096 bytes.', $key));
            }
            $cookie_pairs[] = $cookie;
        }
        if (!$cookie_pairs) {
            return $request;
        }
        return $request->with_header('Cookie', implode('; ', $cookie_pairs));
    }
    /**
     * Find cookies matching the scheme, host, and path
     *
     * @param string $scheme The http scheme to match
     * @param string $host The host to match.
     * @param string $path The path to match
     * @return array<string, mixed> An array of cookie name/value pairs
     */
    protected function find_matching_cookies(string $scheme, string $host, string $path): array
    {
        $out = [];
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        foreach ($this->cookies as $cookie) {
            if ($scheme === 'http' && $cookie->is_secure()) {
                continue;
            }
            if (!str_starts_with($path, $cookie->get_path())) {
                continue;
            }
            $domain = $cookie->get_domain();
            $leading_dot = str_starts_with($domain, '.');
            if ($leading_dot) {
                $domain = ltrim($domain, '.');
            }
            if ($cookie->is_expired($now)) {
                continue;
            }
            $pattern = '/' . preg_quote($domain, '/') . '$/';
            if (!preg_match($pattern, $host)) {
                continue;
            }
            $out[$cookie->get_name()] = $cookie->get_value();
        }
        return $out;
    }
    /**
     * Create a new collection that includes cookies from the response.
     *
     * @param \Psr\Http\Message\ResponseInterface $response Response to extract cookies from.
     * @param \Psr\Http\Message\RequestInterface $request Request to get cookie context from.
     */
    public function add_from_response(Response_Interface $response, Request_Interface $request): static
    {
        $uri = $request->get_uri();
        $host = $uri->get_host();
        $path = $uri->get_path() ?: '/';
        $cookies = static::create_from_header($response->get_header('Set-Cookie'), ['domain' => $host, 'path' => $path]);
        $new = clone $this;
        foreach ($cookies as $cookie) {
            $new->cookies[$cookie->get_id()] = $cookie;
        }
        $new->remove_expired_cookies($host, $path);
        return $new;
    }
    /**
     * Remove expired cookies from the collection.
     *
     * @param string $host The host to check for expired cookies on.
     * @param string $path The path to check for expired cookies on.
     */
    protected function remove_expired_cookies(string $host, string $path): void
    {
        $time = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $host_pattern = '/' . preg_quote($host, '/') . '$/';
        foreach ($this->cookies as $i => $cookie) {
            if (!$cookie->is_expired($time)) {
                continue;
            }
            $path_matches = str_starts_with($path, $cookie->get_path());
            $host_matches = preg_match($host_pattern, $cookie->get_domain());
            if ($path_matches && $host_matches) {
                unset($this->cookies[$i]);
            }
        }
    }
}