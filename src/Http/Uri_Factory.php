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
 * @since         5.0.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Http;

use Cake\Core\Configure;
use function Laminas\Diactoros\Marshal_Headers_From_Sapi;
use Laminas\Diactoros\Uri;
use Laminas\Diactoros\Uri_Factory as DiactorosUriFactory;
use Psr\Http\Message\Uri_Factory_Interface;
use Psr\Http\Message\Uri_Interface;
/**
 * Factory class for creating uri instances.
 */
class Uri_Factory implements Uri_Factory_Interface
{
    /**
     * Create a new URI.
     *
     * @param string $uri The URI to parse.
     * @throws \InvalidArgumentException If the given URI cannot be parsed.
     */
    public function create_uri(string $uri = ''): Uri_Interface
    {
        return new Uri($uri);
    }
    /**
     * Get a new Uri instance and base info from the provided server data.
     *
     * @param array|null $server Array of server data to build the Uri from.
     *   $_SERVER will be used if $server parameter is null.
     * @phpstan-return array{uri: \Psr\Http\Message\UriInterface, base: string, webroot: string}
     */
    public static function marshal_uri_and_base_from_sapi(?array $server = null): array
    {
        $server ??= $_SERVER;
        $headers = marshal_headers_from_sapi($server);
        $uri = Diactoros_Uri_Factory::create_from_sapi($server, $headers);
        ['base' => $base, 'webroot' => $webroot] = static::get_base($uri, $server);
        $uri = static::update_path($base, $uri);
        if (!$uri->get_host()) {
            $uri = $uri->with_host('localhost');
        }
        return ['uri' => $uri, 'base' => $base, 'webroot' => $webroot];
    }
    /**
     * Updates the request URI to remove the base directory.
     *
     * @param string $base The base path to remove.
     * @param \Psr\Http\Message\UriInterface $uri The uri to update.
     */
    protected static function update_path(string $base, Uri_Interface $uri): Uri_Interface
    {
        $path = $uri->get_path();
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        // App.baseUrl is meant to be set only when URL rewriting is not used.
        if (!Configure::read('App.baseUrl')) {
            if ($path === '' || $path === '//') {
                $path = '/';
            }
            return $uri->with_path($path);
        }
        if ($path === '/index.php' && $uri->get_query()) {
            $path = $uri->get_query();
        }
        if (in_array($path, ['', '//', '/index.php'], true)) {
            $path = '/';
        }
        // Check for $webroot/index.php at the start and end of the path.
        $search = '';
        if (str_starts_with($path, '/')) {
            $search .= '/';
        }
        $search .= (Configure::read('App.webroot') ?: 'webroot') . '/index.php';
        if (str_starts_with($path, $search)) {
            $path = substr($path, strlen($search));
        } elseif (str_ends_with($path, $search)) {
            $path = '/';
        }
        if (!$path) {
            $path = '/';
        }
        return $uri->with_path($path);
    }
    /**
     * Calculate the base directory and webroot directory.
     *
     * @param \Psr\Http\Message\UriInterface $uri The Uri instance.
     * @param array $server The SERVER data to use.
     * @return array An array containing the base and webroot paths.
     * @phpstan-return array{base: string, webroot: string}
     */
    protected static function get_base(Uri_Interface $uri, array $server): array
    {
        $config = (array) Configure::read('App') + ['base' => null, 'webroot' => null, 'baseUrl' => null];
        $base = $config['base'];
        $base_url = $config['baseUrl'];
        $webroot = (string) $config['webroot'];
        if ($base !== false && $base !== null) {
            return ['base' => $base, 'webroot' => $base . '/'];
        }
        if (!$base_url) {
            $php_self = $server['PHP_SELF'] ?? null;
            if ($php_self === null) {
                return ['base' => '', 'webroot' => '/'];
            }
            $base = dirname($server['PHP_SELF'] ?? DIRECTORY_SEPARATOR);
            // Clean up additional / which cause following code to fail..
            $base = (string) preg_replace('#/+#', '/', $base);
            $index_pos = strpos($base, '/index.php');
            if ($index_pos !== false) {
                $base = substr($base, 0, $index_pos);
            }
            if ($webroot === basename($base)) {
                $base = dirname($base);
            }
            if ($base === DIRECTORY_SEPARATOR || $base === '.') {
                $base = '';
            }
            $base = implode('/', array_map(rawurlencode(...), explode('/', $base)));
            return ['base' => $base, 'webroot' => $base . '/'];
        }
        $file = '/' . basename((string) $base_url);
        $base = dirname((string) $base_url);
        if ($base === DIRECTORY_SEPARATOR || $base === '.') {
            $base = '';
        }
        $webroot_dir = $base . '/';
        $doc_root = $server['DOCUMENT_ROOT'] ?? '';
        if (($base || !str_contains($doc_root, $webroot)) && !str_contains($webroot_dir, '/' . $webroot . '/')) {
            $webroot_dir .= $webroot . '/';
        }
        return ['base' => $base . $file, 'webroot' => $webroot_dir];
    }
}