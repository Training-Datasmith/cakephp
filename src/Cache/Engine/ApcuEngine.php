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
 * @since         3.5.4
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Cache\Engine;

use Apcu_Iterator;
use Cake\Cache\Cache_Engine;
use Cake\Cache\Event\Cache_After_Add_Event;
use Cake\Cache\Event\Cache_After_Decrement_Event;
use Cake\Cache\Event\Cache_After_Delete_Event;
use Cake\Cache\Event\Cache_After_Get_Event;
use Cake\Cache\Event\Cache_After_Increment_Event;
use Cake\Cache\Event\Cache_After_Set_Event;
use Cake\Cache\Event\Cache_Before_Add_Event;
use Cake\Cache\Event\Cache_Before_Decrement_Event;
use Cake\Cache\Event\Cache_Before_Delete_Event;
use Cake\Cache\Event\Cache_Before_Get_Event;
use Cake\Cache\Event\Cache_Before_Increment_Event;
use Cake\Cache\Event\Cache_Before_Set_Event;
use Cake\Cache\Event\Cache_Cleared_Event;
use Cake\Cache\Event\Cache_Group_Clear_Event;
use Cake\Core\Exception\Cake_Exception;
use DateInterval;
/**
 * APCu storage engine for cache
 *
 * @extends \Cake\Cache\CacheEngine<\Cake\Cache\Engine\ApcuEngine>
 */
