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
 * @since         3.0.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Command;

use Cake\Console\Arguments;
use Cake\Console\Console_Io;
use Cake\Console\Console_Option_Parser;
/**
 * Command for copying plugin assets to app's webroot.
 */
class Plugin_Assets_Copy_Command extends Command
{
    use Plugin_Assets_Trait;
    /**
     * @inheritDoc
     */
    public static function default_name(): string
    {
        return 'plugin assets copy';
    }
    /**
     * @inheritDoc
     */
    public static function get_description(): string
    {
        return "Copy plugin assets to app's webroot.";
    }
    /**
     * Execute the command
     *
     * Copying plugin assets to app's webroot. For vendor namespaced plugin,
     * parent folder for vendor name are created if required.
     *
     * @param \Cake\Console\Arguments $args The command arguments.
     * @param \Cake\Console\ConsoleIo $io The console io
     * @return int|null The exit code or null for success
     */
    public function execute(Arguments $args, Console_Io $io): ?int
    {
        $this->io = $io;
        $this->args = $args;
        $name = $args->get_argument('name');
        $overwrite = (bool) $args->get_option('overwrite');
        $this->_process($this->_list($name), true, $overwrite);
        return static::CODE_SUCCESS;
    }
    /**
     * Get the option parser.
     *
     * @param \Cake\Console\ConsoleOptionParser $parser The option parser to update
     */
    public function build_option_parser(Console_Option_Parser $parser): Console_Option_Parser
    {
        $parser->set_description(static::get_description())->add_argument('name', ['help' => 'A specific plugin you want to copy assets for.', 'required' => false])->add_option('overwrite', ['help' => 'Overwrite existing symlink / folder / files.', 'default' => false, 'boolean' => true]);
        return $parser;
    }
}