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
 * @since         3.6.0
 * @license       https://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Http\Middleware;

use Cake\Http\Exception\Bad_Request_Exception;
use Cake\Utility\Exception\Xml_Exception;
use Cake\Utility\Xml;
use Closure;
use Psr\Http\Message\Response_Interface;
use Psr\Http\Message\Server_Request_Interface;
use Psr\Http\Server\Middleware_Interface;
use Psr\Http\Server\Request_Handler_Interface;
/**
 * Parse encoded request body data.
 *
 * Enables JSON and XML request payloads to be parsed into the request's body.
 * You can also add your own request body parsers using the `addParser()` method.
 */
class Body_Parser_Middleware implements Middleware_Interface
{
    /**
     * Registered Parsers
     *
     * @var array<\Closure>
     */
    protected array $parsers = [];
    /**
     * The HTTP methods to parse data on.
     *
     * @var array<string>
     */
    protected array $methods = ['PUT', 'POST', 'PATCH', 'DELETE'];
    /**
     * Constructor
     *
     * ### Options
     *
     * - `json` Set to false to disable JSON body parsing.
     * - `xml` Set to true to enable XML parsing. Defaults to false, as XML
     *   handling requires more care than JSON does.
     * - `methods` The HTTP methods to parse on. Defaults to PUT, POST, PATCH DELETE.
     *
     * @param array<string, mixed> $options The options to use. See above.
     */
    public function __construct(array $options = [])
    {
        $options += ['json' => true, 'xml' => false, 'methods' => null];
        if ($options['json']) {
            $this->add_parser(['application/json', 'text/json'], $this->decode_json(...));
        }
        if ($options['xml']) {
            $this->add_parser(['application/xml', 'text/xml'], $this->decode_xml(...));
        }
        if ($options['methods']) {
            $this->set_methods($options['methods']);
        }
    }
    /**
     * Set the HTTP methods to parse request bodies on.
     *
     * @param array<string> $methods The methods to parse data on.
     * @return $this
     */
    public function set_methods(array $methods): static
    {
        $this->methods = $methods;
        return $this;
    }
    /**
     * Get the HTTP methods to parse request bodies on.
     *
     * @return array<string>
     */
    public function get_methods(): array
    {
        return $this->methods;
    }
    /**
     * Add a parser.
     *
     * Map a set of content-type header values to be parsed by the $parser.
     *
     * ### Example
     *
     * An naive CSV request body parser could be built like so:
     *
     * ```
     * $parser->addParser(['text/csv'], function ($body) {
     *   return str_getcsv($body);
     * });
     * ```
     *
     * @param array<string> $types An array of content-type header values to match. eg. application/json
     * @param \Closure $parser The parser function. Must return an array of data to be inserted
     *   into the request.
     * @return $this
     */
    public function add_parser(array $types, Closure $parser): static
    {
        foreach ($types as $type) {
            $type = strtolower($type);
            $this->parsers[$type] = $parser;
        }
        return $this;
    }
    /**
     * Get the current parsers
     *
     * @return array<\Closure>
     */
    public function get_parsers(): array
    {
        return $this->parsers;
    }
    /**
     * Apply the middleware.
     *
     * Will modify the request adding a parsed body if the content-type is known.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request The request.
     * @param \Psr\Http\Server\RequestHandlerInterface $handler The request handler.
     * @return \Psr\Http\Message\ResponseInterface A response.
     */
    public function process(Server_Request_Interface $request, Request_Handler_Interface $handler): Response_Interface
    {
        if (!in_array($request->get_method(), $this->methods, true)) {
            return $handler->handle($request);
        }
        [$type] = explode(';', $request->get_header_line('Content-Type'));
        $type = strtolower($type);
        if (!isset($this->parsers[$type])) {
            return $handler->handle($request);
        }
        $parser = $this->parsers[$type];
        $result = $parser($request->get_body()->get_contents());
        if (!is_array($result)) {
            throw new Bad_Request_Exception();
        }
        $request = $request->with_parsed_body($result);
        return $handler->handle($request);
    }
    /**
     * Decode JSON into an array.
     *
     * @param string $body The request body to decode
     */
    protected function decode_json(string $body): ?array
    {
        if ($body === '') {
            return [];
        }
        $decoded = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }
        return (array) $decoded;
    }
    /**
     * Decode XML into an array.
     *
     * @param string $body The request body to decode
     */
    protected function decode_xml(string $body): array
    {
        try {
            $xml = Xml::build($body, ['return' => 'domdocument', 'readFile' => false]);
            // We might not get child nodes if there are nested inline entities.
            /** @var \DOMNodeList<\DOMNode> $domNodeList */
            $dom_node_list = $xml->child_nodes;
            if ((int) $dom_node_list->length > 0) {
                return Xml::to_array($xml);
            }
            return [];
        } catch (Xml_Exception) {
            return [];
        }
    }
}