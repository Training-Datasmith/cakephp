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
namespace Cake\Command;

use Cake\Cache\Cache;
use Cake\Console\Arguments;
use Cake\Console\Console_Io;
use Cake\Console\Console_Option_Parser;
/**
 * CacheList command.
 */
class Cache_List_Command extends Command
{
    /**
     * @inheritDoc
     */
    public static function default_name(): string
    {
        return 'cache list';
    }
    /**
     * @inheritDoc
     */
    public static function get_description(): string
    {
        return 'Show a list of configured caches.';
    }
    /**
     * Hook method for defining this command's option parser.
     *
     * @link https://book.cakephp.org/5/en/console-commands/option-parsers.html
     * @param \Cake\Console\ConsoleOptionParser $parser The parser to be defined
     * @return \Cake\Console\ConsoleOptionParser The built parser.
     */
    public function build_option_parser(Console_Option_Parser $parser): Console_Option_Parser
    {
        $parser = parent::build_option_parser($parser);
        $parser->set_description(static::get_description());
        return $parser;
    }
    /**
     * Get the list of cache prefixes
     *
     * @param \Cake\Console\Arguments $args The command arguments.
     * @param \Cake\Console\ConsoleIo $io The console io
     * @return int|null The exit code or null for success
     */
    public function execute(Arguments $args, Console_Io $io): ?int
    {
        foreach (Cache::configured() as $engine) {
            $io->out("- {$engine}");
        }
        return static::CODE_SUCCESS;
    }
}