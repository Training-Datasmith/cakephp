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
 * @since         4.5.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Command;

use Cake\Cache\Cache;
use Cake\Cache\Exception\InvalidArgumentException;
use Cake\Console\Arguments;
use Cake\Console\Console_Io;
use Cake\Console\Console_Option_Parser;
/**
 * Cache Clear Group command.
 */
class Cache_Clear_Group_Command extends Command
{
    /**
     * Get the command name.
     */
    public static function default_name(): string
    {
        return 'cache clear_group';
    }
    /**
     * @inheritDoc
     */
    public static function get_description(): string
    {
        return 'Clear all data in a single cache group.';
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
        $parser->add_argument('group', ['help' => 'The cache group to clear. For example, `cake cache clear_group mygroup` will clear ' . 'all cache items belonging to group "mygroup".', 'required' => true]);
        $parser->add_argument('config', ['help' => 'Name of the configuration to use. Defaults to no value which clears all cache configurations.']);
        return $parser;
    }
    /**
     * Clears the cache group
     *
     * @param \Cake\Console\Arguments $args The command arguments.
     * @param \Cake\Console\ConsoleIo $io The console io
     * @return int|null The exit code or null for success
     */
    public function execute(Arguments $args, Console_Io $io): ?int
    {
        $group = (string) $args->get_argument('group');
        try {
            $group_configs = Cache::group_configs($group);
        } catch (InvalidArgumentException) {
            $io->error(sprintf('Cache group "%s" not found', $group));
            return static::CODE_ERROR;
        }
        $config = $args->get_argument('config');
        if ($config !== null && Cache::get_config($config) === null) {
            $io->error(sprintf('Cache config "%s" not found', $config));
            return static::CODE_ERROR;
        }
        foreach ($group_configs[$group] as $group_config) {
            if ($config !== null && $config !== $group_config) {
                continue;
            }
            if (!Cache::clear_group($group, $group_config)) {
                $io->error(sprintf('Error encountered clearing group "%s". Was unable to clear entries for "%s".', $group, $group_config));
                $this->abort();
            } else {
                $io->success(sprintf('Cache "%s" was cleared.', $group_config));
            }
        }
        return static::CODE_SUCCESS;
    }
}