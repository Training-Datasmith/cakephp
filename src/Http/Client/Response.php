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
 * @since         3.0.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Http\Client;

use Cake\Core\Exception\Cake_Exception;
use Cake\Http\Cookie\Cookie_Collection;
use Laminas\Diactoros\Message_Trait;
use Laminas\Diactoros\Stream;
use Psr\Http\Message\Response_Interface;
use Simple_Xml_Element;
/**
 * Implements methods for HTTP responses.
 *
 * All the following examples assume that `$response` is an
 * instance of this class.
 *
 * ### Get header values
 *
 * Header names are case-insensitive, but normalized to Title-Case
 * when the response is parsed.
 *
 * ```
 * $val = $response->getHeaderLine('content-type');
 * ```
 *
 * Will read the Content-Type header. You can get all set
 * headers using:
 *
 * ```
 * $response->getHeaders();
 * ```
 *
 * ### Get the response body
 *
 * You can access the response body stream using:
 *
 * ```
 * $content = $response->getBody();
 * ```
 *
 * You can get the body string using:
 *
 * ```
 * $content = $response->getStringBody();
 * ```
 *
 * If your response body is in XML or JSON you can use
 * special content type specific accessors to read the decoded data.
 * JSON data will be returned as arrays, while XML data will be returned
 * as SimpleXML nodes:
 *
 * ```
 * // Get as XML
 * $content = $response->getXml()
 * // Get as JSON
 * $content = $response->getJson()
 * ```
 *
 * If the response cannot be decoded, null will be returned.
 *
 * ### Check the status code
 *
 * You can access the response status code using:
 *
 * ```
 * $content = $response->getStatusCode();
 * ```
 */
