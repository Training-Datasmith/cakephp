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

use Cake\Utility\Xml;
use Laminas\Diactoros\Request_Trait;
use Laminas\Diactoros\Stream;
use Psr\Http\Message\Request_Interface;
use Psr\Http\Message\Uri_Interface;
/**
 * Implements methods for HTTP requests.
 *
 * Used by Cake\Http\Client to contain request information
 * for making requests.
 */
class Request extends Message implements Request_Interface
{
    use Request_Trait;
    /**
     * Constructor
     *
     * Provides backwards compatible defaults for some properties.
     *
     * @phpstan-param array<non-empty-string, non-empty-string> $headers
     * @param \Psr\Http\Message\UriInterface|string $url The request URL
     * @param string $method The HTTP method to use.
     * @param array $headers The HTTP headers to set.
     * @param array|string|null $data The request body to use.
     */
    public function __construct(Uri_Interface|string $url = '', string $method = self::METHOD_GET, array $headers = [], array|string|null $data = null)
    {
        $this->set_method($method);
        $this->uri = $this->create_uri($url);
        $headers += ['Connection' => 'close', 'User-Agent' => ini_get('user_agent') ?: 'CakePHP'];
        $this->add_headers($headers);
        if (in_array($data, [null, '', []], true)) {
            $this->stream = new Stream('php://memory', 'rw');
        } else {
            $this->set_content($data);
        }
    }
    /**
     * Add an array of headers to the request.
     *
     * @phpstan-param array<non-empty-string, non-empty-string> $headers
     * @param array<string, string> $headers The headers to add.
     */
    protected function add_headers(array $headers): void
    {
        foreach ($headers as $key => $val) {
            $normalized = strtolower($key);
            $this->headers[$key] = (array) $val;
            $this->header_names[$normalized] = $key;
        }
    }
    /**
     * Set the body/payload for the message.
     *
     * Array data will be serialized with {@link \Cake\Http\FormData},
     * and the content-type will be set.
     *
     * @param array|string $content The body for the request.
     * @return $this
     */
    protected function set_content(array|string $content): static
    {
        if (is_array($content)) {
            $content_type = $this->get_header_line('content-type');
            if (str_contains($content_type, 'application/json')) {
                $content = json_encode($content, JSON_THROW_ON_ERROR);
            } elseif (str_contains($content_type, 'application/xml')) {
                /** @phpstan-ignore-next-line */
                $content = (string) Xml::from_array($content);
            } else {
                $form_data = new Form_Data();
                $form_data->add_many($content);
                /** @phpstan-var array<non-empty-string, non-empty-string> $headers */
                $headers = ['Content-Type' => $form_data->content_type()];
                $this->add_headers($headers);
                $content = (string) $form_data;
            }
        }
        $stream = new Stream('php://memory', 'rw');
        $stream->write($content);
        $this->stream = $stream;
        return $this;
    }
}