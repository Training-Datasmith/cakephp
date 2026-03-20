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
namespace Cake\Command;

use Cake\Console\Arguments;
use Cake\Console\Console_Io;
use Cake\Console\Console_Option_Parser;
use Cake\Database\Connection;
use Cake\Database\Schema_Cache;
use Cake\Datasource\Connection_Manager;
use RuntimeException;
/**
 * Provides CLI tool for updating schema cache.
 */
class Schemacache_Build_Command extends Command
{
    /**
     * Get the command name.
     */
    public static function default_name(): string
    {
        return 'schema_cache build';
    }
    /**
     * @inheritDoc
     */
    public static function get_description(): string
    {
        return 'Build all metadata caches for the connection.';
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
            $connection = Connection_Manager::get((string) $args->get_option('connection'));
            assert($connection instanceof Connection);
            $cache = new Schema_Cache($connection);
        } catch (RuntimeException $e) {
            $io->error($e->get_message());
            return static::CODE_ERROR;
        }
        $tables = $cache->build($args->get_argument('name'));
        foreach ($tables as $table) {
            $io->verbose(sprintf('Cached `%s`', $table));
        }
        $io->out('<success>Cache build complete</success>');
        return static::CODE_SUCCESS;
    }
    /**
     * Get the option parser.
     *
     * @param \Cake\Console\ConsoleOptionParser $parser The option parser to update
     */
    public function build_option_parser(Console_Option_Parser $parser): Console_Option_Parser
    {
        $parser->set_description([static::get_description(), ' If a table name is provided, only that table will be cached.'])->add_option('connection', ['help' => 'The connection to build/clear metadata cache data for.', 'short' => 'c', 'default' => 'default'])->add_argument('name', ['help' => 'A specific table you want to refresh cached data for.', 'required' => false]);
        return $parser;
    }
}