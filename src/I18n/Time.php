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
 * @since         5.0.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\I18n;

use Cake\Chronos\Chronos_Time;
use Closure;
use Intl_Date_Formatter;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;
/**
 * Extends time class provided by Chronos.
 *
 * Adds handy methods and locale-aware formatting helpers.
 *
 * @phpstan-immutable
 */
class Time extends Chronos_Time implements JsonSerializable, Stringable
{
    use Date_Format_Trait;
    /**
     * The format to use when formatting a time using `Cake\I18n\Time::i18nFormat()`
     * and `__toString`.
     *
     * The format should be either the formatting constants from IntlDateFormatter as
     * described in (https://secure.php.net/manual/en/class.intldateformatter.php) or a pattern
     * as specified in (https://unicode-org.github.io/icu-docs/apidoc/released/icu4c/classSimpleDateFormat.html#details)
     *
     * @see \Cake\I18n\Time::i18nFormat()
     */
    protected static string|int $_to_string_format = Intl_Date_Formatter::SHORT;
    /**
     * The format to use when converting this object to JSON.
     *
     * The format should be either the formatting constants from IntlDateFormatter as
     * described in (https://secure.php.net/manual/en/class.intldateformatter.php) or a pattern
     * as specified in (https://unicode-org.github.io/icu-docs/apidoc/released/icu4c/classSimpleDateFormat.html#details)
     *
     * @see \Cake\I18n\Date::i18nFormat()
     */
    protected static Closure|string|int $_json_encode_format = "HH':'mm':'ss";
    /**
     * The format to use when formatting a time using `Cake\I18n\Time::nice()`
     *
     * The format should be either the formatting constants from IntlDateFormatter as
     * described in (https://secure.php.net/manual/en/class.intldateformatter.php) or a pattern
     * as specified in (https://unicode-org.github.io/icu-docs/apidoc/released/icu4c/classSimpleDateFormat.html#details)
     *
     * @see \Cake\I18n\Time::nice()
     */
    public static string|int $nice_format = Intl_Date_Formatter::MEDIUM;
    /**
     * Sets the default format used when type converting instances of this type to string
     *
     * The format should be either the formatting constants from IntlDateFormatter as
     * described in (https://secure.php.net/manual/en/class.intldateformatter.php) or a pattern
     * as specified in (https://unicode-org.github.io/icu-docs/apidoc/released/icu4c/classSimpleDateFormat.html#details)
     *
     * @param string|int $format Format.
     * @phpcsSuppress SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint
     */
    public static function set_to_string_format($format): void
    {
        static::$_to_string_format = $format;
    }
    /**
     * Resets the format used to the default when converting an instance of this type to
     * a string
     */
    public static function reset_to_string_format(): void
    {
        static::set_to_string_format(Intl_Date_Formatter::SHORT);
    }
    /**
     * Sets the default format used when converting this object to JSON
     *
     * The format should be either the formatting constants from IntlDateFormatter as
     * described in (https://secure.php.net/manual/en/class.intldateformatter.php) or a pattern
     * as specified in (http://www.icu-project.org/apiref/icu4c/classSimpleDateFormat.html#details)
     *
     * Alternatively, the format can provide a callback. In this case, the callback
     * can receive this object and return a formatted string.
     *
     * @see \Cake\I18n\Time::i18nFormat()
     * @param \Closure|string|int $format Format.
     */
    public static function set_json_encode_format(Closure|string|int $format): void
    {
        static::$_json_encode_format = $format;
    }
    /**
     * Returns a new Time object after parsing the provided $time string based on
     * the passed or configured date time format. This method is locale dependent,
     * Any string passed to this function will be interpreted as a locale
     * dependent string.
     *
     * When no $format is provided, the IntlDateFormatter::SHORT format will be used.
     *
     * If it was impossible to parse the provided time, null will be returned.
     *
     * Example:
     *
     * ```
     *  $time = Time::parseTime('11:23pm');
     * ```
     *
     * @param string $time The time string to parse.
     * @param string|int|null $format Any format accepted by IntlDateFormatter.
     */
    public static function parse_time(string $time, string|int|null $format = null): ?static
    {
        $format ??= [Intl_Date_Formatter::NONE, Intl_Date_Formatter::SHORT];
        if (is_int($format)) {
            $format = [Intl_Date_Formatter::NONE, $format];
        }
        return static::_parse_date_time($time, $format);
    }
    /**
     * Returns a formatted string for this time object using the preferred format and
     * language for the specified locale.
     *
     * It is possible to specify the desired format for the string to be displayed.
     * You can either pass `IntlDateFormatter` constants as the first argument of this
     * function, or pass a full ICU date formatting string as specified in the following
     * resource: https://unicode-org.github.io/icu/userguide/format_parse/datetime/#datetime-format-syntax.
     *
     * ### Examples
     *
     * ```
     * $time = new Time('23:10:10');
     * $time->i18nFormat();
     * $time->i18nFormat(\IntlDateFormatter::FULL);
     * $time->i18nFormat("HH':'mm':'ss");
     * ```
     *
     * You can control the default format used through `Time::setToStringFormat()`.
     *
     * You can read about the available IntlDateFormatter constants at
     * https://secure.php.net/manual/en/class.intldateformatter.php
     *
     * Should you need to use a different locale for displaying this time object,
     * pass a locale string as the third parameter to this function.
     *
     * ### Examples
     *
     * ```
     * $time = new Time('2014-04-20');
     * $time->i18nFormat('de-DE');
     * $time->i18nFormat(\IntlDateFormatter::FULL, 'de-DE');
     * ```
     *
     * You can control the default locale used through `DateTime::setDefaultLocale()`.
     * If empty, the default will be taken from the `intl.default_locale` ini config.
     *
     * @param string|int|null $format Format string.
     * @param string|null $locale The locale name in which the time should be displayed (e.g. pt-BR)
     * @return string|int Formatted and translated time string
     */
    public function i18n_format(string|int|null $format = null, ?string $locale = null): string|int
    {
        if ($format === DateTime::UNIX_TIMESTAMP_FORMAT) {
            throw new InvalidArgumentException('UNIT_TIMESTAMP_FORMAT is not supported for Time.');
        }
        $format ??= static::$_to_string_format;
        $format = is_int($format) ? [Intl_Date_Formatter::NONE, $format] : $format;
        $locale = $locale ?: DateTime::get_default_locale();
        return $this->_format_object($this->to_native(), $format, $locale);
    }
    /**
     * Returns a nicely formatted date string for this object.
     *
     * The format to be used is stored in the static property `Time::$niceFormat`.
     *
     * @param string|null $locale The locale name in which the date should be displayed (e.g. pt-BR)
     * @return string Formatted date string
     */
    public function nice(?string $locale = null): string
    {
        return (string) $this->i18n_format(static::$nice_format, $locale);
    }
    /**
     * Returns a string that should be serialized when converting this object to JSON
     *
     * @return string|int
     */
    public function jsonSerialize(): mixed
    {
        if (static::$_json_encode_format instanceof Closure) {
            return call_user_func(static::$_json_encode_format, $this);
        }
        return $this->i18n_format(static::$_json_encode_format);
    }
    /**
     * @inheritDoc
     */
    public function __toString(): string
    {
        return (string) $this->i18n_format();
    }
}