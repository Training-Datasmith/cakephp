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
 * @since         5.1.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Http\Client;

use Cake\Event\Event;
use Cake\Http\Client;
use InvalidArgumentException;
use Psr\Http\Message\Request_Interface;
/**
 * Class Client Event
 *
 * @extends \Cake\Event\Event<\Cake\Http\Client>
 */
class Client_Event extends Event
{
    /**
     * Constructor
     *
     * @param string $name Name of the event
     * @param \Cake\Http\Client $subject The Http Client instance this event applies to.
     * @param array $data Any value you wish to be transported
     *   with this event to it can be read by listeners.
     */
    public function __construct(string $name, Client $subject, array $data = [])
    {
        if (isset($data['response'])) {
            $this->result = $data['response'];
            unset($data['response']);
        }
        parent::__construct($name, $subject, $data);
    }
    /**
     * The result value of the event listeners
     */
    public function get_result(): ?Response
    {
        return $this->result;
    }
    /**
     * Listeners can attach a result value to the event.
     *
     * @param mixed $value The value to set.
     * @return $this
     */
    public function set_result(mixed $value = null)
    {
        if ($value !== null && !$value instanceof Response) {
            throw new InvalidArgumentException('The result for Http Client events must be a `Cake\Http\Client\Response` instance.');
        }
        return parent::set_result($value);
    }
    /**
     * Set request instance.
     *
     * @return $this
     */
    public function set_request(Request_Interface $request): static
    {
        $this->_data['request'] = $request;
        return $this;
    }
    /**
     * Get the request instance.
     */
    public function get_request(): Request_Interface
    {
        return $this->_data['request'];
    }
    /**
     * Set the adapter options.
     *
     * @return $this
     */
    public function set_adapter_options(array $options = []): static
    {
        $this->_data['adapterOptions'] = $options;
        return $this;
    }
    /**
     * Get the adapter options.
     */
    public function get_adapter_options(): array
    {
        return $this->_data['adapterOptions'];
    }
}