class Response extends Message implements Response_Interface
{
    use Message_Trait;
    /**
     * The status code of the response.
     */
    protected int $code = 0;
    /**
     * Cookie Collection instance
     */
    protected ?Cookie_Collection $cookies = null;
    /**
     * The reason phrase for the status code
     */
    protected string $reason_phrase;
    /**
     * Cached decoded XML data.
     */
    protected ?Simple_Xml_Element $_xml = null;
    /**
     * Cached decoded JSON data.
     */
    protected mixed $_json = null;
    /**
     * Constructor
     *
     * @param array<string> $headers Unparsed headers.
     * @param string $body The response body.
     */
    public function __construct(array $headers = [], string $body = '')
    {
        $this->_parse_headers($headers);
        if ($this->get_header_line('Content-Encoding') === 'gzip') {
            $body = $this->_decode_gzip_body($body);
        }
        $stream = new Stream('php://memory', 'wb+');
        $stream->write($body);
        $stream->rewind();
        $this->stream = $stream;
    }
    /**
     * Uncompress a gzip response.
     *
     * Looks for gzip signatures, and if gzinflate() exists,
     * the body will be decompressed.
     *
     * @param string $body Gzip encoded body.
     * @throws \Cake\Core\Exception\CakeException When attempting to decode gzip content without gzinflate.
     */
    protected function _decode_gzip_body(string $body): string
    {
        if (!function_exists('gzinflate')) {
            throw new Cake_Exception('Cannot decompress gzip response body without gzinflate()');
        }
        $offset = 0;
        // Look for gzip 'signature'
        if (str_starts_with($body, "\x1f\x8b")) {
            $offset = 2;
        }
        // Check the format byte
        if (substr($body, $offset, 1) === "\x08") {
            return (string) gzinflate(substr($body, $offset + 8));
        }
        throw new Cake_Exception('Invalid gzip response');
    }
    /**
     * Parses headers if necessary.
     *
     * - Decodes the status code and reason phrase.
     * - Parses and normalizes header names and values.
     *
     * @param array<string> $headers Headers to parse.
     */
    protected function _parse_headers(array $headers): void
    {
        foreach ($headers as $value) {
            if (str_starts_with($value, 'HTTP/')) {
                preg_match('/HTTP\/([\d.]+) ([0-9]+)(.*)/i', $value, $matches);
                $this->protocol = $matches[1];
                $this->code = (int) $matches[2];
                $this->reason_phrase = trim($matches[3]);
                continue;
            }
            if (!str_contains($value, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $value, 2);
            $value = trim($value);
            /** @var non-empty-string $name */
            $name = trim($name);
            $normalized = strtolower($name);
            if (isset($this->headers[$name])) {
                $this->headers[$name][] = $value;
            } else {
                $this->headers[$name] = (array) $value;
                $this->header_names[$normalized] = $name;
            }
        }
    }
    /**
     * Check if the response status code was in the 2xx/3xx range
     */
    public function is_ok(): bool
    {
        return $this->code >= 200 && $this->code <= 399;
    }
    /**
     * Check if the response status code was in the 2xx range
     */
    public function is_success(): bool
    {
        return $this->code >= 200 && $this->code <= 299;
    }
    /**
     * Check if the response had a redirect status code.
     */
    public function is_redirect(): bool
    {
        $codes = [static::STATUS_MOVED_PERMANENTLY, static::STATUS_FOUND, static::STATUS_SEE_OTHER, static::STATUS_TEMPORARY_REDIRECT, static::STATUS_PERMANENT_REDIRECT];
        return in_array($this->code, $codes, true) && $this->get_header_line('Location');
    }
    /**
     * {@inheritDoc}
     *
     * @return int The status code.
     */
    public function get_status_code(): int
    {
        return $this->code;
    }
    /**
     * {@inheritDoc}
     *
     * @param int $code The status code to set.
     * @param string $reasonPhrase The status reason phrase.
     * @return static A copy of the current object with an updated status code.
     */
    public function with_status(int $code, string $reason_phrase = ''): static
    {
        $new = clone $this;
        $new->code = $code;
        $new->reason_phrase = $reason_phrase;
        return $new;
    }
    /**
     * {@inheritDoc}
     *
     * @return string The current reason phrase.
     */
    public function get_reason_phrase(): string
    {
        return $this->reason_phrase;
    }
    /**
     * Get the encoding if it was set.
     */
    public function get_encoding(): ?string
    {
        $content = $this->get_header_line('content-type');
        if (!$content) {
            return null;
        }
        preg_match('/charset\s?=\s?[\'"]?([a-z0-9-_]+)[\'"]?/i', $content, $matches);
        if (empty($matches[1])) {
            return null;
        }
        return $matches[1];
    }
    /**
     * Get the all cookie data.
     *
     * @return array The cookie data
     */
    public function get_cookies(): array
    {
        return $this->_get_cookies();
    }
    /**
     * Get the cookie collection from this response.
     *
     * This method exposes the response's CookieCollection
     * instance allowing you to interact with cookie objects directly.
     */
    public function get_cookie_collection(): Cookie_Collection
    {
        return $this->build_cookie_collection();
    }
    /**
     * Get the value of a single cookie.
     *
     * @param string $name The name of the cookie value.
     * @return array|string|null Either the cookie's value or null when the cookie is undefined.
     */
    public function get_cookie(string $name): array|string|null
    {
        $cookies = $this->build_cookie_collection();
        if (!$cookies->has($name)) {
            return null;
        }
        return $cookies->get($name)->get_value();
    }
    /**
     * Get the full data for a single cookie.
     *
     * @param string $name The name of the cookie value.
     * @return array|null Either the cookie's data or null when the cookie is undefined.
     */
    public function get_cookie_data(string $name): ?array
    {
        $cookies = $this->build_cookie_collection();
        if (!$cookies->has($name)) {
            return null;
        }
        return $cookies->get($name)->to_array();
    }
    /**
     * Lazily build the CookieCollection and cookie objects from the response header
     */
    protected function build_cookie_collection(): Cookie_Collection
    {
        $this->cookies ??= Cookie_Collection::create_from_header($this->get_header('Set-Cookie'));
        return $this->cookies;
    }
    /**
     * Property accessor for `$this->cookies`
     *
     * @return array Array of Cookie data.
     */
    protected function _get_cookies(): array
    {
        $out = [];
        foreach ($this->build_cookie_collection() as $cookie) {
            $out[$cookie->get_name()] = $cookie->to_array();
        }
        return $out;
    }
    /**
     * Get the response body as string.
     */
    public function get_string_body(): string
    {
        return $this->_get_body();
    }
    /**
     * Get the response body as JSON decoded data.
     */
    public function get_json(): mixed
    {
        return $this->_get_json();
    }
    /**
     * Get the response body as JSON decoded data.
     */
    protected function _get_json(): mixed
    {
        if ($this->_json) {
            return $this->_json;
        }
        return $this->_json = json_decode($this->_get_body(), true);
    }
    /**
     * Get the response body as XML decoded data.
     */
    public function get_xml(): ?Simple_Xml_Element
    {
        return $this->_get_xml();
    }
    /**
     * Get the response body as XML decoded data.
     */
    protected function _get_xml(): ?Simple_Xml_Element
    {
        if ($this->_xml !== null) {
            return $this->_xml;
        }
        libxml_use_internal_errors();
        $data = simplexml_load_string($this->_get_body());
        if (!$data) {
            return null;
        }
        $this->_xml = $data;
        return $this->_xml;
    }
    /**
     * Provides magic __get() support.
     *
     * @return array<string>
     */
    protected function _get_headers(): array
    {
        $out = [];
        foreach ($this->headers as $key => $values) {
            $out[$key] = implode(',', $values);
        }
        return $out;
    }
    /**
     * Provides magic __get() support.
     */
    protected function _get_body(): string
    {
        $this->stream->rewind();
        return $this->stream->get_contents();
    }
}