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

use function Cake\Core\Deprecation_Warning;
use Cake\Core\Exception\Cake_Exception;
use Closure;
use InvalidArgumentException;
use ReflectionFunction;
/**
 * The event manager is responsible for keeping track of event listeners, passing the correct
 * data to them, and firing them in the correct order, when associated events are triggered. You
 * can create multiple instances of this object to manage local events or keep a single instance
 * and pass it around to manage all events in your app.
 */
class Event_Manager implements Event_Manager_Interface
{
    /**
     * The default priority queue value for new, attached listeners
     */
    public static int $default_priority = 10;
    /**
     * The globally available instance, used for dispatching events attached from any scope
     */
    protected static ?Event_Manager $_general_manager = null;
    /**
     * List of listener callbacks associated to
     */
    protected array $_listeners = [];
    /**
     * Internal flag to distinguish a common manager from the singleton
     */
    protected bool $_is_global = false;
    /**
     * The event list object.
     *
     * @var \Cake\Event\EventList<object>|null
     */
    protected ?Event_List $_event_list = null;
    /**
     * Enables automatic adding of events to the event list object if it is present.
     */
    protected bool $_track_events = false;
    /**
     * Returns the globally available instance of a Cake\Event\EventManager
     * this is used for dispatching events attached from outside the scope
     * other managers were created. Usually for creating hook systems or inter-class
     * communication
     *
     * If called with the first parameter, it will be set as the globally available instance
     *
     * @param \Cake\Event\EventManager|null $manager Event manager instance.
     * @return \Cake\Event\EventManager The global event manager
     */
    public static function instance(?Event_Manager $manager = null): Event_Manager
    {
        if ($manager === null && static::$_general_manager) {
            return static::$_general_manager;
        }
        if ($manager instanceof Event_Manager) {
            static::$_general_manager = $manager;
        }
        static::$_general_manager ??= new static();
        static::$_general_manager->_is_global = true;
        return static::$_general_manager;
    }
    /**
     * @inheritDoc
     */
    public function on(Event_Listener_Interface|string $event_key, callable|array $options = [], ?callable $callable = null): static
    {
        if ($event_key instanceof Event_Listener_Interface) {
            $this->_attach_subscriber($event_key);
            return $this;
        }
        if ($callable === null && !is_callable($options)) {
            throw new InvalidArgumentException('Second argument of `EventManager::on()` must be a callable if `$callable` is null.');
        }
        if ($callable === null) {
            /** @var callable $options */
            $this->_listeners[$event_key][static::$default_priority][] = ['callable' => $options(...)];
            return $this;
        }
        /** @var array $options */
        $priority = $options['priority'] ?? static::$default_priority;
        $this->_listeners[$event_key][$priority][] = ['callable' => $callable(...)];
        return $this;
    }
    /**
     * Auxiliary function to attach all implemented callbacks of a Cake\Event\EventListenerInterface class instance
     * as individual methods on this manager
     *
     * @param \Cake\Event\EventListenerInterface $subscriber Event listener.
     */
    protected function _attach_subscriber(Event_Listener_Interface $subscriber): void
    {
        foreach ($subscriber->implemented_events() as $event_key => $handlers) {
            foreach ($this->normalize_handlers($subscriber, $handlers) as $handler) {
                $this->on($event_key, $handler['settings'], $handler['callable']);
            }
        }
    }
    /**
     * @inheritDoc
     */
    public function off(Event_Listener_Interface|callable|string $event_key, Event_Listener_Interface|callable|null $callable = null): static
    {
        if ($event_key instanceof Event_Listener_Interface) {
            $this->_detach_subscriber($event_key);
            return $this;
        }
        if (!is_string($event_key)) {
            foreach (array_keys($this->_listeners) as $name) {
                $this->off($name, $event_key);
            }
            return $this;
        }
        if ($callable instanceof Event_Listener_Interface) {
            $this->_detach_subscriber($callable, $event_key);
            return $this;
        }
        if ($callable === null) {
            unset($this->_listeners[$event_key]);
            return $this;
        }
        if (empty($this->_listeners[$event_key])) {
            return $this;
        }
        $callable = $callable(...);
        foreach ($this->_listeners[$event_key] as $priority => $callables) {
            foreach ($callables as $k => $callback) {
                if ($callback['callable'] == $callable) {
                    unset($this->_listeners[$event_key][$priority][$k]);
                    break;
                }
            }
        }
        return $this;
    }
    /**
     * Auxiliary function to help detach all listeners provided by an object implementing EventListenerInterface
     *
     * @param \Cake\Event\EventListenerInterface $subscriber the subscriber to be detached
     * @param string|null $eventKey optional event key name to unsubscribe the listener from
     */
    protected function _detach_subscriber(Event_Listener_Interface $subscriber, ?string $event_key = null): void
    {
        $events = $subscriber->implemented_events();
        if ($event_key && empty($events[$event_key])) {
            return;
        }
        if ($event_key) {
            $events = [$event_key => $events[$event_key]];
        }
        foreach ($events as $key => $handlers) {
            foreach ($this->normalize_handlers($subscriber, $handlers) as $handler) {
                $this->off($key, $handler['callable']);
            }
        }
    }
    /**
     * Builds an array of normalized handlers.
     *
     * A normalized handler is an array with these keys:
     *
     *  - `callable` - The event handler closure
     *  - `settings` - The event handler settings
     *
     * @param \Cake\Event\EventListenerInterface $subscriber Event subscriber
     * @param callable|array|string $handlers Event handlers
     */
    protected function normalize_handlers(Event_Listener_Interface $subscriber, callable|array|string $handlers): array
    {
        // Check if an array of handlers not single handler config array
        if (is_array($handlers) && !isset($handlers['callable'])) {
            foreach ($handlers as &$handler) {
                $handler = $this->normalize_handler($subscriber, $handler);
            }
            return $handlers;
        }
        return [$this->normalize_handler($subscriber, $handlers)];
    }
    /**
     * Builds a single normalized handler.
     *
     * A normalized handler is an array with these keys:
     *
     *  - `callable` - The event handler closure
     *  - `settings` - The event handler settings
     *
     * @param \Cake\Event\EventListenerInterface $subscriber Event subscriber
     * @param callable|array|string $handler Event handler
     */
    protected function normalize_handler(Event_Listener_Interface $subscriber, callable|array|string $handler): array
    {
        $callable = $handler;
        $settings = [];
        if (is_array($handler)) {
            $callable = $handler['callable'];
            $settings = $handler;
            unset($settings['callable']);
        }
        if (is_string($callable)) {
            $callable = $subscriber->{$callable}(...);
        }
        return ['callable' => $callable, 'settings' => $settings];
    }
    /**
     * @inheritDoc
     */
    public function dispatch(Event_Interface|string $event): Event_Interface
    {
        if (is_string($event)) {
            $event = new Event($event);
        }
        $listeners = $this->listeners($event->get_name());
        if ($this->_track_events) {
            $this->add_event_to_list($event);
        }
        if (!$this->_is_global && static::instance()->is_tracking_events()) {
            static::instance()->add_event_to_list($event);
        }
        if (!$listeners) {
            return $event;
        }
        foreach ($listeners as $listener) {
            if ($event->is_stopped()) {
                break;
            }
            $this->_call_listener($listener['callable'], $event);
        }
        return $event;
    }
    /**
     * Calls a listener.
     *
     * @template TSubject of object
     * @param callable $listener The listener to trigger.
     * @param \Cake\Event\EventInterface<TSubject> $event Event instance.
     */
    protected function _call_listener(callable $listener, Event_Interface $event): void
    {
        $result = $listener($event, ...array_values($event->get_data()));
        if ($result !== null) {
            try {
                $class = $event->get_subject()::class;
            } catch (Cake_Exception) {
                $class = 'unknown subject';
            }
            if ($listener instanceof Closure) {
                $ref = new ReflectionFunction($listener);
                $closure_class = $ref->get_closure_scope_class();
                $closure_method = $ref->get_name();
                if ($closure_class && $closure_class->name && $closure_method) {
                    $class = $closure_class->name . '::' . $closure_method . '()';
                }
            }
            deprecation_warning('5.2.0', 'Returning a value from event listeners is deprecated. ' . 'Use `$event->setResult()` instead in `' . $event->get_name() . '` of `' . $class . '`');
            $event->set_result($result);
        }
        if ($event->get_result() === false) {
            $event->stop_propagation();
        }
    }
    /**
     * @inheritDoc
     */
    public function listeners(string $event_key): array
    {
        $local_listeners = [];
        if (!$this->_is_global) {
            $local_listeners = $this->prioritised_listeners($event_key);
        }
        $global_listeners = static::instance()->prioritised_listeners($event_key);
        $priorities = array_merge(array_keys($global_listeners), array_keys($local_listeners));
        $priorities = array_unique($priorities);
        asort($priorities);
        $result = [];
        foreach ($priorities as $priority) {
            if (isset($global_listeners[$priority])) {
                $result = array_merge($result, $global_listeners[$priority]);
            }
            if (isset($local_listeners[$priority])) {
                $result = array_merge($result, $local_listeners[$priority]);
            }
        }
        return $result;
    }
    /**
     * Returns the listeners for the specified event key indexed by priority
     *
     * @param string $eventKey Event key.
     */
    public function prioritised_listeners(string $event_key): array
    {
        if (empty($this->_listeners[$event_key])) {
            return [];
        }
        return $this->_listeners[$event_key];
    }
    /**
     * Returns the listeners matching a specified pattern
     *
     * @param string $eventKeyPattern Pattern to match.
     */
    public function matching_listeners(string $event_key_pattern): array
    {
        $match_pattern = '/' . preg_quote($event_key_pattern, '/') . '/';
        return array_intersect_key($this->_listeners, array_flip(preg_grep($match_pattern, array_keys($this->_listeners), 0) ?: []));
    }
    /**
     * Returns the event list.
     *
     * @return \Cake\Event\EventList<object>|null
     */
    public function get_event_list(): ?Event_List
    {
        return $this->_event_list;
    }
    /**
     * Adds an event to the list if the event list object is present.
     *
     * @template TSubject of object
     * @param \Cake\Event\EventInterface<TSubject> $event An event to add to the list.
     * @return $this
     */
    public function add_event_to_list(Event_Interface $event): static
    {
        $this->_event_list?->add($event);
        return $this;
    }
    /**
     * Enables / disables event tracking at runtime.
     *
     * @param bool $enabled True or false to enable / disable it.
     * @return $this
     */
    public function track_events(bool $enabled): static
    {
        $this->_track_events = $enabled;
        return $this;
    }
    /**
     * Returns whether this manager is set up to track events
     */
    public function is_tracking_events(): bool
    {
        return $this->_track_events && $this->_event_list;
    }
    /**
     * Enables the listing of dispatched events.
     *
     * @param \Cake\Event\EventList<object> $eventList The event list object to use.
     * @return $this
     */
    public function set_event_list(Event_List $event_list): static
    {
        $this->_event_list = $event_list;
        $this->_track_events = true;
        return $this;
    }
    /**
     * Disables the listing of dispatched events.
     *
     * @return $this
     */
    public function unset_event_list(): static
    {
        $this->_event_list = null;
        $this->_track_events = false;
        return $this;
    }
    /**
     * Debug friendly object properties.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        $properties = get_object_vars($this);
        $properties['_generalManager'] = '(object) EventManager';
        $properties['_listeners'] = [];
        foreach ($this->_listeners as $key => $priorities) {
            $listener_count = 0;
            foreach ($priorities as $listeners) {
                $listener_count += count($listeners);
            }
            $properties['_listeners'][$key] = $listener_count . ' listener(s)';
        }
        if ($this->_event_list !== null) {
            foreach ($this->_event_list as $event) {
                try {
                    $subject = $event->get_subject();
                    $properties['_dispatchedEvents'][] = $event->get_name() . ' with subject ' . $subject::class;
                } catch (Cake_Exception) {
                    $properties['_dispatchedEvents'][] = $event->get_name() . ' with no subject';
                }
            }
        } else {
            $properties['_dispatchedEvents'] = null;
        }
        unset($properties['_eventList']);
        return $properties;
    }
}