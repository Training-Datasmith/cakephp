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
use Cake\I18n\Date;
use DateTimeInterface;
use Exception;
use InvalidArgumentException;
/**
 * Class DateType
 */
class Date_Type extends Base_Type implements Batch_Casting_Interface
{
    protected string $_format = 'Y-m-d';
    /**
     * @var array<string>
     */
    protected array $_marshal_formats = ['Y-m-d'];
    /**
     * Whether `marshal()` should use locale-aware parser with `_localeMarshalFormat`.
     */
    protected bool $_use_locale_marshal = false;
    /**
     * The locale-aware format `marshal()` uses when `_useLocaleParser` is true.
     *
     * See `Cake\I18n\Date::parseDate()` for accepted formats.
     */
    protected string|int|null $_locale_marshal_format = null;
    /**
     * The classname to use when creating objects.
     *
     * @var class-string<\Cake\Chronos\ChronosDate>
     */
    protected string $_class_name;
    /**
     * @inheritDoc
     */
    public function __construct(?string $name = null)
    {
        parent::__construct($name);
        $this->_class_name = class_exists(Date::class) ? Date::class : Chronos_Date::class;
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
        if (is_int($value)) {
            $class = $this->_class_name;
            $value = new $class('@' . $value);
        }
        assert(is_object($value) && method_exists($value, 'format'));
        return $value->format($this->_format);
    }
    /**
     * {@inheritDoc}
     *
     * @param mixed $value Value to be converted to PHP equivalent
     * @param \Cake\Database\Driver $driver Object from which database preferences and configuration will be extracted
     */
    public function to_php(mixed $value, Driver $driver): ?Chronos_Date
    {
        if ($value === null) {
            return null;
        }
        $class = $this->_class_name;
        if (is_int($value)) {
            $instance = new $class('@' . $value);
        } elseif (str_starts_with((string) $value, '0000-00-00')) {
            return null;
        } else {
            $instance = new $class($value);
        }
        return $instance;
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
                $instance = new $class($value);
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
    public function marshal(mixed $value): ?Chronos_Date
    {
        if ($value instanceof $this->_class_name) {
            return $value;
        }
        if ($value instanceof DateTimeInterface || $value instanceof Chronos_Date) {
            return new $this->_class_name($value->format($this->_format));
        }
        $class = $this->_class_name;
        try {
            if (is_int($value) || is_string($value) && ctype_digit($value)) {
                return new $class('@' . $value);
            }
            if (is_string($value)) {
                if ($this->_use_locale_marshal) {
                    return $this->_parse_locale_value($value);
                }
                return $this->_parse_value($value);
            }
        } catch (Exception) {
            return null;
        }
        if (!is_array($value) || !isset($value['year'], $value['month'], $value['day']) || !is_numeric($value['year']) || !is_numeric($value['month']) || !is_numeric($value['day'])) {
            return null;
        }
        $format = sprintf('%d-%02d-%02d', $value['year'], $value['month'], $value['day']);
        return new $class($format);
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
        if (is_a($this->_class_name, Date::class, true)) {
            $this->_use_locale_marshal = $enable;
            return $this;
        }
        throw new Database_Exception(sprintf('Cannot use locale parsing with %s', $this->_class_name));
    }
    /**
     * Sets the locale-aware format used by `marshal()` when parsing strings.
     *
     * See `Cake\I18n\Date::parseDate()` for accepted formats.
     *
     * @param string|int $format The locale-aware format
     * @see \Cake\I18n\Date::parseDate()
     * @return $this
     */
    public function set_locale_format(string|int $format): static
    {
        $this->_locale_marshal_format = $format;
        return $this;
    }
    /**
     * Get the classname used for building objects.
     *
     * @return class-string<\Cake\Chronos\ChronosDate>
     */
    public function get_date_class_name(): string
    {
        return $this->_class_name;
    }
    protected function _parse_locale_value(string $value): ?Date
    {
        /** @var class-string<\Cake\I18n\Date> $class */
        $class = $this->_class_name;
        return $class::parse_date($value, $this->_locale_marshal_format);
    }
    /**
     * Converts a string into a DateTime object after parsing it using the
     * formats in `_marshalFormats`.
     *
     * @param string $value The value to parse and convert to an object.
     */
    protected function _parse_value(string $value): ?Chronos_Date
    {
        $class = $this->_class_name;
        foreach ($this->_marshal_formats as $format) {
            try {
                return $class::create_from_format($format, $value);
            } catch (InvalidArgumentException) {
                continue;
            }
        }
        return null;
    }
}