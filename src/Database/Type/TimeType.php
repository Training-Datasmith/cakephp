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
namespace Cake\Database\Type;

use Cake\Chronos\Chronos_Time;
use Cake\Core\Exception\Cake_Exception;
use Cake\Database\Driver;
use Cake\I18n\Time;
use DateTimeInterface;
use InvalidArgumentException;
/**
 * Time type converter.
 *
 * Use to convert time instances to strings and back.
 */
class Time_Type extends Base_Type implements Batch_Casting_Interface
{
    /**
     * The PHP Time format used when converting to string.
     */
    protected string $_format = 'H:i:s';
    /**
     * Whether `marshal()` should use locale-aware parser with `_localeMarshalFormat`.
     */
    protected bool $_use_locale_marshal = false;
    /**
     * The locale-aware format `marshal()` uses when `_useLocaleParser` is true.
     *
     * See `Cake\I18n\Time::parseTime()` for accepted formats.
     */
    protected string|int|null $_locale_marshal_format = null;
    /**
     * The classname to use when creating objects.
     *
     * @var class-string<\Cake\Chronos\ChronosTime>
     */
    protected string $_class_name;
    /**
     * Constructor
     *
     * @param string|null $name The name identifying this type.
     * @param class-string<\Cake\Chronos\ChronosTime>|null $className Class name for time representation.
     */
    public function __construct(?string $name = null, ?string $class_name = null)
    {
        parent::__construct($name);
        if ($class_name === null) {
            $class_name = class_exists(Time::class) ? Time::class : Chronos_Time::class;
        }
        $this->_class_name = $class_name;
    }
    /**
     * Convert request data into a datetime object.
     *
     * @param mixed $value Request data
     */
    public function marshal(mixed $value): ?Chronos_Time
    {
        if ($value instanceof $this->_class_name) {
            return $value;
        }
        if ($value instanceof DateTimeInterface || $value instanceof Chronos_Time) {
            return new $this->_class_name($value->format($this->_format));
        }
        if (is_string($value)) {
            if ($this->_use_locale_marshal) {
                return $this->_parse_local_time_value($value);
            }
            return $this->_parse_time_value($value);
        }
        if (!is_array($value)) {
            return null;
        }
        $value += ['hour' => null, 'minute' => null, 'second' => 0, 'microsecond' => 0];
        if (!is_numeric($value['hour']) || !is_numeric($value['minute']) || !is_numeric($value['second']) || !is_numeric($value['microsecond'])) {
            return null;
        }
        if (isset($value['meridian']) && (int) $value['hour'] === 12) {
            $value['hour'] = 0;
        }
        if (isset($value['meridian'])) {
            $value['hour'] = strtolower($value['meridian']) === 'am' ? $value['hour'] : $value['hour'] + 12;
        }
        $format = sprintf('%02d:%02d:%02d.%06d', $value['hour'], $value['minute'], $value['second'], $value['microsecond']);
        return new $this->_class_name($format);
    }
    /**
     * @inheritDoc
     */
    public function many_to_php(array $values, array $fields, Driver $driver): array
    {
        foreach ($fields as $field) {
            if (!isset($values[$field])) {
                continue;
            }
            $value = $values[$field];
            $instance = new $this->_class_name($value);
            $values[$field] = $instance;
        }
        return $values;
    }
    /**
     * Convert time data into the database time format.
     *
     * @param mixed $value The value to convert.
     * @param \Cake\Database\Driver $driver The driver instance to convert with.
     */
    public function to_database(mixed $value, Driver $driver): mixed
    {
        if ($value === null || is_string($value)) {
            return $value;
        }
        assert(method_exists($value, 'format'));
        return $value->format($this->_format);
    }
    /**
     * Convert time values to PHP time instances
     *
     * @param mixed $value The value to convert.
     * @param \Cake\Database\Driver $driver The driver instance to convert with.
     */
    public function to_php(mixed $value, Driver $driver): ?Chronos_Time
    {
        if ($value === null) {
            return null;
        }
        return new $this->_class_name($value);
    }
    /**
     * Get the classname used for building objects.
     *
     * @return class-string<\Cake\Chronos\ChronosTime>
     */
    public function get_time_class_name(): string
    {
        return $this->_class_name;
    }
    /**
     * Converts a string into a Time object
     *
     * @param string $value The value to parse and convert to an object.
     */
    protected function _parse_time_value(string $value): ?Chronos_Time
    {
        try {
            return $this->_class_name::parse($value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
    /**
     * Converts a string into a Time object after parsing it using the locale
     * aware parser with the format set by `setLocaleFormat()`.
     *
     * @param string $value The value to parse and convert to an object.
     */
    protected function _parse_local_time_value(string $value): ?Chronos_Time
    {
        assert(is_a($this->_class_name, Time::class, true));
        return $this->_class_name::parse_time($value, $this->_locale_marshal_format);
    }
    /**
     * Sets whether to parse strings passed to `marshal()` using
     * the locale-aware format set by `setLocaleFormat()`.
     *
     * @param bool $enable Whether to enable
     * @return $this
     */
    public function use_locale_parser(bool $enable = true): static
    {
        if ($enable && ($this->_class_name !== Time::class && !is_subclass_of($this->_class_name, Time::class))) {
            throw new Cake_Exception('You must install the `cakephp/i18n` package to use locale aware parsing.');
        }
        $this->_use_locale_marshal = $enable;
        return $this;
    }
    /**
     * Sets the locale-aware format used by `marshal()` when parsing strings.
     *
     * See `Cake\I18n\Time::parseTime()` for accepted formats.
     *
     * @param string|int|null $format The locale-aware format
     * @see \Cake\I18n\Time::parseTime()
     * @return $this
     */
    public function set_locale_format(string|int|null $format): static
    {
        $this->_locale_marshal_format = $format;
        return $this;
    }
}