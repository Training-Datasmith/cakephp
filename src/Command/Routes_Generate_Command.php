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
use Cake\Routing\Exception\Missing_Route_Exception;
use Cake\Routing\Router;
/**
 * Provides interactive CLI tools for URL generation
 */
class Routes_Generate_Command extends Command
{
    /**
     * @inheritDoc
     */
    public static function default_name(): string
    {
        return 'routes generate';
    }
    /**
     * @inheritDoc
     */
    public static function get_description(): string
    {
        return 'Check a routing array against the routes.';
    }
    /**
     * Display all routes in an application
     *
     * @param \Cake\Console\Arguments $args The command arguments.
     * @param \Cake\Console\ConsoleIo $io The console io
     * @return int|null The exit code or null for success
     */
    public function execute(Arguments $args, Console_Io $io): ?int
    {
        try {
            $args = $this->_split_args($args->get_arguments());
            $url = Router::url($args);
            $io->out("> {$url}");
            $io->out();
        } catch (Missing_Route_Exception) {
            $io->warning('The provided parameters do not match any routes.');
            $io->out();
            return static::CODE_ERROR;
        }
        return static::CODE_SUCCESS;
    }
    /**
     * Split the CLI arguments into a hash.
     *
     * @param array<string> $args The arguments to split.
     * @return array<string|bool>
     */
    protected function _split_args(array $args): array
    {
        $out = [];
        foreach ($args as $arg) {
            if (str_contains($arg, ':')) {
                [$key, $value] = explode(':', $arg, 2);
                if (in_array($value, ['true', 'false'], true)) {
                    $value = $value === 'true';
                }
                $out[$key] = $value;
            } else {
                $out[] = $arg;
            }
        }
        return $out;
    }
    /**
     * Get the option parser.
     *
     * @param \Cake\Console\ConsoleOptionParser $parser The option parser to update
     */
    public function build_option_parser(Console_Option_Parser $parser): Console_Option_Parser
    {
        $parser->set_description([static::get_description(), 'Will output the URL if there is a match.' . "\n\n" . 'Routing parameters should be supplied in a key:value format. ' . 'For example `controller:Articles action:view 2`']);
        return $parser;
    }
}