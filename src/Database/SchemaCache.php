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
 * @since         3.6.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Database;

use Cake\Database\Schema\Cached_Collection;
/**
 * Schema Cache.
 *
 * This tool is intended to be used by deployment scripts so that you
 * can prevent thundering herd effects on the metadata cache when new
 * versions of your application are deployed, or when migrations
 * requiring updated metadata are required.
 *
 * @link https://en.wikipedia.org/wiki/Thundering_herd_problem About the thundering herd problem
 */
class Schema_Cache
{
    /**
     * Schema
     */
    protected Cached_Collection $_schema;
    /**
     * Constructor
     *
     * @param \Cake\Database\Connection $connection Connection name to get the schema for or a connection instance
     */
    public function __construct(Connection $connection)
    {
        $this->_schema = $this->get_schema($connection);
    }
    /**
     * Build metadata.
     *
     * @param string|null $name The name of the table to build cache data for.
     * @return array<string> Returns a list build table caches
     */
    public function build(?string $name = null): array
    {
        if ($name) {
            $tables = [$name];
        } else {
            $tables = $this->_schema->list_tables();
        }
        foreach ($tables as $table) {
            $this->_schema->describe($table, ['forceRefresh' => true]);
        }
        return $tables;
    }
    /**
     * Clear metadata.
     *
     * @param string|null $name The name of the table to clear cache data for.
     * @return array<string> Returns a list of cleared table caches
     */
    public function clear(?string $name = null): array
    {
        if ($name) {
            $tables = [$name];
        } else {
            $tables = $this->_schema->list_tables();
        }
        $cacher = $this->_schema->get_cacher();
        foreach ($tables as $table) {
            $key = $this->_schema->cache_key($table);
            $cacher->delete($key);
        }
        return $tables;
    }
    /**
     * Helper method to get the schema collection.
     *
     * @param \Cake\Database\Connection $connection Connection object
     * @throws \RuntimeException If given connection object is not compatible with schema caching
     */
    public function get_schema(Connection $connection): Cached_Collection
    {
        $config = $connection->config();
        if (empty($config['cacheMetadata'])) {
            $connection->cache_metadata(true);
        }
        /** @var \Cake\Database\Schema\CachedCollection */
        return $connection->get_schema_collection();
    }
}