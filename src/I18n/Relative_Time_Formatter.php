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
 * @since         3.2.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\I18n;

use Cake\Chronos\Chronos_Date;
use Cake\Chronos\Difference_Formatter_Interface;
use DateTimeInterface;
/**
 * Helper class for formatting relative dates & times.
 *
 * @internal
 */
class Relative_Time_Formatter implements Difference_Formatter_Interface
{
    /**
     * Get the difference in a human-readable format.
     *
     * @param \Cake\Chronos\ChronosDate|\DateTimeInterface $first The datetime to start with.
     * @param \Cake\Chronos\ChronosDate|\DateTimeInterface|null $second The datetime to compare against.
     * @param bool $absolute Removes time difference modifiers ago, after, etc.
     * @return string The difference between the two days in a human-readable format.
     * @see \Cake\Chronos\Chronos::diffForHumans
     */
    public function diff_for_humans(Chronos_Date|DateTimeInterface $first, Chronos_Date|DateTimeInterface|null $second = null, bool $absolute = false): string
    {
        $is_now = $second === null;
        if ($second === null) {
            if ($first instanceof Chronos_Date) {
                $second = Date::now();
            } else {
                $second = DateTime::now($first->get_timezone());
            }
        }
        assert($first instanceof Chronos_Date && $second instanceof Chronos_Date || $first instanceof DateTimeInterface && $second instanceof DateTimeInterface);
        $diff_interval = $first->diff($second);
        switch (true) {
            case $diff_interval->y > 0:
                $count = $diff_interval->y;
                $message = __dn('cake', '{0} year', '{0} years', $count, $count);
                break;
            case $diff_interval->m > 0:
                $count = $diff_interval->m;
                $message = __dn('cake', '{0} month', '{0} months', $count, $count);
                break;
            case $diff_interval->d > 0:
                $count = $diff_interval->d;
                if ($count >= DateTime::DAYS_PER_WEEK) {
                    $count = (int) ($count / DateTime::DAYS_PER_WEEK);
                    $message = __dn('cake', '{0} week', '{0} weeks', $count, $count);
                } else {
                    $message = __dn('cake', '{0} day', '{0} days', $count, $count);
                }
                break;
            case $diff_interval->h > 0:
                $count = $diff_interval->h;
                $message = __dn('cake', '{0} hour', '{0} hours', $count, $count);
                break;
            case $diff_interval->i > 0:
                $count = $diff_interval->i;
                $message = __dn('cake', '{0} minute', '{0} minutes', $count, $count);
                break;
            default:
                $count = $diff_interval->s;
                $message = __dn('cake', '{0} second', '{0} seconds', $count, $count);
                break;
        }
        if ($absolute) {
            return $message;
        }
        $is_future = $diff_interval->invert === 1;
        if ($is_now) {
            return $is_future ? __d('cake', '{0} from now', $message) : __d('cake', '{0} ago', $message);
        }
        return $is_future ? __d('cake', '{0} after', $message) : __d('cake', '{0} before', $message);
    }
    /**
     * Format a time into a relative timestring.
     *
     * @param \Cake\I18n\DateTime|\Cake\I18n\Date $time The time instance to format.
     * @param array<string, mixed> $options Array of options.
     * @return string Relative time string.
     * @see \Cake\I18n\Time::timeAgoInWords()
     */
    public function time_ago_in_words(DateTime|Date $time, array $options = []): string
    {
        $options = $this->_options($options, DateTime::class);
        if ($time instanceof DateTime && $options['timezone']) {
            $time = $time->set_timezone($options['timezone']);
        }
        /** @var \Cake\Chronos\Chronos $from */
        $from = $options['from'];
        $now = (int) $from->format('U');
        $in_seconds = (int) $time->format('U');
        $backwards = $in_seconds > $now;
        $future_time = $now;
        $past_time = $in_seconds;
        if ($backwards) {
            $future_time = $in_seconds;
            $past_time = $now;
        }
        $diff = $future_time - $past_time;
        if (!$diff) {
            return __d('cake', 'just now', 'just now');
        }
        if ($diff > abs($now - (int) (new DateTime($options['end']))->format('U'))) {
            return sprintf($options['absoluteString'], $time->i18n_format($options['format']));
        }
        $diff_data = $this->_diff_data($future_time, $past_time, $backwards, $options);
        [$f_num, $f_word, $years, $months, $weeks, $days, $hours, $minutes, $seconds] = array_values($diff_data);
        $relative_date = [];
        if ($f_num >= 1 && $years > 0) {
            $relative_date[] = __dn('cake', '{0} year', '{0} years', $years, $years);
        }
        if ($f_num >= 2 && $months > 0) {
            $relative_date[] = __dn('cake', '{0} month', '{0} months', $months, $months);
        }
        if ($f_num >= 3 && $weeks > 0) {
            $relative_date[] = __dn('cake', '{0} week', '{0} weeks', $weeks, $weeks);
        }
        if ($f_num >= 4 && $days > 0) {
            $relative_date[] = __dn('cake', '{0} day', '{0} days', $days, $days);
        }
        if ($f_num >= 5 && $hours > 0) {
            $relative_date[] = __dn('cake', '{0} hour', '{0} hours', $hours, $hours);
        }
        if ($f_num >= 6 && $minutes > 0) {
            $relative_date[] = __dn('cake', '{0} minute', '{0} minutes', $minutes, $minutes);
        }
        if ($f_num >= 7 && $seconds > 0) {
            $relative_date[] = __dn('cake', '{0} second', '{0} seconds', $seconds, $seconds);
        }
        $relative_date = implode(', ', $relative_date);
        // When time has passed
        if (!$backwards) {
            $about_ago = ['second' => __d('cake', 'about a second ago'), 'minute' => __d('cake', 'about a minute ago'), 'hour' => __d('cake', 'about an hour ago'), 'day' => __d('cake', 'about a day ago'), 'week' => __d('cake', 'about a week ago'), 'month' => __d('cake', 'about a month ago'), 'year' => __d('cake', 'about a year ago')];
            return $relative_date ? sprintf($options['relativeString'], $relative_date) : $about_ago[$f_word];
        }
        // When time is to come
        if ($relative_date) {
            return $relative_date;
        }
        $about_in = ['second' => __d('cake', 'in about a second'), 'minute' => __d('cake', 'in about a minute'), 'hour' => __d('cake', 'in about an hour'), 'day' => __d('cake', 'in about a day'), 'week' => __d('cake', 'in about a week'), 'month' => __d('cake', 'in about a month'), 'year' => __d('cake', 'in about a year')];
        return $about_in[$f_word];
    }
    /**
     * Calculate the data needed to format a relative difference string.
     *
     * @param string|int $futureTime The timestamp from the future.
     * @param string|int $pastTime The timestamp from the past.
     * @param bool $backwards Whether the difference was backwards.
     * @param array<string, mixed> $options An array of options.
     * @return array An array of values.
     */
    protected function _diff_data(string|int $future_time, string|int $past_time, bool $backwards, array $options): array
    {
        $future_time = (int) $future_time;
        $past_time = (int) $past_time;
        $diff = $future_time - $past_time;
        // If more than a week, then take into account the length of months
        if ($diff >= 604800) {
            $future = [];
            [$future['H'], $future['i'], $future['s'], $future['d'], $future['m'], $future['Y']] = explode('/', date('H/i/s/d/m/Y', $future_time));
            $past = [];
            [$past['H'], $past['i'], $past['s'], $past['d'], $past['m'], $past['Y']] = explode('/', date('H/i/s/d/m/Y', $past_time));
            $weeks = 0;
            $days = 0;
            $hours = 0;
            $minutes = 0;
            $seconds = 0;
            $years = (int) $future['Y'] - (int) $past['Y'];
            $months = (int) $future['m'] + 12 * $years - (int) $past['m'];
            if ($months >= 12) {
                $years = floor($months / 12);
                $months -= $years * 12;
            }
            if ((int) $future['m'] < (int) $past['m'] && (int) $future['Y'] - (int) $past['Y'] === 1) {
                $years--;
            }
            if ((int) $future['d'] >= (int) $past['d']) {
                $days = (int) $future['d'] - (int) $past['d'];
            } else {
                $days_in_past_month = (int) date('t', $past_time);
                $days_in_future_month = (int) date('t', (int) mktime(0, 0, 0, (int) $future['m'] - 1, 1, (int) $future['Y']));
                if (!$backwards) {
                    $days = $days_in_past_month - (int) $past['d'] + (int) $future['d'];
                } else {
                    $days = $days_in_future_month - (int) $past['d'] + (int) $future['d'];
                }
                if ($future['m'] !== $past['m']) {
                    $months--;
                }
            }
            if (!$months && $years >= 1 && $diff < $years * 31536000) {
                $months = 11;
                $years--;
            }
            if ($months >= 12) {
                $years++;
                $months -= 12;
            }
            if ($days >= 7) {
                $weeks = floor($days / 7);
                $days -= $weeks * 7;
            }
        } else {
            $years = 0;
            $months = 0;
            $weeks = 0;
            $days = floor($diff / 86400);
            $diff -= $days * 86400;
            $hours = floor($diff / 3600);
            $diff -= $hours * 3600;
            $minutes = floor($diff / 60);
            $diff -= $minutes * 60;
            $seconds = $diff;
        }
        $f_word = $options['accuracy']['second'];
        if ($years > 0) {
            $f_word = $options['accuracy']['year'];
        } elseif (abs($months) > 0) {
            $f_word = $options['accuracy']['month'];
        } elseif (abs($weeks) > 0) {
            $f_word = $options['accuracy']['week'];
        } elseif (abs($days) > 0) {
            $f_word = $options['accuracy']['day'];
        } elseif (abs($hours) > 0) {
            $f_word = $options['accuracy']['hour'];
        } elseif (abs($minutes) > 0) {
            $f_word = $options['accuracy']['minute'];
        }
        $f_num = str_replace(['year', 'month', 'week', 'day', 'hour', 'minute', 'second'], ['1', '2', '3', '4', '5', '6', '7'], $f_word);
        return [$f_num, $f_word, (int) $years, (int) $months, (int) $weeks, (int) $days, (int) $hours, (int) $minutes, (int) $seconds];
    }
    /**
     * Format a date into a relative date string.
     *
     * @param \Cake\I18n\DateTime|\Cake\I18n\Date $date The date to format.
     * @param array<string, mixed> $options Array of options.
     * @return string Relative date string.
     * @see \Cake\I18n\Date::timeAgoInWords()
     */
    public function date_ago_in_words(DateTime|Date $date, array $options = []): string
    {
        $options = $this->_options($options, Date::class);
        if ($date instanceof DateTime && $options['timezone']) {
            $date = $date->set_timezone($options['timezone']);
        }
        /** @var \Cake\Chronos\Chronos $from */
        $from = $options['from'];
        $now = (int) $from->format('U');
        $in_seconds = (int) $date->format('U');
        $backwards = $in_seconds > $now;
        $future_time = $now;
        $past_time = $in_seconds;
        if ($backwards) {
            $future_time = $in_seconds;
            $past_time = $now;
        }
        $diff = $future_time - $past_time;
        if (!$diff) {
            return __d('cake', 'today');
        }
        if ($diff > abs($now - (int) (new Date($options['end']))->format('U'))) {
            return sprintf($options['absoluteString'], $date->i18n_format($options['format']));
        }
        $diff_data = $this->_diff_data($future_time, $past_time, $backwards, $options);
        [$f_num, $f_word, $years, $months, $weeks, $days] = array_values($diff_data);
        $relative_date = [];
        if ($f_num >= 1 && $years > 0) {
            $relative_date[] = __dn('cake', '{0} year', '{0} years', $years, $years);
        }
        if ($f_num >= 2 && $months > 0) {
            $relative_date[] = __dn('cake', '{0} month', '{0} months', $months, $months);
        }
        if ($f_num >= 3 && $weeks > 0) {
            $relative_date[] = __dn('cake', '{0} week', '{0} weeks', $weeks, $weeks);
        }
        if ($f_num >= 4 && $days > 0) {
            $relative_date[] = __dn('cake', '{0} day', '{0} days', $days, $days);
        }
        $relative_date = implode(', ', $relative_date);
        // When time has passed
        if (!$backwards) {
            $about_ago = ['day' => __d('cake', 'about a day ago'), 'week' => __d('cake', 'about a week ago'), 'month' => __d('cake', 'about a month ago'), 'year' => __d('cake', 'about a year ago')];
            return $relative_date ? sprintf($options['relativeString'], $relative_date) : $about_ago[$f_word];
        }
        // When time is to come
        if ($relative_date) {
            return $relative_date;
        }
        $about_in = ['day' => __d('cake', 'in about a day'), 'week' => __d('cake', 'in about a week'), 'month' => __d('cake', 'in about a month'), 'year' => __d('cake', 'in about a year')];
        return $about_in[$f_word];
    }
    /**
     * Build the options for relative date formatting.
     *
     * @param array<string, mixed> $options The options provided by the user.
     * @param string $class The class name to use for defaults.
     * @return array<string, mixed> Options with defaults applied.
     * @phpstan-param class-string<\Cake\I18n\Date>|class-string<\Cake\I18n\DateTime> $class
     */
    protected function _options(array $options, string $class): array
    {
        $options += ['from' => $class::now(), 'timezone' => null, 'format' => $class::$word_format, 'accuracy' => $class::$word_accuracy, 'end' => $class::$word_end, 'relativeString' => __d('cake', '%s ago'), 'absoluteString' => __d('cake', 'on %s')];
        if (is_string($options['accuracy'])) {
            $accuracy = $options['accuracy'];
            $options['accuracy'] = [];
            foreach ($class::$word_accuracy as $key => $level) {
                $options['accuracy'][$key] = $accuracy;
            }
        } else {
            $options['accuracy'] += $class::$word_accuracy;
        }
        return $options;
    }
}