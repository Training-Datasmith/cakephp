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
use Cake\Cache\Engine\Apcu_Engine;
use Cake\Cache\Exception\InvalidArgumentException;
use Cake\Console\Arguments;
use Cake\Console\Console_Io;
use Cake\Console\Console_Option_Parser;
/**
 * CacheClear command.
 */
class Cache_Clear_Command extends Command
{
    /**
     * @inheritDoc
     */
    public static function default_name(): string
    {
        return 'cache clear';
    }
    /**
     * @inheritDoc
     */
    public static function get_description(): string
    {
        return 'Clear all data in a single cache engine.';
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
        $parser->set_description(static::get_description())->add_argument('engine', ['help' => 'The cache engine to clear.' . 'For example, `cake cache clear _cake_model_` will clear the model cache.' . ' Use `cake cache list` to list available engines.', 'required' => true]);
        return $parser;
    }
    /**
     * Implement this method with your command's logic.
     *
     * @param \Cake\Console\Arguments $args The command arguments.
     * @param \Cake\Console\ConsoleIo $io The console io
     * @return int|null The exit code or null for success
     */
    public function execute(Arguments $args, Console_Io $io): ?int
    {
        $name = (string) $args->get_argument('engine');
        try {
            $io->out("Clearing {$name}");
            $engine = Cache::pool($name);
            Cache::clear($name);
            if ($engine instanceof Apcu_Engine) {
                $io->warning("ApcuEngine detected: Cleared {$name} CLI cache successfully " . "but {$name} web cache must be cleared separately.");
            } else {
                $io->out("<success>Cleared {$name} cache</success>");
            }
        } catch (InvalidArgumentException $e) {
            $io->error($e->get_message());
            $this->abort();
        }
        return static::CODE_SUCCESS;
    }
}