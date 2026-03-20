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

use Brick\Var_Exporter\Var_Exporter;
use Cake\Console\Arguments;
use Cake\Console\Console_Io;
use Cake\Console\Console_Option_Parser;
use Cake\Utility\Hash;
/**
 * Command for unloading plugins.
 */
class Plugin_Unload_Command extends Command
{
    /**
     * Config file
     */
    protected string $config_file = CONFIG . 'plugins.php';
    /**
     * @inheritDoc
     */
    public static function default_name(): string
    {
        return 'plugin unload';
    }
    /**
     * @inheritDoc
     */
    public static function get_description(): string
    {
        return 'Command for unloading plugins.';
    }
    /**
     * Execute the command
     *
     * @param \Cake\Console\Arguments $args The command arguments.
     * @param \Cake\Console\ConsoleIo $io The console io
     * @return int|null The exit code or null for success
     */
    public function execute(Arguments $args, Console_Io $io): ?int
    {
        $plugin = (string) $args->get_argument('plugin');
        $result = $this->modify_config_file($plugin);
        if ($result === null) {
            $io->success('Plugin removed from `CONFIG/plugins.php`');
            return static::CODE_SUCCESS;
        }
        $io->err($result);
        return static::CODE_ERROR;
    }
    /**
     * Modify the plugins config file.
     *
     * @param string $plugin Plugin name.
     */
    protected function modify_config_file(string $plugin): ?string
    {
        // phpcs:ignore
        $config = @include $this->config_file;
        if (!is_array($config)) {
            return '`CONFIG/plugins.php` not found or does not return an array';
        }
        $config = Hash::normalize($config);
        if (!array_key_exists($plugin, $config)) {
            return sprintf('Plugin `%s` could not be found', $plugin);
        }
        unset($config[$plugin]);
        if (class_exists(Var_Exporter::class)) {
            $array = Var_Exporter::export($config);
        } else {
            $array = var_export($config, true);
        }
        $contents = '<?php' . "\n" . 'return ' . $array . ';';
        if (file_put_contents($this->config_file, $contents)) {
            return null;
        }
        return 'Failed to update `CONFIG/plugins.php`';
    }
    /**
     * Get the option parser.
     *
     * @param \Cake\Console\ConsoleOptionParser $parser The option parser to update
     */
    public function build_option_parser(Console_Option_Parser $parser): Console_Option_Parser
    {
        $parser->set_description(static::get_description())->add_argument('plugin', ['help' => 'Name of the plugin to unload.', 'required' => true]);
        return $parser;
    }
}