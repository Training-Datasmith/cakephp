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
 * @since         3.0.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\ORM\Behavior;

use Cake\Database\Type\Date_Time_Type;
use Cake\Database\Type_Factory;
use Cake\Datasource\Entity_Interface;
use Cake\Event\Event_Interface;
use Cake\I18n\DateTime;
use Cake\ORM\Behavior;
use DateTimeInterface;
use UnexpectedValueException;
/**
 * Class TimestampBehavior
 */
class Timestamp_Behavior extends Behavior
{
    /**
     * Default config
     *
     * These are merged with user-provided config when the behavior is used.
     *
     * events - an event-name keyed array of which fields to update, and when, for a given event
     * possible values for when a field will be updated are "always", "new" or "existing", to set
     * the field value always, only when a new record or only when an existing record.
     *
     * refreshTimestamp - if true (the default) the timestamp used will be the current time when
     * the code is executed, to set to an explicit date time value - set refreshTimestamp to false
     * and call setTimestamp() on the behavior class before use.
     *
     * @var array<string, mixed>
     */
    protected array $_default_config = ['implementedFinders' => [], 'implementedMethods' => ['timestamp' => 'timestamp', 'touch' => 'touch'], 'events' => ['Model.beforeSave' => ['created' => 'new', 'modified' => 'always']], 'refreshTimestamp' => true];
    /**
     * Current timestamp
     */
    protected ?DateTime $_ts = null;
    /**
     * Initialize hook
     *
     * If events are specified - do *not* merge them with existing events,
     * overwrite the events to listen on
     *
     * @param array<string, mixed> $config The config for this behavior.
     */
    public function initialize(array $config): void
    {
        if (isset($config['events'])) {
            $this->set_config('events', $config['events'], false);
        }
    }
    /**
     * There is only one event handler, it can be configured to be called for any event
     *
     * @param \Cake\Event\EventInterface<\Cake\ORM\Table> $event Event instance.
     * @param \Cake\Datasource\EntityInterface $entity Entity instance.
     * @throws \UnexpectedValueException If a field's value is misdefined.
     * @throws \UnexpectedValueException When the value for an event is not 'always', 'new' or 'existing'.
     */
    public function handle_event(Event_Interface $event, Entity_Interface $entity): void
    {
        $event_name = $event->get_name();
        $events = $this->_config['events'];
        $new = $entity->is_new();
        $refresh = $this->_config['refreshTimestamp'];
        foreach ($events[$event_name] as $field => $when) {
            if (!in_array($when, ['always', 'new', 'existing'], true)) {
                throw new UnexpectedValueException(sprintf('When should be one of "always", "new" or "existing". The passed value `%s` is invalid.', $when));
            }
            if ($when === 'always' || $when === 'new' && $new || $when === 'existing' && !$new) {
                $this->_update_field($entity, $field, $refresh);
            }
        }
    }
    /**
     * implementedEvents
     *
     * The implemented events of this behavior depend on configuration
     *
     * @return array<string, mixed>
     */
    public function implemented_events(): array
    {
        /** @var array<string, mixed> */
        return array_fill_keys(array_keys($this->_config['events']), 'handleEvent');
    }
    /**
     * Get or set the timestamp to be used
     *
     * Set the timestamp to the given DateTime object, or if not passed a new DateTime object
     * If an explicit date time is passed, the config option `refreshTimestamp` is
     * automatically set to false.
     *
     * @param \DateTimeInterface|null $ts Timestamp
     * @param bool $refreshTimestamp If true timestamp is refreshed.
     */
    public function timestamp(?DateTimeInterface $ts = null, bool $refresh_timestamp = false): DateTime
    {
        if ($ts) {
            if ($this->_config['refreshTimestamp']) {
                $this->_config['refreshTimestamp'] = false;
            }
            $this->_ts = new DateTime($ts);
        } elseif ($this->_ts === null || $refresh_timestamp) {
            $this->_ts = new DateTime();
        }
        return $this->_ts;
    }
    /**
     * Touch an entity
     *
     * Bumps timestamp fields for an entity. For any fields configured to be updated
     * "always" or "existing", update the timestamp value. This method will overwrite
     * any pre-existing value.
     *
     * @param \Cake\Datasource\EntityInterface $entity Entity instance.
     * @param string $eventName Event name.
     * @return bool true if a field is updated, false if no action performed
     */
    public function touch(Entity_Interface $entity, string $event_name = 'Model.beforeSave'): bool
    {
        $events = $this->_config['events'];
        if (empty($events[$event_name])) {
            return false;
        }
        $return = false;
        $refresh = $this->_config['refreshTimestamp'];
        foreach ($events[$event_name] as $field => $when) {
            if (in_array($when, ['always', 'existing'], true)) {
                $return = true;
                $entity->set_dirty($field, false);
                $this->_update_field($entity, $field, $refresh);
            }
        }
        return $return;
    }
    /**
     * Update a field, if it hasn't been updated already
     *
     * @param \Cake\Datasource\EntityInterface $entity Entity instance.
     * @param string $field Field name
     * @param bool $refreshTimestamp Whether to refresh timestamp.
     */
    protected function _update_field(Entity_Interface $entity, string $field, bool $refresh_timestamp): void
    {
        if ($entity->is_dirty($field)) {
            return;
        }
        $ts = $this->timestamp(null, $refresh_timestamp);
        $column_type = $this->table()->get_schema()->get_column_type($field);
        if (!$column_type) {
            return;
        }
        $type = Type_Factory::build($column_type);
        assert($type instanceof Date_Time_Type, sprintf('TimestampBehavior only supports columns of type `%s`.', Date_Time_Type::class));
        /** @var class-string<\Cake\I18n\DateTime> $class */
        $class = $type->get_date_time_class_name();
        $entity->set($field, new $class($ts));
    }
}