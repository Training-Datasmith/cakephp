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
 * @since         1.2.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Command;

use Cake\Console\Arguments;
use Cake\Console\Console_Io;
use Cake\Console\Console_Option_Parser;
use Cake\Core\App;
use Cake\Core\Exception\Cake_Exception;
use Cake\Core\Plugin;
use Cake\Utility\Inflector;
use Directory_Iterator;
/**
 * Command for interactive I18N management.
 */
class I18n_Init_Command extends Command
{
    /**
     * @inheritDoc
     */
    public static function default_name(): string
    {
        return 'i18n init';
    }
    /**
     * @inheritDoc
     */
    public static function get_description(): string
    {
        return 'Initialize a language PO file from the POT file.';
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
        $language = $args->get_argument('language');
        if (!$language) {
            $language = $io->ask('Please specify language code, e.g. `en`, `eng`, `en_US` etc.');
        }
        if (strlen($language) < 2) {
            $io->error('Invalid language code. Valid is `en`, `eng`, `en_US` etc.');
            return static::CODE_ERROR;
        }
        $paths = array_values(App::path('locales'));
        if ($args->has_option('plugin')) {
            $plugin = Inflector::camelize((string) $args->get_option('plugin'));
            $paths = [Plugin::path($plugin) . 'resources' . DIRECTORY_SEPARATOR . 'locales' . DIRECTORY_SEPARATOR];
        }
        $response = $io->ask('What folder?', rtrim($paths[0], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
        $source_folder = rtrim($response, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $target_folder = $source_folder . $language . DIRECTORY_SEPARATOR;
        if (!is_dir($target_folder)) {
            mkdir($target_folder, 0777 ^ umask(), true);
        }
        $count = 0;
        $iterator = new Directory_Iterator($source_folder);
        foreach ($iterator as $file_info) {
            if (!$file_info->is_file()) {
                continue;
            }
            $filename = $file_info->get_filename();
            $new_filename = $file_info->get_basename('.pot');
            $new_filename .= '.po';
            $content = file_get_contents($source_folder . $filename);
            if ($content === false) {
                throw new Cake_Exception(sprintf('Cannot read file content of `%s`', $source_folder . $filename));
            }
            $io->create_file($target_folder . $new_filename, $content);
            $count++;
        }
        $io->out('Generated ' . $count . ' PO files in ' . $target_folder);
        return static::CODE_SUCCESS;
    }
    /**
     * Gets the option parser instance and configures it.
     *
     * @param \Cake\Console\ConsoleOptionParser $parser The parser to update
     */
    public function build_option_parser(Console_Option_Parser $parser): Console_Option_Parser
    {
        $parser->set_description(static::get_description())->add_option('plugin', ['help' => 'The plugin to create a PO file in.', 'short' => 'p'])->add_argument('language', ['help' => 'Two-letter language code to create PO files for.']);
        return $parser;
    }
}