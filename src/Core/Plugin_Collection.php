<?php

declare (strict_types=1);
/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright 2005-2011, Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @since         3.6.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Core;

use Cake\Core\Exception\Cake_Exception;
use Cake\Core\Exception\Missing_Plugin_Exception;
use Cake\Utility\Hash;
use Countable;
use Generator;
use InvalidArgumentException;
use Iterator;
/**
 * Plugin Collection
 *
 * Holds onto plugin objects loaded into an application, and
 * provides methods for iterating, and finding plugins based
 * on criteria.
 *
 * This class implements the Iterator interface to allow plugins
 * to be iterated, handling the situation where a plugin's hook
 * method (usually bootstrap) loads another plugin during iteration.
 *
 * While its implementation supported nested iteration it does not
 * support using `continue` or `break` inside loops.
 *
 * @template-implements \Iterator<string, \Cake\Core\PluginInterface>
 */
class Plugin_Collection implements Iterator, Countable
{
    /**
     * Plugin list
     *
     * @var array<string, \Cake\Core\PluginInterface>
     */
    protected array $plugins = [];
    /**
     * Names of plugins
     *
     * @var array<string>
     */
    protected array $names = [];
    /**
     * Iterator position stack.
     *
     * @var array<int>
     */
    protected array $positions = [];
    /**
     * Loop depth
     */
    protected int $loop_depth = -1;
    /**
     * Constructor
     *
     * @param array<\Cake\Core\PluginInterface> $plugins The map of plugins to add to the collection.
     */
    public function __construct(array $plugins = [])
    {
        foreach ($plugins as $plugin) {
            $this->add($plugin);
        }
        Plugin_Config::load_installer_config();
    }
    /**
     * Add plugins from config array.
     *
     * @param array $config Configuration array. For e.g.:
     *   ```
     *   [
     *       'Company/TestPluginThree',
     *       'TestPlugin' => ['onlyDebug' => true, 'onlyCli' => true],
     *       'Nope' => ['optional' => true],
     *       'Named' => ['routes' => false, 'bootstrap' => false],
     *   ]
     *   ```
     */
    public function add_from_config(array $config): void
    {
        $not_debug = !Configure::read('debug');
        $not_cli = PHP_SAPI !== 'cli';
        /** @var array{onlyDebug?: bool, onlyCli?: bool, optional?: bool} $options */
        foreach (Hash::normalize($config, default: []) as $name => $options) {
            $only_debug = $options['onlyDebug'] ?? false;
            $only_cli = $options['onlyCli'] ?? false;
            $optional = $options['optional'] ?? false;
            if ($only_debug && $not_debug) {
                continue;
            }
            if ($only_cli && $not_cli) {
                continue;
            }
            try {
                $plugin = $this->create($name, $options);
                $this->add($plugin);
            } catch (Missing_Plugin_Exception $e) {
                if (!$optional) {
                    throw $e;
                }
            }
        }
    }
    /**
     * Locate a plugin path by looking at configuration data.
     *
     * This will use the `plugins` Configure key, and fallback to enumerating `App::path('plugins')`
     *
     * This method is not part of the official public API as plugins with
     * no plugin class are being phased out.
     *
     * @param string $name The plugin name to locate a path for.
     * @throws \Cake\Core\Exception\MissingPluginException when a plugin path cannot be resolved.
     * @internal
     */
    public function find_path(string $name): string
    {
        // Ensure plugin config is loaded each time. This is necessary primarily
        // for testing because the Configure::clear() call in TestCase::tearDown()
        // wipes out all configuration including plugin paths config.
        Plugin_Config::load_installer_config();
        /** @var string|null $path */
        $path = Configure::read('plugins.' . $name);
        if ($path) {
            return $path;
        }
        $plugin_path = str_replace('/', DIRECTORY_SEPARATOR, $name);
        $paths = App::path('plugins');
        foreach ($paths as $path) {
            if (is_dir($path . $plugin_path)) {
                return $path . $plugin_path . DIRECTORY_SEPARATOR;
            }
        }
        throw new Missing_Plugin_Exception(['plugin' => $name]);
    }
    /**
     * Add a plugin to the collection
     *
     * Plugins will be keyed by their names.
     *
     * @param \Cake\Core\PluginInterface $plugin The plugin to load.
     * @return $this
     */
    public function add(Plugin_Interface $plugin): static
    {
        $name = $plugin->get_name();
        if (isset($this->plugins[$name])) {
            throw new Cake_Exception(sprintf('Plugin named `%s` is already loaded', $name));
        }
        $this->plugins[$name] = $plugin;
        $this->names = array_keys($this->plugins);
        return $this;
    }
    /**
     * Remove a plugin from the collection if it exists.
     *
     * @param string $name The named plugin.
     * @return $this
     */
    public function remove(string $name): static
    {
        unset($this->plugins[$name]);
        $this->names = array_keys($this->plugins);
        return $this;
    }
    /**
     * Remove all plugins from the collection
     *
     * @return $this
     */
    public function clear(): static
    {
        $this->plugins = [];
        $this->names = [];
        $this->positions = [];
        $this->loop_depth = -1;
        return $this;
    }
    /**
     * Check whether the named plugin exists in the collection.
     *
     * @param string $name The named plugin.
     */
    public function has(string $name): bool
    {
        return isset($this->plugins[$name]);
    }
    /**
     * Get the a plugin by name.
     *
     * If a plugin isn't already loaded it will be autoloaded on first access
     * and that plugins loaded this way may miss some hook methods.
     *
     * @param string $name The plugin to get.
     * @return \Cake\Core\PluginInterface The plugin.
     * @throws \Cake\Core\Exception\MissingPluginException when unknown plugins are fetched.
     */
    public function get(string $name): Plugin_Interface
    {
        if ($this->has($name)) {
            return $this->plugins[$name];
        }
        $plugin = $this->create($name);
        $this->add($plugin);
        return $plugin;
    }
    /**
     * Create a plugin instance from a name/classname and configuration.
     *
     * @param string $name The plugin name or classname
     * @param array<string, mixed> $config Configuration options for the plugin.
     * @throws \Cake\Core\Exception\MissingPluginException When plugin instance could not be created.
     * @throws \InvalidArgumentException When class name cannot be found or an empty name is provided.
     * @phpstan-param class-string<\Cake\Core\PluginInterface>|string $name
     */
    public function create(string $name, array $config = []): Plugin_Interface
    {
        if ($name === '') {
            throw new InvalidArgumentException('Plugin name cannot be empty.');
        }
        if (str_contains($name, '\\')) {
            if (!class_exists($name)) {
                throw new InvalidArgumentException(sprintf('Class `%s` does not exist.', $name));
            }
            return new $name($config);
        }
        $config += ['name' => $name];
        $namespace = str_replace('/', '\\', $name);
        $pos = strpos($name, '/');
        $name_part = $pos === false ? $name : substr($name, $pos + 1);
        // Check for [Vendor/]Foo/FooPlugin class
        $class_name = $namespace . '\\' . $name_part . 'Plugin';
        if (!class_exists($class_name)) {
            // Check for [Vendor/]Foo/Plugin class
            $class_name = $namespace . '\\' . 'Plugin';
            if (class_exists($class_name)) {
                deprecation_warning('5.3.0', 'Loading plugins with a plugin class named `Plugin` is deprecated.' . " Rename the class to `{$name_part}Plugin` instead.");
            } else {
                $class_name = Base_Plugin::class;
                if (empty($config['path'])) {
                    $config['path'] = $this->find_path($name);
                }
                deprecation_warning('5.3.0', 'Loading plugins without a plugin class is deprecated.' . " You can create the missing class using `bin/cake bake plugin {$name} --class-only`.");
            }
        }
        /** @var class-string<\Cake\Core\PluginInterface> $className */
        return new $class_name($config);
    }
    /**
     * Implementation of Countable.
     *
     * Get the number of plugins in the collection.
     */
    public function count(): int
    {
        return count($this->plugins);
    }
    /**
     * Part of Iterator Interface
     */
    public function next(): void
    {
        $this->positions[$this->loop_depth]++;
    }
    /**
     * Part of Iterator Interface
     */
    public function key(): string
    {
        return $this->names[$this->positions[$this->loop_depth]];
    }
    /**
     * Part of Iterator Interface
     */
    public function current(): Plugin_Interface
    {
        $position = $this->positions[$this->loop_depth];
        $name = $this->names[$position];
        return $this->plugins[$name];
    }
    /**
     * Part of Iterator Interface
     */
    public function rewind(): void
    {
        $this->positions[] = 0;
        $this->loop_depth += 1;
    }
    /**
     * Part of Iterator Interface
     */
    public function valid(): bool
    {
        $valid = isset($this->names[$this->positions[$this->loop_depth]]);
        if (!$valid) {
            array_pop($this->positions);
            $this->loop_depth -= 1;
        }
        return $valid;
    }
    /**
     * Filter the plugins to those with the named hook enabled.
     *
     * @param string $hook The hook to filter plugins by
     * @return \Generator<\Cake\Core\PluginInterface> A generator containing matching plugins.
     * @throws \InvalidArgumentException on invalid hooks
     */
    public function with(string $hook): Generator
    {
        if (!in_array($hook, Plugin_Interface::VALID_HOOKS, true)) {
            throw new InvalidArgumentException(sprintf('The `%s` hook is not a known plugin hook.', $hook));
        }
        foreach ($this as $plugin) {
            if ($plugin->is_enabled($hook)) {
                yield $plugin;
            }
        }
    }
}