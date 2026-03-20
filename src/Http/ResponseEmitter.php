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
 * @since         3.3.5
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 *
 * Parts of this file are derived from Zend-Diactoros
 * @copyright Copyright (c) 2015-2016 Zend Technologies USA Inc. (https://www.zend.com/)
 * @license   https://github.com/zendframework/zend-diactoros/blob/master/LICENSE.md New BSD License
 */
namespace Cake\Http;

use Cake\Http\Cookie\Cookie;
use Cake\Http\Cookie\Cookie_Interface;
use Laminas\Diactoros\Relative_Stream;
use Psr\Http\Message\Response_Interface;
/**
 * Emits a Response to the PHP Server API.
 */
class Response_Emitter
{
    /**
     * Constructor
     *
     * @param int $maxBufferLength Maximum output buffering size for each iteration.
     */
    public function __construct(
        /**
         * Maximum output buffering size for each iteration.
         */
        protected int $max_buffer_length = 8192
    )
    {
    }
    /**
     * Emit a response.
     *
     * Emits a response, including status line, headers, and the message body,
     * according to the environment.
     *
     * @param \Psr\Http\Message\ResponseInterface $response The response to emit.
     */
    public function emit(Response_Interface $response): bool
    {
        $file = '';
        $line = 0;
        if (headers_sent($file, $line)) {
            $message = "Unable to emit headers. Headers sent in file={$file} line={$line}";
            trigger_error($message, E_USER_WARNING);
        }
        $this->emit_status_line($response);
        $this->emit_headers($response);
        $range = $this->parse_content_range($response->get_header_line('Content-Range'));
        if (is_array($range)) {
            $this->emit_body_range($range, $response);
        } else {
            $this->emit_body($response);
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        return true;
    }
    /**
     * Emit the message body.
     *
     * @param \Psr\Http\Message\ResponseInterface $response The response to emit
     */
    protected function emit_body(Response_Interface $response): void
    {
        if (in_array($response->get_status_code(), [204, 304], true)) {
            return;
        }
        $body = $response->get_body();
        if (!$body->is_seekable()) {
            echo $body;
            return;
        }
        $body->rewind();
        while (!$body->eof()) {
            echo $body->read($this->max_buffer_length);
        }
    }
    /**
     * Emit a range of the message body.
     *
     * @param array $range The range data to emit
     * @param \Psr\Http\Message\ResponseInterface $response The response to emit
     */
    protected function emit_body_range(array $range, Response_Interface $response): void
    {
        [, $first, $last] = $range;
        $body = $response->get_body();
        if (!$body->is_seekable()) {
            $contents = $body->get_contents();
            echo substr($contents, $first, $last - $first + 1);
            return;
        }
        $body = new Relative_Stream($body, $first);
        $body->rewind();
        $pos = 0;
        /** @var int $length */
        $length = $last - $first + 1;
        while (!$body->eof() && $pos < $length) {
            if ($pos + $this->max_buffer_length > $length) {
                echo $body->read($length - $pos);
                break;
            }
            echo $body->read($this->max_buffer_length);
            $pos = $body->tell();
        }
    }
    /**
     * Emit the status line.
     *
     * Emits the status line using the protocol version and status code from
     * the response; if a reason phrase is available, it, too, is emitted.
     *
     * @param \Psr\Http\Message\ResponseInterface $response The response to emit
     */
    protected function emit_status_line(Response_Interface $response): void
    {
        $reason_phrase = $response->get_reason_phrase();
        header(sprintf('HTTP/%s %d%s', $response->get_protocol_version(), $response->get_status_code(), $reason_phrase ? ' ' . $reason_phrase : ''));
    }
    /**
     * Emit response headers.
     *
     * Loops through each header, emitting each; if the header value
     * is an array with multiple values, ensures that each is sent
     * in such a way as to create aggregate headers (instead of replace
     * the previous).
     *
     * @param \Psr\Http\Message\ResponseInterface $response The response to emit
     */
    protected function emit_headers(Response_Interface $response): void
    {
        $cookies = [];
        if ($response instanceof Response) {
            $cookies = iterator_to_array($response->get_cookie_collection());
        }
        foreach ($response->get_headers() as $name => $values) {
            if (strtolower($name) === 'set-cookie') {
                $cookies = array_merge($cookies, $values);
                continue;
            }
            $first = true;
            foreach ($values as $value) {
                header(sprintf('%s: %s', $name, $value), $first);
                $first = false;
            }
        }
        $this->emit_cookies($cookies);
    }
    /**
     * Emit cookies using setcookie()
     *
     * @param array<\Cake\Http\Cookie\CookieInterface|string> $cookies An array of cookies.
     */
    protected function emit_cookies(array $cookies): void
    {
        foreach ($cookies as $cookie) {
            $this->set_cookie($cookie);
        }
    }
    /**
     * Helper methods to set cookie.
     *
     * @param \Cake\Http\Cookie\CookieInterface|string $cookie Cookie.
     */
    protected function set_cookie(Cookie_Interface|string $cookie): bool
    {
        if (is_string($cookie)) {
            $cookie = Cookie::create_from_header_string($cookie, ['path' => '']);
        }
        return setcookie($cookie->get_name(), $cookie->get_scalar_value(), $cookie->get_options());
    }
    /**
     * Parse content-range header
     * https://www.w3.org/Protocols/rfc2616/rfc2616-sec14.html#sec14.16
     *
     * @param string $header The Content-Range header to parse.
     * @return array|false [unit, first, last, length]; returns false if no
     *     content range or an invalid content range is provided
     */
    protected function parse_content_range(string $header): array|false
    {
        if (preg_match('/(?P<unit>[\w]+)\s+(?P<first>\d+)-(?P<last>\d+)\/(?P<length>\d+|\*)/', $header, $matches)) {
            return [$matches['unit'], (int) $matches['first'], (int) $matches['last'], $matches['length'] === '*' ? '*' : (int) $matches['length']];
        }
        return false;
    }
}