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
 * @since         2.1.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Event;

use Cake\Core\Exception\Cake_Exception;
/**
 * Class Event
 *
 * @template TSubject of object
 * @implements \Cake\Event\EventInterface<TSubject>
 */
class Event implements Event_Interface
{
    /**
     * Property used to retain the result value of the event listeners
     *
     * Use setResult() and getResult() to set and get the result.
     */
    protected mixed $result = null;
    /**
     * Flags an event as stopped or not, default is false
     */
    protected bool $_stopped = false;
    /**
     * Constructor
     *
     * ### Examples of usage:
     *
     * ```
     *  $event = new Event('Order.afterBuy', $this, ['buyer' => $userData]);
     *  $event = new Event('User.afterRegister', $userModel);
     * ```
     *
     * @param string $_name Name of the event
     * @param TSubject|null $_subject the object that this event applies to
     *   (usually the object that is generating the event).
     * @param array $_data any value you wish to be transported
     *   with this event to it can be read by listeners.
     * @phpstan-param TSubject|null $subject
     */
    public function __construct(
        /**
         * Name of the event
         */
        protected string $_name,
        /**
         * The object this event applies to (usually the same object that generates the event)
         */
        protected ?object $_subject = null,
        /**
         * Custom data for the method that receives the event
         */
        protected array $_data = []
    )
    {
    }
    /**
     * Returns the name of this event. This is usually used as the event identifier
     */
    public function get_name(): string
    {
        return $this->_name;
    }
    /**
     * Returns the subject of this event
     *
     * If the event has no subject an exception will be raised.
     *
     * @return TSubject
     * @throws \Cake\Core\Exception\CakeException
     */
    public function get_subject(): object
    {
        if ($this->_subject === null) {
            throw new Cake_Exception('No subject set for this event');
        }
        return $this->_subject;
    }
    /**
     * Stops the event from being used anymore
     */
    public function stop_propagation(): void
    {
        $this->_stopped = true;
    }
    /**
     * Check if the event is stopped
     *
     * @return bool True if the event is stopped
     */
    public function is_stopped(): bool
    {
        return $this->_stopped;
    }
    /**
     * The result value of the event listeners
     */
    public function get_result(): mixed
    {
        return $this->result;
    }
    /**
     * Listeners can attach a result value to the event.
     *
     * Setting the result to `false` will also stop event propagation.
     *
     * @param mixed $value The value to set.
     * @return $this
     */
    public function set_result(mixed $value = null): static
    {
        $this->result = $value;
        return $this;
    }
    /**
     * @inheritDoc
     */
    public function get_data(?string $key = null): mixed
    {
        if ($key !== null) {
            return $this->_data[$key] ?? null;
        }
        return $this->_data;
    }
    /**
     * @inheritDoc
     */
    public function set_data(array|string $key, $value = null): static
    {
        if (is_array($key)) {
            $this->_data = $key;
        } else {
            $this->_data[$key] = $value;
        }
        return $this;
    }
}