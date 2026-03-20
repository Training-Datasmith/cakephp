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

use Cake\Utility\Hash;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use Value_Error;
/**
 * Cookie object to build a cookie and turn it into a header value
 *
 * An HTTP cookie (also called web cookie, Internet cookie, browser cookie or
 * simply cookie) is a small piece of data sent from a website and stored on
 * the user's computer by the user's web browser while the user is browsing.
 *
 * Cookies were designed to be a reliable mechanism for websites to remember
 * stateful information (such as items added in the shopping cart in an online
 * store) or to record the user's browsing activity (including clicking
 * particular buttons, logging in, or recording which pages were visited in
 * the past). They can also be used to remember arbitrary pieces of information
 * that the user previously entered into form fields such as names, and preferences.
 *
 * Cookie objects are immutable, and you must re-assign variables when modifying
 * cookie objects:
 *
 * ```
 * $cookie = $cookie->withValue('0');
 * ```
 *
 * @link https://tools.ietf.org/html/draft-ietf-httpbis-rfc6265bis-03
 * @link https://en.wikipedia.org/wiki/HTTP_cookie
 * @see \Cake\Http\Cookie\CookieCollection for working with collections of cookies.
 * @see \Cake\Http\Response::getCookieCollection() for working with response cookies.
 */
class Cookie implements Cookie_Interface
{
    /**
     * Cookie name
     */
    protected string $name = '';
    /**
     * Raw Cookie value.
     */
    protected array|string $value = '';
    /**
     * Whether a JSON value has been expanded into an array.
     */
    protected bool $is_expanded = false;
    /**
     * Expiration time
     */
    protected ?DateTimeInterface $expires_at = null;
    /**
     * Path
     */
    protected string $path = '/';
    /**
     * Domain
     */
    protected string $domain = '';
    /**
     * Secure
     */
    protected bool $secure = false;
    /**
     * HTTP only
     */
    protected bool $http_only = false;
    /**
     * Samesite
     */
    protected ?Same_Site_Enum $same_site = null;
    /**
     * Default attributes for a cookie.
     *
     * @var array<string, mixed>
     * @see \Cake\Http\Cookie\Cookie::setDefaults()
     */
    protected static array $defaults = ['expires' => null, 'path' => '/', 'domain' => '', 'secure' => false, 'httponly' => false, 'samesite' => null];
    /**
     * Constructor
     *
     * The constructors args are similar to the native PHP `setcookie()` method.
     * The only difference is the 3rd argument which excepts null or an
     * DateTime or DateTimeImmutable object instead an integer.
     *
     * @link https://php.net/manual/en/function.setcookie.php
     * @param string $name Cookie name
     * @param array|string|float|int|bool $value Value of the cookie
     * @param \DateTimeInterface|null $expiresAt Expiration time and date
     * @param string|null $path Path
     * @param string|null $domain Domain
     * @param bool|null $secure Is secure
     * @param bool|null $httpOnly HTTP Only
     * @param \Cake\Http\Cookie\SameSiteEnum|string|null $sameSite Samesite
     */
    public function __construct(string $name, array|string|float|int|bool $value = '', ?DateTimeInterface $expires_at = null, ?string $path = null, ?string $domain = null, ?bool $secure = null, ?bool $http_only = null, Same_Site_Enum|string|null $same_site = null)
    {
        $this->validate_name($name);
        $this->name = $name;
        $this->_set_value($value);
        $this->domain = $domain ?? static::$defaults['domain'];
        $this->http_only = $http_only ?? static::$defaults['httponly'];
        $this->path = $path ?? static::$defaults['path'];
        $this->secure = $secure ?? static::$defaults['secure'];
        $this->same_site = static::resolve_same_site_enum($same_site ?? static::$defaults['samesite']);
        if ($expires_at) {
            if ($expires_at instanceof DateTime) {
                $expires_at = clone $expires_at;
            }
            /** @var \DateTimeImmutable|\DateTime $expiresAt */
            $expires_at = $expires_at->set_timezone(new DateTimeZone('GMT'));
        } else {
            $expires_at = static::$defaults['expires'];
        }
        $this->expires_at = $expires_at;
    }
    /**
     * Set default options for the cookies.
     *
     * Valid option keys are:
     *
     * - `expires`: Can be a UNIX timestamp or `strtotime()` compatible string or `DateTimeInterface` instance or `null`.
     * - `path`: A path string. Defaults to `'/'`.
     * - `domain`: Domain name string. Defaults to `''`.
     * - `httponly`: Boolean. Defaults to `false`.
     * - `secure`: Boolean. Defaults to `false`.
     * - `samesite`: Can be one of `CookieInterface::SAMESITE_LAX`, `CookieInterface::SAMESITE_STRICT`,
     *    `CookieInterface::SAMESITE_NONE` or `null`. Defaults to `null`.
     *
     * @param array<string, mixed> $options Default options.
     */
    public static function set_defaults(array $options): void
    {
        if (isset($options['expires'])) {
            $options['expires'] = static::date_time_instance($options['expires']);
        }
        if (isset($options['samesite'])) {
            $options['samesite'] = static::resolve_same_site_enum($options['samesite']);
        }
        static::$defaults = $options + static::$defaults;
    }
    /**
     * Factory method to create Cookie instances.
     *
     * @param string $name Cookie name
     * @param array|string|float|int|bool $value Value of the cookie
     * @param array<string, mixed> $options Cookies options.
     * @see \Cake\Cookie\Cookie::setDefaults()
     */
    public static function create(string $name, array|string|float|int|bool $value, array $options = []): static
    {
        $options += static::$defaults;
        $options['expires'] = static::date_time_instance($options['expires']);
        return new static($name, $value, $options['expires'], $options['path'], $options['domain'], $options['secure'], $options['httponly'], $options['samesite']);
    }
    /**
     * Converts non null expiry value into DateTimeInterface instance.
     *
     * @param \DateTimeInterface|string|int|null $expires Expiry value.
     */
    protected static function date_time_instance(DateTimeInterface|string|int|null $expires): ?DateTimeInterface
    {
        if ($expires === null) {
            return null;
        }
        if ($expires instanceof DateTimeInterface) {
            /**
             * @phpstan-ignore-next-line
             */
            return $expires->set_timezone(new DateTimeZone('GMT'));
        }
        if (!is_numeric($expires)) {
            $expires = strtotime($expires) ?: null;
        }
        if ($expires !== null) {
            return new DateTimeImmutable('@' . $expires);
        }
        return null;
    }
    /**
     * Create Cookie instance from "set-cookie" header string.
     *
     * @param string $cookie Cookie header string.
     * @param array<string, mixed> $defaults Default attributes.
     * @see \Cake\Http\Cookie\Cookie::setDefaults()
     */
    public static function create_from_header_string(string $cookie, array $defaults = []): static
    {
        if (str_contains($cookie, '";"')) {
            $cookie = str_replace('";"', '{__cookie_replace__}', $cookie);
            $parts = str_replace('{__cookie_replace__}', '";"', explode(';', $cookie));
        } else {
            $parts = preg_split('/\;[ \t]*/', $cookie) ?: [];
        }
        $name_value = explode('=', (string) array_shift($parts), 2);
        $name = array_shift($name_value);
        $value = array_shift($name_value) ?? '';
        $data = ['name' => urldecode($name), 'value' => urldecode($value)] + $defaults;
        foreach ($parts as $part) {
            if (str_contains($part, '=')) {
                [$key, $value] = explode('=', $part);
            } else {
                $key = $part;
                $value = true;
            }
            $key = strtolower($key);
            $data[$key] = $value;
        }
        if (isset($data['max-age'])) {
            $data['expires'] = time() + (int) $data['max-age'];
            unset($data['max-age']);
        }
        // Ignore invalid value when parsing headers
        // https://tools.ietf.org/html/draft-west-first-party-cookies-07#section-4.1
        if (isset($data['samesite'])) {
            try {
                $data['samesite'] = static::resolve_same_site_enum($data['samesite']);
            } catch (Value_Error) {
                unset($data['samesite']);
            }
        }
        $name = $data['name'];
        $value = $data['value'];
        unset($data['name'], $data['value']);
        /** @phpstan-ignore return.type */
        return Cookie::create($name, $value, $data);
    }
    /**
     * Returns a header value as string
     */
    public function to_header_value(): string
    {
        $value = $this->value;
        if ($this->is_expanded) {
            assert(is_array($value), '$value is not an array');
            $value = $this->_flatten($value);
        }
        $header_value = [];
        /** @var string $value */
        $header_value[] = sprintf('%s=%s', $this->name, rawurlencode($value));
        if ($this->expires_at) {
            $header_value[] = sprintf('expires=%s', $this->get_formatted_expires());
        }
        if ($this->path !== '') {
            $header_value[] = sprintf('path=%s', $this->path);
        }
        if ($this->domain !== '') {
            $header_value[] = sprintf('domain=%s', $this->domain);
        }
        if ($this->same_site) {
            $header_value[] = sprintf('samesite=%s', $this->same_site->value);
        }
        if ($this->secure) {
            $header_value[] = 'secure';
        }
        if ($this->http_only) {
            $header_value[] = 'httponly';
        }
        return implode('; ', $header_value);
    }
    /**
     * @inheritDoc
     */
    public function with_name(string $name): static
    {
        $this->validate_name($name);
        $new = clone $this;
        $new->name = $name;
        return $new;
    }
    /**
     * @inheritDoc
     */
    public function get_id(): string
    {
        return "{$this->name};{$this->domain};{$this->path}";
    }
    /**
     * @inheritDoc
     */
    public function get_name(): string
    {
        return $this->name;
    }
    /**
     * Validates the cookie name
     *
     * @param string $name Name of the cookie
     * @throws \InvalidArgumentException
     * @link https://tools.ietf.org/html/rfc2616#section-2.2 Rules for naming cookies.
     */
    protected function validate_name(string $name): void
    {
        if (preg_match("/[=,;\t\r\n\v\f]/", $name)) {
            throw new InvalidArgumentException(sprintf('The cookie name `%s` contains invalid characters.', $name));
        }
        if (!$name) {
            throw new InvalidArgumentException('The cookie name cannot be empty.');
        }
    }
    /**
     * @inheritDoc
     */
    public function get_value(): array|string
    {
        return $this->value;
    }
    /**
     * @inheritDoc
     */
    public function get_scalar_value(): string
    {
        if ($this->is_expanded) {
            assert(is_array($this->value), '$value is not an array');
            return $this->_flatten($this->value);
        }
        assert(is_string($this->value), '$value is not a string');
        return $this->value;
    }
    /**
     * @inheritDoc
     */
    public function with_value(array|string|float|int|bool $value): static
    {
        $new = clone $this;
        $new->_set_value($value);
        return $new;
    }
    /**
     * Setter for the value attribute.
     *
     * @param array|string|float|int|bool $value The value to store.
     */
    protected function _set_value(array|string|float|int|bool $value): void
    {
        $this->is_expanded = is_array($value);
        $this->value = is_array($value) ? $value : (string) $value;
    }
    /**
     * @inheritDoc
     */
    public function with_path(string $path): static
    {
        $new = clone $this;
        $new->path = $path;
        return $new;
    }
    /**
     * @inheritDoc
     */
    public function get_path(): string
    {
        return $this->path;
    }
    /**
     * @inheritDoc
     */
    public function with_domain(string $domain): static
    {
        $new = clone $this;
        $new->domain = $domain;
        return $new;
    }
    /**
     * @inheritDoc
     */
    public function get_domain(): string
    {
        return $this->domain;
    }
    /**
     * @inheritDoc
     */
    public function is_secure(): bool
    {
        return $this->secure;
    }
    /**
     * @inheritDoc
     */
    public function with_secure(bool $secure): static
    {
        $new = clone $this;
        $new->secure = $secure;
        return $new;
    }
    /**
     * @inheritDoc
     */
    public function with_http_only(bool $http_only): static
    {
        $new = clone $this;
        $new->http_only = $http_only;
        return $new;
    }
    /**
     * @inheritDoc
     */
    public function is_http_only(): bool
    {
        return $this->http_only;
    }
    /**
     * @inheritDoc
     */
    public function with_expiry(DateTimeInterface $date_time): static
    {
        if ($date_time instanceof DateTime) {
            $date_time = clone $date_time;
        }
        $new = clone $this;
        $new->expires_at = $date_time->set_timezone(new DateTimeZone('GMT'));
        return $new;
    }
    /**
     * @inheritDoc
     */
    public function get_expiry(): ?DateTimeInterface
    {
        return $this->expires_at;
    }
    /**
     * @inheritDoc
     */
    public function get_expires_timestamp(): ?int
    {
        if (!$this->expires_at) {
            return null;
        }
        return (int) $this->expires_at->format('U');
    }
    /**
     * @inheritDoc
     */
    public function get_formatted_expires(): string
    {
        if (!$this->expires_at) {
            return '';
        }
        return $this->expires_at->format(static::EXPIRES_FORMAT);
    }
    /**
     * @inheritDoc
     */
    public function is_expired(?DateTimeInterface $time = null): bool
    {
        $time = $time ?: new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($time instanceof DateTime) {
            $time = clone $time;
        }
        if (!$this->expires_at) {
            return false;
        }
        return $this->expires_at < $time;
    }
    /**
     * @inheritDoc
     */
    public function with_never_expire(): static
    {
        $new = clone $this;
        $new->expires_at = new DateTimeImmutable('2038-01-01');
        return $new;
    }
    /**
     * @inheritDoc
     */
    public function with_expired(): static
    {
        $new = clone $this;
        $new->expires_at = new DateTimeImmutable('@1');
        return $new;
    }
    /**
     * @inheritDoc
     */
    public function get_same_site(): ?Same_Site_Enum
    {
        return $this->same_site;
    }
    /**
     * @inheritDoc
     */
    public function with_same_site(Same_Site_Enum|string|null $same_site): static
    {
        $new = clone $this;
        $new->same_site = static::resolve_same_site_enum($same_site);
        return $new;
    }
    /**
     * Create SameSiteEnum instance.
     *
     * @param \Cake\Http\Cookie\SameSiteEnum|string|null $sameSite SameSite value
     */
    protected static function resolve_same_site_enum(Same_Site_Enum|string|null $same_site): ?Same_Site_Enum
    {
        return match (true) {
            $same_site === null => $same_site,
            $same_site instanceof Same_Site_Enum => $same_site,
            default => Same_Site_Enum::from(ucfirst(strtolower($same_site))),
        };
    }
    /**
     * Checks if a value exists in the cookie data.
     *
     * This method will expand serialized complex data,
     * on first use.
     *
     * @param string $path Path to check
     */
    public function check(string $path): bool
    {
        if ($this->is_expanded === false) {
            assert(is_string($this->value), '$value is not a string');
            $this->value = $this->_expand($this->value);
        }
        assert(is_array($this->value), '$value is not an array');
        return Hash::check($this->value, $path);
    }
    /**
     * Create a new cookie with updated data.
     *
     * @param string $path Path to write to
     * @param mixed $value Value to write
     */
    public function with_added_value(string $path, mixed $value): static
    {
        $new = clone $this;
        if ($new->is_expanded === false) {
            assert(is_string($new->value), '$value is not a string');
            $new->value = $new->_expand($new->value);
        }
        assert(is_array($new->value), '$value is not an array');
        $new->value = Hash::insert($new->value, $path, $value);
        return $new;
    }
    /**
     * Create a new cookie without a specific path
     *
     * @param string $path Path to remove
     */
    public function without_added_value(string $path): static
    {
        $new = clone $this;
        if ($new->is_expanded === false) {
            assert(is_string($new->value), '$value is not a string');
            $new->value = $new->_expand($new->value);
        }
        assert(is_array($new->value), '$value is not an array');
        $new->value = Hash::remove($new->value, $path);
        return $new;
    }
    /**
     * Read data from the cookie
     *
     * This method will expand serialized complex data,
     * on first use.
     *
     * @param string|null $path Path to read the data from
     */
    public function read(?string $path = null): mixed
    {
        if ($this->is_expanded === false) {
            assert(is_string($this->value), '$value is not a string');
            $this->value = $this->_expand($this->value);
        }
        if ($path === null) {
            return $this->value;
        }
        assert(is_array($this->value), '$value is not an array');
        return Hash::get($this->value, $path);
    }
    /**
     * Checks if the cookie value was expanded
     */
    public function is_expanded(): bool
    {
        return $this->is_expanded;
    }
    /**
     * @inheritDoc
     */
    public function get_options(): array
    {
        $options = ['expires' => (int) $this->get_expires_timestamp(), 'path' => $this->path, 'domain' => $this->domain, 'secure' => $this->secure, 'httponly' => $this->http_only];
        if ($this->same_site !== null) {
            $options['samesite'] = $this->same_site->value;
        }
        return $options;
    }
    /**
     * @inheritDoc
     */
    public function to_array(): array
    {
        return ['name' => $this->name, 'value' => $this->get_scalar_value()] + $this->get_options();
    }
    /**
     * Implode method to keep keys are multidimensional arrays
     *
     * @param array $array Map of key and values
     * @return string A JSON encoded string.
     */
    protected function _flatten(array $array): string
    {
        return json_encode($array, JSON_THROW_ON_ERROR);
    }
    /**
     * Explode method to return array from string set in CookieComponent::_flatten()
     * Maintains reading backwards compatibility with 1.x CookieComponent::_flatten().
     *
     * @param string $string A string containing JSON encoded data, or a bare string.
     * @return array|string Map of key and values
     */
    protected function _expand(string $string): array|string
    {
        $this->is_expanded = true;
        $first = substr($string, 0, 1);
        if ($first === '{' || $first === '[') {
            return json_decode($string, true) ?? $string;
        }
        $array = [];
        foreach (explode(',', $string) as $pair) {
            $key = explode('|', $pair);
            if (!isset($key[1])) {
                return $key[0];
            }
            $array[$key[0]] = $key[1];
        }
        return $array;
    }
}