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
 * @since         4.0.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Http\Client\Exception;

use Psr\Http\Client\Request_Exception_Interface;
use Psr\Http\Message\Request_Interface;
use RuntimeException;
use Throwable;
/**
 * Exception for when a request failed.
 *
 * Examples:
 *
 *   - Request is invalid (e.g. method is missing)
 *   - Runtime request errors (e.g. the body stream is not seekable)
 */
class Request_Exception extends RuntimeException implements Request_Exception_Interface
{
    protected Request_Interface $request;
    /**
     * Constructor.
     *
     * @param string $message Exception message.
     * @param \Psr\Http\Message\RequestInterface $request Request instance.
     * @param \Throwable|null $previous Previous Exception
     */
    public function __construct(string $message, Request_Interface $request, ?Throwable $previous = null)
    {
        $this->request = $request;
        parent::__construct($message, 0, $previous);
    }
    /**
     * Returns the request.
     *
     * The request object MAY be a different object from the one passed to ClientInterface::sendRequest()
     */
    public function get_request(): Request_Interface
    {
        return $this->request;
    }
}