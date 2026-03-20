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
 * @since         5.3.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Http\Rate_Limit;

use Psr\Simple_Cache\Cache_Interface;
/**
 * Fixed window rate limiter implementation
 */
class Fixed_Window_Rate_Limiter implements Rate_Limiter_Interface
{
    /**
     * Cache instance
     */
    protected Cache_Interface $cache;
    /**
     * Constructor
     *
     * @param \Psr\SimpleCache\CacheInterface $cache Cache instance
     */
    public function __construct(Cache_Interface $cache)
    {
        $this->cache = $cache;
    }
    /**
     * @inheritDoc
     */
    public function attempt(string $identifier, int $limit, int $window, int $cost = 1): array
    {
        $now = time();
        $window_start = (int) ($now / $window) * $window;
        $key = $identifier . '_' . $window_start;
        $count = (int) $this->cache->get($key, 0);
        $allowed = $count + $cost <= $limit;
        if ($allowed) {
            $count += $cost;
            $ttl = $window_start + $window - $now;
            $this->cache->set($key, $count, $ttl);
        }
        return ['allowed' => $allowed, 'limit' => $limit, 'remaining' => max(0, $limit - $count), 'reset' => $window_start + $window];
    }
    /**
     * @inheritDoc
     */
    public function reset(string $identifier): void
    {
        $now = time();
        $window = 3600;
        // Assume max window of 1 hour for reset
        $window_start = (int) ($now / $window) * $window;
        $this->cache->delete($identifier . '_' . $window_start);
    }
}