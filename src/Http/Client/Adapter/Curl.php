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
 * @since         3.7.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Http\Client\Adapter;

use Cake\Http\Client\Adapter_Interface;
use Cake\Http\Client\Exception\Client_Exception;
use Cake\Http\Client\Exception\Network_Exception;
use Cake\Http\Client\Exception\Request_Exception;
use Cake\Http\Client\Request;
use Cake\Http\Client\Response;
use Cake\Http\Exception\Http_Exception;
use Composer\Ca_Bundle\Ca_Bundle;
use Curl_Handle;
use Psr\Http\Message\Request_Interface;
/**
 * Implements sending Cake\Http\Client\Request via ext/curl.
 *
 * In addition to the standard options documented in {@link \Cake\Http\Client},
 * this adapter supports all available curl options. Additional curl options
 * can be set via the `curl` option key when making requests or configuring
 * a client.
 */
class Curl implements Adapter_Interface
{
    /**
     * @inheritDoc
     */
    public function send(Request_Interface $request, array $options): array
    {
        if (!extension_loaded('curl')) {
            throw new Client_Exception('curl extension is not loaded.');
        }
        $ch = curl_init();
        if ($ch === false) {
            throw new Client_Exception('Could not initialize curl session.');
        }
        $options = $this->build_options($request, $options);
        curl_setopt_array($ch, $options);
        $body = $this->exec($ch);
        assert($body !== true);
        if ($body === false) {
            $error_code = curl_errno($ch);
            $error = curl_error($ch);
            $message = "cURL Error ({$error_code}) {$error}";
            $error_numbers = [CURLE_FAILED_INIT, CURLE_URL_MALFORMAT, CURLE_URL_MALFORMAT_USER];
            if (in_array($error_code, $error_numbers, true)) {
                throw new Request_Exception($message, $request);
            }
            throw new Network_Exception($message, $request);
        }
        return $this->create_response($ch, $body);
    }
    /**
     * Convert client options into curl options.
     *
     * @param \Psr\Http\Message\RequestInterface $request The request.
     * @param array<string, mixed> $options The client options
     */
    public function build_options(Request_Interface $request, array $options): array
    {
        $headers = [];
        foreach ($request->get_headers() as $key => $values) {
            $headers[] = $key . ': ' . implode(', ', $values);
        }
        $out = [CURLOPT_URL => (string) $request->get_uri(), CURLOPT_HTTP_VERSION => $this->get_protocol_version($request), CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => $headers];
        switch ($request->get_method()) {
            case Request::METHOD_GET:
                $out[CURLOPT_HTTPGET] = true;
                break;
            case Request::METHOD_POST:
                $out[CURLOPT_POST] = true;
                break;
            case Request::METHOD_HEAD:
                $out[CURLOPT_NOBODY] = true;
                break;
            default:
                $out[CURLOPT_POST] = true;
                $out[CURLOPT_CUSTOMREQUEST] = $request->get_method();
                break;
        }
        $body = $request->get_body();
        $body->rewind();
        $out[CURLOPT_POSTFIELDS] = $body->get_contents();
        // GET requests with bodies require custom request to be used.
        if ($out[CURLOPT_POSTFIELDS] !== '' && isset($out[CURLOPT_HTTPGET])) {
            $out[CURLOPT_CUSTOMREQUEST] = 'GET';
        }
        if ($out[CURLOPT_POSTFIELDS] === '') {
            unset($out[CURLOPT_POSTFIELDS]);
        }
        if (empty($options['ssl_cafile'])) {
            $options['ssl_cafile'] = Ca_Bundle::get_bundled_ca_bundle_path();
        }
        if (!empty($options['ssl_verify_host'])) {
            // Value of 1 or true is deprecated. Only 2 or 0 should be used now.
            $options['ssl_verify_host'] = 2;
        }
        $option_map = ['timeout' => CURLOPT_TIMEOUT, 'ssl_verify_peer' => CURLOPT_SSL_VERIFYPEER, 'ssl_verify_host' => CURLOPT_SSL_VERIFYHOST, 'ssl_cafile' => CURLOPT_CAINFO, 'ssl_local_cert' => CURLOPT_SSLCERT, 'ssl_passphrase' => CURLOPT_SSLCERTPASSWD];
        foreach ($option_map as $option => $curl_opt) {
            if (isset($options[$option])) {
                $out[$curl_opt] = $options[$option];
            }
        }
        if (isset($options['proxy']['proxy'])) {
            $out[CURLOPT_PROXY] = $options['proxy']['proxy'];
        }
        if (isset($options['proxy']['username'])) {
            $password = !empty($options['proxy']['password']) ? $options['proxy']['password'] : '';
            $out[CURLOPT_PROXYUSERPWD] = $options['proxy']['username'] . ':' . $password;
        }
        if (isset($options['curl']) && is_array($options['curl'])) {
            // Can't use array_merge() because keys will be re-ordered.
            foreach ($options['curl'] as $key => $value) {
                $out[$key] = $value;
            }
        }
        return $out;
    }
    /**
     * Convert HTTP version number into curl value.
     *
     * @param \Psr\Http\Message\RequestInterface $request The request to get a protocol version for.
     */
    protected function get_protocol_version(Request_Interface $request): int
    {
        return match ($request->get_protocol_version()) {
            '1.0' => CURL_HTTP_VERSION_1_0,
            '1.1' => CURL_HTTP_VERSION_1_1,
            '2', '2.0' => defined('CURL_HTTP_VERSION_2TLS') ? CURL_HTTP_VERSION_2TLS : (defined('CURL_HTTP_VERSION_2_0') ? CURL_HTTP_VERSION_2_0 : throw new Http_Exception('libcurl 7.33 or greater required for HTTP/2 support')),
            default => CURL_HTTP_VERSION_NONE,
        };
    }
    /**
     * Convert the raw curl response into an Http\Client\Response
     *
     * @param \CurlHandle $handle Curl handle
     * @param string $responseData string The response data from curl_exec
     * @return array<\Cake\Http\Client\Response>
     */
    protected function create_response(Curl_Handle $handle, string $response_data): array
    {
        $header_size = curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        $headers = trim(substr($response_data, 0, $header_size));
        $body = substr($response_data, $header_size);
        $response = new Response(explode("\r\n", $headers), $body);
        return [$response];
    }
    /**
     * Execute the curl handle.
     *
     * @param \CurlHandle $ch Curl Resource handle
     */
    protected function exec(Curl_Handle $ch): string|bool
    {
        return curl_exec($ch);
    }
}