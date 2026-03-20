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
 * @since         3.0.10
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Event;

/**
 * Implements Cake\Event\EventDispatcherInterface.
 *
 * @template TSubject of object
 */
trait Event_Dispatcher_Trait
{
    /**
     * Instance of the Cake\Event\EventManager this object is using
     * to dispatch inner events.
     */
    protected Event_Manager_Interface $_event_manager;
    /**
     * Default class name for new event objects.
     */
    protected string $_event_class = Event::class;
    /**
     * Returns the Cake\Event\EventManager manager instance for this object.
     *
     * You can use this instance to register any new listeners or callbacks to the
     * object events, or create your own events and trigger them at will.
     */
    public function get_event_manager(): Event_Manager_Interface
    {
        return $this->_event_manager ??= new Event_Manager();
    }
    /**
     * Returns the Cake\Event\EventManagerInterface instance for this object.
     *
     * You can use this instance to register any new listeners or callbacks to the
     * object events, or create your own events and trigger them at will.
     *
     * @param \Cake\Event\EventManagerInterface $eventManager the eventManager to set
     * @return $this
     */
    public function set_event_manager(Event_Manager_Interface $event_manager)
    {
        $this->_event_manager = $event_manager;
        return $this;
    }
    /**
     * Wrapper for creating and dispatching events.
     *
     * Returns a dispatched event.
     *
     * @param string $name Name of the event.
     * @param array $data Any value you wish to be transported with this event to
     * it can be read by listeners.
     * @param TSubject|null $subject The object that this event applies to
     * ($this by default).
     * @return \Cake\Event\EventInterface<TSubject>
     * @phpstan-ignore missingType.generics
     */
    public function dispatch_event(string $name, array $data = [], ?object $subject = null): Event_Interface
    {
        $subject ??= $this;
        /**
         * @var \Cake\Event\EventInterface<TSubject> $event Coerce for psalm/phpstan
         * @phpstan-ignore missingType.generics (TSubject may itself be generic)
         */
        $event = new $this->_event_class($name, $subject, $data);
        $this->get_event_manager()->dispatch($event);
        return $event;
    }
}