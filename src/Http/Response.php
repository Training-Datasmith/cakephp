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
 * @since         2.0.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Http;

use Cake\Core\Configure;
use function Cake\Core\env;
use Cake\Http\Cookie\Cookie_Collection;
use Cake\Http\Cookie\Cookie_Interface;
use Cake\Http\Exception\Not_Found_Exception;
use function Cake\I18n\__d;
use Cake\I18n\DateTime as CakeDateTime;
use DateTime;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use Laminas\Diactoros\Message_Trait;
use Laminas\Diactoros\Stream;
use Psr\Http\Message\Response_Interface;
use Psr\Http\Message\Stream_Interface;
use Spl_File_Info;
use Stringable;
/**
 * Responses contain the response text, status and headers of a HTTP response.
 *
 * There are external packages such as `fig/http-message-util` that provide HTTP
 * status code constants. These can be used with any method that accepts or
 * returns a status code integer. Keep in mind that these constants might
 * include status codes that are not allowed which will throw an
 * `\InvalidArgumentException`.
 */
class Response implements Response_Interface, Stringable
{
    use Message_Trait;
    /**
     * @var int
     */
    public const STATUS_CODE_MIN = 100;
    /**
     * @var int
     */
    public const STATUS_CODE_MAX = 599;
    /**
     * Allowed HTTP status codes and their default description.
     *
     * @var array<int, string>
     */
    protected array $_status_codes = [100 => 'Continue', 101 => 'Switching Protocols', 102 => 'Processing', 200 => 'OK', 201 => 'Created', 202 => 'Accepted', 203 => 'Non-Authoritative Information', 204 => 'No Content', 205 => 'Reset Content', 206 => 'Partial Content', 207 => 'Multi-status', 208 => 'Already Reported', 226 => 'IM used', 300 => 'Multiple Choices', 301 => 'Moved Permanently', 302 => 'Found', 303 => 'See Other', 304 => 'Not Modified', 305 => 'Use Proxy', 306 => '(Unused)', 307 => 'Temporary Redirect', 308 => 'Permanent Redirect', 400 => 'Bad Request', 401 => 'Unauthorized', 402 => 'Payment Required', 403 => 'Forbidden', 404 => 'Not Found', 405 => 'Method Not Allowed', 406 => 'Not Acceptable', 407 => 'Proxy Authentication Required', 408 => 'Request Timeout', 409 => 'Conflict', 410 => 'Gone', 411 => 'Length Required', 412 => 'Precondition Failed', 413 => 'Request Entity Too Large', 414 => 'Request-URI Too Large', 415 => 'Unsupported Media Type', 416 => 'Requested range not satisfiable', 417 => 'Expectation Failed', 418 => "I'm a teapot", 421 => 'Misdirected Request', 422 => 'Unprocessable Entity', 423 => 'Locked', 424 => 'Failed Dependency', 425 => 'Unordered Collection', 426 => 'Upgrade Required', 428 => 'Precondition Required', 429 => 'Too Many Requests', 431 => 'Request Header Fields Too Large', 444 => 'Connection Closed Without Response', 451 => 'Unavailable For Legal Reasons', 499 => 'Client Closed Request', 500 => 'Internal Server Error', 501 => 'Not Implemented', 502 => 'Bad Gateway', 503 => 'Service Unavailable', 504 => 'Gateway Timeout', 505 => 'Unsupported Version', 506 => 'Variant Also Negotiates', 507 => 'Insufficient Storage', 508 => 'Loop Detected', 510 => 'Not Extended', 511 => 'Network Authentication Required', 599 => 'Network Connect Timeout Error'];
    /**
     * Status code to send to the client
     */
    protected int $_status = 200;
    /**
     * File object for file to be read out as response
     */
    protected ?Spl_File_Info $_file = null;
    /**
     * File range. Used for requesting ranges of files.
     *
     * @var array<int>
     */
    protected array $_file_range = [];
    /**
     * The charset the response body is encoded with
     */
    protected string $_charset = 'UTF-8';
    /**
     * Holds all the cache directives that will be converted
     * into headers when sending the response
     *
     * @var array<string, mixed>
     */
    protected array $_cache_directives = [];
    /**
     * Collection of cookies to send to the client
     */
    protected Cookie_Collection $_cookies;
    /**
     * Reason Phrase
     */
    protected string $_reason_phrase = 'OK';
    /**
     * Stream mode options.
     */
    protected string $_stream_mode = 'wb+';
    /**
     * Stream target or resource object.
     *
     * @var resource|string
     */
    protected $_stream_target = 'php://memory';
    /**
     * Constructor
     *
     * @param array<string, mixed> $options list of parameters to setup the response. Possible values are:
     *
     *  - body: the response text that should be sent to the client
     *  - status: the HTTP status code to respond with
     *  - type: a complete mime-type string or an extension mapped in this class
     *  - charset: the charset for the response body
     * @throws \InvalidArgumentException
     */
    public function __construct(array $options = [])
    {
        $this->_stream_target = $options['streamTarget'] ?? $this->_stream_target;
        $this->_stream_mode = $options['streamMode'] ?? $this->_stream_mode;
        if (isset($options['stream'])) {
            if (!$options['stream'] instanceof Stream_Interface) {
                throw new InvalidArgumentException('Stream option must be an object that implements StreamInterface');
            }
            $this->stream = $options['stream'];
        } else {
            $this->_create_stream();
        }
        if (isset($options['body'])) {
            $this->stream->write($options['body']);
        }
        if (isset($options['status'])) {
            $this->_set_status($options['status']);
        }
        $options['charset'] ??= Configure::read('App.encoding');
        $this->_charset = $options['charset'];
        $type = 'text/html';
        if (isset($options['type'])) {
            $type = $this->resolve_type($options['type']);
        }
        $this->_set_content_type($type);
        $this->_cookies = new Cookie_Collection();
    }
    /**
     * Creates the stream object.
     */
    protected function _create_stream(): void
    {
        $this->stream = new Stream($this->_stream_target, $this->_stream_mode);
    }
    /**
     * Formats the Content-Type header based on the configured contentType and charset
     * the charset will only be set in the header if the response is of type text/*
     *
     * Note: Content-Type header will be cleared for 304 and 204 status codes as these
     * status codes must not have a Content-Type header.
     *
     * @param string $type The type to set.
     */
    protected function _set_content_type(string $type): void
    {
        if (in_array($this->_status, [304, 204], true)) {
            $this->_clear_header('Content-Type');
            return;
        }
        $allowed = ['application/javascript', 'application/xml', 'application/rss+xml'];
        $charset = false;
        if ($this->_charset && (str_starts_with($type, 'text/') || in_array($type, $allowed, true))) {
            $charset = true;
        }
        if ($charset && !str_contains($type, ';')) {
            $this->_set_header('Content-Type', "{$type}; charset={$this->_charset}");
        } else {
            $this->_set_header('Content-Type', $type);
        }
    }
    /**
     * Return an instance with an updated location header.
     *
     * If the current status code is 200, it will be replaced
     * with 302.
     *
     * @param string $url The location to redirect to.
     * @return static A new response with the Location header set.
     */
    public function with_location(string $url): static
    {
        $new = $this->with_header('Location', $url);
        if ($new->_status === 200) {
            $new->_status = 302;
        }
        return $new;
    }
    /**
     * Sets a header.
     *
     * @phpstan-param non-empty-string $header
     * @param string $header Header key.
     * @param string $value Header value.
     */
    protected function _set_header(string $header, string $value): void
    {
        $normalized = strtolower($header);
        $this->header_names[$normalized] = $header;
        $this->headers[$header] = [$value];
    }
    /**
     * Clear header
     *
     * @phpstan-param non-empty-string $header
     * @param string $header Header key.
     */
    protected function _clear_header(string $header): void
    {
        $normalized = strtolower($header);
        if (!isset($this->header_names[$normalized])) {
            return;
        }
        $original = $this->header_names[$normalized];
        unset($this->header_names[$normalized], $this->headers[$original]);
    }
    /**
     * Gets the response status code.
     *
     * The status code is a 3-digit integer result code of the server's attempt
     * to understand and satisfy the request.
     *
     * @return int Status code.
     */
    public function get_status_code(): int
    {
        return $this->_status;
    }
    /**
     * Return an instance with the specified status code and, optionally, reason phrase.
     *
     * If no reason phrase is specified, implementations MAY choose to default
     * to the RFC 7231 or IANA recommended reason phrase for the response's
     * status code.
     *
     * This method MUST be implemented in such a way as to retain the
     * immutability of the message, and MUST return an instance that has the
     * updated status and reason phrase.
     *
     * If the status code is 304 or 204, the existing Content-Type header
     * will be cleared, as these response codes have no body.
     *
     * There are external packages such as `fig/http-message-util` that provide HTTP
     * status code constants. These can be used with any method that accepts or
     * returns a status code integer. However, keep in mind that these constants
     * might include status codes that are not allowed which will throw an
     * `\InvalidArgumentException`.
     *
     * @link https://tools.ietf.org/html/rfc7231#section-6
     * @link https://www.iana.org/assignments/http-status-codes/http-status-codes.xhtml
     * @param int $code The 3-digit integer status code to set.
     * @param string $reasonPhrase The reason phrase to use with the
     *     provided status code; if none is provided, implementations MAY
     *     use the defaults as suggested in the HTTP specification.
     * @throws \InvalidArgumentException For invalid status code arguments.
     */
    public function with_status(int $code, string $reason_phrase = ''): static
    {
        $new = clone $this;
        $new->_set_status($code, $reason_phrase);
        return $new;
    }
    /**
     * Modifier for response status
     *
     * @param int $code The status code to set.
     * @param string $reasonPhrase The response reason phrase.
     * @throws \InvalidArgumentException For invalid status code arguments.
     */
    protected function _set_status(int $code, string $reason_phrase = ''): void
    {
        if ($code < static::STATUS_CODE_MIN || $code > static::STATUS_CODE_MAX) {
            throw new InvalidArgumentException(sprintf('Invalid status code: %s. Use a valid HTTP status code in range 1xx - 5xx.', $code));
        }
        $this->_status = $code;
        if ($reason_phrase === '' && isset($this->_status_codes[$code])) {
            $reason_phrase = $this->_status_codes[$code];
        }
        $this->_reason_phrase = $reason_phrase;
        // These status codes don't have bodies and can't have content-types.
        if (in_array($code, [304, 204], true)) {
            $this->_clear_header('Content-Type');
        }
    }
    /**
     * Gets the response reason phrase associated with the status code.
     *
     * Because a reason phrase is not a required element in a response
     * status line, the reason phrase value MAY be null. Implementations MAY
     * choose to return the default RFC 7231 recommended reason phrase (or those
     * listed in the IANA HTTP Status Code Registry) for the response's
     * status code.
     *
     * @link https://tools.ietf.org/html/rfc7231#section-6
     * @link https://www.iana.org/assignments/http-status-codes/http-status-codes.xhtml
     * @return string Reason phrase; must return an empty string if none present.
     */
    public function get_reason_phrase(): string
    {
        return $this->_reason_phrase;
    }
    /**
     * Sets a content type definition into the map.
     *
     * E.g.: setTypeMap('xhtml', ['application/xhtml+xml', 'application/xhtml'])
     *
     * This is needed for RequestHandlerComponent and recognition of types.
     *
     * @param string $type Content type.
     * @param array<string>|string $mimeType Definition of the mime type.
     */
    public function set_type_map(string $type, array|string $mime_type): void
    {
        Mime_Type::set_mime_types($type, $mime_type);
    }
    /**
     * Returns the current content type.
     */
    public function get_type(): string
    {
        $header = $this->get_header_line('Content-Type');
        if (str_contains($header, ';')) {
            return explode(';', $header)[0];
        }
        return $header;
    }
    /**
     * Get an updated response with the content type set.
     *
     * If you attempt to set the type on a 304 or 204 status code response, the
     * content type will not take effect as these status codes do not have content-types.
     *
     * @param string $contentType Either a file extension which will be mapped to a mime-type or a concrete mime-type.
     */
    public function with_type(string $content_type): static
    {
        $mapped_type = $this->resolve_type($content_type);
        $new = clone $this;
        $new->_set_content_type($mapped_type);
        return $new;
    }
    /**
     * Translate and validate content-types.
     *
     * @param string $contentType The content-type or type alias.
     * @return string The resolved content-type
     * @throws \InvalidArgumentException When an invalid content-type or alias is used.
     */
    protected function resolve_type(string $content_type): string
    {
        if (str_contains($content_type, '/')) {
            return $content_type;
        }
        $mime_type = Mime_Type::get_mime_type($content_type);
        if ($mime_type === null) {
            throw new InvalidArgumentException(sprintf('`%s` is an invalid content type.', $content_type));
        }
        return $mime_type;
    }
    /**
     * Returns the mime type definition for an alias
     *
     * e.g `getMimeType('pdf'); // returns 'application/pdf'`
     *
     * @param string $alias the content type alias to map
     * @return array|string|false String mapped mime type or false if $alias is not mapped
     */
    public function get_mime_type(string $alias): array|string|false
    {
        $mime_types = Mime_Type::get_mime_types($alias);
        if ($mime_types === null) {
            return false;
        }
        return count($mime_types) === 1 ? $mime_types[0] : $mime_types;
    }
    /**
     * Maps a content-type back to an alias
     *
     * e.g `mapType('application/pdf'); // returns 'pdf'`
     *
     * @param array|string $ctype Either a string content type to map, or an array of types.
     * @return array|string|null Aliases for the types provided.
     */
    public function map_type(array|string $ctype): array|string|null
    {
        if (is_array($ctype)) {
            return array_map($this->map_type(...), $ctype);
        }
        return Mime_Type::get_extension($ctype);
    }
    /**
     * Returns the current charset.
     */
    public function get_charset(): string
    {
        return $this->_charset;
    }
    /**
     * Get a new instance with an updated charset.
     *
     * @param string $charset Character set string.
     */
    public function with_charset(string $charset): static
    {
        $new = clone $this;
        $new->_charset = $charset;
        $new->_set_content_type($this->get_type());
        return $new;
    }
    /**
     * Create a new instance with headers to instruct the client to not cache the response
     */
    public function with_disabled_cache(): static
    {
        return $this->with_header('Expires', 'Mon, 26 Jul 1997 05:00:00 GMT')->with_header('Last-Modified', Cake_Date_Time::parse(time())->to_rfc7231string())->with_header('Cache-Control', 'no-store, no-cache, must-revalidate, post-check=0, pre-check=0');
    }
    /**
     * Create a new instance with the headers to enable client caching.
     *
     * @param string|int $since a valid time since the response text has not been modified
     * @param string|int $time a valid time for cache expiry
     */
    public function with_cache(string|int $since, string|int $time = '+1 day'): static
    {
        if (!is_int($time)) {
            $time = strtotime($time);
            if ($time === false) {
                throw new InvalidArgumentException('Invalid time parameter. Ensure your time value can be parsed by strtotime');
            }
        }
        return $this->with_header('Date', Cake_Date_Time::parse(time())->to_rfc7231string())->with_modified($since)->with_expires($time)->with_sharable(true)->with_max_age($time - time());
    }
    /**
     * Create a new instance with the public/private Cache-Control directive set.
     *
     * @param bool $public If set to true, the Cache-Control header will be set as public
     *   if set to false, the response will be set to private.
     * @param int|null $time time in seconds after which the response should no longer be considered fresh.
     */
    public function with_sharable(bool $public, ?int $time = null): static
    {
        $new = clone $this;
        unset($new->_cache_directives['private'], $new->_cache_directives['public']);
        $key = $public ? 'public' : 'private';
        $new->_cache_directives[$key] = true;
        if ($time !== null) {
            $new->_cache_directives['max-age'] = $time;
        }
        $new->_set_cache_control();
        return $new;
    }
    /**
     * Create a new instance with the Cache-Control s-maxage directive.
     *
     * The max-age is the number of seconds after which the response should no longer be considered
     * a good candidate to be fetched from a shared cache (like in a proxy server).
     *
     * @param int $seconds The number of seconds for shared max-age
     */
    public function with_shared_max_age(int $seconds): static
    {
        $new = clone $this;
        $new->_cache_directives['s-maxage'] = $seconds;
        $new->_set_cache_control();
        return $new;
    }
    /**
     * Create an instance with Cache-Control max-age directive set.
     *
     * The max-age is the number of seconds after which the response should no longer be considered
     * a good candidate to be fetched from the local (client) cache.
     *
     * @param int $seconds The seconds a cached response can be considered valid
     */
    public function with_max_age(int $seconds): static
    {
        $new = clone $this;
        $new->_cache_directives['max-age'] = $seconds;
        $new->_set_cache_control();
        return $new;
    }
    /**
     * Create an instance with Cache-Control must-revalidate directive set.
     *
     * Sets the Cache-Control must-revalidate directive.
     * must-revalidate indicates that the response should not be served
     * stale by a cache under any circumstance without first revalidating
     * with the origin.
     *
     * @param bool $enable If boolean sets or unsets the directive.
     */
    public function with_must_revalidate(bool $enable): static
    {
        $new = clone $this;
        if ($enable) {
            $new->_cache_directives['must-revalidate'] = true;
        } else {
            unset($new->_cache_directives['must-revalidate']);
        }
        $new->_set_cache_control();
        return $new;
    }
    /**
     * Helper method to generate a valid Cache-Control header from the options set
     * in other methods
     */
    protected function _set_cache_control(): void
    {
        $control = '';
        foreach ($this->_cache_directives as $key => $val) {
            $control .= $val === true ? $key : sprintf('%s=%s', $key, $val);
            $control .= ', ';
        }
        $control = rtrim($control, ', ');
        $this->_set_header('Cache-Control', $control);
    }
    /**
     * Create a new instance with the Expires header set.
     *
     * Strings without an explicit time zone will be converted
     * from the default time zone to UTC.
     *
     * ### Examples:
     *
     * ```
     * // Will Expire the response cache now
     * $response->withExpires('now')
     *
     * // Will set the expiration in next 24 hours
     * $response->withExpires(new DateTime('+1 day'))
     * ```
     *
     * @param \DateTimeInterface|string|int|null $time Valid time string or \DateTime instance.
     */
    public function with_expires(DateTimeInterface|string|int|null $time): static
    {
        return $this->with_header('Expires', $this->get_rfc7231($time));
    }
    /**
     * Create a new instance with the Last-Modified header set.
     *
     * Strings without an explicit time zone will be converted
     * from the default time zone to UTC.
     *
     * ### Examples:
     *
     * ```
     * // Will Expire the response cache now
     * $response->withModified('now')
     *
     * // Will set the expiration in next 24 hours
     * $response->withModified(new DateTime('+1 day'))
     * ```
     *
     * @param \DateTimeInterface|string|int $time Valid time string or \DateTime instance.
     */
    public function with_modified(DateTimeInterface|string|int $time): static
    {
        return $this->with_header('Last-Modified', $this->get_rfc7231($time));
    }
    /**
     * Create a new instance as 'not modified'
     *
     * This will remove any body contents set the status code
     * to "304" and removing headers that describe
     * a response body.
     */
    public function with_not_modified(): static
    {
        $new = $this->with_status(304);
        $new->_create_stream();
        $remove = ['Allow', 'Content-Encoding', 'Content-Language', 'Content-Length', 'Content-MD5', 'Content-Type', 'Last-Modified'];
        foreach ($remove as $header) {
            $new = $new->without_header($header);
        }
        return $new;
    }
    /**
     * Create a new instance with the Vary header set.
     *
     * If an array is passed values will be imploded into a comma
     * separated string. If no parameters are passed, then an
     * array with the current Vary header value is returned
     *
     * @param array<string>|string $cacheVariances A single Vary string or an array
     *   containing the list for variances.
     */
    public function with_vary(array|string $cache_variances): static
    {
        return $this->with_header('Vary', (array) $cache_variances);
    }
    /**
     * Create a new instance with the Etag header set.
     *
     * Etags are a strong indicative that a response can be cached by a
     * HTTP client. A bad way of generating Etags is creating a hash of
     * the response output, instead generate a unique hash of the
     * unique components that identifies a request, such as a
     * modification time, a resource Id, and anything else you consider it
     * that makes the response unique.
     *
     * The second parameter is used to inform clients that the content has
     * changed, but semantically it is equivalent to existing cached values. Consider
     * a page with a hit counter, two different page views are equivalent, but
     * they differ by a few bytes. This permits the Client to decide whether they should
     * use the cached data.
     *
     * @param string $hash The unique hash that identifies this response
     * @param bool $weak Whether the response is semantically the same as
     *   other with the same hash or not. Defaults to false
     */
    public function with_etag(string $hash, bool $weak = false): static
    {
        $hash = sprintf('%s"%s"', $weak ? 'W/' : '', $hash);
        return $this->with_header('Etag', $hash);
    }
    /**
     * Returns a DateTime object initialized at the $time param and using UTC
     * as timezone
     *
     * @param \DateTimeInterface|string|int|null $time Valid time string or \DateTimeInterface instance.
     */
    protected function _get_utc_date(DateTimeInterface|string|int|null $time = null): DateTimeInterface
    {
        if ($time instanceof DateTimeInterface) {
            $result = clone $time;
        } elseif (is_int($time)) {
            $result = new DateTime(date('Y-m-d H:i:s', $time));
        } else {
            $result = new DateTime($time ?? 'now');
        }
        /** @phpstan-ignore-next-line */
        return $result->set_timezone(new DateTimeZone('UTC'));
    }
    /**
     * Converts the time zone to GMT and returns a string in RFC7231 format.
     * This replaced the deprecated and broken ``DATE_RFC7231`` formatting constant.
     */
    protected function get_rfc7231(DateTimeInterface|string|int|null $time = null): string
    {
        return $this->_get_utc_date($time)->format('D, d M Y H:i:s \G\M\T');
    }
    /**
     * Sets the correct output buffering handler to send a compressed response. Responses will
     * be compressed with zlib, if the extension is available.
     *
     * @return bool false if client does not accept compressed responses or no handler is available, true otherwise
     */
    public function compress(): bool
    {
        return ini_get('zlib.output_compression') !== '1' && extension_loaded('zlib') && str_contains((string) env('HTTP_ACCEPT_ENCODING'), 'gzip') && ob_start('ob_gzhandler');
    }
    /**
     * Returns whether the resulting output will be compressed by PHP
     */
    public function output_compressed(): bool
    {
        return str_contains((string) env('HTTP_ACCEPT_ENCODING'), 'gzip') && (ini_get('zlib.output_compression') === '1' || in_array('ob_gzhandler', ob_list_handlers(), true));
    }
    /**
     * Create a new instance with the Content-Disposition header set.
     *
     * @param string $filename The name of the file as the browser will download the response
     */
    public function with_download(string $filename): static
    {
        return $this->with_header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }
    /**
     * Create a new response with the Content-Length header set.
     *
     * @param string|int $bytes Number of bytes
     */
    public function with_length(string|int $bytes): static
    {
        return $this->with_header('Content-Length', (string) $bytes);
    }
    /**
     * Create a new response with the Link header set.
     *
     * ### Examples
     *
     * ```
     * $response = $response->withAddedLink('http://example.com?page=1', ['rel' => 'prev'])
     *     ->withAddedLink('http://example.com?page=3', ['rel' => 'next']);
     * ```
     *
     * Will generate:
     *
     * ```
     * Link: <http://example.com?page=1>; rel="prev"
     * Link: <http://example.com?page=3>; rel="next"
     * ```
     *
     * @param string $url The LinkHeader url.
     * @param array<string, mixed> $options The LinkHeader params.
     * @since 3.6.0
     */
    public function with_added_link(string $url, array $options = []): static
    {
        $params = [];
        foreach ($options as $key => $option) {
            $params[] = $key . '="' . $option . '"';
        }
        $param = '';
        if ($params) {
            $param = '; ' . implode('; ', $params);
        }
        return $this->with_added_header('Link', '<' . $url . '>' . $param);
    }
    /**
     * Checks whether a response has not been modified according to the 'If-None-Match'
     * (Etags) and 'If-Modified-Since' (last modification date) request
     * headers.
     *
     * In order to interact with this method you must mark responses as not modified.
     * You need to set at least one of the `Last-Modified` or `Etag` response headers
     * before calling this method. Otherwise, a comparison will not be possible.
     *
     * @param \Cake\Http\ServerRequest $request Request object
     * @return bool Whether the response is 'modified' based on cache headers.
     */
    public function is_not_modified(Server_Request $request): bool
    {
        $etags = preg_split('/\s*,\s*/', $request->get_header_line('If-None-Match'), 0, PREG_SPLIT_NO_EMPTY) ?: [];
        $response_tag = $this->get_header_line('Etag');
        $etag_matches = null;
        if ($response_tag) {
            $etag_matches = in_array('*', $etags, true) || in_array($response_tag, $etags, true);
        }
        $modified_since = $request->get_header_line('If-Modified-Since');
        $time_matches = null;
        if ($modified_since && $this->has_header('Last-Modified')) {
            $time_matches = strtotime($this->get_header_line('Last-Modified')) === strtotime($modified_since);
        }
        if ($etag_matches === null && $time_matches === null) {
            return false;
        }
        return $etag_matches !== false && $time_matches !== false;
    }
    /**
     * String conversion. Fetches the response body as a string.
     * Does *not* send headers.
     * If body is a callable, a blank string is returned.
     */
    public function __toString(): string
    {
        $this->stream->rewind();
        return (string) $this->stream->get_contents();
    }
    /**
     * Create a new response with a cookie set.
     *
     * ### Example
     *
     * ```
     * // add a cookie object
     * $response = $response->withCookie(new Cookie('remember_me', 1));
     * ```
     *
     * @param \Cake\Http\Cookie\CookieInterface $cookie cookie object
     */
    public function with_cookie(Cookie_Interface $cookie): static
    {
        $new = clone $this;
        $new->_cookies = $new->_cookies->add($cookie);
        return $new;
    }
    /**
     * Create a new response with an expired cookie set.
     *
     * ### Example
     *
     * ```
     * // add a cookie object
     * $response = $response->withExpiredCookie(new Cookie('remember_me'));
     * ```
     *
     * @param \Cake\Http\Cookie\CookieInterface $cookie cookie object
     */
    public function with_expired_cookie(Cookie_Interface $cookie): static
    {
        $cookie = $cookie->with_expired();
        $new = clone $this;
        $new->_cookies = $new->_cookies->add($cookie);
        return $new;
    }
    /**
     * Read a single cookie from the response.
     *
     * This method provides read access to pending cookies. It will
     * not read the `Set-Cookie` header if set.
     *
     * @param string $name The cookie name you want to read.
     * @return array|null Either the cookie data or null
     */
    public function get_cookie(string $name): ?array
    {
        if (!$this->_cookies->has($name)) {
            return null;
        }
        return $this->_cookies->get($name)->to_array();
    }
    /**
     * Get all cookies in the response.
     *
     * Returns an associative array of cookie name => cookie data.
     *
     * @return array<string, array>
     */
    public function get_cookies(): array
    {
        $out = [];
        foreach ($this->_cookies as $cookie) {
            $out[$cookie->get_name()] = $cookie->to_array();
        }
        return $out;
    }
    /**
     * Get the CookieCollection from the response
     */
    public function get_cookie_collection(): Cookie_Collection
    {
        return $this->_cookies;
    }
    /**
     * Get a new instance with provided cookie collection.
     *
     * @param \Cake\Http\Cookie\CookieCollection $cookieCollection Cookie collection to set.
     */
    public function with_cookie_collection(Cookie_Collection $cookie_collection): static
    {
        $new = clone $this;
        $new->_cookies = $cookie_collection;
        return $new;
    }
    /**
     * Get a CorsBuilder instance for defining CORS headers.
     *
     * @param \Cake\Http\ServerRequest $request Request object
     * @return \Cake\Http\CorsBuilder A builder object that provides a fluent interface for defining
     *   additional CORS headers.
     */
    public function cors(Server_Request $request): Cors_Builder
    {
        $origin = $request->get_header_line('Origin');
        $https = $request->is('https');
        return new Cors_Builder($this, $origin, $https);
    }
    /**
     * Create a new instance that is based on a file.
     *
     * This method will augment both the body and a number of related headers.
     *
     * If `$_SERVER['HTTP_RANGE']` is set, a slice of the file will be
     * returned instead of the entire file.
     *
     * ### Options keys
     *
     * - name: Alternate download name
     * - download: If `true` sets download header and forces file to
     *   be downloaded rather than displayed inline.
     *
     * @param string $path Absolute path to file.
     * @param array<string, mixed> $options Options See above.
     * @throws \Cake\Http\Exception\NotFoundException
     */
    public function with_file(string $path, array $options = []): static
    {
        $file = $this->validate_file($path);
        $options += ['name' => null, 'download' => null];
        $extension = $file->get_extension();
        $mapped = Mime_Type::get_mime_type_for_file($file->get_real_path());
        if ($extension === '' && $options['download'] === null) {
            $options['download'] = true;
        }
        $new = clone $this;
        if ($mapped) {
            $new = $new->with_type($mapped);
        }
        $file_size = $file->get_size();
        if ($options['download']) {
            $name = $options['name'] ?: $file->get_file_name();
            $new = $new->with_download($name)->with_header('Content-Transfer-Encoding', 'binary');
        }
        $new = $new->with_header('Accept-Ranges', 'bytes');
        $http_range = (string) env('HTTP_RANGE');
        if ($http_range) {
            $new->_file_range($file, $http_range);
        } else {
            $new = $new->with_header('Content-Length', (string) $file_size);
        }
        $new->_file = $file;
        $new->stream = new Stream($file->get_pathname(), 'rb');
        return $new;
    }
    /**
     * Convenience method to set a string into the response body
     *
     * @param string|null $string The string to be sent
     */
    public function with_string_body(?string $string): static
    {
        $new = clone $this;
        $new->_create_stream();
        $new->stream->write((string) $string);
        return $new;
    }
    /**
     * Validate a file path is a valid response body.
     *
     * @param string $path The path to the file.
     * @throws \Cake\Http\Exception\NotFoundException
     */
    protected function validate_file(string $path): Spl_File_Info
    {
        if (str_contains($path, '../') || str_contains($path, '..\\')) {
            throw new Not_Found_Exception(__d('cake', 'The requested file contains `..` and will not be read.'));
        }
        $file = new Spl_File_Info($path);
        if (!$file->is_file() || !$file->is_readable()) {
            if (Configure::read('debug')) {
                throw new Not_Found_Exception(sprintf('The requested file %s was not found or not readable', $path));
            }
            throw new Not_Found_Exception(__d('cake', 'The requested file was not found'));
        }
        return $file;
    }
    /**
     * Get the current file if one exists.
     *
     * @return \SplFileInfo|null The file to use in the response or null
     */
    public function get_file(): ?Spl_File_Info
    {
        return $this->_file;
    }
    /**
     * Apply a file range to a file and set the end offset.
     *
     * If an invalid range is requested a 416 Status code will be used
     * in the response.
     *
     * @param \SplFileInfo $file The file to set a range on.
     * @param string $httpRange The range to use.
     */
    protected function _file_range(Spl_File_Info $file, string $http_range): void
    {
        $file_size = $file->get_size();
        $last_byte = $file_size - 1;
        $start = 0;
        $end = $last_byte;
        preg_match('/^bytes\s*=\s*(\d+)?\s*-\s*(\d+)?$/', $http_range, $matches);
        if ($matches) {
            $start = $matches[1];
            $end = $matches[2] ?? '';
        }
        if ($start === '') {
            $start = $file_size - (int) $end;
            $end = $last_byte;
        }
        if ($end === '') {
            $end = $last_byte;
        }
        if ($start > $end || $end > $last_byte || $start > $last_byte) {
            $this->_set_status(416);
            $this->_set_header('Content-Range', 'bytes 0-' . $last_byte . '/' . $file_size);
            return;
        }
        $this->_set_header('Content-Length', (string) ((int) $end - (int) $start + 1));
        $this->_set_header('Content-Range', 'bytes ' . $start . '-' . $end . '/' . $file_size);
        $this->_set_status(206);
        /**
         * @var int $start
         * @var int $end
         */
        $this->_file_range = [$start, $end];
    }
    /**
     * Returns an array that can be used to describe the internal state of this
     * object.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['status' => $this->_status, 'contentType' => $this->get_type(), 'headers' => $this->headers, 'file' => $this->_file, 'fileRange' => $this->_file_range, 'cookies' => $this->_cookies, 'cacheDirectives' => $this->_cache_directives, 'body' => (string) $this->get_body()];
    }
}