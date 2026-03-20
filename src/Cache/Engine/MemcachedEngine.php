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
 * @since         2.5.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Cache\Engine;

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
use Cake\Cache\Exception\InvalidArgumentException;
use Cake\Core\Exception\Cake_Exception;
use DateInterval;
use Memcached;
/**
 * Memcached storage engine for cache. Memcached has some limitations in the amount of
 * control you have over expire times far in the future. See MemcachedEngine::write() for
 * more information.
 *
 * Memcached engine supports binary protocol and igbinary
 * serialization (if memcached extension is compiled with --enable-igbinary).
 * Compressed keys can also be incremented/decremented.
 *
 * @extends \Cake\Cache\CacheEngine<\Cake\Cache\Engine\MemcachedEngine>
 */
class Memcached_Engine extends Cache_Engine
{
    /**
     * memcached wrapper.
     */
    protected Memcached $_Memcached;
    /**
     * The default config used unless overridden by runtime configuration
     *
     * - `compress` Whether to compress data
     * - `duration` Specify how long items in this cache configuration last.
     * - `groups` List of groups or 'tags' associated to every key stored in this config.
     *    handy for deleting a complete group from cache.
     * - `username` Login to access the Memcache server
     * - `password` Password to access the Memcache server
     * - `persistent` The name of the persistent connection. All configurations using
     *    the same persistent value will share a single underlying connection.
     * - `prefix` Prepended to all entries. Good for when you need to share a keyspace
     *    with either another cache config or another application.
     * - `serialize` The serializer engine used to serialize data. Available engines are 'php',
     *    'igbinary' and 'json'. Besides 'php', the memcached extension must be compiled with the
     *    appropriate serializer support.
     * - `servers` String or array of memcached servers. If an array MemcachedEngine will use
     *    them as a pool.
     * - `options` - Additional options for the memcached client. Should be an array of option => value.
     *    Use the \Memcached::OPT_* constants as keys.
     *
     * @var array<string, mixed>
     */
    protected array $_default_config = ['compress' => false, 'duration' => 3600, 'groups' => [], 'host' => null, 'username' => null, 'password' => null, 'persistent' => null, 'port' => null, 'prefix' => 'cake_', 'serialize' => 'php', 'servers' => ['127.0.0.1'], 'options' => []];
    /**
     * List of available serializer engines
     *
     * Memcached must be compiled with JSON and igbinary support to use these engines
     *
     * @var array<string, int>
     */
    protected array $_serializers = [];
    /**
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
     * @throws \Cake\Cache\Exception\InvalidArgumentException When you try use authentication without
     *   Memcached compiled with SASL support
     */
    public function init(array $config = []): bool
    {
        if (!extension_loaded('memcached')) {
            throw new Cake_Exception('The `memcached` extension must be enabled to use MemcachedEngine.');
        }
        $this->_serializers = ['igbinary' => Memcached::SERIALIZER_IGBINARY, 'json' => Memcached::SERIALIZER_JSON, 'php' => Memcached::SERIALIZER_PHP];
        if (defined('Memcached::HAVE_MSGPACK')) {
            $this->_serializers['msgpack'] = Memcached::SERIALIZER_MSGPACK;
        }
        parent::init($config);
        if (!empty($config['host'])) {
            if (empty($config['port'])) {
                $config['servers'] = [$config['host']];
            } else {
                $config['servers'] = [sprintf('%s:%d', $config['host'], $config['port'])];
            }
        }
        if (isset($config['servers'])) {
            $this->set_config('servers', $config['servers'], false);
        }
        if (!is_array($this->_config['servers'])) {
            $this->_config['servers'] = [$this->_config['servers']];
        }
        if (isset($this->_Memcached)) {
            return true;
        }
        if ($this->_config['persistent']) {
            $this->_Memcached = new Memcached($this->_config['persistent']);
        } else {
            $this->_Memcached = new Memcached();
        }
        $this->_set_options();
        $server_list = $this->_Memcached->get_server_list();
        if ($server_list) {
            if ($this->_Memcached->is_persistent()) {
                foreach ($server_list as $server) {
                    if (!in_array($server['host'] . ':' . $server['port'], $this->_config['servers'], true)) {
                        throw new InvalidArgumentException('Invalid cache configuration. Multiple persistent cache configurations are detected' . ' with different `servers` values. `servers` values for persistent cache configurations' . ' must be the same when using the same persistence id.');
                    }
                }
            }
            return true;
        }
        $servers = [];
        foreach ($this->_config['servers'] as $server) {
            $servers[] = $this->parse_server_string($server);
        }
        if (!$this->_Memcached->add_servers($servers)) {
            return false;
        }
        if (is_array($this->_config['options'])) {
            foreach ($this->_config['options'] as $opt => $value) {
                $this->_Memcached->set_option($opt, $value);
            }
        }
        if (empty($this->_config['username']) && !empty($this->_config['login'])) {
            throw new InvalidArgumentException('Please pass "username" instead of "login" for connecting to Memcached');
        }
        if ($this->_config['username'] !== null && $this->_config['password'] !== null) {
            // @phpstan-ignore function.alreadyNarrowedType (check kept for SASL support detection)
            if (!method_exists($this->_Memcached, 'setSaslAuthData')) {
                throw new InvalidArgumentException('Memcached extension is not built with SASL support');
            }
            $this->_Memcached->set_option(Memcached::OPT_BINARY_PROTOCOL, true);
            $this->_Memcached->set_sasl_auth_data($this->_config['username'], $this->_config['password']);
        }
        return true;
    }
    /**
     * Set the memcached instance options
     *
     * @throws \Cake\Cache\Exception\InvalidArgumentException When the Memcached extension is not built
     *   with the desired serializer engine.
     */
    protected function _set_options(): void
    {
        $this->_Memcached->set_option(Memcached::OPT_LIBKETAMA_COMPATIBLE, true);
        $serializer = strtolower((string) $this->_config['serialize']);
        if (!isset($this->_serializers[$serializer])) {
            throw new InvalidArgumentException(sprintf('`%s` is not a valid serializer engine for Memcached.', $serializer));
        }
        if ($serializer !== 'php' && !constant('Memcached::HAVE_' . strtoupper($serializer))) {
            throw new InvalidArgumentException(sprintf('Memcached extension is not compiled with `%s` support.', $serializer));
        }
        $this->_Memcached->set_option(Memcached::OPT_SERIALIZER, $this->_serializers[$serializer]);
        // Check for Amazon ElastiCache instance
        if (defined('Memcached::OPT_CLIENT_MODE') && defined('Memcached::DYNAMIC_CLIENT_MODE')) {
            $this->_Memcached->set_option(Memcached::OPT_CLIENT_MODE, Memcached::DYNAMIC_CLIENT_MODE);
        }
        $this->_Memcached->set_option(Memcached::OPT_COMPRESSION, (bool) $this->_config['compress']);
    }
    /**
     * Parses the server address into the host/port. Handles both IPv6 and IPv4
     * addresses and Unix sockets
     *
     * @param string $server The server address string.
     * @return array Array containing host, port
     */
    public function parse_server_string(string $server): array
    {
        $socket_transport = 'unix://';
        if (str_starts_with($server, $socket_transport)) {
            return [substr($server, strlen($socket_transport)), 0];
        }
        if (str_starts_with($server, '[')) {
            $position = strpos($server, ']:');
            if ($position !== false) {
                $position++;
            }
        } else {
            $position = strpos($server, ':');
        }
        $port = 11211;
        $host = $server;
        if ($position !== false) {
            $host = substr($server, 0, $position);
            $port = substr($server, $position + 1);
        }
        return [$host, (int) $port];
    }
    /**
     * Read an option value from the memcached connection.
     *
     * @param int $name The option name to read.
     * @see https://secure.php.net/manual/en/memcached.getoption.php
     */
    public function get_option(int $name): string|int|bool|null
    {
        return $this->_Memcached->get_option($name);
    }
    /**
     * Write data for key into cache. When using memcached as your cache engine
     * remember that the Memcached pecl extension does not support cache expiry
     * times greater than 30 days in the future. Any duration greater than 30 days
     * will be treated as real Unix time value rather than an offset from current time.
     *
     * @param string $key Identifier for the data
     * @param mixed $value Data to be cached
     * @param \DateInterval|int|null $ttl Optional. The TTL value of this item. If no value is sent and
     *   the driver supports TTL then the library may set a default value
     *   for it or let the driver take care of that.
     * @return bool True if the data was successfully cached, false on failure
     * @see https://www.php.net/manual/en/memcached.set.php
     */
    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
    {
        $key = $this->_key($key);
        $duration = $this->duration($ttl);
        $this->_event_class = Cache_Before_Set_Event::class;
        $this->dispatch_event(Cache_Before_Set_Event::NAME, ['key' => $key, 'value' => $value, 'ttl' => $duration]);
        $success = $this->_Memcached->set($key, $value, $duration);
        $this->_event_class = Cache_After_Set_Event::class;
        $this->dispatch_event(Cache_After_Set_Event::NAME, ['key' => $key, 'value' => $value, 'success' => $success, 'ttl' => $duration]);
        return $success;
    }
    /**
     * Write many cache entries to the cache at once
     *
     * @param iterable $values An array of data to be stored in the cache
     * @param \DateInterval|int|null $ttl Optional. The TTL value of this item. If no value is sent and
     *   the driver supports TTL then the library may set a default value
     *   for it or let the driver take care of that.
     * @return bool Whether the write was successful or not.
     */
    public function set_multiple(iterable $values, DateInterval|int|null $ttl = null): bool
    {
        $cache_data = [];
        foreach ($values as $key => $value) {
            $cache_data[$this->_key($key)] = $value;
        }
        $duration = $this->duration($ttl);
        return $this->_Memcached->set_multi($cache_data, $duration);
    }
    /**
     * Read a key from the cache
     *
     * @param string $key Identifier for the data
     * @param mixed $default Default value to return if the key does not exist.
     * @return mixed The cached data, or default value if the data doesn't exist, has
     * expired, or if there was an error fetching it.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $key = $this->_key($key);
        $this->_event_class = Cache_Before_Get_Event::class;
        $this->dispatch_event(Cache_Before_Get_Event::NAME, ['key' => $key, 'default' => $default]);
        $value = $this->_Memcached->get($key);
        $this->_event_class = Cache_After_Get_Event::class;
        if ($this->_Memcached->get_result_code() === Memcached::RES_NOTFOUND) {
            $this->dispatch_event(Cache_After_Get_Event::NAME, ['key' => $key, 'value' => null, 'success' => false]);
            return $default;
        }
        $this->dispatch_event(Cache_After_Get_Event::NAME, ['key' => $key, 'value' => $value, 'success' => true]);
        return $value;
    }
    /**
     * Read many keys from the cache at once
     *
     * @param iterable<string> $keys An array of identifiers for the data
     * @param mixed $default Default value to return for keys that do not exist.
     * @return iterable<string, mixed> An array containing, for each of the given $keys, the cached data or
     *   `$default` if cached data could not be retrieved.
     */
    public function get_multiple(iterable $keys, mixed $default = null): iterable
    {
        $cache_keys = [];
        foreach ($keys as $key) {
            $cache_keys[$key] = $this->_key($key);
        }
        $values = $this->_Memcached->get_multi($cache_keys);
        if ($values === false) {
            return array_fill_keys(array_keys($cache_keys), $default);
        }
        $return = [];
        foreach ($cache_keys as $original => $prefixed) {
            $return[$original] = array_key_exists($prefixed, $values) ? $values[$prefixed] : $default;
        }
        return $return;
    }
    /**
     * Increments the value of an integer cached key
     *
     * @param string $key Identifier for the data
     * @param int $offset How much to increment
     * @return int|false New incremented value, false otherwise
     */
    public function increment(string $key, int $offset = 1): int|false
    {
        $key = $this->_key($key);
        $this->_event_class = Cache_Before_Increment_Event::class;
        $this->dispatch_event(Cache_Before_Increment_Event::NAME, ['key' => $key, 'offset' => $offset]);
        $value = $this->_Memcached->increment($key, $offset);
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
     */
    public function decrement(string $key, int $offset = 1): int|false
    {
        $key = $this->_key($key);
        $this->_event_class = Cache_Before_Decrement_Event::class;
        $this->dispatch_event(Cache_Before_Decrement_Event::NAME, ['key' => $key, 'offset' => $offset]);
        $value = $this->_Memcached->decrement($key, $offset);
        $this->_event_class = Cache_After_Decrement_Event::class;
        $this->dispatch_event(Cache_After_Decrement_Event::NAME, ['key' => $key, 'offset' => $offset, 'success' => $value !== false, 'value' => $value]);
        return $value;
    }
    /**
     * Delete a key from the cache
     *
     * @param string $key Identifier for the data
     * @return bool True if the value was successfully deleted, false if it didn't
     *   exist or couldn't be removed.
     */
    public function delete(string $key): bool
    {
        $key = $this->_key($key);
        $this->_event_class = Cache_Before_Delete_Event::class;
        $this->dispatch_event(Cache_Before_Delete_Event::NAME, ['key' => $key]);
        $success = $this->_Memcached->delete($key);
        $this->_event_class = Cache_After_Delete_Event::class;
        $this->dispatch_event(Cache_After_Delete_Event::NAME, ['key' => $key, 'success' => $success]);
        return $success;
    }
    /**
     * Delete many keys from the cache at once
     *
     * @param iterable $keys An array of identifiers for the data
     * @return bool of boolean values that are true if the key was successfully
     *   deleted, false if it didn't exist or couldn't be removed.
     */
    public function delete_multiple(iterable $keys): bool
    {
        $cache_keys = [];
        $this->_event_class = Cache_Before_Delete_Event::class;
        foreach ($keys as $key) {
            $cache_keys[] = $this->_key($key);
            $this->dispatch_event(Cache_Before_Delete_Event::NAME, ['key' => $key]);
        }
        $success = (bool) $this->_Memcached->delete_multi($cache_keys);
        $this->_event_class = Cache_After_Delete_Event::class;
        foreach ($cache_keys as $key) {
            $this->dispatch_event(Cache_After_Delete_Event::NAME, ['key' => $key, 'success' => $success]);
        }
        return $success;
    }
    /**
     * Delete all keys from the cache
     *
     * @return bool True if the cache was successfully cleared, false otherwise
     */
    public function clear(): bool
    {
        $keys = $this->_Memcached->get_all_keys();
        if ($keys === false) {
            return false;
        }
        foreach ($keys as $key) {
            if (str_starts_with((string) $key, (string) $this->_config['prefix'])) {
                $this->_Memcached->delete($key);
            }
        }
        $this->_event_class = Cache_Cleared_Event::class;
        $this->dispatch_event(Cache_Cleared_Event::NAME);
        return true;
    }
    /**
     * Add a key to the cache if it does not already exist.
     *
     * @param string $key Identifier for the data.
     * @param mixed $value Data to be cached.
     * @return bool True if the data was successfully cached, false on failure.
     */
    public function add(string $key, mixed $value): bool
    {
        $duration = $this->_config['duration'];
        $key = $this->_key($key);
        $this->_event_class = Cache_Before_Add_Event::class;
        $this->dispatch_event(Cache_Before_Add_Event::NAME, ['key' => $key, 'value' => $value, 'ttl' => $duration]);
        $success = $this->_Memcached->add($key, $value, $duration);
        $this->_event_class = Cache_After_Add_Event::class;
        $this->dispatch_event(Cache_After_Add_Event::NAME, ['key' => $key, 'value' => $value, 'success' => $success, 'ttl' => $duration]);
        return $success;
    }
    /**
     * Returns the `group value` for each of the configured groups
     * If the group initial value was not found, then it initializes
     * the group accordingly.
     *
     * @return array<string>
     */
    public function groups(): array
    {
        if (!$this->_compiled_group_names) {
            foreach ($this->_config['groups'] as $group) {
                $this->_compiled_group_names[] = $this->_config['prefix'] . $group;
            }
        }
        $groups = $this->_Memcached->get_multi($this->_compiled_group_names) ?: [];
        if (count($groups) !== count($this->_config['groups'])) {
            foreach ($this->_compiled_group_names as $group) {
                if (!isset($groups[$group])) {
                    $this->_Memcached->set($group, 1, 0);
                    $groups[$group] = 1;
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
     * @param string $group name of the group to be cleared
     * @return bool success
     */
    public function clear_group(string $group): bool
    {
        $result = (bool) $this->_Memcached->increment($this->_config['prefix'] . $group);
        $this->_event_class = Cache_Group_Clear_Event::class;
        $this->dispatch_event(Cache_Group_Clear_Event::NAME, ['group' => $group]);
        return $result;
    }
}