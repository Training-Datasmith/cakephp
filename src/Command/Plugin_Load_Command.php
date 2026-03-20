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
use Cake\Core\Exception\Missing_Plugin_Exception;
use Cake\Core\Plugin;
use Cake\Core\Plugin_Interface;
use Cake\Utility\Hash;
/**
 * Command for loading plugins.
 */
class Plugin_Load_Command extends Command
{
    /**
     * @var array<string>
     */
    protected static array $dev_tags = ['dev', 'testing', 'static analysis'];
    /**
     * @var array<string>
     */
    protected static array $cli_tags = ['cli', 'command line', 'shell'];
    /**
     * Config file
     */
    protected string $config_file = CONFIG . 'plugins.php';
    /**
     * @inheritDoc
     */
    public static function default_name(): string
    {
        return 'plugin load';
    }
    /**
     * @inheritDoc
     */
    public static function get_description(): string
    {
        return 'Command for loading plugins.';
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
        $options = [];
        if ($args->get_option('only-debug')) {
            $options['onlyDebug'] = true;
        }
        if ($args->get_option('only-cli')) {
            $options['onlyCli'] = true;
        }
        if ($args->get_option('optional')) {
            $options['optional'] = true;
        }
        foreach (Plugin_Interface::VALID_HOOKS as $hook) {
            if ($args->get_option('no-' . $hook)) {
                $options[$hook] = false;
            }
        }
        $path = null;
        try {
            $path = Plugin::get_collection()->find_path($plugin);
        } catch (Missing_Plugin_Exception $e) {
            if (empty($options['optional'])) {
                $io->error($e->get_message());
                $io->error('Ensure you have the correct spelling and casing.');
                return static::CODE_ERROR;
            }
        }
        $recommendations = $path ? $this->recommendations($path) : [];
        foreach ($recommendations as $name => $v) {
            if (isset($options[$name]) && $options[$name] === $v) {
                continue;
            }
            $option = $name . ': ' . ($v ? 'true' : 'false');
            $question = 'Based on the plugin composer keywords, this seems to be `' . $option . '`. ';
            $question .= 'Do you want to change this?';
            $in = $io->ask_choice($question, ['y', 'n'], 'y');
            if ($in !== 'y') {
                continue;
            }
            $options[$name] = $v;
        }
        $result = $this->modify_config_file($plugin, $options);
        if ($result === static::CODE_ERROR) {
            $io->error('Failed to update `CONFIG/plugins.php`');
        }
        $io->success('Plugin added successfully to `CONFIG/plugins.php`');
        return $result;
    }
    /**
     * Modify the plugins config file.
     *
     * @param string $plugin Plugin name.
     * @param array<string, mixed> $options Plugin options.
     */
    protected function modify_config_file(string $plugin, array $options): int
    {
        // phpcs:ignore
        $config = @include $this->config_file;
        if (!is_array($config)) {
            $config = [];
        } else {
            $config = Hash::normalize($config);
        }
        $config[$plugin] = $options;
        if (class_exists(Var_Exporter::class)) {
            $array = Var_Exporter::export($config, Var_Exporter::TRAILING_COMMA_IN_ARRAY);
        } else {
            $array = var_export($config, true);
        }
        $contents = '<?php' . "\n\n" . 'return ' . $array . ';' . "\n";
        if (file_put_contents($this->config_file, $contents)) {
            return static::CODE_SUCCESS;
        }
        return static::CODE_ERROR;
    }
    /**
     * @return array<string, bool>
     */
    protected function recommendations(string $path): array
    {
        $file = $path . 'composer.json';
        if (!file_exists($file)) {
            return [];
        }
        $content = file_get_contents($file);
        $array = $content ? json_decode($content, true) : [];
        $keywords = $array['keywords'] ?? [];
        if (!$keywords) {
            return [];
        }
        $recommendations = [];
        foreach (static::$dev_tags as $tag) {
            if (in_array($tag, $keywords, true)) {
                $recommendations['onlyDebug'] = true;
            }
        }
        foreach (static::$cli_tags as $tag) {
            if (in_array($tag, $keywords, true)) {
                $recommendations['onlyCli'] = true;
            }
        }
        if (!empty($recommendations['onlyDebug'])) {
            $recommendations['optional'] = true;
        }
        return $recommendations;
    }
    /**
     * Get the option parser.
     *
     * @param \Cake\Console\ConsoleOptionParser $parser The option parser to update
     */
    public function build_option_parser(Console_Option_Parser $parser): Console_Option_Parser
    {
        return $parser->set_description(static::get_description())->add_argument('plugin', ['help' => 'Name of the plugin to load. Must be in CamelCase format. Example: cake plugin load Example', 'required' => true])->add_option('only-debug', ['boolean' => true, 'help' => 'Load the plugin only when `debug` is enabled.'])->add_option('only-cli', ['boolean' => true, 'help' => 'Load the plugin only for CLI.'])->add_option('optional', ['boolean' => true, 'help' => 'Do not throw an error if the plugin is not available.'])->add_option('no-bootstrap', ['boolean' => true, 'help' => 'Do not run the `bootstrap()` hook.'])->add_option('no-console', ['boolean' => true, 'help' => 'Do not run the `console()` hook.'])->add_option('no-middleware', ['boolean' => true, 'help' => 'Do not run the `middleware()` hook.'])->add_option('no-routes', ['boolean' => true, 'help' => 'Do not run the `routes()` hook.'])->add_option('no-services', ['boolean' => true, 'help' => 'Do not run the `services()` hook.']);
    }
}