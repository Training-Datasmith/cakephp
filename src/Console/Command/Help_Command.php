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
namespace Cake\Console\Command;

use ArrayIterator;
use Cake\Console\Arguments;
use Cake\Console\Base_Command;
use Cake\Console\Command_Collection;
use Cake\Console\Command_Collection_Aware_Interface;
use Cake\Console\Command_Hidden_Interface;
use Cake\Console\Console_Io;
use Cake\Console\Console_Option_Parser;
use Cake\Console\Console_Output;
use Cake\Core\Configure;
use Cake\Core\Plugin;
use Cake\Utility\Inflector;
use Simple_Xml_Element;
/**
 * Print out command list
 */
class Help_Command extends Base_Command implements Command_Collection_Aware_Interface
{
    /**
     * The command collection to get help on.
     */
    protected Command_Collection $commands;
    /**
     * @inheritDoc
     */
    public function set_command_collection(Command_Collection $commands): void
    {
        $this->commands = $commands;
    }
    /**
     * Main function Prints out the list of commands.
     *
     * @param \Cake\Console\Arguments $args The command arguments.
     * @param \Cake\Console\ConsoleIo $io The console io
     */
    public function execute(Arguments $args, Console_Io $io): ?int
    {
        $commands = $this->commands->getIterator();
        if ($commands instanceof ArrayIterator) {
            $commands->ksort();
        }
        // Filter by command prefix if provided
        $filter = $args->get_argument('command');
        if ($filter) {
            $commands = $this->filter_by_prefix($commands, $filter);
        }
        if ($args->get_option('xml')) {
            $this->as_xml($io, $commands);
            return static::CODE_SUCCESS;
        }
        $verbose = $io->level() >= Console_Io::VERBOSE;
        $this->as_text($io, $commands, $verbose);
        return static::CODE_SUCCESS;
    }
    /**
     * Filter commands by prefix.
     *
     * @param iterable<string, string|object> $commands The command collection.
     * @param string $prefix The prefix to filter by.
     * @return array<string, string|object> Filtered commands.
     */
    protected function filter_by_prefix(iterable $commands, string $prefix): array
    {
        $filtered = [];
        foreach ($commands as $name => $class) {
            if (str_starts_with($name, $prefix . ' ') || $name === $prefix) {
                $filtered[$name] = $class;
            }
        }
        return $filtered;
    }
    /**
     * Output text.
     *
     * @param \Cake\Console\ConsoleIo $io The console io
     * @param iterable<string, string|object> $commands The command collection to output.
     * @param bool $verbose Whether to show verbose output with descriptions.
     */
    protected function as_text(Console_Io $io, iterable $commands, bool $verbose = false): void
    {
        $invert = [];
        foreach ($commands as $name => $class) {
            // Skip hidden commands
            if (is_subclass_of($class, Command_Hidden_Interface::class)) {
                continue;
            }
            if (is_object($class)) {
                $class = $class::class;
            }
            $invert[$class] ??= [];
            $invert[$class][] = $name;
        }
        $command_list = [];
        foreach ($invert as $class => $names) {
            preg_match('/^(.+)\\\\Command\\\\/', $class, $matches);
            // Probably not a useful class
            if (!$matches) {
                continue;
            }
            $shortest_name = $this->get_shortest_name($names);
            if (str_contains($shortest_name, '.')) {
                [, $shortest_name] = explode('.', $shortest_name, 2);
            }
            $command_list[] = ['name' => $shortest_name, 'description' => is_subclass_of($class, Base_Command::class) ? $class::get_description() : ''];
        }
        sort($command_list);
        if ($verbose) {
            $version = Configure::version();
            $debug = Configure::read('debug') ? 'true' : 'false';
            $io->out("<info>CakePHP:</info> {$version} (debug: {$debug})", 2);
            $this->output_paths($io);
            $this->output_grouped($io, $invert);
        } else {
            $this->output_compact_commands($io, $command_list);
            $io->out('');
        }
        $root = $this->get_root_name();
        $io->out("To run a command, type <info>`{$root} command_name [args|options]`</info>");
        $io->out("To get help on a specific command, type <info>`{$root} command_name --help`</info>");
        if (!$verbose) {
            $io->out("To see full descriptions and plugin grouping, use <info>`{$root} --help -v`</info>", 2);
        } else {
            $io->out('', 2);
        }
    }
    /**
     * Output commands grouped by plugin/namespace (verbose mode).
     *
     * @param \Cake\Console\ConsoleIo $io The console io
     * @param array<string, array<string>> $invert Inverted command map (class => names).
     */
    protected function output_grouped(Console_Io $io, array $invert): void
    {
        $grouped = [];
        $plugins = Plugin::loaded();
        foreach ($invert as $class => $names) {
            preg_match('/^(.+)\\\\Command\\\\/', $class, $matches);
            if (!$matches) {
                continue;
            }
            if ($names === []) {
                continue;
            }
            $namespace = str_replace('\\', '/', $matches[1]);
            $prefix = 'app';
            if ($namespace === 'Cake') {
                $prefix = 'cakephp';
            } elseif (method_exists($class, 'getGroup')) {
                $prefix = $class::get_group();
            } elseif (in_array($namespace, $plugins, true)) {
                $prefix = Inflector::underscore($namespace);
            }
            $shortest_name = $this->get_shortest_name($names);
            if (str_contains($shortest_name, '.')) {
                [, $shortest_name] = explode('.', $shortest_name, 2);
            }
            $grouped[$prefix][] = ['name' => $shortest_name, 'description' => is_subclass_of($class, Base_Command::class) ? $class::get_description() : ''];
        }
        ksort($grouped);
        if (isset($grouped['app'])) {
            $app = $grouped['app'];
            unset($grouped['app']);
            $grouped = ['app' => $app] + $grouped;
        }
        $io->out('<info>Available Commands:</info>', 2);
        foreach ($grouped as $prefix => $names) {
            $io->out("<info>{$prefix}</info>:");
            sort($names);
            foreach ($names as $data) {
                $io->out(' - ' . $data['name']);
                if ($data['description']) {
                    $io->info(str_pad(" └", 13, "─") . ' ' . $data['description']);
                }
            }
            $io->out('');
        }
    }
    /**
     * Output commands with inline descriptions, grouped by prefix.
     *
     * @param \Cake\Console\ConsoleIo $io The console io
     * @param array<array{name: string, description: string}> $commands List of commands with names and descriptions.
     */
    protected function output_compact_commands(Console_Io $io, array $commands): void
    {
        $max_width = $this->get_terminal_width();
        // Group commands by their first word (prefix)
        $groups = [];
        foreach ($commands as $data) {
            $parts = explode(' ', $data['name'], 2);
            $prefix = $parts[0];
            $subcommand = $parts[1] ?? null;
            $groups[$prefix] ??= [];
            $groups[$prefix][] = ['subcommand' => $subcommand, 'description' => $data['description']];
        }
        // Separate single commands from grouped commands
        $single_commands = [];
        $grouped_commands = [];
        foreach ($groups as $prefix => $cmds) {
            if (count($cmds) === 1 && $cmds[0]['subcommand'] === null) {
                $single_commands[$prefix] = $cmds[0];
            } else {
                $grouped_commands[$prefix] = $cmds;
            }
        }
        // Find the longest full command name for padding
        $max_name_length = 0;
        foreach ($commands as $data) {
            $max_name_length = max($max_name_length, strlen($data['name']));
        }
        $name_column_width = $max_name_length + 3;
        // Output single commands under "Available Commands:" header
        $is_first = true;
        if ($single_commands !== []) {
            $io->out('<info>Available Commands:</info>');
            foreach ($single_commands as $prefix => $cmd) {
                $description = $cmd['description'];
                $padding = str_repeat(' ', $name_column_width - 2 - strlen($prefix));
                $line_prefix = '  <info>' . $prefix . '</info>' . $padding;
                if ($description !== '') {
                    $description = strtok($description, "\n");
                    $this->output_wrapped_line($io, $line_prefix, $description, $max_width);
                } else {
                    $io->out($line_prefix);
                }
            }
            $is_first = false;
        }
        // Output grouped commands with headers
        foreach ($grouped_commands as $prefix => $cmds) {
            if (!$is_first) {
                $io->out('');
            }
            $io->out("<info>{$prefix}:</info>");
            foreach ($cmds as $cmd) {
                $full_name = $cmd['subcommand'] !== null ? $prefix . ' ' . $cmd['subcommand'] : $prefix;
                $description = $cmd['description'];
                $padding = str_repeat(' ', $name_column_width - 2 - strlen($full_name));
                $line_prefix = '  <info>' . $full_name . '</info>' . $padding;
                if ($description !== '') {
                    $description = strtok($description, "\n");
                    $this->output_wrapped_line($io, $line_prefix, $description, $max_width);
                } else {
                    $io->out($line_prefix);
                }
            }
            $is_first = false;
        }
    }
    /**
     * Output a line with description, wrapping based on terminal width.
     *
     * @param \Cake\Console\ConsoleIo $io The console io
     * @param string $prefix The line prefix (command name with padding)
     * @param string $description The description text
     * @param int $maxWidth Maximum terminal width
     * @param int $maxChars Maximum total description characters (0 = unlimited)
     */
    protected function output_wrapped_line(Console_Io $io, string $prefix, string $description, int $max_width, int $max_chars = 200): void
    {
        $prefix_length = strlen($this->strip_markup($prefix));
        $available_width = $max_width - $prefix_length;
        if ($available_width <= 10) {
            $io->out($prefix);
            return;
        }
        // Truncate description to max chars if set
        if ($max_chars > 0 && strlen($description) > $max_chars) {
            $description = substr($description, 0, $max_chars - 3) . '...';
        }
        if (strlen($description) <= $available_width) {
            $io->out($prefix . $description);
            return;
        }
        // Wrap description across multiple lines
        $indent = str_repeat(' ', $prefix_length);
        $remaining = $description;
        $first_line = true;
        while ($remaining !== '') {
            $line_prefix = $first_line ? $prefix : $indent;
            $first_line = false;
            if (strlen($remaining) <= $available_width) {
                $io->out($line_prefix . $remaining);
                break;
            }
            // Find word break point
            $break_point = strrpos(substr($remaining, 0, $available_width), ' ');
            if ($break_point === false || $break_point < $available_width / 2) {
                $break_point = $available_width;
            }
            $io->out($line_prefix . substr($remaining, 0, $break_point));
            $remaining = ltrim(substr($remaining, $break_point));
        }
    }
    /**
     * Get terminal width for line wrapping.
     *
     * @return int Terminal width in columns
     */
    protected function get_terminal_width(): int
    {
        // Check COLUMNS environment variable (commonly set by shells)
        $columns = getenv('COLUMNS');
        if ($columns !== false && is_numeric($columns) && (int) $columns > 0) {
            return (int) $columns;
        }
        // Try tput cols (Unix/Linux/macOS)
        if (str_contains(strtolower(PHP_OS), 'win') === false) {
            $result = null;
            $output = exec('tput cols 2>/dev/null', result_code: $result);
            if ($result === 0 && is_numeric($output) && (int) $output > 0) {
                return (int) $output;
            }
            // Try stty size (returns "rows cols")
            $output = exec('stty size 2>/dev/null', result_code: $result);
            if ($result === 0 && $output !== false && preg_match('/^\d+\s+(\d+)$/', $output, $matches)) {
                return (int) $matches[1];
            }
        }
        // Default to 120 columns (modern terminals)
        return 120;
    }
    /**
     * Output relevant paths if defined
     *
     * @param \Cake\Console\ConsoleIo $io IO object.
     */
    protected function output_paths(Console_Io $io): void
    {
        $paths = [];
        if (Configure::check('App.dir')) {
            $app_path = rtrim((string) Configure::read('App.dir'), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            // Extra space is to align output
            $paths['app'] = ' ' . $app_path;
        }
        if (defined('ROOT')) {
            $paths['root'] = rtrim((string) ROOT, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        }
        if (defined('CORE_PATH')) {
            $paths['core'] = rtrim(CORE_PATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        }
        if ($paths === []) {
            return;
        }
        $io->out('<info>Current Paths:</info>', 2);
        foreach ($paths as $key => $value) {
            $io->out("* {$key}: {$value}");
        }
        $io->out('');
    }
    /**
     * @param array<string> $names Names
     * @phpstan-param non-empty-array<string> $names
     */
    protected function get_shortest_name(array $names): string
    {
        usort($names, fn($a, $b) => strlen((string) $a) - strlen((string) $b));
        return array_shift($names);
    }
    /**
     * Strip ConsoleOutput markup tags from a string.
     *
     * @param string $text Text that may contain markup tags
     * @return string Text with markup tags removed
     */
    protected function strip_markup(string $text): string
    {
        return preg_replace('/<\/?[a-z]+>/', '', $text) ?? $text;
    }
    /**
     * Output as XML
     *
     * @param \Cake\Console\ConsoleIo $io The console io
     * @param iterable<string, string|object> $commands The command collection to output
     */
    protected function as_xml(Console_Io $io, iterable $commands): void
    {
        $shells = new Simple_Xml_Element('<shells></shells>');
        foreach ($commands as $name => $class) {
            // Skip hidden commands
            if (is_subclass_of($class, Command_Hidden_Interface::class)) {
                continue;
            }
            if (is_object($class)) {
                $class = $class::class;
            }
            $shell = $shells->add_child('shell');
            $shell->add_attribute('name', $name);
            $shell->add_attribute('call_as', $name);
            $shell->add_attribute('provider', $class);
            $shell->add_attribute('help', $name . ' -h');
        }
        $io->set_output_as(Console_Output::RAW);
        $io->out((string) $shells->save_xml());
    }
    /**
     * Gets the option parser instance and configures it.
     *
     * @param \Cake\Console\ConsoleOptionParser $parser The parser to build
     */
    protected function build_option_parser(Console_Option_Parser $parser): Console_Option_Parser
    {
        $parser->set_description('Get the list of available commands for this application.')->add_argument('command', ['help' => 'Filter commands by prefix (e.g., "cache" to show only cache commands).'])->add_option('xml', ['help' => 'Get the listing as XML.', 'boolean' => true]);
        return $parser;
    }
}