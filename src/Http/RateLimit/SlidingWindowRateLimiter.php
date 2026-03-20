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
 * Sliding window rate limiter implementation
 */
class Sliding_Window_Rate_Limiter implements Rate_Limiter_Interface
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
        $key = $identifier;
        $data = $this->cache->get($key, ['count' => 0, 'reset' => $now + $window, 'window_start' => $now]);
        $elapsed = $now - $data['window_start'];
        if ($elapsed >= $window) {
            $data = ['count' => 0, 'reset' => $now + $window, 'window_start' => $now];
        } else {
            $weight = 1 - $elapsed / $window;
            $data['count'] = (int) ceil($data['count'] * $weight);
        }
        $allowed = $data['count'] + $cost <= $limit;
        if ($allowed) {
            $data['count'] += $cost;
            $this->cache->set($key, $data, $window);
        }
        return ['allowed' => $allowed, 'limit' => $limit, 'remaining' => max(0, $limit - (int) $data['count']), 'reset' => $data['reset']];
    }
    /**
     * @inheritDoc
     */
    public function reset(string $identifier): void
    {
        $this->cache->delete($identifier);
    }
}