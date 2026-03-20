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
namespace Cake\Console;

use Cake\Console\Exception\Stop_Exception;
use Cake\Log\Engine\Console_Log;
use Cake\Log\Log;
use RuntimeException;
use Spl_File_Object;
/**
 * A wrapper around the various IO operations shell tasks need to do.
 *
 * Packages up the stdout, stderr, and stdin streams providing a simple
 * consistent interface for shells to use. This class also makes mocking streams
 * easy to do in unit tests.
 */
class Console_Io
{
    /**
     * Output constant making verbose shells.
     *
     * @var int
     */
    public const VERBOSE = 2;
    /**
     * Output constant for making normal shells.
     *
     * @var int
     */
    public const NORMAL = 1;
    /**
     * Output constants for making quiet shells.
     *
     * @var int
     */
    public const QUIET = 0;
    /**
     * The output stream
     */
    protected Console_Output $_out;
    /**
     * The error stream
     */
    protected Console_Output $_err;
    /**
     * The input stream
     */
    protected Console_Input $_in;
    /**
     * The helper registry.
     */
    protected Helper_Registry $_helpers;
    /**
     * The current output level.
     */
    protected int $_level = self::NORMAL;
    /**
     * The number of bytes last written to the output stream
     * used when overwriting the previous message.
     */
    protected int $_last_written = 0;
    /**
     * Whether files should be overwritten
     */
    protected bool $force_overwrite = false;
    protected bool $interactive = true;
    /**
     * Constructor
     *
     * @param \Cake\Console\ConsoleOutput|null $out A ConsoleOutput object for stdout.
     * @param \Cake\Console\ConsoleOutput|null $err A ConsoleOutput object for stderr.
     * @param \Cake\Console\ConsoleInput|null $in A ConsoleInput object for stdin.
     * @param \Cake\Console\HelperRegistry|null $helpers A HelperRegistry instance
     */
    public function __construct(?Console_Output $out = null, ?Console_Output $err = null, ?Console_Input $in = null, ?Helper_Registry $helpers = null)
    {
        $this->_out = $out ?: new Console_Output('php://stdout');
        $this->_err = $err ?: new Console_Output('php://stderr');
        $this->_in = $in ?: new Console_Input('php://stdin');
        $this->_helpers = $helpers ?: new Helper_Registry();
        $this->_helpers->set_io($this);
    }
    /**
     * @param bool $value Value
     */
    public function set_interactive(bool $value): void
    {
        $this->interactive = $value;
    }
    /**
     * Get/set the current output level.
     *
     * @param int|null $level The current output level.
     * @return int The current output level.
     */
    public function level(?int $level = null): int
    {
        if ($level !== null) {
            $this->_level = $level;
        }
        return $this->_level;
    }
    /**
     * Output at the verbose level.
     *
     * @param array<string>|string $message A string or an array of strings to output
     * @param int $newlines Number of newlines to append
     * @return int|null The number of bytes returned from writing to stdout
     *   or null if current level is less than ConsoleIo::VERBOSE
     */
    public function verbose(array|string $message, int $newlines = 1): ?int
    {
        return $this->out($message, $newlines, self::VERBOSE);
    }
    /**
     * Output at all levels.
     *
     * @param array<string>|string $message A string or an array of strings to output
     * @param int $newlines Number of newlines to append
     * @return int|null The number of bytes returned from writing to stdout
     *   or null if current level is less than ConsoleIo::QUIET
     */
    public function quiet(array|string $message, int $newlines = 1): ?int
    {
        return $this->out($message, $newlines, self::QUIET);
    }
    /**
     * Outputs a single or multiple messages to stdout. If no parameters
     * are passed outputs just a newline.
     *
     * ### Output levels
     *
     * There are 3 built-in output level. ConsoleIo::QUIET, ConsoleIo::NORMAL, ConsoleIo::VERBOSE.
     * The verbose and quiet output levels, map to the `verbose` and `quiet` output switches
     * present in most shells. Using ConsoleIo::QUIET for a message means it will always display.
     * While using ConsoleIo::VERBOSE means it will only display when verbose output is toggled.
     *
     * @param array<string>|string $message A string or an array of strings to output
     * @param int $newlines Number of newlines to append
     * @param int $level The message's output level, see above.
     * @return int|null The number of bytes returned from writing to stdout
     *   or null if provided $level is greater than current level.
     * @link https://book.cakephp.org/5/en/console-commands/input-output.html#creating-output
     */
    public function out(array|string $message = '', int $newlines = 1, int $level = self::NORMAL): ?int
    {
        if ($level > $this->_level) {
            return null;
        }
        $this->_last_written = $this->_out->write($message, $newlines);
        return $this->_last_written;
    }
    /**
     * Convenience method for out() that wraps message between <info> tag
     *
     * @param array<string>|string $message A string or an array of strings to output
     * @param int $newlines Number of newlines to append
     * @param int $level The message's output level, see above.
     * @return int|null The number of bytes returned from writing to stdout
     *   or null if provided $level is greater than current level.
     * @link https://book.cakephp.org/5/en/console-commands/input-output.html#creating-output
     */
    public function info(array|string $message, int $newlines = 1, int $level = self::NORMAL): ?int
    {
        $message_type = 'info';
        $message = $this->wrap_message_with_type($message_type, $message);
        return $this->out($message, $newlines, $level);
    }
    /**
     * Convenience method for out() that wraps message between <comment> tag
     *
     * @param array<string>|string $message A string or an array of strings to output
     * @param int $newlines Number of newlines to append
     * @param int $level The message's output level, see above.
     * @return int|null The number of bytes returned from writing to stdout
     *   or null if provided $level is greater than current level.
     * @link https://book.cakephp.org/5/en/console-commands/input-output.html#creating-output
     */
    public function comment(array|string $message, int $newlines = 1, int $level = self::NORMAL): ?int
    {
        $message_type = 'comment';
        $message = $this->wrap_message_with_type($message_type, $message);
        return $this->out($message, $newlines, $level);
    }
    /**
     * Convenience method for err() that wraps message between <warning> tag
     *
     * @param array<string>|string $message A string or an array of strings to output
     * @param int $newlines Number of newlines to append
     * @return int The number of bytes returned from writing to stderr.
     * @link https://book.cakephp.org/5/en/console-commands/input-output.html#creating-output
     */
    public function warning(array|string $message, int $newlines = 1): int
    {
        $message_type = 'warning';
        $message = $this->wrap_message_with_type($message_type, $message);
        return $this->err($message, $newlines);
    }
    /**
     * Convenience method for err() that wraps message between <error> tag
     *
     * @param array<string>|string $message A string or an array of strings to output
     * @param int $newlines Number of newlines to append
     * @return int The number of bytes returned from writing to stderr.
     * @link https://book.cakephp.org/5/en/console-commands/input-output.html#creating-output
     */
    public function error(array|string $message, int $newlines = 1): int
    {
        $message_type = 'error';
        $message = $this->wrap_message_with_type($message_type, $message);
        return $this->err($message, $newlines);
    }
    /**
     * Convenience method for out() that wraps message between <success> tag
     *
     * @param array<string>|string $message A string or an array of strings to output
     * @param int $newlines Number of newlines to append
     * @param int $level The message's output level, see above.
     * @return int|null The number of bytes returned from writing to stdout
     *   or null if provided $level is greater than current level.
     * @link https://book.cakephp.org/5/en/console-commands/input-output.html#creating-output
     */
    public function success(array|string $message, int $newlines = 1, int $level = self::NORMAL): ?int
    {
        $message_type = 'success';
        $message = $this->wrap_message_with_type($message_type, $message);
        return $this->out($message, $newlines, $level);
    }
    /**
     * Halts the current process with a StopException.
     *
     * @param string $message Error message.
     * @param int $code Error code.
     * @throws \Cake\Console\Exception\StopException
     */
    public function abort(string $message, int $code = Command_Interface::CODE_ERROR): never
    {
        $this->error($message);
        throw new Stop_Exception($message, $code);
    }
    /**
     * Wraps a message with a given message type, e.g. <warning>
     *
     * @param string $messageType The message type, e.g. "warning".
     * @param array<string>|string $message The message to wrap.
     * @return array<string>|string The message wrapped with the given message type.
     */
    protected function wrap_message_with_type(string $message_type, array|string $message): array|string
    {
        if (is_array($message)) {
            foreach ($message as $k => $v) {
                $message[$k] = "<{$message_type}>{$v}</{$message_type}>";
            }
        } else {
            $message = "<{$message_type}>{$message}</{$message_type}>";
        }
        return $message;
    }
    /**
     * Overwrite some already output text.
     *
     * Useful for building progress bars, or when you want to replace
     * text already output to the screen with new text.
     *
     * **Warning** You cannot overwrite text that contains newlines.
     *
     * @param array<string>|string $message The message to output.
     * @param int $newlines Number of newlines to append.
     * @param int|null $size The number of bytes to overwrite. Defaults to the
     *    length of the last message output.
     */
    public function overwrite(array|string $message, int $newlines = 1, ?int $size = null): void
    {
        $size = $size ?: $this->_last_written;
        // Output backspaces.
        $this->out(str_repeat("\x08", $size), 0);
        $new_bytes = (int) $this->out($message, 0);
        // Fill any remaining bytes with spaces.
        $fill = $size - $new_bytes;
        if ($fill > 0) {
            $this->out(str_repeat(' ', $fill), 0);
        }
        if ($newlines) {
            $this->out($this->nl($newlines), 0);
        }
        // Store length of content + fill so if the new content
        // is shorter than the old content the next overwrite
        // will work.
        if ($fill > 0) {
            $this->_last_written = $new_bytes + $fill;
        }
    }
    /**
     * Outputs a single or multiple error messages to stderr. If no parameters
     * are passed outputs just a newline.
     *
     * @param array<string>|string $message A string or an array of strings to output
     * @param int $newlines Number of newlines to append
     * @return int The number of bytes returned from writing to stderr.
     * @link https://book.cakephp.org/5/en/console-commands/input-output.html#creating-output
     */
    public function err(array|string $message = '', int $newlines = 1): int
    {
        return $this->_err->write($message, $newlines);
    }
    /**
     * Returns a single or multiple linefeeds sequences.
     *
     * @param int $multiplier Number of times the linefeed sequence should be repeated
     */
    public function nl(int $multiplier = 1): string
    {
        return str_repeat(Console_Output::LF, $multiplier);
    }
    /**
     * Outputs a series of minus characters to the standard output, acts as a visual separator.
     *
     * @param int $newlines Number of newlines to pre- and append
     * @param int $width Width of the line, defaults to 79
     */
    public function hr(int $newlines = 0, int $width = 79): void
    {
        $this->out('', $newlines);
        $this->out(str_repeat('-', $width));
        $this->out('', $newlines);
    }
    /**
     * Prompts the user for input, and returns it.
     *
     * @param string $prompt Prompt text.
     * @param string|null $default Default input value.
     * @return string Either the default value, or the user-provided input.
     */
    public function ask(string $prompt, ?string $default = null): string
    {
        return $this->_get_input($prompt, null, $default);
    }
    /**
     * Change the output mode of the stdout stream
     *
     * @param int $mode The output mode.
     * @see \Cake\Console\ConsoleOutput::setOutputAs()
     */
    public function set_output_as(int $mode): void
    {
        $this->_out->set_output_as($mode);
    }
    /**
     * Gets defined styles.
     *
     * @see \Cake\Console\ConsoleOutput::styles()
     */
    public function styles(): array
    {
        return $this->_out->styles();
    }
    /**
     * Get defined style.
     *
     * @param string $style The style to get.
     * @see \Cake\Console\ConsoleOutput::getStyle()
     */
    public function get_style(string $style): array
    {
        return $this->_out->get_style($style);
    }
    /**
     * Adds a new output style.
     *
     * @param string $style The style to set.
     * @param array $definition The array definition of the style to change or create.
     * @see \Cake\Console\ConsoleOutput::setStyle()
     */
    public function set_style(string $style, array $definition): void
    {
        $this->_out->set_style($style, $definition);
    }
    /**
     * Prompts the user for input based on a list of options, and returns it.
     *
     * @param string $prompt Prompt text.
     * @param array<string>|string $options Array or string of options.
     * @param string|null $default Default input value.
     * @return string Either the default value, or the user-provided input.
     */
    public function ask_choice(string $prompt, array|string $options, ?string $default = null): string
    {
        if (is_string($options)) {
            if (str_contains($options, ',')) {
                $options = explode(',', $options);
            } elseif (str_contains($options, '/')) {
                $options = explode('/', $options);
            } else {
                $options = [$options];
            }
        }
        $print_options = '(' . implode('/', $options) . ')';
        $options = array_merge(array_map(strtolower(...), $options), array_map(strtoupper(...), $options), $options);
        $in = '';
        while ($in === '' || !in_array($in, $options, true)) {
            $in = $this->_get_input($prompt, $print_options, $default);
        }
        return $in;
    }
    /**
     * Prompts the user for input, and returns it.
     *
     * @param string $prompt Prompt text.
     * @param string|null $options String of options. Pass null to omit.
     * @param string|null $default Default input value. Pass null to omit.
     * @return string Either the default value, or the user-provided input.
     */
    protected function _get_input(string $prompt, ?string $options, ?string $default): string
    {
        if (!$this->interactive) {
            return (string) $default;
        }
        $options_text = '';
        if ($options !== null) {
            $options_text = " {$options} ";
        }
        $default_text = '';
        if ($default !== null) {
            $default_text = "[{$default}] ";
        }
        $this->_out->write('<question>' . $prompt . "</question>{$options_text}\n{$default_text}> ", 0);
        $result = $this->_in->read();
        $result = $result === null ? '' : trim($result);
        if ($default !== null && $result === '') {
            return $default;
        }
        return $result;
    }
    /**
     * Connects or disconnects the loggers to the console output.
     *
     * Used to enable or disable logging stream output to stdout and stderr
     * If you don't wish all log output in stdout or stderr
     * through Cake's Log class, call this function with `$enable=false`.
     *
     * If you would like to take full control of how console application logging
     * to stdout works add a logger that uses `'className' => 'Console'`. By
     * providing a console logger you replace the framework default behavior.
     *
     * @param int|bool $enable Use a boolean to enable/toggle all logging. Use
     *   one of the verbosity constants (self::VERBOSE, self::QUIET, self::NORMAL)
     *   to control logging levels. VERBOSE enables debug logs, NORMAL does not include debug logs,
     *   QUIET disables notice, info and debug logs.
     */
    public function set_loggers(int|bool $enable): void
    {
        Log::drop('stdout');
        Log::drop('stderr');
        if ($enable === false) {
            return;
        }
        // If the application has configured a console logger
        // we don't add a redundant one.
        foreach (Log::configured() as $logger_name) {
            $log = Log::engine($logger_name);
            if ($log instanceof Console_Log) {
                return;
            }
        }
        $out_levels = ['notice', 'info'];
        if ($enable === static::VERBOSE || $enable === true) {
            $out_levels[] = 'debug';
        }
        if ($enable !== static::QUIET) {
            $stdout = new Console_Log(['types' => $out_levels, 'stream' => $this->_out]);
            Log::set_config('stdout', ['engine' => $stdout]);
        }
        $stderr = new Console_Log(['types' => ['emergency', 'alert', 'critical', 'error', 'warning'], 'stream' => $this->_err]);
        Log::set_config('stderr', ['engine' => $stderr]);
    }
    /**
     * Render a Console Helper
     *
     * Create and render the output for a helper object. If the helper
     * object has not already been loaded, it will be loaded and constructed.
     *
     * @param string $name The name of the helper to render
     * @param array<string, mixed> $config Configuration data for the helper.
     * @return \Cake\Console\Helper The created helper instance.
     */
    public function helper(string $name, array $config = []): Helper
    {
        $name = ucfirst($name);
        /** @var \Cake\Console\Helper */
        return $this->_helpers->load($name, $config);
    }
    /**
     * Create a file at the given path.
     *
     * This method will prompt the user if a file will be overwritten.
     * Setting `forceOverwrite` to true will suppress this behavior
     * and always overwrite the file.
     *
     * If the user replies `a` subsequent `forceOverwrite` parameters will
     * be coerced to true and all files will be overwritten.
     *
     * @param string $path The path to create the file at.
     * @param string $contents The contents to put into the file.
     * @param bool $forceOverwrite Whether the file should be overwritten.
     *   If true, no question will be asked about whether to overwrite existing files.
     * @return bool Success.
     * @throws \Cake\Console\Exception\StopException When `q` is given as an answer
     *   to whether a file should be overwritten.
     */
    public function create_file(string $path, string $contents, bool $force_overwrite = false): bool
    {
        $this->out();
        $force_overwrite = $force_overwrite || $this->force_overwrite;
        if (file_exists($path) && $force_overwrite === false) {
            $this->warning("File `{$path}` exists");
            $key = $this->ask_choice('Do you want to overwrite?', ['y', 'n', 'a', 'q'], 'n');
            $key = strtolower($key);
            if ($key === 'q') {
                $this->error('Quitting.', 2);
                throw new Stop_Exception('Not creating file. Quitting.');
            }
            if ($key === 'a') {
                $this->force_overwrite = true;
                $key = 'y';
            }
            if ($key !== 'y') {
                $this->out("Skip `{$path}`", 2);
                return false;
            }
        } else {
            $this->out("Creating file {$path}");
        }
        try {
            // Create the directory using the current user permissions.
            $directory = dirname($path);
            if (!file_exists($directory)) {
                mkdir($directory, 0777 ^ umask(), true);
            }
            $file = new Spl_File_Object($path, 'w');
        } catch (RuntimeException) {
            $this->error("Could not write to `{$path}`. Permission denied.", 2);
            return false;
        }
        $file->rewind();
        $file->fwrite($contents);
        if (file_exists($path)) {
            $this->out("<success>Wrote</success> `{$path}`");
            return true;
        }
        $this->error("Could not write to `{$path}`.", 2);
        return false;
    }
}