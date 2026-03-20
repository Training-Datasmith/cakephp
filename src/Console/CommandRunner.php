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
 * @since         3.5.0
 * @license       https://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Console;

use Cake\Command\Version_Command;
use Cake\Console\Command\Help_Command;
use Cake\Console\Exception\Missing_Option_Exception;
use Cake\Console\Exception\Stop_Exception;
use Cake\Core\Console_Application_Interface;
use Cake\Core\Container_Application_Interface;
use Cake\Core\Event_Aware_Application_Interface;
use Cake\Core\Plugin_Application_Interface;
use Cake\Event\Event_Dispatcher_Interface;
use Cake\Event\Event_Dispatcher_Trait;
use Cake\Event\Event_Manager;
use Cake\Event\Event_Manager_Interface;
use Cake\Routing\Router;
use Cake\Routing\Routing_Application_Interface;
use Cake\Utility\Inflector;
/**
 * Run CLI commands for the provided application.
 *
 * @implements \Cake\Event\EventDispatcherInterface<\Cake\Core\ConsoleApplicationInterface>
 */
class Command_Runner implements Event_Dispatcher_Interface
{
    /**
     * @use \Cake\Event\EventDispatcherTrait<\Cake\Core\ConsoleApplicationInterface>
     */
    use Event_Dispatcher_Trait;
    /**
     * Alias mappings.
     *
     * @var array<string, string>
     */
    protected array $aliases = ['--version' => 'version', '--help' => 'help', '-h' => 'help', '-v' => 'help', '--verbose' => 'help'];
    /**
     * Constructor
     *
     * @param \Cake\Core\ConsoleApplicationInterface $app The application to run CLI commands for.
     * @param string $root The root command name to be removed from argv.
     * @param \Cake\Console\CommandFactoryInterface|null $factory Command factory instance.
     */
    public function __construct(
        /**
         * The application console commands are being run for.
         */
        protected Console_Application_Interface $app,
        /**
         * The root command name. Defaults to `cake`.
         */
        protected string $root = 'cake',
        /**
         * The application console commands are being run for.
         */
        protected ?Command_Factory_Interface $factory = null
    )
    {
    }
    /**
     * Replace the entire alias map for a runner.
     *
     * Aliases allow you to define alternate names for commands
     * in the collection. This can be useful to add top level switches
     * like `--version` or `-h`
     *
     * ### Usage
     *
     * ```
     * $runner->setAliases(['--version' => 'version']);
     * ```
     *
     * @param array<string, string> $aliases The map of aliases to replace.
     * @return $this
     */
    public function set_aliases(array $aliases): static
    {
        $this->aliases = $aliases;
        return $this;
    }
    /**
     * Run the command contained in $argv.
     *
     * Use the application to do the following:
     *
     * - Bootstrap the application
     * - Create the CommandCollection using the console() hook on the application.
     * - Trigger the `Console.buildCommands` event of auto-wiring plugins.
     * - Run the requested command.
     *
     * @param array $argv The arguments from the CLI environment.
     * @param \Cake\Console\ConsoleIo|null $io The ConsoleIo instance. Used primarily for testing.
     * @return int The exit code of the command.
     */
    public function run(array $argv, ?Console_Io $io = null): int
    {
        assert($argv !== [], 'Cannot run any commands. No arguments received.');
        $this->bootstrap();
        if ($this->app instanceof Event_Aware_Application_Interface) {
            $event_manager = $this->get_event_manager();
            $event_manager = $this->app->events($event_manager);
            $event_manager = $this->app->plugin_events($event_manager);
            $this->set_event_manager($event_manager);
        }
        $commands = new Command_Collection(['help' => Help_Command::class]);
        if (class_exists(Version_Command::class)) {
            $commands->add('version', Version_Command::class);
        }
        $commands = $this->app->console($commands);
        if ($this->app instanceof Plugin_Application_Interface) {
            $commands = $this->app->plugin_console($commands);
        }
        $this->dispatch_event('Console.buildCommands', ['commands' => $commands]);
        $this->load_routes();
        // Remove the root executable segment
        array_shift($argv);
        $io = $io ?: new Console_Io();
        /** @var array{string|null, array} $resolved */
        $resolved = $this->longest_command_name($commands, $argv);
        [$name, $argv] = $resolved;
        // If -v/--verbose is used as command, preserve it as flag for help command
        if ($name === '-v' || $name === '--verbose') {
            $argv = array_merge([$name], $argv);
            $name = 'help';
        }
        // Check if this is a command prefix (e.g., "cache" has subcommands like "cache clear")
        // Show help for that prefix instead of running the base command
        if ($name !== null && !$commands->has($name) && $this->has_commands_with_prefix($commands, $name)) {
            $argv = array_merge([$name], $argv);
            $name = 'help';
        }
        try {
            $name = $this->resolve_name($commands, $io, $name);
        } catch (Missing_Option_Exception $e) {
            $io->error($e->get_full_message());
            return Command_Interface::CODE_ERROR;
        }
        $command = $this->get_command($io, $commands, $name);
        $result = $this->run_command($command, $argv, $io);
        if ($result === null) {
            return Command_Interface::CODE_SUCCESS;
        }
        if ($result >= 0 && $result <= 255) {
            return $result;
        }
        return Command_Interface::CODE_ERROR;
    }
    /**
     * Application bootstrap wrapper.
     *
     * Calls the application's `bootstrap()` hook. After the application the
     * plugins are bootstrapped.
     */
    protected function bootstrap(): void
    {
        $this->app->bootstrap();
        if ($this->app instanceof Plugin_Application_Interface) {
            $this->app->plugin_bootstrap();
        }
    }
    /**
     * Get the application's event manager or the global one.
     */
    public function get_event_manager(): Event_Manager_Interface
    {
        if ($this->app instanceof Plugin_Application_Interface) {
            return $this->app->get_event_manager();
        }
        return Event_Manager::instance();
    }
    /**
     * Get/set the application's event manager.
     *
     * @param \Cake\Event\EventManagerInterface $eventManager The event manager to set.
     * @return $this
     */
    public function set_event_manager(Event_Manager_Interface $event_manager): static
    {
        if ($this->app instanceof Event_Dispatcher_Interface) {
            $this->app->set_event_manager($event_manager);
        }
        return $this;
    }
    /**
     * Get the shell instance for a given command name
     *
     * @param \Cake\Console\ConsoleIo $io The IO wrapper for the created shell class.
     * @param \Cake\Console\CommandCollection $commands The command collection to find the shell in.
     * @param string $name The command name to find
     */
    protected function get_command(Console_Io $io, Command_Collection $commands, string $name): Command_Interface
    {
        $instance = $commands->get($name);
        if (is_string($instance)) {
            $instance = $this->create_command($instance);
        }
        $instance->set_name("{$this->root} {$name}");
        if ($instance instanceof Command_Collection_Aware_Interface) {
            $instance->set_command_collection($commands);
        }
        return $instance;
    }
    /**
     * Build the longest command name that exists in the collection
     *
     * Build the longest command name that matches a
     * defined command. This will traverse a maximum of 3 tokens.
     *
     * @param \Cake\Console\CommandCollection $commands The command collection to check.
     * @param array $argv The CLI arguments.
     * @return array An array of the resolved name and modified argv.
     */
    protected function longest_command_name(Command_Collection $commands, array $argv): array
    {
        for ($i = 3; $i > 1; $i--) {
            $parts = array_slice($argv, 0, $i);
            $name = implode(' ', $parts);
            if ($commands->has($name)) {
                return [$name, array_slice($argv, $i)];
            }
            $first_char = $name[0] ?? '';
            if ($first_char === strtoupper($first_char) && str_contains($name, '.')) {
                $under_name = Inflector::underscore($name);
                if ($commands->has($under_name)) {
                    return [$under_name, array_slice($argv, $i)];
                }
            }
        }
        $name = array_shift($argv);
        return [$name, $argv];
    }
    /**
     * Resolve the command name into a name that exists in the collection.
     *
     * Apply backwards compatible inflections and aliases.
     * Will step forward up to 3 tokens in $argv to generate
     * a command name in the CommandCollection. More specific
     * command names take precedence over less specific ones.
     *
     * @param \Cake\Console\CommandCollection $commands The command collection to check.
     * @param \Cake\Console\ConsoleIo $io ConsoleIo object for errors.
     * @param string|null $name The name from the CLI args.
     * @return string The resolved name.
     * @throws \Cake\Console\Exception\MissingOptionException
     */
    protected function resolve_name(Command_Collection $commands, Console_Io $io, ?string $name): string
    {
        if (!$name) {
            $io->error('No command provided. Choose one of the available commands.', 2);
            $name = 'help';
        }
        $name = $this->aliases[$name] ?? $name;
        if (!$commands->has($name)) {
            $name = Inflector::underscore($name);
        }
        if (!$commands->has($name)) {
            throw new Missing_Option_Exception("Unknown command `{$this->root} {$name}`. " . "Run `{$this->root} --help` to get the list of commands.", $name, $commands->keys());
        }
        return $name;
    }
    /**
     * Check if there are commands that start with the given prefix.
     *
     * @param \Cake\Console\CommandCollection $commands The command collection.
     * @param string $prefix The prefix to check.
     * @return bool True if commands with this prefix exist.
     */
    protected function has_commands_with_prefix(Command_Collection $commands, string $prefix): bool
    {
        foreach ($commands->keys() as $name) {
            if (str_starts_with($name, $prefix . ' ')) {
                return true;
            }
        }
        return false;
    }
    /**
     * Execute a Command class.
     *
     * @param \Cake\Console\CommandInterface $command The command to run.
     * @param array $argv The CLI arguments to invoke.
     * @param \Cake\Console\ConsoleIo $io The console io
     * @return int|null Exit code
     */
    protected function run_command(Command_Interface $command, array $argv, Console_Io $io): ?int
    {
        try {
            if ($command instanceof Event_Dispatcher_Interface) {
                $command->set_event_manager($this->get_event_manager());
            }
            return $command->run($argv, $io);
        } catch (Stop_Exception $e) {
            return $e->get_code();
        }
    }
    /**
     * The wrapper for creating command instances.
     *
     * @param string $className Command class name.
     */
    protected function create_command(string $class_name): Command_Interface
    {
        if (!$this->factory) {
            $container = null;
            if ($this->app instanceof Container_Application_Interface) {
                $container = $this->app->get_container();
            }
            $this->factory = new Command_Factory($container);
            $container?->add(Command_Factory_Interface::class, $this->factory);
        }
        return $this->factory->create($class_name);
    }
    /**
     * Ensure that the application's routes are loaded.
     *
     * Console commands and shells often need to generate URLs.
     */
    protected function load_routes(): void
    {
        if (!$this->app instanceof Routing_Application_Interface) {
            return;
        }
        $builder = Router::create_route_builder('/');
        $this->app->routes($builder);
        if ($this->app instanceof Plugin_Application_Interface) {
            $this->app->plugin_routes($builder);
        }
    }
}