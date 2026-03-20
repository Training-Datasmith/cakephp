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

use Cake\Http\Client;
use Cake\Http\Client\Request;
use Cake\Http\Header_Utility;
use Cake\Utility\Hash;
use InvalidArgumentException;
/**
 * Digest authentication adapter for Cake\Http\Client
 *
 * Generally not directly constructed, but instead used by {@link \Cake\Http\Client}
 * when $options['auth']['type'] is 'digest'
 */
class Digest
{
    /**
     * Algorithms
     */
    public const ALGO_MD5 = 'MD5';
    public const ALGO_SHA_256 = 'SHA-256';
    public const ALGO_SHA_512_256 = 'SHA-512-256';
    public const ALGO_MD5_SESS = 'MD5-sess';
    public const ALGO_SHA_256_SESS = 'SHA-256-sess';
    public const ALGO_SHA_512_256_SESS = 'SHA-512-256-sess';
    /**
     * QOP
     */
    public const QOP_AUTH = 'auth';
    public const QOP_AUTH_INT = 'auth-int';
    /**
     * Algorithms <-> Hash type
     */
    public const HASH_ALGORITHMS = [self::ALGO_MD5 => 'md5', self::ALGO_SHA_256 => 'sha256', self::ALGO_SHA_512_256 => 'sha512/256', self::ALGO_MD5_SESS => 'md5', self::ALGO_SHA_256_SESS => 'sha256', self::ALGO_SHA_512_256_SESS => 'sha512/256'];
    /**
     * Algorithm
     */
    protected string $algorithm;
    /**
     * Hash type
     */
    protected string $hash_type;
    /**
     * Is Sess algorithm
     */
    protected bool $is_sess_algorithm = false;
    /**
     * Constructor
     *
     * Deprecated: $options list is unused and will be removed in 6.0.
     *
     * @param \Cake\Http\Client $_client Http client object.
     */
    public function __construct(
        /**
         * Instance of Cake\Http\Client
         */
        protected Client $_client
    )
    {
    }
    /**
     * Set algorithm based on credentials
     *
     * @param array $credentials authentication params
     */
    protected function set_algorithm(array $credentials): void
    {
        $algorithm = $credentials['algorithm'] ?? self::ALGO_MD5;
        if (!isset(self::HASH_ALGORITHMS[$algorithm])) {
            throw new InvalidArgumentException('Invalid Algorithm. Valid ones are: ' . implode(',', array_keys(self::HASH_ALGORITHMS)));
        }
        $this->algorithm = $algorithm;
        $this->is_sess_algorithm = str_contains($this->algorithm, '-sess');
        $this->hash_type = Hash::get(self::HASH_ALGORITHMS, $this->algorithm);
    }
    /**
     * Add Authorization header to the request.
     *
     * @param \Cake\Http\Client\Request $request The request object.
     * @param array<string, mixed> $credentials Authentication credentials.
     * @return \Cake\Http\Client\Request The updated request.
     * @see https://www.ietf.org/rfc/rfc2617.txt
     */
    public function authentication(Request $request, array $credentials): Request
    {
        if (!isset($credentials['username'], $credentials['password'])) {
            return $request;
        }
        if (!isset($credentials['realm'])) {
            $credentials = $this->_get_server_info($request, $credentials);
        }
        if (!isset($credentials['realm'])) {
            return $request;
        }
        $this->set_algorithm($credentials);
        $value = $this->_generate_header($request, $credentials);
        return $request->with_header('Authorization', $value);
    }
    /**
     * Retrieve information about the authentication
     *
     * Will get the realm and other tokens by performing
     * another request without authentication to get authentication
     * challenge.
     *
     * @param \Cake\Http\Client\Request $request The request object.
     * @param array $credentials Authentication credentials.
     * @return array modified credentials.
     */
    protected function _get_server_info(Request $request, array $credentials): array
    {
        $response = $this->_client->get((string) $request->get_uri(), [], ['auth' => ['type' => null]]);
        $header = $response->get_header('WWW-Authenticate');
        if (!$header) {
            return [];
        }
        $matches = Header_Utility::parse_www_authenticate($header[0]);
        $credentials = array_merge($credentials, $matches);
        if (($this->is_sess_algorithm || !empty($credentials['qop'])) && empty($credentials['nc'])) {
            $credentials['nc'] = 1;
        }
        return $credentials;
    }
    protected function generate_cnonce(): string
    {
        return bin2hex(random_bytes(8));
    }
    /**
     * Generate the header Authorization
     *
     * @param \Cake\Http\Client\Request $request The request object.
     * @param array<string, mixed> $credentials Authentication credentials.
     */
    protected function _generate_header(Request $request, array $credentials): string
    {
        $path = $request->get_request_target();
        if ($this->is_sess_algorithm) {
            $credentials['cnonce'] = $this->generate_cnonce();
            $a1 = hash($this->hash_type, $credentials['username'] . ':' . $credentials['realm'] . ':' . $credentials['password']) . ':' . $credentials['nonce'] . ':' . $credentials['cnonce'];
        } else {
            $a1 = $credentials['username'] . ':' . $credentials['realm'] . ':' . $credentials['password'];
        }
        $ha1 = hash($this->hash_type, $a1);
        $a2 = $request->get_method() . ':' . $path;
        $nc = sprintf('%08x', $credentials['nc'] ?? 1);
        if (empty($credentials['qop'])) {
            $ha2 = hash($this->hash_type, $a2);
            $response = hash($this->hash_type, $ha1 . ':' . $credentials['nonce'] . ':' . $ha2);
        } else {
            if (!in_array($credentials['qop'], [self::QOP_AUTH, self::QOP_AUTH_INT])) {
                throw new InvalidArgumentException('Invalid QOP parameter. Valid types are: ' . implode(',', [self::QOP_AUTH, self::QOP_AUTH_INT]));
            }
            if ($credentials['qop'] === self::QOP_AUTH_INT) {
                $a2 = $request->get_method() . ':' . $path . ':' . hash($this->hash_type, (string) $request->get_body());
            }
            if (empty($credentials['cnonce'])) {
                $credentials['cnonce'] = $this->generate_cnonce();
            }
            $ha2 = hash($this->hash_type, $a2);
            $response = hash($this->hash_type, $ha1 . ':' . $credentials['nonce'] . ':' . $nc . ':' . $credentials['cnonce'] . ':' . $credentials['qop'] . ':' . $ha2);
        }
        $auth_header = 'Digest ';
        $auth_header .= 'username="' . str_replace(['\\', '"'], ['\\\\', '\"'], $credentials['username']) . '", ';
        $auth_header .= 'realm="' . $credentials['realm'] . '", ';
        $auth_header .= 'nonce="' . $credentials['nonce'] . '", ';
        $auth_header .= 'uri="' . $path . '", ';
        $auth_header .= 'algorithm="' . $this->algorithm . '"';
        if (!empty($credentials['qop'])) {
            $auth_header .= ', qop=' . $credentials['qop'];
        }
        if ($this->is_sess_algorithm || !empty($credentials['qop'])) {
            $auth_header .= ', nc=' . $nc . ', cnonce="' . $credentials['cnonce'] . '"';
        }
        $auth_header .= ', response="' . $response . '"';
        if (!empty($credentials['opaque'])) {
            $auth_header .= ', opaque="' . $credentials['opaque'] . '"';
        }
        return $auth_header;
    }
}