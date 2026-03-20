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
 * @since         3.5.0
 * @license       https://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Console;

use Cake\Core\App;
use Cake\Core\Configure;
use Cake\Core\Plugin;
use Cake\Utility\Filesystem;
use Cake\Utility\Inflector;
use ReflectionClass;
/**
 * Used by CommandCollection and CommandTask to scan the filesystem
 * for command classes.
 *
 * @internal
 */
class Command_Scanner
{
    /**
     * Scan CakePHP internals for shells & commands.
     *
     * @return array A list of command metadata.
     */
    public function scan_core(): array
    {
        return $this->scan_dir(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Command' . DIRECTORY_SEPARATOR, 'Cake\Command\\', '', ['command_list']);
    }
    /**
     * Scan the application for shells & commands.
     *
     * @return array A list of command metadata.
     */
    public function scan_app(): array
    {
        $app_namespace = Configure::read('App.namespace');
        return $this->scan_dir(App::class_path('Command')[0], $app_namespace . '\Command\\', '', []);
    }
    /**
     * Scan the named plugin for shells and commands
     *
     * @param string $plugin The named plugin.
     * @return array A list of command metadata.
     */
    public function scan_plugin(string $plugin): array
    {
        if (!Plugin::is_loaded($plugin)) {
            return [];
        }
        $path = Plugin::class_path($plugin);
        $namespace = str_replace('/', '\\', $plugin);
        $prefix = Inflector::underscore($plugin) . '.';
        return $this->scan_dir($path . 'Command', $namespace . '\Command\\', $prefix, []);
    }
    /**
     * Scan a directory for .php files and return the class names that
     * should be within them.
     *
     * @param string $path The directory to read.
     * @param string $namespace The namespace the shells live in.
     * @param string $prefix The prefix to apply to commands for their full name.
     * @param array<string> $hide A list of command names to hide as they are internal commands.
     * @return array The list of shell info arrays based on scanning the filesystem and inflection.
     */
    protected function scan_dir(string $path, string $namespace, string $prefix, array $hide): array
    {
        if (!is_dir($path)) {
            return [];
        }
        // This ensures `Command` class is not added to the list.
        $hide[] = '';
        $class_pattern = '/Command\.php$/';
        $fs = new Filesystem();
        /** @var \Iterator<\SplFileInfo> $files */
        $files = $fs->find($path, $class_pattern);
        $commands = [];
        foreach ($files as $file_info) {
            $file = $file_info->get_filename();
            $name = Inflector::underscore((string) preg_replace($class_pattern, '', $file));
            if (in_array($name, $hide, true)) {
                continue;
            }
            $class = $namespace . $file_info->get_basename('.php');
            if (!is_subclass_of($class, Command_Interface::class)) {
                continue;
            }
            $reflection = new ReflectionClass($class);
            if ($reflection->is_abstract()) {
                continue;
            }
            if (is_subclass_of($class, Base_Command::class)) {
                $name = $class::default_name();
            }
            $commands[$path . $file] = ['file' => $path . $file, 'fullName' => $prefix . $name, 'name' => $name, 'class' => $class];
        }
        ksort($commands);
        return array_values($commands);
    }
}