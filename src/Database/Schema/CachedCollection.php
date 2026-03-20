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
namespace Cake\Database\Schema;

use Psr\Simple_Cache\Cache_Interface;
/**
 * Decorates a schema collection and adds caching
 */
class Cached_Collection implements Collection_Interface
{
    /**
     * Cacher instance.
     */
    protected Cache_Interface $cacher;
    /**
     * Constructor.
     *
     * @param \Cake\Database\Schema\CollectionInterface $collection The collection to wrap.
     * @param string $prefix The cache key prefix to use. Typically the connection name.
     * @param \Psr\SimpleCache\CacheInterface $cacher Cacher instance.
     */
    public function __construct(
        /**
         * The decorated schema collection
         */
        protected Collection_Interface $collection,
        /**
         * The cache key prefix
         */
        protected string $prefix,
        Cache_Interface $cacher
    )
    {
        $this->cacher = $cacher;
    }
    /**
     * @inheritDoc
     */
    public function list_tables_without_views(): array
    {
        return $this->collection->list_tables_without_views();
    }
    /**
     * @inheritDoc
     */
    public function list_tables(): array
    {
        return $this->collection->list_tables();
    }
    /**
     * Get the column metadata for a table.
     *
     * The name can include a database schema name in the form 'schema.table'.
     *
     * Caching will be applied if `cacheMetadata` key is present in the Connection
     * configuration options. Defaults to _cake_model_ when true.
     *
     * ### Options
     *
     * - `forceRefresh` - Set to true to force rebuilding the cached metadata.
     *   Defaults to false.
     *
     * @param string $name The name of the table to describe.
     * @param array<string, mixed> $options The options to use, see above.
     * @return \Cake\Database\Schema\TableSchemaInterface Object with column metadata.
     * @throws \Cake\Database\Exception\DatabaseException when table cannot be described.
     */
    public function describe(string $name, array $options = []): Table_Schema_Interface
    {
        $options += ['forceRefresh' => false];
        $cache_key = $this->cache_key($name);
        if (!$options['forceRefresh']) {
            $cached = $this->cacher->get($cache_key);
            if ($cached !== null) {
                return $cached;
            }
        }
        $table = $this->collection->describe($name, $options);
        $this->cacher->set($cache_key, $table);
        return $table;
    }
    /**
     * Get the cache key for a given name.
     *
     * @param string $name The name to get a cache key for.
     * @return string The cache key.
     */
    public function cache_key(string $name): string
    {
        return $this->prefix . '_' . $name;
    }
    /**
     * Set a cacher.
     *
     * @param \Psr\SimpleCache\CacheInterface $cacher Cacher object
     * @return $this
     */
    public function set_cacher(Cache_Interface $cacher): static
    {
        $this->cacher = $cacher;
        return $this;
    }
    /**
     * Get a cacher.
     *
     * @return \Psr\SimpleCache\CacheInterface $cacher Cacher object
     */
    public function get_cacher(): Cache_Interface
    {
        return $this->cacher;
    }
}