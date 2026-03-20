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
use Cake\Core\Configure;
use Cake\Core\Plugin;
use Cake\Utility\Filesystem;
use Cake\Utility\Inflector;
use InvalidArgumentException;
/**
 * Trait for symlinking / copying plugin assets to app's webroot.
 *
 * @internal
 */
trait Plugin_Assets_Trait
{
    /**
     * Arguments
     */
    protected Arguments $args;
    /**
     * Console IO
     */
    protected Console_Io $io;
    /**
     * Get list of plugins to process. Plugins without a webroot directory are skipped.
     *
     * @param string|null $name Name of plugin for which to symlink assets.
     *   If null all plugins will be processed.
     * @return array<string, mixed> List of plugins with meta data.
     */
    protected function _list(?string $name = null): array
    {
        if ($name === null) {
            $plugins_list = Plugin::loaded();
        } else {
            $plugins_list = [$name];
        }
        $plugins = [];
        foreach ($plugins_list as $plugin) {
            $path = Plugin::path($plugin) . 'webroot';
            if (!is_dir($path)) {
                $this->io->verbose('', 1);
                $this->io->verbose(sprintf('Skipping plugin %s. It does not have webroot folder.', $plugin), 2);
                continue;
            }
            $link = Inflector::underscore($plugin);
            $www_root = Configure::read('App.wwwRoot');
            $dir = $www_root;
            $namespaced = false;
            if (str_contains($link, '/')) {
                $namespaced = true;
                $parts = explode('/', $link);
                $link = array_pop($parts);
                $dir = $www_root . implode(DIRECTORY_SEPARATOR, $parts) . DIRECTORY_SEPARATOR;
            }
            $plugins[$plugin] = ['srcPath' => Plugin::path($plugin) . 'webroot', 'destDir' => $dir, 'link' => $link, 'namespaced' => $namespaced];
        }
        return $plugins;
    }
    /**
     * Process plugins
     *
     * @param array<string, mixed> $plugins List of plugins to process
     * @param bool $copy Force copy mode. Default false.
     * @param bool $overwrite Overwrite existing files.
     * @param bool $relative Relative. Default false.
     */
    protected function _process(array $plugins, bool $copy = false, bool $overwrite = false, bool $relative = false): void
    {
        foreach ($plugins as $plugin => $config) {
            $this->io->out();
            $this->io->out('For plugin: ' . $plugin);
            $this->io->hr();
            if ($config['namespaced'] && !is_dir($config['destDir']) && !$this->_create_directory($config['destDir'])) {
                continue;
            }
            $dest = $config['destDir'] . $config['link'];
            if ($copy) {
                if ((is_link($dest) || $overwrite) && !$this->_remove($config)) {
                    continue;
                }
                if (file_exists($dest)) {
                    $this->io->verbose($dest . ' already exists', 1);
                } else {
                    $this->_copy_directory($config['srcPath'], $dest);
                }
                continue;
            }
            $result = $this->_create_symlink($config['srcPath'], $dest, $relative);
            if ($result) {
                continue;
            }
            if ($this->_is_symlink_valid($config['srcPath'], $dest)) {
                $this->io->verbose($dest . ' already exists', 1);
                continue;
            }
            if (!$this->_remove($config)) {
                continue;
            }
            if (!$this->_create_symlink($config['srcPath'], $dest)) {
                continue;
            }
        }
        $this->io->out();
        $this->io->out('Done');
    }
    /**
     * Remove folder/symlink.
     *
     * @param array<string, mixed> $config Plugin config.
     */
    protected function _remove(array $config): bool
    {
        if ($config['namespaced'] && !is_dir($config['destDir'])) {
            return true;
        }
        $dest = $config['destDir'] . $config['link'];
        if (is_link($dest)) {
            // phpcs:ignore
            $success = DIRECTORY_SEPARATOR === '\\' ? @rmdir($dest) : @unlink($dest);
            if ($success) {
                $this->io->out('Unlinked ' . $dest);
                return true;
            }
            $this->io->error('Failed to unlink  ' . $dest);
            return false;
        }
        if (!file_exists($dest)) {
            return true;
        }
        $fs = new Filesystem();
        if (!$fs->delete_dir($dest)) {
            $this->io->error('Failed to delete ' . $dest);
            return false;
        }
        $this->io->out('Deleted ' . $dest);
        return true;
    }
    /**
     * Create directory
     *
     * @param string $dir Directory name
     */
    protected function _create_directory(string $dir): bool
    {
        // phpcs:disable
        $result = @mkdir($dir, 0777 ^ umask(), true);
        // phpcs:enable
        if ($result) {
            $this->io->out('Created directory ' . $dir);
            return true;
        }
        $this->io->error('Failed creating directory ' . $dir);
        return false;
    }
    /**
     * Create symlink
     *
     * @param string $target Target directory
     * @param string $link Link name
     * @param bool $relative Relative (true) or Absolute (false)
     */
    protected function _create_symlink(string $target, string $link, bool $relative = false): bool
    {
        if ($relative) {
            $target = $this->_make_relative_path($link, $target);
        }
        // phpcs:disable
        $result = @symlink($target, $link);
        // phpcs:enable
        if ($result) {
            $this->io->out('Created symlink ' . $link);
            return true;
        }
        return false;
    }
    /**
     * Generate a relative path from one directory to another.
     *
     * @param string $from The symlink path
     * @param string $to The target path
     * @return string Relative path
     */
    protected function _make_relative_path(string $from, string $to): string
    {
        $from = is_dir($from) ? rtrim($from, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR : dirname($from);
        $from = realpath($from);
        $to = realpath($to);
        if ($from === false || $to === false) {
            throw new InvalidArgumentException('Invalid path provided to _makeRelativePath.');
        }
        $from_parts = explode(DIRECTORY_SEPARATOR, $from);
        $to_parts = explode(DIRECTORY_SEPARATOR, $to);
        $from_count = count($from_parts);
        $to_count = count($to_parts);
        // Remove common parts
        while ($from_count && $to_count && $from_parts[0] === $to_parts[0]) {
            array_shift($from_parts);
            array_shift($to_parts);
            $from_count--;
            $to_count--;
        }
        return str_repeat('..' . DIRECTORY_SEPARATOR, $from_count) . implode(DIRECTORY_SEPARATOR, $to_parts);
    }
    /**
     * Checks if symlink exist and points to the correct target.
     */
    protected function _is_symlink_valid(string $target, string $link): bool
    {
        if (!is_link($link)) {
            return false;
        }
        $linked_path = readlink($link);
        if ($linked_path === false) {
            return false;
        }
        return realpath($target) === realpath($linked_path);
    }
    /**
     * Copy directory
     *
     * @param string $source Source directory
     * @param string $destination Destination directory
     */
    protected function _copy_directory(string $source, string $destination): bool
    {
        $fs = new Filesystem();
        if ($fs->copy_dir($source, $destination)) {
            $this->io->out('Copied assets to directory ' . $destination);
            return true;
        }
        $this->io->error('Error copying assets to directory ' . $destination);
        return false;
    }
}