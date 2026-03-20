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
 * @since         5.1.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Command;

use Cake\Console\Arguments;
use Cake\Console\Console_Io;
use Cake\Console\Console_Option_Parser;
use Cake\Core\Plugin;
use Cake\Core\Plugin_Config;
use function Cake\I18n\__d;
/**
 * Displays all currently available plugins.
 */
class Plugin_List_Command extends Command
{
    /**
     * @inheritDoc
     */
    public static function default_name(): string
    {
        return 'plugin list';
    }
    /**
     * @inheritDoc
     */
    public static function get_description(): string
    {
        return 'Displays all currently available plugins.';
    }
    /**
     * Displays all currently available plugins.
     *
     * @param \Cake\Console\Arguments $args The command arguments.
     * @param \Cake\Console\ConsoleIo $io The console io
     * @return int|null The exit code or null for success
     */
    public function execute(Arguments $args, Console_Io $io): ?int
    {
        $loaded_plugins_collection = Plugin::get_collection();
        $path = (string) $args->get_option('composer-path');
        $config = Plugin_Config::get_app_config($path ?: null);
        $table = [['Plugin', 'Is Loaded', 'Only Debug', 'Only CLI', 'Optional', 'Version']];
        if ($config === []) {
            $io->warning(__d('cake', 'No plugins have been found.'));
            return static::CODE_ERROR;
        }
        foreach ($config as $plugin_name => $options) {
            $is_loaded = $loaded_plugins_collection->has($plugin_name);
            $only_debug = $options['onlyDebug'] ?? false;
            $only_cli = $options['onlyCli'] ?? false;
            $optional = $options['optional'] ?? false;
            $version = $options['version'] ?? '';
            $table[] = [$plugin_name, $is_loaded ? 'X' : '', $only_debug ? 'X' : '', $only_cli ? 'X' : '', $optional ? 'X' : '', $version];
        }
        $io->helper('Table')->output($table);
        return static::CODE_SUCCESS;
    }
    /**
     * Get the option parser.
     *
     * @param \Cake\Console\ConsoleOptionParser $parser The option parser to update
     */
    public function build_option_parser(Console_Option_Parser $parser): Console_Option_Parser
    {
        $parser->set_description(static::get_description());
        $parser->add_option('composer-path', ['help' => 'The absolute path to the composer.lock file to retrieve the versions from']);
        return $parser;
    }
}