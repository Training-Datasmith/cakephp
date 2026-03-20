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
 * @link          https://cakephp.org CakePHP Project
 * @since         2.5.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Command;

use Cake\Console\Arguments;
use Cake\Console\Base_Command;
use Cake\Console\Command_Collection;
use Cake\Console\Command_Collection_Aware_Interface;
use Cake\Console\Command_Hidden_Interface;
use Cake\Console\Console_Io;
use Cake\Console\Console_Option_Parser;
use ReflectionClass;
/**
 * Provide command completion shells such as bash.
 */
class Completion_Command extends Command implements Command_Collection_Aware_Interface
{
    protected Command_Collection $commands;
    /**
     * @inheritDoc
     */
    public static function get_description(): string
    {
        return 'Used by shells like bash to autocomplete command name, options and arguments';
    }
    /**
     * Set the command collection used to get completion data on.
     *
     * @param \Cake\Console\CommandCollection $commands The command collection
     */
    public function set_command_collection(Command_Collection $commands): void
    {
        $this->commands = $commands;
    }
    /**
     * Gets the option parser instance and configures it.
     *
     * @param \Cake\Console\ConsoleOptionParser $parser The parser to build
     */
    public function build_option_parser(Console_Option_Parser $parser): Console_Option_Parser
    {
        $modes = ['commands' => 'Output a list of available commands', 'subcommands' => 'Output a list of available sub-commands for a command', 'options' => 'Output a list of available options for a command and possible subcommand.'];
        $mode_help = '';
        foreach ($modes as $key => $help) {
            $mode_help .= "- <info>{$key}</info> {$help}\n";
        }
        $parser->set_description(static::get_description())->add_argument('mode', ['help' => 'The type of thing to get completion on.', 'required' => true, 'choices' => array_keys($modes)])->add_argument('command', ['help' => 'The command name to get information on.', 'required' => false])->add_argument('subcommand', ['help' => 'The sub-command related to command to get information on.', 'required' => false])->set_epilog(['The various modes allow you to get help information on commands and their arguments.', 'The available modes are:', '', $mode_help, '', 'This command is not intended to be called manually, and should be invoked from a ' . 'terminal completion script.']);
        return $parser;
    }
    /**
     * Main function Prints out the list of commands.
     *
     * @param \Cake\Console\Arguments $args The command arguments.
     * @param \Cake\Console\ConsoleIo $io The console io
     */
    public function execute(Arguments $args, Console_Io $io): ?int
    {
        return match ($args->get_argument('mode')) {
            'commands' => $this->get_commands($args, $io),
            'subcommands' => $this->get_subcommands($args, $io),
            'options' => $this->get_options($args, $io),
            default => static::CODE_ERROR,
        };
    }
    /**
     * Get the list of defined commands.
     *
     * @param \Cake\Console\Arguments $args The command arguments.
     * @param \Cake\Console\ConsoleIo $io The console io
     */
    protected function get_commands(Arguments $args, Console_Io $io): int
    {
        $options = [];
        $verbose = $io->level() >= Console_Io::VERBOSE;
        // Build a map of command base names (without subcommands) to their classes
        // to detect true duplicates (plugin-prefixed alias pointing to same command)
        $command_classes = [];
        foreach ($this->commands as $key => $value) {
            if (is_subclass_of($value, Command_Hidden_Interface::class)) {
                continue;
            }
            $parts = explode(' ', $key);
            $command_name = $parts[0];
            // Only track base commands (no subcommands) and prefer first occurrence
            if (count($parts) === 1 && !isset($command_classes[$command_name])) {
                $command_classes[$command_name] = $value;
            }
        }
        foreach ($this->commands as $key => $value) {
            if (is_subclass_of($value, Command_Hidden_Interface::class)) {
                continue;
            }
            $parts = explode(' ', $key);
            $command_name = $parts[0];
            // Skip plugin-prefixed aliases only if they are true duplicates
            // (i.e., a short form exists that resolves to the same command class)
            if (!$verbose && str_contains($command_name, '.')) {
                $short_name = explode('.', $command_name)[1];
                if (isset($command_classes[$short_name]) && isset($command_classes[$command_name]) && $command_classes[$short_name] === $command_classes[$command_name]) {
                    continue;
                }
            }
            $options[] = $command_name;
        }
        $options = array_unique($options);
        $io->out(implode(' ', $options));
        return static::CODE_SUCCESS;
    }
    /**
     * Get the list of defined sub-commands.
     *
     * @param \Cake\Console\Arguments $args The command arguments.
     * @param \Cake\Console\ConsoleIo $io The console io
     */
    protected function get_subcommands(Arguments $args, Console_Io $io): int
    {
        $name = $args->get_argument('command');
        if ($name === null || $name === '') {
            return static::CODE_SUCCESS;
        }
        $options = [];
        foreach ($this->commands as $key => $value) {
            if (is_subclass_of($value, Command_Hidden_Interface::class)) {
                continue;
            }
            $parts = explode(' ', $key);
            if ($parts[0] !== $name) {
                continue;
            }
            // Space separate command name, collect
            // hits as subcommands
            if (count($parts) > 1) {
                $options[] = implode(' ', array_slice($parts, 1));
            }
        }
        $options = array_unique($options);
        $io->out(implode(' ', $options));
        return static::CODE_SUCCESS;
    }
    /**
     * Get the options for a command or subcommand
     *
     * @param \Cake\Console\Arguments $args The command arguments.
     * @param \Cake\Console\ConsoleIo $io The console io
     */
    protected function get_options(Arguments $args, Console_Io $io): ?int
    {
        $name = $args->get_argument('command');
        $subcommand = $args->get_argument('subcommand');
        $options = [];
        foreach ($this->commands as $key => $value) {
            if (is_subclass_of($value, Command_Hidden_Interface::class)) {
                continue;
            }
            $parts = explode(' ', $key);
            if ($parts[0] !== $name) {
                continue;
            }
            if ($subcommand && !isset($parts[1])) {
                continue;
            }
            if ($subcommand && isset($parts[1]) && $parts[1] !== $subcommand) {
                continue;
            }
            // Handle class strings
            if (is_string($value)) {
                $reflection = new ReflectionClass($value);
                $value = $reflection->new_instance();
                assert($value instanceof Base_Command);
            }
            if (method_exists($value, 'getOptionParser')) {
                /** @var \Cake\Console\ConsoleOptionParser $parser */
                $parser = $value->get_option_parser();
                foreach ($parser->options() as $name => $option) {
                    $options[] = "--{$name}";
                    $short = $option->short();
                    if ($short) {
                        $options[] = "-{$short}";
                    }
                }
            }
        }
        $options = array_unique($options);
        $io->out(implode(' ', $options));
        return static::CODE_SUCCESS;
    }
}