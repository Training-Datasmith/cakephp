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
namespace Cake\Cache\Engine;

use Cake\Cache\Cache_Engine;
use Cake\Cache\Event\Cache_After_Delete_Event;
use Cake\Cache\Event\Cache_After_Get_Event;
use Cake\Cache\Event\Cache_After_Set_Event;
use Cake\Cache\Event\Cache_Before_Delete_Event;
use Cake\Cache\Event\Cache_Before_Get_Event;
use Cake\Cache\Event\Cache_Before_Set_Event;
use Cake\Cache\Event\Cache_Cleared_Event;
use Cake\Cache\Event\Cache_Group_Clear_Event;
use Callback_Filter_Iterator;
use DateInterval;
use Exception;
use Filesystem_Iterator;
use LogicException;
use Recursive_Directory_Iterator;
use Recursive_Iterator_Iterator;
use Spl_File_Info;
use Spl_File_Object;
/**
 * File Storage engine for cache. Filestorage is the slowest cache storage
 * to read and write. However, it is good for servers that don't have other storage
 * engine available, or have content which is not performance sensitive.
 *
 * You can configure a FileEngine cache, using Cache::config()
 *
 * @extends \Cake\Cache\CacheEngine<\Cake\Cache\Engine\FileEngine>
 */
class File_Engine extends Cache_Engine
{
    /**
     * Instance of SplFileObject class
     */
    protected Spl_File_Object $_File;
    /**
     * The default config used unless overridden by runtime configuration
     *
     * - `duration` Specify how long items in this cache configuration last.
     * - `groups` List of groups or 'tags' associated to every key stored in this config.
     *    handy for deleting a complete group from cache.
     * - `lock` Used by FileCache. Should files be locked before writing to them?
     * - `mask` The mask used for created files
     * - `dirMask` The mask used for created folders
     * - `path` Path to where cache files should be saved. Defaults to system's temp dir.
     * - `prefix` Prepended to all entries. Good for when you need to share a keyspace
     *    with either another cache config or another application.
     * - `serialize` Should cache objects be serialized first.
     *
     * @var array<string, mixed>
     */
    protected array $_default_config = ['duration' => 3600, 'groups' => [], 'lock' => true, 'mask' => 0664, 'dirMask' => 0777, 'path' => null, 'prefix' => 'cake_', 'serialize' => true];
    /**
     * True unless FileEngine::__active(); fails
     */
    protected bool $_init = true;
    /**
     * Initialize File Cache Engine
     *
     * Called automatically by the cache frontend.
     *
     * @param array<string, mixed> $config array of setting for the engine
     * @return bool True if the engine has been successfully initialized, false if not
     */
    public function init(array $config = []): bool
    {
        parent::init($config);
        $this->_config['path'] ??= sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cake_cache' . DIRECTORY_SEPARATOR;
        if (substr((string) $this->_config['path'], -1) !== DIRECTORY_SEPARATOR) {
            $this->_config['path'] .= DIRECTORY_SEPARATOR;
        }
        if ($this->_group_prefix) {
            $this->_group_prefix = str_replace('_', DIRECTORY_SEPARATOR, $this->_group_prefix);
        }
        return $this->_active();
    }
    /**
     * Write data for key into cache
     *
     * @param string $key Identifier for the data
     * @param mixed $value Data to be cached
     * @param \DateInterval|int|null $ttl Optional. The TTL value of this item. If no value is sent and
     *   the driver supports TTL then the library may set a default value
     *   for it or let the driver take care of that.
     * @return bool True on success and false on failure.
     */
    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
    {
        if ($value === '' || !$this->_init) {
            return false;
        }
        $duration = $this->duration($ttl);
        $key = $this->_key($key);
        $this->_event_class = Cache_Before_Set_Event::class;
        $this->dispatch_event(Cache_Before_Set_Event::NAME, ['key' => $key, 'value' => $value, 'ttl' => $duration]);
        $this->_event_class = Cache_After_Set_Event::class;
        if ($this->_set_key($key, true) === false) {
            $this->dispatch_event(Cache_After_Set_Event::NAME, ['key' => $key, 'value' => $value, 'success' => false, 'ttl' => $duration]);
            return false;
        }
        $orig_value = $value;
        if (!empty($this->_config['serialize'])) {
            $value = serialize($value);
        }
        $expires = time() + $duration;
        $contents = implode('', [$expires, PHP_EOL, $value, PHP_EOL]);
        if ($this->_config['lock']) {
            $this->_File->flock(LOCK_EX);
        }
        $this->_File->rewind();
        $success = $this->_File->ftruncate(0) && $this->_File->fwrite($contents) && $this->_File->fflush();
        if ($this->_config['lock']) {
            $this->_File->flock(LOCK_UN);
        }
        unset($this->_File);
        $this->dispatch_event(Cache_After_Set_Event::NAME, ['key' => $key, 'value' => $orig_value, 'success' => $success, 'ttl' => $duration]);
        return $success;
    }
    /**
     * Read a key from the cache
     *
     * @param string $key Identifier for the data
     * @param mixed $default Default value to return if the key does not exist.
     * @return mixed The cached data, or default value if the data doesn't exist, has
     *   expired, or if there was an error fetching it
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $key = $this->_key($key);
        $this->_event_class = Cache_Before_Get_Event::class;
        $this->dispatch_event(Cache_Before_Get_Event::NAME, ['key' => $key, 'default' => $default]);
        $this->_event_class = Cache_After_Get_Event::class;
        if (!$this->_init || $this->_set_key($key) === false) {
            $this->dispatch_event(Cache_After_Get_Event::NAME, ['key' => $key, 'value' => null, 'success' => false]);
            return $default;
        }
        if ($this->_config['lock']) {
            $this->_File->flock(LOCK_SH);
        }
        $this->_File->rewind();
        $time = time();
        $cachetime = (int) $this->_File->current();
        if ($cachetime < $time) {
            if ($this->_config['lock']) {
                $this->_File->flock(LOCK_UN);
            }
            $this->dispatch_event(Cache_After_Get_Event::NAME, ['key' => $key, 'value' => null, 'success' => false]);
            return $default;
        }
        $data = '';
        $this->_File->next();
        while ($this->_File->valid()) {
            $data .= $this->_File->current();
            $this->_File->next();
        }
        if ($this->_config['lock']) {
            $this->_File->flock(LOCK_UN);
        }
        $data = trim($data);
        if ($data !== '' && !empty($this->_config['serialize'])) {
            $data = unserialize($data, ['allowed_classes' => true]);
            $this->dispatch_event(Cache_After_Get_Event::NAME, ['key' => $key, 'value' => $data, 'success' => true]);
            return $data;
        }
        $this->dispatch_event(Cache_After_Get_Event::NAME, ['key' => $key, 'value' => $data, 'success' => true]);
        return $data;
    }
    /**
     * Delete a key from the cache
     *
     * @param string $key Identifier for the data
     * @return bool True if the value was successfully deleted, false if it didn't
     *   exist or couldn't be removed
     */
    public function delete(string $key): bool
    {
        $key = $this->_key($key);
        $this->_event_class = Cache_Before_Delete_Event::class;
        $this->dispatch_event(Cache_Before_Delete_Event::NAME, ['key' => $key]);
        $this->_event_class = Cache_After_Delete_Event::class;
        if ($this->_set_key($key) === false || !$this->_init) {
            $this->dispatch_event(Cache_After_Delete_Event::NAME, ['key' => $key, 'success' => false]);
            return false;
        }
        $path = $this->_File->get_real_path();
        unset($this->_File);
        if ($path === false) {
            $this->dispatch_event(Cache_After_Delete_Event::NAME, ['key' => $key, 'success' => false]);
            return false;
        }
        $this->dispatch_event(Cache_After_Delete_Event::NAME, ['key' => $key, 'success' => true]);
        // phpcs:disable
        return @unlink($path);
        // phpcs:enable
    }
    /**
     * Delete all values from the cache
     *
     * @return bool True if the cache was successfully cleared, false otherwise
     */
    public function clear(): bool
    {
        if (!$this->_init) {
            return false;
        }
        unset($this->_File);
        $this->_clear_directory($this->_config['path']);
        $directory = new Recursive_Directory_Iterator($this->_config['path'], Filesystem_Iterator::SKIP_DOTS);
        /** @var iterable<\SplFileInfo> $iterator */
        $iterator = new Recursive_Iterator_Iterator($directory, Recursive_Iterator_Iterator::SELF_FIRST);
        $cleared = [];
        foreach ($iterator as $file_info) {
            if ($file_info->is_file()) {
                unset($file_info);
                continue;
            }
            $real_path = $file_info->get_real_path();
            if (!$real_path) {
                unset($file_info);
                continue;
            }
            $path = $real_path . DIRECTORY_SEPARATOR;
            if (!in_array($path, $cleared, true)) {
                $this->_clear_directory($path);
                $cleared[] = $path;
            }
            // possible inner iterators need to be unset too in order for locks on parents to be released
            unset($file_info);
        }
        // unsetting iterators helps releasing possible locks in certain environments,
        // which could otherwise make `rmdir()` fail
        unset($directory, $iterator);
        $this->_event_class = Cache_Cleared_Event::class;
        $this->dispatch_event(Cache_Cleared_Event::NAME);
        return true;
    }
    /**
     * Used to clear a directory of matching files.
     *
     * @param string $path The path to search.
     */
    protected function _clear_directory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $dir = dir($path);
        if (!$dir) {
            return;
        }
        $prefix_length = strlen((string) $this->_config['prefix']);
        while (($entry = $dir->read()) !== false) {
            if (substr($entry, 0, $prefix_length) !== $this->_config['prefix']) {
                continue;
            }
            try {
                $file = new Spl_File_Object($path . $entry, 'r');
            } catch (Exception) {
                continue;
            }
            if ($file->is_file()) {
                $file_path = $file->get_real_path();
                unset($file);
                if ($file_path !== false) {
                    // phpcs:disable
                    @unlink($file_path);
                    // phpcs:enable
                }
            }
        }
        $dir->close();
    }
    /**
     * Not implemented
     *
     * @param string $key The key to decrement
     * @param int $offset The number to offset
     * @return int|false
     * @throws \LogicException
     */
    public function decrement(string $key, int $offset = 1): int|false
    {
        throw new LogicException('Files cannot be atomically decremented.');
    }
    /**
     * Not implemented
     *
     * @param string $key The key to increment
     * @param int $offset The number to offset
     * @return int|false
     * @throws \LogicException
     */
    public function increment(string $key, int $offset = 1): int|false
    {
        throw new LogicException('Files cannot be atomically incremented.');
    }
    /**
     * Sets the current cache key this class is managing, and creates a writable SplFileObject
     * for the cache file the key is referring to.
     *
     * @param string $key The key
     * @param bool $createKey Whether the key should be created if it doesn't exists, or not
     * @return bool true if the cache key could be set, false otherwise
     */
    protected function _set_key(string $key, bool $create_key = false): bool
    {
        $groups = null;
        if ($this->_group_prefix) {
            $groups = vsprintf($this->_group_prefix, $this->groups());
        }
        $dir = $this->_config['path'] . $groups;
        if (!is_dir($dir)) {
            mkdir($dir, $this->_config['dirMask'] ^ umask(), true);
        }
        $path = new Spl_File_Info($dir . $key);
        if (!$create_key && !$path->is_file()) {
            return false;
        }
        if (!isset($this->_File) || $this->_File->get_basename() !== $key || $this->_File->valid() === false) {
            $exists = is_file($path->get_pathname());
            try {
                $this->_File = $path->open_file('c+');
            } catch (Exception $e) {
                trigger_error($e->get_message(), E_USER_WARNING);
                return false;
            }
            unset($path);
            if (!$exists && !chmod($this->_File->get_pathname(), (int) $this->_config['mask'])) {
                trigger_error(sprintf('Could not apply permission mask `%s` on cache file `%s`', $this->_config['mask'], $this->_File->get_pathname()), E_USER_WARNING);
            }
        }
        return true;
    }
    /**
     * Determine if cache directory is writable
     */
    protected function _active(): bool
    {
        $dir = new Spl_File_Info($this->_config['path']);
        $path = $dir->get_pathname();
        $success = true;
        if (!is_dir($path)) {
            // phpcs:disable
            $success = @mkdir($path, $this->_config['dirMask'] ^ umask(), true);
            // phpcs:enable
        }
        $is_writable_dir = $dir->is_dir() && $dir->is_writable();
        if (!$success || $this->_init && !$is_writable_dir) {
            $this->_init = false;
            trigger_error(sprintf('%s is not writable', $this->_config['path']), E_USER_WARNING);
        }
        return $success;
    }
    /**
     * @inheritDoc
     */
    protected function _key(string $key): string
    {
        $key = parent::_key($key);
        return rawurlencode($key);
    }
    /**
     * Recursively deletes all files under any directory named as $group
     *
     * @param string $group The group to clear.
     * @return bool success
     */
    public function clear_group(string $group): bool
    {
        unset($this->_File);
        $prefix = (string) $this->_config['prefix'];
        $directory_iterator = new Recursive_Directory_Iterator($this->_config['path']);
        $contents = new Recursive_Iterator_Iterator($directory_iterator, Recursive_Iterator_Iterator::CHILD_FIRST);
        /** @var iterable<\SplFileInfo> $filtered */
        $filtered = new Callback_Filter_Iterator($contents, function (Spl_File_Info $current) use ($group, $prefix): bool {
            if (!$current->is_file()) {
                return false;
            }
            $has_prefix = $prefix === '' || str_starts_with($current->get_basename(), $prefix);
            if ($has_prefix === false) {
                return false;
            }
            return str_contains($current->get_pathname(), DIRECTORY_SEPARATOR . $group . DIRECTORY_SEPARATOR);
        });
        foreach ($filtered as $object) {
            $path = $object->get_pathname();
            unset($object);
            // phpcs:ignore
            @unlink($path);
        }
        // unsetting iterators helps releasing possible locks in certain environments,
        // which could otherwise make `rmdir()` fail
        unset($directory_iterator, $contents, $filtered);
        $this->_event_class = Cache_Group_Clear_Event::class;
        $this->dispatch_event(Cache_Group_Clear_Event::NAME, ['group' => $group]);
        return true;
    }
}