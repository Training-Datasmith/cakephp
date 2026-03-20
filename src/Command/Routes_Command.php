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
 * @since         3.1.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Command;

use Cake\Console\Arguments;
use Cake\Console\Console_Io;
use Cake\Console\Console_Option_Parser;
use Cake\Routing\Router;
/**
 * Provides interactive CLI tools for routing.
 */
class Routes_Command extends Command
{
    /**
     * @inheritDoc
     */
    public static function get_description(): string
    {
        return 'Get the list of routes connected in this application.';
    }
    /**
     * Display all routes in an application
     *
     * @param \Cake\Console\Arguments $args The command arguments.
     * @param \Cake\Console\ConsoleIo $io The console io
     * @return int|null The exit code or null for success
     * @throws \JsonException
     */
    public function execute(Arguments $args, Console_Io $io): ?int
    {
        $header = ['Route name', 'URI template', 'Plugin', 'Prefix', 'Controller', 'Action', 'Method(s)'];
        if ($args->get_option('with-middlewares') || $args->get_option('verbose')) {
            $header[] = 'Middlewares';
        }
        if ($args->get_option('verbose')) {
            $header[] = 'Defaults';
        }
        $available_routes = Router::routes();
        $output = [];
        $duplicate_routes_counter = [];
        foreach ($available_routes as $route) {
            $methods = isset($route->defaults['_method']) ? (array) $route->defaults['_method'] : [''];
            $item = [$route->options['_name'] ?? $route->get_name(), $route->template, $route->defaults['plugin'] ?? '', $route->defaults['prefix'] ?? '', $route->defaults['controller'] ?? '', $route->defaults['action'] ?? '', implode(', ', $methods)];
            if ($args->get_option('with-middlewares') || $args->get_option('verbose')) {
                $item[] = implode(', ', $route->get_middleware());
            }
            if ($args->get_option('verbose')) {
                ksort($route->defaults);
                $item[] = json_encode($route->defaults, JSON_THROW_ON_ERROR);
            }
            $output[] = $item;
            foreach ($methods as $method) {
                $duplicate_routes_counter[$route->template][$method] ??= 0;
                $duplicate_routes_counter[$route->template][$method]++;
            }
        }
        if ($args->get_option('sort')) {
            usort($output, fn(array $a, array $b) => strcasecmp((string) $a[0], (string) $b[0]));
        }
        array_unshift($output, $header);
        $io->helper('table')->output($output);
        $io->out();
        $duplicate_routes = [];
        foreach ($available_routes as $route) {
            $methods = isset($route->defaults['_method']) ? (array) $route->defaults['_method'] : [''];
            foreach ($methods as $method) {
                if ($duplicate_routes_counter[$route->template][$method] > 1 || $method === '' && count($duplicate_routes_counter[$route->template]) > 1 || $method !== '' && isset($duplicate_routes_counter[$route->template][''])) {
                    $duplicate_routes[] = [$route->options['_name'] ?? $route->get_name(), $route->template, $route->defaults['plugin'] ?? '', $route->defaults['prefix'] ?? '', $route->defaults['controller'] ?? '', $route->defaults['action'] ?? '', implode(', ', $methods)];
                    break;
                }
            }
        }
        if ($duplicate_routes) {
            array_unshift($duplicate_routes, $header);
            $io->warning('The following possible route collisions were detected.');
            $io->helper('table')->output($duplicate_routes);
            $io->out();
        }
        return static::CODE_SUCCESS;
    }
    /**
     * Get the option parser.
     *
     * @param \Cake\Console\ConsoleOptionParser $parser The option parser to update
     */
    public function build_option_parser(Console_Option_Parser $parser): Console_Option_Parser
    {
        $parser->set_description(static::get_description())->add_option('sort', ['help' => 'Sorts alphabetically by route name A-Z', 'short' => 's', 'boolean' => true])->add_option('with-middlewares', ['help' => 'Show route specific middlewares', 'short' => 'm', 'boolean' => true]);
        return $parser;
    }
}