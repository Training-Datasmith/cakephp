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
 * @since         3.0.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Http\Exception;

use Cake\Core\Exception\Cake_Exception;
use Cake\Core\Exception\Http_Error_Code_Interface;
/**
 * Parent class for all the HTTP related exceptions in CakePHP.
 * All HTTP status/error related exceptions should extend this class so
 * catch blocks can be specifically typed.
 *
 * You may also use this as a meaningful bridge to {@link \Cake\Core\Exception\CakeException}, e.g.:
 * throw new \Cake\Network\Exception\HttpException('HTTP Version Not Supported', 505);
 */
class Http_Exception extends Cake_Exception implements Http_Error_Code_Interface
{
    /**
     * @inheritDoc
     */
    protected int $_default_code = 500;
    /**
     * @var array<non-empty-string, array<string>|string>
     */
    protected array $headers = [];
    /**
     * Set a single HTTP response header.
     *
     * @param non-empty-string $header Header name
     * @param array<string>|string|null $value Header value
     */
    public function set_header(string $header, array|string|null $value = null): void
    {
        $this->headers[$header] = $value ?? '';
    }
    /**
     * Sets HTTP response headers.
     *
     * @param array<non-empty-string, array<string>|string> $headers Array of header name and value pairs.
     */
    public function set_headers(array $headers): void
    {
        $this->headers = $headers;
    }
    /**
     * Returns array of response headers.
     *
     * @return array<non-empty-string, array<string>|string>
     */
    public function get_headers(): array
    {
        return $this->headers;
    }
}