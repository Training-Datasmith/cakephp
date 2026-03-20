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
 * @since         5.2.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Command;

use Cake\Console\Arguments;
use Cake\Console\Console_Io;
use Cake\Console\Console_Option_Parser;
/**
 * Command for updating counter cache.
 */
class Counter_Cache_Command extends Command
{
    /**
     * @inheritDoc
     */
    public static function default_name(): string
    {
        return 'counter_cache';
    }
    /**
     * @inheritDoc
     */
    public static function get_description(): string
    {
        return 'Update counter cache for a model.';
    }
    /**
     * Execute the command.
     *
     * Updates the counter cache for the specified model and association based
     * on the model's counter cache behavior's configuration.
     *
     * @param \Cake\Console\Arguments $args The command arguments.
     * @param \Cake\Console\ConsoleIo $io The console io
     * @return int The exit code or null for success
     */
    public function execute(Arguments $args, Console_Io $io): int
    {
        $table = $this->fetch_table($args->get_argument('model'));
        if (!$table->has_behavior('CounterCache')) {
            $io->error('The specified model does not have the CounterCache behavior attached.');
            return static::CODE_ERROR;
        }
        $method_args = [];
        if ($args->has_option('assoc')) {
            $method_args['assocName'] = $args->get_option('assoc');
        }
        if ($args->has_option('limit')) {
            $method_args['limit'] = (int) $args->get_option('limit');
        }
        if ($args->has_option('page')) {
            $method_args['page'] = (int) $args->get_option('page');
        }
        /** @var \Cake\ORM\Table<array{CounterCache: \Cake\ORM\Behavior\CounterCacheBehavior}> $table */
        $table->get_behavior('CounterCache')->update_counter_cache(...$method_args);
        $io->success('Counter cache updated successfully.');
        return static::CODE_SUCCESS;
    }
    /**
     * @inheritDoc
     */
    public function build_option_parser(Console_Option_Parser $parser): Console_Option_Parser
    {
        $parser->set_description(static::get_description())->add_argument('model', ['help' => 'The model to update the counter cache for.', 'required' => true])->add_option('assoc', ['help' => 'The association to update the counter cache for. By default all associations are updated.', 'short' => 'a', 'default' => null])->add_option('limit', ['help' => 'The number of records to update per page/iteration', 'short' => 'l', 'default' => null])->add_option('page', ['help' => 'The page/iteration number. By default all records will be updated one page at a time.', 'short' => 'p', 'default' => null]);
        return $parser;
    }
}