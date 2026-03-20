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
 * @since         3.6.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Console;

use Cake\Console\Exception\Console_Exception;
use function Cake\Core\Deprecation_Warning;
/**
 * Provides an interface for interacting with
 * a command's options and arguments.
 */
class Arguments
{
    /**
     * Constructor
     *
     * @param array<int, array<string>|string> $args Positional arguments
     * @param array<string, array<string>|string|bool|null> $options Named arguments
     * @param array<int, string> $argNames List of argument names. Order is expected to be
     *  the same as $args.
     */
    public function __construct(
        /**
         * Positional arguments.
         */
        protected array $args,
        /**
         * Named options
         */
        protected array $options,
        /**
         * Positional argument name map
         */
        protected array $arg_names
    )
    {
    }
    /**
     * Get all positional arguments.
     *
     * @return array<int, array<string>|string>
     */
    public function get_arguments(): array
    {
        return $this->args;
    }
    /**
     * Get positional arguments by index.
     *
     * @param int $index The argument index to access.
     * @return string|null The argument value or null
     */
    public function get_argument_at(int $index): ?string
    {
        if (!$this->has_argument_at($index)) {
            return null;
        }
        $value = $this->args[$index];
        if ($value !== null && !is_string($value)) {
            throw new Console_Exception(sprintf('Argument at index `%d` is not of type `string`, use `getArrayArgument()` instead.', $index));
        }
        return $value;
    }
    /**
     * Get positional arguments (multiple) by index.
     *
     * @param int $index The argument index to access.
     * @return array|null The argument value or null
     */
    public function get_array_argument_at(int $index): ?array
    {
        if (!$this->has_argument_at($index)) {
            return null;
        }
        $value = $this->args[$index];
        if ($value !== null && !is_array($value)) {
            throw new Console_Exception(sprintf('Argument at index `%d` is not of type `array`, use `getArgument()` instead.', $index));
        }
        return $value;
    }
    /**
     * Check if a positional argument exists by index
     *
     * @param int $index The argument index to check.
     */
    public function has_argument_at(int $index): bool
    {
        return isset($this->args[$index]);
    }
    /**
     * Check if a positional argument exists by name
     *
     * @param string $name The argument name to check.
     */
    public function has_argument(string $name): bool
    {
        $offset = array_search($name, $this->arg_names, true);
        if ($offset === false) {
            return false;
        }
        return isset($this->args[$offset]);
    }
    /**
     * Returns positional argument value by name or null if doesn't exist
     *
     * @param string $name The argument name to check.
     */
    public function get_argument(string $name): ?string
    {
        $this->assert_argument_exists($name);
        $offset = array_search($name, $this->arg_names, true);
        $value = $this->args[$offset] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new Console_Exception(sprintf('Argument `%s` is not of type `string`, use `getArrayArgument()` instead.', $name));
        }
        return $value;
    }
    /**
     * Gets a multiple (array) argument's value or null if not set.
     *
     * @param string $name Argument name.
     * @return array<string>|null
     */
    public function get_array_argument(string $name): ?array
    {
        $this->assert_argument_exists($name);
        $offset = array_search($name, $this->arg_names, true);
        $value = $this->args[$offset] ?? null;
        if ($value !== null && !is_array($value)) {
            throw new Console_Exception(sprintf('Argument `%s` is not of type `array`, use `getArgument()` instead.', $name));
        }
        return $value;
    }
    /**
     * Get an array of all the options
     *
     * @return array<string, array<string>|string|bool|null>
     */
    public function get_options(): array
    {
        return $this->options;
    }
    /**
     * Get a non-multiple option's value or null if not set.
     *
     * @param string $name The name of the option to check.
     */
    public function get_option(string $name): string|bool|null
    {
        $value = $this->options[$name] ?? null;
        if (is_array($value)) {
            throw new Console_Exception(sprintf('Cannot get multiple values for option `%s`, use `getArrayOption()` instead.', $name));
        }
        assert($value === null || is_string($value) || is_bool($value));
        return $value;
    }
    /**
     * Get a boolean option's value or null if not set.
     *
     * @param string $name Option name.
     */
    public function get_boolean_option(string $name): ?bool
    {
        $value = $this->options[$name] ?? null;
        if ($value !== null && !is_bool($value)) {
            throw new Console_Exception(sprintf('Option `%s` is not of type `bool`, use `getOption()` instead.', $name));
        }
        return $value;
    }
    /**
     * Gets a multiple option's value or null if not set.
     *
     * @return array<string>|null
     * @deprecated 5.2.0 Use getArrayOption instead.
     */
    public function get_multiple_option(string $name): ?array
    {
        deprecation_warning('5.2.0', 'getMultipleOption() is deprecated. Use `getArrayOption()` instead.');
        return $this->get_array_option($name);
    }
    /**
     * Gets a multiple (array) option's value or null if not set.
     *
     * @return array<string>|null
     */
    public function get_array_option(string $name): ?array
    {
        $value = $this->options[$name] ?? null;
        if ($value !== null && !is_array($value)) {
            throw new Console_Exception(sprintf('Option `%s` is not of type `array`, use `getOption()` instead.', $name));
        }
        return $value;
    }
    /**
     * Check if an option is defined and not null.
     *
     * @param string $name The name of the option to check.
     */
    public function has_option(string $name): bool
    {
        return isset($this->options[$name]);
    }
    protected function assert_argument_exists(string $name): void
    {
        if (in_array($name, $this->arg_names, true)) {
            return;
        }
        throw new Console_Exception(sprintf('Argument `%s` is not defined on this Command. Could this be an option maybe?', $name));
    }
}