class Apcu_Engine extends Cache_Engine
{
    /**
     * Contains the compiled group names
     * (prefixed with the global configuration prefix)
     *
     * @var array<string>
     */
    protected array $_compiled_group_names = [];
    /**
     * Initialize the Cache Engine
     *
     * Called automatically by the cache frontend
     *
     * @param array<string, mixed> $config array of setting for the engine
     * @return bool True if the engine has been successfully initialized, false if not
     */
    public function init(array $config = []): bool
    {
        if (!extension_loaded('apcu')) {
            throw new Cake_Exception('The `apcu` extension must be enabled to use ApcuEngine.');
        }
        return parent::init($config);
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
     * @link https://secure.php.net/manual/en/function.apcu-store.php
     */
    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
    {
        $key = $this->_key($key);
        $duration = $this->duration($ttl);
        $this->_event_class = Cache_Before_Set_Event::class;
        $this->dispatch_event(Cache_Before_Set_Event::NAME, ['key' => $key, 'value' => $value, 'ttl' => $duration]);
        $success = apcu_store($key, $value, $duration);
        $this->_event_class = Cache_After_Set_Event::class;
        $this->dispatch_event(Cache_After_Set_Event::NAME, ['key' => $key, 'value' => $value, 'success' => $success, 'ttl' => $duration]);
        return $success;
    }
    /**
     * Read a key from the cache
     *
     * @param string $key Identifier for the data
     * @param mixed $default Default value in case the cache misses.
     * @return mixed The cached data, or default if the data doesn't exist,
     *   has expired, or if there was an error fetching it
     * @link https://secure.php.net/manual/en/function.apcu-fetch.php
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $key = $this->_key($key);
        $this->_event_class = Cache_Before_Get_Event::class;
        $this->dispatch_event(Cache_Before_Get_Event::NAME, ['key' => $key, 'default' => $default]);
        $value = apcu_fetch($key, $success);
        $this->_event_class = Cache_After_Get_Event::class;
        $this->dispatch_event(Cache_After_Get_Event::NAME, ['key' => $key, 'value' => $value, 'success' => $success]);
        if ($success === false) {
            return $default;
        }
        return $value;
    }
    /**
     * Increments the value of an integer cached key
     *
     * @param string $key Identifier for the data
     * @param int $offset How much to increment
     * @return int|false New incremented value, false otherwise
     * @link https://secure.php.net/manual/en/function.apcu-inc.php
     */
    public function increment(string $key, int $offset = 1): int|false
    {
        $key = $this->_key($key);
        $this->_event_class = Cache_Before_Increment_Event::class;
        $this->dispatch_event(Cache_Before_Increment_Event::NAME, ['key' => $key, 'offset' => $offset]);
        $value = apcu_inc($key, $offset);
        $this->_event_class = Cache_After_Increment_Event::class;
        $this->dispatch_event(Cache_After_Increment_Event::NAME, ['key' => $key, 'offset' => $offset, 'success' => $value !== false, 'value' => $value]);
        return $value;
    }
    /**
     * Decrements the value of an integer cached key
     *
     * @param string $key Identifier for the data
     * @param int $offset How much to subtract
     * @return int|false New decremented value, false otherwise
     * @link https://secure.php.net/manual/en/function.apcu-dec.php
     */
    public function decrement(string $key, int $offset = 1): int|false
    {
        $key = $this->_key($key);
        $this->_event_class = Cache_Before_Decrement_Event::class;
        $this->dispatch_event(Cache_Before_Decrement_Event::NAME, ['key' => $key, 'offset' => $offset]);
        $result = apcu_dec($key, $offset);
        $this->_event_class = Cache_After_Decrement_Event::class;
        $this->dispatch_event(Cache_After_Decrement_Event::NAME, ['key' => $key, 'offset' => $offset, 'success' => $result !== false, 'value' => $result]);
        return $result;
    }
    /**
     * Delete a key from the cache
     *
     * @param string $key Identifier for the data
     * @return bool True if the value was successfully deleted, false if it didn't exist or couldn't be removed
     * @link https://secure.php.net/manual/en/function.apcu-delete.php
     */
    public function delete(string $key): bool
    {
        $key = $this->_key($key);
        $this->_event_class = Cache_Before_Delete_Event::class;
        $this->dispatch_event(Cache_Before_Delete_Event::NAME, ['key' => $key]);
        $result = apcu_delete($key);
        $this->_event_class = Cache_After_Delete_Event::class;
        $this->dispatch_event(Cache_After_Delete_Event::NAME, ['key' => $key, 'success' => $result]);
        return $result;
    }
    /**
     * Delete all keys from the cache. This will clear every cache config using APCu.
     *
     * @return bool True on success.
     * @link https://secure.php.net/manual/en/function.apcu-cache-info.php
     * @link https://secure.php.net/manual/en/function.apcu-delete.php
     */
    public function clear(): bool
    {
        if (class_exists(Apcu_Iterator::class, false)) {
            $iterator = new Apcu_Iterator('/^' . preg_quote((string) $this->_config['prefix'], '/') . '/', APC_ITER_NONE);
            apcu_delete($iterator);
            $this->_event_class = Cache_Cleared_Event::class;
            $this->dispatch_event(Cache_Cleared_Event::NAME);
            return true;
        }
        $cache = apcu_cache_info();
        // Raises warning by itself already
        foreach ($cache['cache_list'] as $key) {
            if (str_starts_with((string) $key['info'], (string) $this->_config['prefix'])) {
                apcu_delete($key['info']);
            }
        }
        $this->_event_class = Cache_Cleared_Event::class;
        $this->dispatch_event(Cache_Cleared_Event::NAME);
        return true;
    }
    /**
     * Write data for key into cache if it doesn't exist already.
     * If it already exists, it fails and returns false.
     *
     * @param string $key Identifier for the data.
     * @param mixed $value Data to be cached.
     * @return bool True if the data was successfully cached, false on failure.
     * @link https://secure.php.net/manual/en/function.apcu-add.php
     */
    public function add(string $key, mixed $value): bool
    {
        $key = $this->_key($key);
        $duration = $this->_config['duration'];
        $this->_event_class = Cache_Before_Add_Event::class;
        $this->dispatch_event(Cache_Before_Add_Event::NAME, ['key' => $key, 'value' => $value, 'ttl' => $duration]);
        $result = apcu_add($key, $value, $duration);
        $this->_event_class = Cache_After_Add_Event::class;
        $this->dispatch_event(Cache_After_Add_Event::NAME, ['key' => $key, 'value' => $value, 'success' => $result, 'ttl' => $duration]);
        return $result;
    }
    /**
     * Returns the `group value` for each of the configured groups
     * If the group initial value was not found, then it initializes
     * the group accordingly.
     *
     * @return array<string>
     * @link https://secure.php.net/manual/en/function.apcu-fetch.php
     * @link https://secure.php.net/manual/en/function.apcu-store.php
     */
    public function groups(): array
    {
        if (!$this->_compiled_group_names) {
            foreach ($this->_config['groups'] as $group) {
                $this->_compiled_group_names[] = $this->_config['prefix'] . $group;
            }
        }
        $success = false;
        $groups = apcu_fetch($this->_compiled_group_names, $success);
        if ($success && count($groups) !== count($this->_config['groups'])) {
            foreach ($this->_compiled_group_names as $group) {
                if (!isset($groups[$group])) {
                    $value = 1;
                    if (apcu_store($group, $value) === false) {
                        $this->warning(sprintf('Failed to store key `%s` with value `%s` into APCu cache.', $group, $value));
                    }
                    $groups[$group] = $value;
                }
            }
            ksort($groups);
        }
        $result = [];
        $groups = array_values($groups);
        foreach ($this->_config['groups'] as $i => $group) {
            $result[] = $group . $groups[$i];
        }
        return $result;
    }
    /**
     * Increments the group value to simulate deletion of all keys under a group
     * old values will remain in storage until they expire.
     *
     * @param string $group The group to clear.
     * @return bool success
     * @link https://secure.php.net/manual/en/function.apcu-inc.php
     */
    public function clear_group(string $group): bool
    {
        $success = false;
        apcu_inc($this->_config['prefix'] . $group, 1, $success);
        $this->_event_class = Cache_Group_Clear_Event::class;
        $this->dispatch_event(Cache_Group_Clear_Event::NAME, ['group' => $group]);
        return $success;
    }
}