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
 * @since         4.0.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Console;

use Cake\Console\Exception\Console_Exception;
use Cake\Console\Exception\Stop_Exception;
use Cake\Event\Event_Dispatcher_Interface;
use Cake\Event\Event_Dispatcher_Trait;
use Cake\Event\Event_Interface;
use Cake\Event\Event_Listener_Interface;
use Cake\Utility\Inflector;
/**
 * Base class for console commands.
 *
 * Provides hooks for common command features:
 *
 * - `initialize` Acts as a post-construct hook.
 * - `buildOptionParser` Build/Configure the option parser for your command.
 * - `execute` Execute your command with parsed Arguments and ConsoleIo
 *
 * ### Life cycle callbacks
 *
 * CakePHP fires a number of life cycle callbacks during each command execution.
 * By implementing a method you can receive the related events. The available
 * callbacks are:
 *
 * - `beforeExecute(EventInterface $event)`
 *   Called immediately prior to the command's run method. This is a good place to do
 *   general logic that applies to command setup.
 * - `afterExecute(EventInterface $event)`
 *   Called immediately after the command's run method, unless an exception occurs.
 *
 * @implements \Cake\Event\EventDispatcherInterface<static>
 */
abstract class Base_Command implements Command_Interface, Event_Dispatcher_Interface, Event_Listener_Interface
{
    /**
     * @use \Cake\Event\EventDispatcherTrait<static>
     */
    use Event_Dispatcher_Trait;
    /**
     * The name of this command.
     */
    protected string $name = 'cake unknown';
    /**
     * Constructor
     *
     * @param \Cake\Console\CommandFactoryInterface|null $factory Command factory instance.
     */
    public function __construct(protected ?Command_Factory_Interface $factory = null)
    {
        $this->get_event_manager()->on($this);
    }
    /**
     * @inheritDoc
     */
    public function set_name(string $name)
    {
        assert(str_contains($name, ' ') && !str_starts_with($name, ' '), "The name '{$name}' is missing a space. Names should look like `cake routes`");
        $this->name = $name;
        return $this;
    }
    /**
     * Get the command name.
     */
    public function get_name(): string
    {
        return $this->name;
    }
    /**
     * Get the command description.
     */
    public static function get_description(): string
    {
        return '';
    }
    /**
     * Get the root command name.
     */
    public function get_root_name(): string
    {
        [$root] = explode(' ', $this->name);
        return $root;
    }
    /**
     * Get the command name.
     *
     * Returns the command name based on class name.
     * For e.g. for a command with class name `UpdateTableCommand` the default
     * name returned would be `'update_table'`.
     */
    public static function default_name(): string
    {
        $pos = strrpos(static::class, '\\');
        $name = substr(static::class, $pos + 1, -7);
        return Inflector::underscore($name);
    }
    /**
     * Get the option parser.
     *
     * You can override buildOptionParser() to define your options & arguments.
     *
     * @throws \Cake\Core\Exception\CakeException When the parser is invalid
     */
    public function get_option_parser(): Console_Option_Parser
    {
        [$root, $name] = explode(' ', $this->name, 2);
        $parser = new Console_Option_Parser($name);
        $parser->set_root_name($root);
        $parser->set_description(static::get_description());
        return $this->build_option_parser($parser);
    }
    /**
     * Hook method for defining this command's option parser.
     *
     * @param \Cake\Console\ConsoleOptionParser $parser The parser to be defined
     * @return \Cake\Console\ConsoleOptionParser The built parser.
     */
    protected function build_option_parser(Console_Option_Parser $parser): Console_Option_Parser
    {
        return $parser;
    }
    /**
     * Hook method invoked by CakePHP when a command is about to be executed.
     *
     * Override this method and implement expensive/important setup steps that
     * should not run on every command run. This method will be called *before*
     * the options and arguments are validated and processed.
     */
    public function initialize(): void
    {
    }
    /**
     * Returns a list of all events that will fire in the command during its lifecycle.
     * You can override this function to add your own listener callbacks
     *
     * @return array<string, mixed>
     */
    public function implemented_events(): array
    {
        return ['Command.beforeExecute' => 'beforeExecute', 'Command.afterExecute' => 'afterExecute'];
    }
    /**
     * Called immediately prior to the command's run method. You can use this method to configure and customize the
     * command or perform logic that needs to happen before the command runs.
     *
     * @param \Cake\Event\EventInterface<static> $event An Event instance
     * @link https://book.cakephp.org/5/en/console-commands/commands.html#lifecycle-callbacks
     */
    public function before_execute(Event_Interface $event, Arguments $args, Console_Io $io): void
    {
    }
    /**
     * Called immediately after the command's run method, unless an exception occurs. You can use this method to
     * perform logic that needs to happen after the command runs.
     *
     * @param \Cake\Event\EventInterface<static> $event An Event instance
     * @link https://book.cakephp.org/5/en/console-commands/commands.html#lifecycle-callbacks
     */
    public function after_execute(Event_Interface $event, Arguments $args, Console_Io $io, ?int $result): void
    {
    }
    /**
     * @inheritDoc
     */
    public function run(array $argv, Console_Io $io): ?int
    {
        $this->initialize();
        $parser = $this->get_option_parser();
        try {
            [$options, $arguments] = $parser->parse($argv, $io);
            $args = new Arguments($arguments, $options, $parser->argument_names());
        } catch (Console_Exception $e) {
            $io->error('Error: ' . $e->get_message());
            return static::CODE_ERROR;
        }
        $this->set_output_level($args, $io);
        if ($args->get_option('help')) {
            $this->display_help($parser, $args, $io);
            return static::CODE_SUCCESS;
        }
        if ($args->get_option('quiet')) {
            $io->set_interactive(false);
        }
        $this->dispatch_event('Command.beforeExecute', ['args' => $args, 'io' => $io]);
        $result = $this->execute($args, $io);
        $this->dispatch_event('Command.afterExecute', ['args' => $args, 'io' => $io, 'result' => $result]);
        return $result;
    }
    /**
     * Output help content
     *
     * @param \Cake\Console\ConsoleOptionParser $parser The option parser.
     * @param \Cake\Console\Arguments $args The command arguments.
     * @param \Cake\Console\ConsoleIo $io The console io
     */
    protected function display_help(Console_Option_Parser $parser, Arguments $args, Console_Io $io): void
    {
        $format = 'text';
        if ($args->get_argument_at(0) === 'xml') {
            $format = 'xml';
            $io->set_output_as(Console_Output::RAW);
        }
        $io->out($parser->help($format));
    }
    /**
     * Set the output level based on the Arguments.
     *
     * @param \Cake\Console\Arguments $args The command arguments.
     * @param \Cake\Console\ConsoleIo $io The console io
     */
    protected function set_output_level(Arguments $args, Console_Io $io): void
    {
        $io->set_loggers(Console_Io::NORMAL);
        if ($args->get_option('quiet')) {
            $io->level(Console_Io::QUIET);
            $io->set_loggers(Console_Io::QUIET);
        }
        if ($args->get_option('verbose')) {
            $io->level(Console_Io::VERBOSE);
            $io->set_loggers(Console_Io::VERBOSE);
        }
    }
    /**
     * Implement this method with your command's logic.
     *
     * @param \Cake\Console\Arguments $args The command arguments.
     * @param \Cake\Console\ConsoleIo $io The console io
     * @return int|null|void The exit code or null for success
     */
    abstract public function execute(Arguments $args, Console_Io $io);
    /**
     * Halt the current process with a StopException.
     *
     * @param int $code The exit code to use.
     * @throws \Cake\Console\Exception\StopException
     */
    public function abort(int $code = self::CODE_ERROR): never
    {
        throw new Stop_Exception('Command aborted', $code);
    }
    /**
     * Execute another command with the provided set of arguments.
     *
     * If you are using a string command name, that command's dependencies
     * will not be resolved with the application container. Instead you will
     * need to pass the command as an object with all of its dependencies.
     *
     * @param \Cake\Console\CommandInterface|string $command The command class name or command instance.
     * @param array $args The arguments to invoke the command with.
     * @param \Cake\Console\ConsoleIo|null $io The ConsoleIo instance to use for the executed command.
     * @return int|null The exit code or null for success of the command.
     */
    public function execute_command(Command_Interface|string $command, array $args = [], ?Console_Io $io = null): ?int
    {
        if (is_string($command)) {
            assert(is_subclass_of($command, Command_Interface::class), sprintf('Command `%s` is not a subclass of `%s`.', $command, Command_Interface::class));
            $command = $this->factory?->create($command) ?? new $command();
        }
        $io = $io ?: new Console_Io();
        try {
            return $command->run($args, $io);
        } catch (Stop_Exception $e) {
            return $e->get_code();
        }
    }
}