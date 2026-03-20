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
namespace Cake\Http\Client\Auth;

use Cake\Core\Exception\Cake_Exception;
use Cake\Http\Client\Request;
use Cake\Utility\Security;
use Psr\Http\Message\Uri_Interface;
/**
 * Oauth 1 authentication strategy for Cake\Http\Client
 *
 * This object does not handle getting Oauth access tokens from the service
 * provider. It only handles make client requests *after* you have obtained the Oauth
 * tokens.
 *
 * Generally not directly constructed, but instead used by {@link \Cake\Http\Client}
 * when $options['auth']['type'] is 'oauth'
 */
class Oauth
{
    /**
     * Add headers for Oauth authorization.
     *
     * @param \Cake\Http\Client\Request $request The request object.
     * @param array $credentials Authentication credentials.
     * @return \Cake\Http\Client\Request The updated request.
     * @throws \Cake\Core\Exception\CakeException On invalid signature types.
     */
    public function authentication(Request $request, array $credentials): Request
    {
        if (!isset($credentials['consumerKey'])) {
            return $request;
        }
        if (empty($credentials['method'])) {
            $credentials['method'] = 'hmac-sha1';
        }
        $credentials['method'] = strtoupper((string) $credentials['method']);
        switch ($credentials['method']) {
            case 'HMAC-SHA1':
                $has_keys = isset($credentials['consumerSecret'], $credentials['token'], $credentials['tokenSecret']);
                if (!$has_keys) {
                    return $request;
                }
                $value = $this->_hmac_sha1($request, $credentials);
                break;
            case 'RSA-SHA1':
                if (!isset($credentials['privateKey'])) {
                    return $request;
                }
                $value = $this->_rsa_sha1($request, $credentials);
                break;
            case 'PLAINTEXT':
                $has_keys = isset($credentials['consumerSecret'], $credentials['token'], $credentials['tokenSecret']);
                if (!$has_keys) {
                    return $request;
                }
                $value = $this->_plaintext($request, $credentials);
                break;
            default:
                throw new Cake_Exception(sprintf('Unknown Oauth signature method `%s`.', $credentials['method']));
        }
        return $request->with_header('Authorization', $value);
    }
    /**
     * Plaintext signing
     *
     * This method is **not** suitable for plain HTTP.
     * You should only ever use PLAINTEXT when dealing with SSL
     * services.
     *
     * @param \Cake\Http\Client\Request $request The request object.
     * @param array $credentials Authentication credentials.
     * @return string Authorization header.
     */
    protected function _plaintext(Request $request, array $credentials): string
    {
        $values = ['oauth_version' => '1.0', 'oauth_nonce' => bin2hex(random_bytes(16)), 'oauth_timestamp' => time(), 'oauth_signature_method' => 'PLAINTEXT', 'oauth_token' => $credentials['token'], 'oauth_consumer_key' => $credentials['consumerKey']];
        if (isset($credentials['realm'])) {
            $values['oauth_realm'] = $credentials['realm'];
        }
        $key = [$credentials['consumerSecret'], $credentials['tokenSecret']];
        $key = implode('&', $key);
        $values['oauth_signature'] = $key;
        return $this->_build_auth($values);
    }
    /**
     * Use HMAC-SHA1 signing.
     *
     * This method is suitable for plain HTTP or HTTPS.
     *
     * @param \Cake\Http\Client\Request $request The request object.
     * @param array $credentials Authentication credentials.
     */
    protected function _hmac_sha1(Request $request, array $credentials): string
    {
        $nonce = $credentials['nonce'] ?? bin2hex(random_bytes(16));
        $timestamp = $credentials['timestamp'] ?? time();
        $values = ['oauth_version' => '1.0', 'oauth_nonce' => $nonce, 'oauth_timestamp' => $timestamp, 'oauth_signature_method' => 'HMAC-SHA1', 'oauth_token' => $credentials['token'], 'oauth_consumer_key' => $this->_encode($credentials['consumerKey'])];
        $base_string = $this->base_string($request, $values);
        // Consumer key should only be encoded for base string calculation as
        // auth header generation already encodes independently
        $values['oauth_consumer_key'] = $credentials['consumerKey'];
        if (isset($credentials['realm'])) {
            $values['oauth_realm'] = $credentials['realm'];
        }
        $key = [$credentials['consumerSecret'], $credentials['tokenSecret']];
        $key = array_map($this->_encode(...), $key);
        $key = implode('&', $key);
        $values['oauth_signature'] = base64_encode(hash_hmac('sha1', $base_string, $key, true));
        return $this->_build_auth($values);
    }
    /**
     * Use RSA-SHA1 signing.
     *
     * This method is suitable for plain HTTP or HTTPS.
     *
     * @param \Cake\Http\Client\Request $request The request object.
     * @param array $credentials Authentication credentials.
     */
    protected function _rsa_sha1(Request $request, array $credentials): string
    {
        if (!function_exists('openssl_pkey_get_private')) {
            throw new Cake_Exception('RSA-SHA1 signature method requires the OpenSSL extension.');
        }
        $nonce = $credentials['nonce'] ?? bin2hex(Security::random_bytes(16));
        $timestamp = $credentials['timestamp'] ?? time();
        $values = ['oauth_version' => '1.0', 'oauth_nonce' => $nonce, 'oauth_timestamp' => $timestamp, 'oauth_signature_method' => 'RSA-SHA1', 'oauth_consumer_key' => $credentials['consumerKey']];
        if (isset($credentials['consumerSecret'])) {
            $values['oauth_consumer_secret'] = $credentials['consumerSecret'];
        }
        if (isset($credentials['token'])) {
            $values['oauth_token'] = $credentials['token'];
        }
        if (isset($credentials['tokenSecret'])) {
            $values['oauth_token_secret'] = $credentials['tokenSecret'];
        }
        $base_string = $this->base_string($request, $values);
        if (isset($credentials['realm'])) {
            $values['oauth_realm'] = $credentials['realm'];
        }
        if (is_resource($credentials['privateKey'])) {
            $resource = $credentials['privateKey'];
            $private_key = stream_get_contents($resource);
            rewind($resource);
            $credentials['privateKey'] = $private_key;
        }
        $credentials += ['privateKeyPassphrase' => ''];
        if (is_resource($credentials['privateKeyPassphrase'])) {
            $resource = $credentials['privateKeyPassphrase'];
            $passphrase = stream_get_line($resource, 0, PHP_EOL);
            rewind($resource);
            $credentials['privateKeyPassphrase'] = $passphrase;
        }
        $private_key = openssl_pkey_get_private($credentials['privateKey'], $credentials['privateKeyPassphrase']);
        $this->check_ssl_error();
        assert($private_key !== false);
        $signature = '';
        openssl_sign($base_string, $signature, $private_key);
        $this->check_ssl_error();
        $values['oauth_signature'] = base64_encode((string) $signature);
        return $this->_build_auth($values);
    }
    /**
     * Generate the Oauth basestring
     *
     * - Querystring, request data and oauth_* parameters are combined.
     * - Values are sorted by name and then value.
     * - Request values are concatenated and urlencoded.
     * - The request URL (without querystring) is normalized.
     * - The HTTP method, URL and request parameters are concatenated and returned.
     *
     * @param \Cake\Http\Client\Request $request The request object.
     * @param array $oauthValues Oauth values.
     */
    public function base_string(Request $request, array $oauth_values): string
    {
        $parts = [$request->get_method(), $this->_normalized_url($request->get_uri()), $this->_normalized_params($request, $oauth_values)];
        $parts = array_map($this->_encode(...), $parts);
        return implode('&', $parts);
    }
    /**
     * Builds a normalized URL
     *
     * Section 9.1.2. of the Oauth spec
     *
     * @param \Psr\Http\Message\UriInterface $uri Uri object to build a normalized version of.
     * @return string Normalized URL
     */
    protected function _normalized_url(Uri_Interface $uri): string
    {
        $out = $uri->get_scheme() . '://';
        $out .= strtolower($uri->get_host());
        return $out . $uri->get_path();
    }
    /**
     * Sorts and normalizes request data and oauthValues
     *
     * Section 9.1.1 of Oauth spec.
     *
     * - URL encode keys + values.
     * - Sort keys & values by byte value.
     *
     * @param \Cake\Http\Client\Request $request The request object.
     * @param array $oauthValues Oauth values.
     * @return string sorted and normalized values
     */
    protected function _normalized_params(Request $request, array $oauth_values): string
    {
        $query = parse_url((string) $request->get_uri(), PHP_URL_QUERY);
        parse_str((string) $query, $query_args);
        $post = [];
        $content_type = $request->get_header_line('Content-Type');
        if ($content_type === '' || $content_type === 'application/x-www-form-urlencoded') {
            parse_str((string) $request->get_body(), $post);
        }
        $args = array_merge($query_args, $oauth_values, $post);
        $pairs = $this->_normalize_data($args);
        $data = [];
        foreach ($pairs as $pair) {
            $data[] = implode('=', $pair);
        }
        sort($data, SORT_STRING);
        return implode('&', $data);
    }
    /**
     * Recursively convert request data into the normalized form.
     *
     * @param array $args The arguments to normalize.
     * @param string $path The current path being converted.
     * @see https://tools.ietf.org/html/rfc5849#section-3.4.1.3.2
     */
    protected function _normalize_data(array $args, string $path = ''): array
    {
        $data = [];
        foreach ($args as $key => $value) {
            if ($path) {
                // Fold string keys with [].
                // Numeric keys result in a=b&a=c. While this isn't
                // standard behavior in PHP, it is common in other platforms.
                if (!is_numeric($key)) {
                    $key = "{$path}[{$key}]";
                } else {
                    $key = $path;
                }
            }
            if (is_array($value)) {
                uksort($value, strcmp(...));
                $data = array_merge($data, $this->_normalize_data($value, $key));
            } else {
                $data[] = [$key, $value];
            }
        }
        return $data;
    }
    /**
     * Builds the Oauth Authorization header value.
     *
     * @param array $data The oauth_* values to build
     */
    protected function _build_auth(array $data): string
    {
        $out = 'OAuth ';
        $params = [];
        foreach ($data as $key => $value) {
            $params[] = $key . '="' . $this->_encode((string) $value) . '"';
        }
        return $out . implode(',', $params);
    }
    /**
     * URL Encodes a value based on rules of rfc3986
     *
     * @param string $value Value to encode.
     */
    protected function _encode(string $value): string
    {
        return str_replace(['%7E', '+'], ['~', ' '], rawurlencode($value));
    }
    /**
     * Check for SSL errors and throw an exception if found.
     *
     * @throws \Cake\Core\Exception\CakeException When an error is found
     */
    protected function check_ssl_error(): void
    {
        $error = '';
        while ($text = openssl_error_string()) {
            $error .= $text;
        }
        if ($error !== '') {
            throw new Cake_Exception('openssl error: ' . $error);
        }
    }
}