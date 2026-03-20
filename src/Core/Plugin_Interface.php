<?php

declare (strict_types=1);
/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright 2005-2011, Cake Software Foundation, Inc. (https://cakefoundation.org)
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
use Cake\Http\Middleware_Queue;
use Cake\Routing\Route_Builder;
/**
 * Plugin Interface
 *
 * @method \Cake\Event\EventManagerInterface events(\Cake\Event\EventManagerInterface $eventManager)
 */
interface Plugin_Interface
{
    /**
     * List of valid hooks.
     *
     * @var array<string>
     */
    public const VALID_HOOKS = ['bootstrap', 'console', 'middleware', 'routes', 'services', 'events'];
    /**
     * Get the name of this plugin.
     */
    public function get_name(): string;
    /**
     * Get the filesystem path to this plugin
     */
    public function get_path(): string;
    /**
     * Get the filesystem path to configuration for this plugin
     */
    public function get_config_path(): string;
    /**
     * Get the filesystem path to configuration for this plugin
     */
    public function get_class_path(): string;
    /**
     * Get the filesystem path to templates for this plugin
     */
    public function get_template_path(): string;
    /**
     * Load all the application configuration and bootstrap logic.
     *
     * The default implementation of this method will include the `config/bootstrap.php` in the plugin if it exist. You
     * can override this method to replace that behavior.
     *
     * The host application is provided as an argument. This allows you to load additional
     * plugin dependencies, or attach events.
     *
     * @param \Cake\Core\PluginApplicationInterface<mixed> $app The host application
     */
    public function bootstrap(Plugin_Application_Interface $app): void;
    /**
     * Add console commands for the plugin.
     *
     * @param \Cake\Console\CommandCollection $commands The command collection to update
     */
    public function console(Command_Collection $commands): Command_Collection;
    /**
     * Add middleware for the plugin.
     *
     * @param \Cake\Http\MiddlewareQueue $middlewareQueue The middleware queue to update.
     */
    public function middleware(Middleware_Queue $middleware_queue): Middleware_Queue;
    /**
     * Add routes for the plugin.
     *
     * The default implementation of this method will include the `config/routes.php` in the plugin if it exists. You
     * can override this method to replace that behavior.
     *
     * @param \Cake\Routing\RouteBuilder $routes The route builder to update.
     */
    public function routes(Route_Builder $routes): void;
    /**
     * Register plugin services to the application's container
     *
     * @param \Cake\Core\ContainerInterface $container Container instance.
     */
    public function services(Container_Interface $container): void;
    /**
     * Disables the named hook
     *
     * @param string $hook The hook to disable
     * @return $this
     */
    public function disable(string $hook);
    /**
     * Enables the named hook
     *
     * @param string $hook The hook to disable
     * @return $this
     */
    public function enable(string $hook);
    /**
     * Check if the named hook is enabled
     *
     * @param string $hook The hook to check
     */
    public function is_enabled(string $hook): bool;
}