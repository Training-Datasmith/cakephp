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
namespace Cake\Http\Client\Adapter;

use Cake\Http\Client\Adapter_Interface;
use Cake\Http\Client\Exception\Client_Exception;
use Cake\Http\Client\Exception\Network_Exception;
use Cake\Http\Client\Exception\Request_Exception;
use Cake\Http\Client\Response;
use Composer\Ca_Bundle\Ca_Bundle;
use Psr\Http\Message\Request_Interface;
/**
 * Implements sending Cake\Http\Client\Request
 * via php's stream API.
 *
 * This approach and implementation is partly inspired by Aura.Http
 */
class Stream implements Adapter_Interface
{
    /**
     * Context resource used by the stream API.
     *
     * @var resource|null
     */
    protected $_context;
    /**
     * Array of options/content for the HTTP stream context.
     *
     * @var array<string, mixed>
     */
    protected array $_context_options = [];
    /**
     * Array of options/content for the SSL stream context.
     *
     * @var array<string, mixed>
     */
    protected array $_ssl_context_options = [];
    /**
     * The stream resource.
     *
     * @var resource|null
     */
    protected $_stream;
    /**
     * Connection error list.
     */
    protected array $_connection_errors = [];
    /**
     * @inheritDoc
     */
    public function send(Request_Interface $request, array $options): array
    {
        $this->_stream = null;
        $this->_context = null;
        $this->_context_options = [];
        $this->_ssl_context_options = [];
        $this->_connection_errors = [];
        $this->_build_context($request, $options);
        return $this->_send($request);
    }
    /**
     * Create the response list based on the headers & content
     *
     * Creates one or many response objects based on the number
     * of redirects that occurred.
     *
     * @param list<string> $headers The list of headers from the request(s)
     * @param string $content The response content.
     * @return array<\Cake\Http\Client\Response> The list of responses from the request(s)
     */
    public function create_responses(array $headers, string $content): array
    {
        $indexes = [];
        $responses = [];
        foreach ($headers as $i => $header) {
            if (strtoupper(substr($header, 0, 5)) === 'HTTP/') {
                $indexes[] = $i;
            }
        }
        $last = count($indexes) - 1;
        foreach ($indexes as $i => $start) {
            $end = isset($indexes[$i + 1]) ? $indexes[$i + 1] - $start : null;
            $header_slice = array_slice($headers, $start, $end);
            $body = $i === $last ? $content : '';
            $responses[] = $this->_build_response($header_slice, $body);
        }
        return $responses;
    }
    /**
     * Build the stream context out of the request object.
     *
     * @param \Psr\Http\Message\RequestInterface $request The request to build context from.
     * @param array<string, mixed> $options Additional request options.
     */
    protected function _build_context(Request_Interface $request, array $options): void
    {
        $this->_build_content($request, $options);
        $this->_build_headers($request, $options);
        $this->_build_options($request, $options);
        $url = $request->get_uri();
        $scheme = parse_url((string) $url, PHP_URL_SCHEME);
        if ($scheme === 'https') {
            $this->_build_ssl_context($request, $options);
        }
        $this->_context = stream_context_create(['http' => $this->_context_options, 'ssl' => $this->_ssl_context_options]);
    }
    /**
     * Build the header context for the request.
     *
     * Creates cookies & headers.
     *
     * @param \Psr\Http\Message\RequestInterface $request The request being sent.
     * @param array<string, mixed> $options Array of options to use.
     */
    protected function _build_headers(Request_Interface $request, array $options): void
    {
        $headers = [];
        foreach ($request->get_headers() as $name => $values) {
            $headers[] = sprintf('%s: %s', $name, implode(', ', $values));
        }
        $this->_context_options['header'] = implode("\r\n", $headers);
    }
    /**
     * Builds the request content based on the request object.
     *
     * If the $request->body() is a string, it will be used as is.
     * Array data will be processed with {@link \Cake\Http\Client\FormData}
     *
     * @param \Psr\Http\Message\RequestInterface $request The request being sent.
     * @param array<string, mixed> $options Array of options to use.
     */
    protected function _build_content(Request_Interface $request, array $options): void
    {
        $body = $request->get_body();
        $body->rewind();
        $this->_context_options['content'] = $body->get_contents();
    }
    /**
     * Build miscellaneous options for the request.
     *
     * @param \Psr\Http\Message\RequestInterface $request The request being sent.
     * @param array<string, mixed> $options Array of options to use.
     */
    protected function _build_options(Request_Interface $request, array $options): void
    {
        $this->_context_options['method'] = $request->get_method();
        $this->_context_options['protocol_version'] = $request->get_protocol_version();
        $this->_context_options['ignore_errors'] = true;
        if (isset($options['timeout'])) {
            $this->_context_options['timeout'] = $options['timeout'];
        }
        // Redirects are handled in the client layer because of cookie handling issues.
        $this->_context_options['max_redirects'] = 0;
        if (isset($options['proxy']['proxy'])) {
            $this->_context_options['request_fulluri'] = true;
            $this->_context_options['proxy'] = $options['proxy']['proxy'];
        }
    }
    /**
     * Build SSL options for the request.
     *
     * @param \Psr\Http\Message\RequestInterface $request The request being sent.
     * @param array<string, mixed> $options Array of options to use.
     */
    protected function _build_ssl_context(Request_Interface $request, array $options): void
    {
        $ssl_options = ['ssl_verify_peer', 'ssl_verify_peer_name', 'ssl_verify_depth', 'ssl_allow_self_signed', 'ssl_cafile', 'ssl_local_cert', 'ssl_local_pk', 'ssl_passphrase'];
        if (empty($options['ssl_cafile'])) {
            $options['ssl_cafile'] = Ca_Bundle::get_bundled_ca_bundle_path();
        }
        if (!empty($options['ssl_verify_host'])) {
            $url = $request->get_uri();
            $host = parse_url((string) $url, PHP_URL_HOST);
            $this->_ssl_context_options['peer_name'] = $host;
        }
        foreach ($ssl_options as $key) {
            if (isset($options[$key])) {
                $name = substr($key, 4);
                $this->_ssl_context_options[$name] = $options[$key];
            }
        }
    }
    /**
     * Open the stream and send the request.
     *
     * @param \Psr\Http\Message\RequestInterface $request The request object.
     * @return array Array of populated Response objects
     * @throws \Psr\Http\Client\NetworkExceptionInterface
     */
    protected function _send(Request_Interface $request): array
    {
        $deadline = false;
        if (isset($this->_context_options['timeout']) && $this->_context_options['timeout'] > 0) {
            /** @var int $deadline */
            $deadline = time() + $this->_context_options['timeout'];
        }
        $url = $request->get_uri();
        $this->_open((string) $url, $request);
        $content = '';
        $timed_out = false;
        assert($this->_stream !== null, 'HTTP stream failed to open');
        while (!feof($this->_stream)) {
            if ($deadline !== false) {
                stream_set_timeout($this->_stream, max($deadline - time(), 1));
            }
            $content .= fread($this->_stream, 8192);
            $meta = stream_get_meta_data($this->_stream);
            if ($meta['timed_out'] || $deadline !== false && time() > $deadline) {
                $timed_out = true;
                break;
            }
        }
        $meta = stream_get_meta_data($this->_stream);
        fclose($this->_stream);
        if ($timed_out) {
            throw new Network_Exception('Connection timed out ' . $url, $request);
        }
        $headers = $meta['wrapper_data'];
        if (isset($headers['headers']) && is_array($headers['headers'])) {
            $headers = $headers['headers'];
        }
        return $this->create_responses($headers, $content);
    }
    /**
     * Build a response object
     *
     * @param array<string> $headers Unparsed headers.
     * @param string $body The response body.
     */
    protected function _build_response(array $headers, string $body): Response
    {
        return new Response($headers, $body);
    }
    /**
     * Open the socket and handle any connection errors.
     *
     * @param string $url The url to connect to.
     * @param \Psr\Http\Message\RequestInterface $request The request object.
     * @throws \Psr\Http\Client\RequestExceptionInterface
     */
    protected function _open(string $url, Request_Interface $request): void
    {
        if (!(bool) ini_get('allow_url_fopen')) {
            throw new Client_Exception('The PHP directive `allow_url_fopen` must be enabled.');
        }
        set_error_handler(function ($code, $message): bool {
            $this->_connection_errors[] = $message;
            return true;
        });
        try {
            $stream = fopen($url, 'rb', false, $this->_context);
            if ($stream === false) {
                $stream = null;
            }
            $this->_stream = $stream;
        } finally {
            restore_error_handler();
        }
        if (!$this->_stream || $this->_connection_errors) {
            throw new Request_Exception(implode("\n", $this->_connection_errors), $request);
        }
    }
    /**
     * Get the context options
     *
     * Useful for debugging and testing context creation.
     *
     * @return array<string, mixed>
     */
    public function context_options(): array
    {
        return array_merge($this->_context_options, $this->_ssl_context_options);
    }
}