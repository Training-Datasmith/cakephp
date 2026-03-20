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
use Cake\Http\Exception\Redirect_Exception;
use Cake\Http\Server_Request;
use Cake\Routing\Exception\Missing_Route_Exception;
use Cake\Routing\Router;
/**
 * Provides interactive CLI tool for testing routes.
 */
class Routes_Check_Command extends Command
{
    /**
     * @inheritDoc
     */
    public static function default_name(): string
    {
        return 'routes check';
    }
    /**
     * @inheritDoc
     */
    public static function get_description(): string
    {
        return 'Check a URL string against the routes.';
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
        $url = $args->get_argument('url');
        try {
            $parsed = Router::parse_request(new Server_Request(['url' => $url]));
            $name = $parsed['_name'] ?? $parsed['_route']->get_name();
            unset($parsed['_route'], $parsed['_matchedRoute']);
            ksort($parsed);
            $output = [['Route name', 'URI template', 'Defaults'], [$name, $url, json_encode($parsed, JSON_THROW_ON_ERROR)]];
            $io->helper('table')->output($output);
            $io->out();
        } catch (Redirect_Exception $e) {
            $output = [['URI template', 'Redirect'], [$url, $e->get_message()]];
            $io->helper('table')->output($output);
            $io->out();
        } catch (Missing_Route_Exception) {
            $io->warning("'{$url}' did not match any routes.");
            $io->out();
            return static::CODE_ERROR;
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
        $parser->set_description([static::get_description(), 'Will output the routing parameters the route resolves to.'])->add_argument('url', ['help' => 'The URL to check.', 'required' => true]);
        return $parser;
    }
}