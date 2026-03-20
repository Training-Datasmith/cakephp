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

use Cake\Chronos\Chronos_Date;
use Cake\Database\Driver;
use Cake\Database\Exception\Database_Exception;
use Cake\I18n\DateTime;
use DateTime as NativeDateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use InvalidArgumentException;
use PDO;
/**
 * Datetime type converter.
 *
 * Use to convert datetime instances to strings & back.
 */
class Date_Time_Type extends Base_Type implements Batch_Casting_Interface
{
    /**
     * The DateTime format used when converting to string.
     */
    protected string $_format = 'Y-m-d H:i:s';
    /**
     * The DateTime formats allowed by `marshal()`.
     *
     * @var array<string>
     */
    protected array $_marshal_formats = ['Y-m-d H:i', 'Y-m-d H:i:s', 'Y-m-d H:i:s.u', 'Y-m-d\TH:i', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s.u', 'Y-m-d\TH:i:s.uP'];
    /**
     * Whether `marshal()` should use locale-aware parser with `_localeMarshalFormat`.
     */
    protected bool $_use_locale_marshal = false;
    /**
     * The locale-aware format `marshal()` uses when `_useLocaleParser` is true.
     *
     * See `Cake\I18n\Time::parseDateTime()` for accepted formats.
     */
    protected array|string|int|null $_locale_marshal_format = null;
    /**
     * The classname to use when creating objects.
     *
     * @var class-string<\Cake\I18n\DateTime>|class-string<\DateTimeImmutable>
     */
    protected string $_class_name;
    /**
     * Database time zone.
     */
    protected ?DateTimeZone $db_timezone = null;
    /**
     * User time zone.
     */
    protected ?DateTimeZone $user_timezone = null;
    /**
     * Default time zone.
     */
    protected DateTimeZone $default_timezone;
    /**
     * Whether database time zone is kept when converting
     */
    protected bool $keep_database_timezone = false;
    /**
     * {@inheritDoc}
     *
     * @param string|null $name The name identifying this type
     */
    public function __construct(?string $name = null)
    {
        parent::__construct($name);
        $this->default_timezone = new DateTimeZone(date_default_timezone_get());
        $this->_class_name = class_exists(DateTime::class) ? DateTime::class : DateTimeImmutable::class;
    }
    /**
     * Convert DateTime instance into strings.
     *
     * @param mixed $value The value to convert.
     * @param \Cake\Database\Driver $driver The driver instance to convert with.
     */
    public function to_database(mixed $value, Driver $driver): ?string
    {
        if ($value === null || is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            $class = $this->_class_name;
            $value = new $class('@' . $value);
        }
        if ($value instanceof Chronos_Date) {
            return $value->format($this->_format);
        }
        if (!$value instanceof DateTimeInterface) {
            return null;
        }
        if ($this->db_timezone !== null && $this->db_timezone->get_name() !== $value->get_timezone()->get_name()) {
            if (!$value instanceof DateTimeImmutable) {
                $value = clone $value;
            }
            $value = $value->set_timezone($this->db_timezone);
        }
        return $value->format($this->_format);
    }
    /**
     * Set database timezone.
     *
     * This is the time zone used when converting database strings to DateTime
     * instances and converting DateTime instances to database strings.
     *
     * @see DateTimeType::setKeepDatabaseTimezone
     * @param \DateTimeZone|string|null $timezone Database timezone.
     * @return $this
     */
    public function set_database_timezone(DateTimeZone|string|null $timezone): static
    {
        if (is_string($timezone)) {
            $timezone = new DateTimeZone($timezone);
        }
        $this->db_timezone = $timezone;
        return $this;
    }
    /**
     * Set user timezone.
     *
     * This is the time zone used when marshaling strings to DateTime instances.
     *
     * @param \DateTimeZone|string|null $timezone User timezone.
     * @return $this
     */
    public function set_user_timezone(DateTimeZone|string|null $timezone): static
    {
        if (is_string($timezone)) {
            $timezone = new DateTimeZone($timezone);
        }
        $this->user_timezone = $timezone;
        return $this;
    }
    /**
     * {@inheritDoc}
     *
     * @param mixed $value Value to be converted to PHP equivalent
     * @param \Cake\Database\Driver $driver Object from which database preferences and configuration will be extracted
     */
    public function to_php(mixed $value, Driver $driver): DateTime|DateTimeImmutable|null
    {
        if ($value === null) {
            return null;
        }
        $class = $this->_class_name;
        if (is_numeric($value)) {
            $instance = new $class('@' . $value);
        } elseif (str_starts_with((string) $value, '0000-00-00')) {
            return null;
        } else {
            $instance = new $class($value, $this->db_timezone);
        }
        if (!$this->keep_database_timezone && $instance->get_timezone() && $instance->get_timezone()->get_name() !== $this->default_timezone->get_name()) {
            return $instance->set_timezone($this->default_timezone);
        }
        return $instance;
    }
    /**
     * Set whether DateTime object created from database string is converted
     * to default time zone.
     *
     * If your database date times are in a specific time zone that you want
     * to keep in the DateTime instance then set this to true.
     *
     * When false, datetime timezones are converted to default time zone.
     * This is default behavior.
     *
     * @param bool $keep If true, database time zone is kept when converting
     *      to DateTime instances.
     * @return $this
     */
    public function set_keep_database_timezone(bool $keep): static
    {
        $this->keep_database_timezone = $keep;
        return $this;
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
            $class = $this->_class_name;
            if (is_int($value)) {
                $instance = new $class('@' . $value);
            } elseif (str_starts_with((string) $value, '0000-00-00')) {
                $values[$field] = null;
                continue;
            } else {
                $instance = new $class($value, $this->db_timezone);
            }
            if (!$this->keep_database_timezone && $instance->get_timezone() && $instance->get_timezone()->get_name() !== $this->default_timezone->get_name()) {
                $instance = $instance->set_timezone($this->default_timezone);
            }
            $values[$field] = $instance;
        }
        return $values;
    }
    /**
     * Convert request data into a datetime object.
     *
     * @param mixed $value Request data
     */
    public function marshal(mixed $value): ?DateTimeInterface
    {
        if ($value instanceof DateTimeInterface) {
            if ($value instanceof Native_Date_Time) {
                $value = clone $value;
            }
            /** @var \Datetime|\DateTimeImmutable $value */
            return $value->set_timezone($this->default_timezone);
        }
        if ($value instanceof Chronos_Date) {
            return $value->to_native();
        }
        $class = $this->_class_name;
        try {
            if (is_int($value) || is_string($value) && ctype_digit($value)) {
                $date_time = new $class('@' . $value);
                return $date_time->set_timezone($this->default_timezone);
            }
            if (is_string($value)) {
                if ($this->_use_locale_marshal) {
                    $date_time = $this->_parse_locale_value($value);
                } else {
                    $date_time = $this->_parse_value($value);
                }
                if ($date_time) {
                    return $date_time->set_timezone($this->default_timezone);
                }
                return $date_time;
            }
        } catch (Exception) {
            return null;
        }
        if (!is_array($value)) {
            return null;
        }
        $value += ['year' => null, 'month' => null, 'day' => null, 'hour' => 0, 'minute' => 0, 'second' => 0, 'microsecond' => 0];
        if (!is_numeric($value['year']) || !is_numeric($value['month']) || !is_numeric($value['day']) || !is_numeric($value['hour']) || !is_numeric($value['minute']) || !is_numeric($value['second']) || !is_numeric($value['microsecond'])) {
            return null;
        }
        if (isset($value['meridian']) && (int) $value['hour'] === 12) {
            $value['hour'] = 0;
        }
        if (isset($value['meridian'])) {
            $value['hour'] = strtolower($value['meridian']) === 'am' ? $value['hour'] : $value['hour'] + 12;
        }
        $format = sprintf('%d-%02d-%02d %02d:%02d:%02d.%06d', $value['year'], $value['month'], $value['day'], $value['hour'], $value['minute'], $value['second'], $value['microsecond']);
        $date_time = new $class($format, $value['timezone'] ?? $this->user_timezone);
        return $date_time->set_timezone($this->default_timezone);
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
        if ($enable === false) {
            $this->_use_locale_marshal = $enable;
            return $this;
        }
        if (is_a($this->_class_name, DateTime::class, true)) {
            $this->_use_locale_marshal = $enable;
            return $this;
        }
        throw new Database_Exception(sprintf('Cannot use locale parsing with the %s class', $this->_class_name));
    }
    /**
     * Sets the locale-aware format used by `marshal()` when parsing strings.
     *
     * See `Cake\I18n\Time::parseDateTime()` for accepted formats.
     *
     * @param array|string $format The locale-aware format
     * @see \Cake\I18n\Time::parseDateTime()
     * @return $this
     */
    public function set_locale_format(array|string $format): static
    {
        $this->_locale_marshal_format = $format;
        return $this;
    }
    /**
     * Get the classname used for building objects.
     *
     * @return class-string<\Cake\I18n\DateTime>|class-string<\DateTimeImmutable>
     */
    public function get_date_time_class_name(): string
    {
        return $this->_class_name;
    }
    /**
     * Converts a string into a DateTime object after parsing it using the locale
     * aware parser with the format set by `setLocaleFormat()`.
     *
     * @param string $value The value to parse and convert to an object.
     */
    protected function _parse_locale_value(string $value): ?DateTime
    {
        /** @var class-string<\Cake\I18n\DateTime> $class */
        $class = $this->_class_name;
        return $class::parse_date_time($value, $this->_locale_marshal_format, $this->user_timezone);
    }
    /**
     * Converts a string into a DateTime object after parsing it using the
     * formats in `_marshalFormats`.
     *
     * @param string $value The value to parse and convert to an object.
     */
    protected function _parse_value(string $value): DateTime|DateTimeImmutable|null
    {
        $class = $this->_class_name;
        foreach ($this->_marshal_formats as $format) {
            try {
                $date_time = $class::create_from_format($format, $value, $this->user_timezone);
                // Check for false in case DateTimeImmutable is used
                if ($date_time !== false) {
                    return $date_time;
                }
            } catch (InvalidArgumentException) {
                // Chronos wraps DateTimeImmutable::createFromFormat and throws
                // exception if parse fails.
                continue;
            }
        }
        return null;
    }
    /**
     * @inheritDoc
     */
    public function to_statement(mixed $value, Driver $driver): int
    {
        return PDO::PARAM_STR;
    }
}