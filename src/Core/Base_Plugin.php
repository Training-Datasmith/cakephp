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

use Cake\Console\Command_Collection;
use Cake\Event\Event_Manager_Interface;
use Cake\Http\Middleware_Queue;
use Cake\Routing\Route_Builder;
use Closure;
use InvalidArgumentException;
use ReflectionClass;
/**
 * Base Plugin Class
 *
 * Every plugin should extend from this class or implement the interfaces and
 * include a plugin class in its src root folder.
 */
class Base_Plugin implements Plugin_Interface
{
    /**
     * Do bootstrapping or not
     */
    protected bool $bootstrap_enabled = true;
    /**
     * Console middleware
     */
    protected bool $console_enabled = true;
    /**
     * Enable middleware
     */
    protected bool $middleware_enabled = true;
    /**
     * Register container services
     */
    protected bool $services_enabled = true;
    /**
     * Load routes or not
     */
    protected bool $routes_enabled = true;
    /**
     * Load events or not
     */
    protected bool $events_enabled = true;
    /**
     * The path to this plugin.
     */
    protected ?string $path = null;
    /**
     * The class path for this plugin.
     */
    protected ?string $class_path = null;
    /**
     * The config path for this plugin.
     */
    protected ?string $config_path = null;
    /**
     * The templates path for this plugin.
     */
    protected ?string $template_path = null;
    /**
     * The name of this plugin
     */
    protected ?string $name = null;
    /**
     * Constructor
     *
     * @param array<string, mixed> $options Options
     */
    public function __construct(array $options = [])
    {
        foreach (static::VALID_HOOKS as $key) {
            if (isset($options[$key])) {
                $this->{"{$key}Enabled"} = (bool) $options[$key];
            }
        }
        foreach (['name', 'path', 'classPath', 'configPath', 'templatePath'] as $path) {
            if (isset($options[$path])) {
                $this->{$path} = $options[$path];
            }
        }
        $this->initialize();
    }
    /**
     * Initialization hook called from constructor.
     */
    public function initialize(): void
    {
    }
    /**
     * @inheritDoc
     */
    public function get_name(): string
    {
        if ($this->name !== null) {
            return $this->name;
        }
        $parts = explode('\\', static::class);
        array_pop($parts);
        return $this->name = implode('/', $parts);
    }
    /**
     * @inheritDoc
     */
    public function get_path(): string
    {
        if ($this->path !== null) {
            return $this->path;
        }
        $reflection = new ReflectionClass($this);
        $path = dirname((string) $reflection->get_file_name());
        // Trim off src
        if (str_ends_with($path, 'src')) {
            $path = substr($path, 0, -3);
        }
        return $this->path = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }
    /**
     * @inheritDoc
     */
    public function get_config_path(): string
    {
        if ($this->config_path !== null) {
            return $this->config_path;
        }
        $path = $this->get_path();
        return $path . 'config' . DIRECTORY_SEPARATOR;
    }
    /**
     * @inheritDoc
     */
    public function get_class_path(): string
    {
        if ($this->class_path !== null) {
            return $this->class_path;
        }
        $path = $this->get_path();
        return $path . 'src' . DIRECTORY_SEPARATOR;
    }
    /**
     * @inheritDoc
     */
    public function get_template_path(): string
    {
        if ($this->template_path !== null) {
            return $this->template_path;
        }
        $path = $this->get_path();
        return $this->template_path = $path . 'templates' . DIRECTORY_SEPARATOR;
    }
    /**
     * @inheritDoc
     */
    public function enable(string $hook): static
    {
        $this->check_hook($hook);
        $this->{"{$hook}Enabled"} = true;
        return $this;
    }
    /**
     * @inheritDoc
     */
    public function disable(string $hook): static
    {
        $this->check_hook($hook);
        $this->{"{$hook}Enabled"} = false;
        return $this;
    }
    /**
     * @inheritDoc
     */
    public function is_enabled(string $hook): bool
    {
        $this->check_hook($hook);
        return $this->{"{$hook}Enabled"} === true;
    }
    /**
     * Check if a hook name is valid
     *
     * @param string $hook The hook name to check
     * @throws \InvalidArgumentException on invalid hooks
     */
    protected function check_hook(string $hook): void
    {
        if (!in_array($hook, static::VALID_HOOKS, true)) {
            throw new InvalidArgumentException(sprintf('`%s` is not a valid hook name. Must be one of `%s.`', $hook, implode(', ', static::VALID_HOOKS)));
        }
    }
    /**
     * @inheritDoc
     */
    public function routes(Route_Builder $routes): void
    {
        $path = $this->get_config_path() . 'routes.php';
        if (is_file($path)) {
            $return = require $path;
            if ($return instanceof Closure) {
                $return($routes);
            }
        }
    }
    /**
     * {@inheritDoc}
     *
     * @param \Cake\Core\PluginApplicationInterface<mixed> $app The host application
     */
    public function bootstrap(Plugin_Application_Interface $app): void
    {
        $bootstrap = $this->get_config_path() . 'bootstrap.php';
        if (is_file($bootstrap)) {
            require $bootstrap;
        }
    }
    /**
     * @inheritDoc
     */
    public function console(Command_Collection $commands): Command_Collection
    {
        return $commands->add_many($commands->discover_plugin($this->get_name()));
    }
    /**
     * @inheritDoc
     */
    public function middleware(Middleware_Queue $middleware_queue): Middleware_Queue
    {
        return $middleware_queue;
    }
    /**
     * Register container services for this plugin.
     *
     * @param \Cake\Core\ContainerInterface $container The container to add services to.
     */
    public function services(Container_Interface $container): void
    {
    }
    /**
     * Register application events.
     *
     * @param \Cake\Event\EventManagerInterface $eventManager The global event manager to register listeners on
     */
    public function events(Event_Manager_Interface $event_manager): Event_Manager_Interface
    {
        return $event_manager;
    }
}