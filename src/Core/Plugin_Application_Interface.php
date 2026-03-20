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
namespace Cake\Core;

use Cake\Console\Command_Collection;
use Cake\Event\Event_Dispatcher_Interface;
use Cake\Http\Middleware_Queue;
use Cake\Routing\Route_Builder;
/**
 * Interface for Applications that leverage plugins & events.
 *
 * Events can be bound to the application event manager during
 * the application's bootstrap and plugin bootstrap.
 *
 * @template TSubject
 * @extends \Cake\Event\EventDispatcherInterface<\Cake\Http\BaseApplication>
 */
interface Plugin_Application_Interface extends Event_Dispatcher_Interface
{
    /**
     * Add a plugin to the loaded plugin set.
     *
     * If the named plugin does not exist, or does not define a Plugin class, an
     * instance of `Cake\Core\BasePlugin` will be used. This generated class will have
     * all plugin hooks enabled.
     *
     * @param \Cake\Core\PluginInterface|string $name The plugin name or plugin object.
     * @param array<string, mixed> $config The configuration data for the plugin if using a string for $name
     * @return $this
     */
    public function add_plugin(Plugin_Interface|string $name, array $config = []);
    /**
     * Run bootstrap logic for loaded plugins.
     */
    public function plugin_bootstrap(): void;
    /**
     * Run routes hooks for loaded plugins
     *
     * @param \Cake\Routing\RouteBuilder $routes The route builder to use.
     */
    public function plugin_routes(Route_Builder $routes): Route_Builder;
    /**
     * Run middleware hooks for plugins
     *
     * @param \Cake\Http\MiddlewareQueue $middleware The MiddlewareQueue to use.
     */
    public function plugin_middleware(Middleware_Queue $middleware): Middleware_Queue;
    /**
     * Run console hooks for plugins
     *
     * @param \Cake\Console\CommandCollection $commands The CommandCollection to use.
     */
    public function plugin_console(Command_Collection $commands): Command_Collection;
}