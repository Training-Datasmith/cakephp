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
 * Token bucket rate limiter implementation
 */
class Token_Bucket_Rate_Limiter implements Rate_Limiter_Interface
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
        $now = microtime(true);
        $key = $identifier;
        $data = $this->cache->get($identifier, ['tokens' => $limit, 'last_update' => $now]);
        // Refill tokens based on time elapsed
        $elapsed = $now - $data['last_update'];
        $refill_rate = $limit / $window;
        $tokens_to_add = $elapsed * $refill_rate;
        $data['tokens'] = min($limit, $data['tokens'] + $tokens_to_add);
        $data['last_update'] = $now;
        $allowed = $data['tokens'] >= $cost;
        if ($allowed) {
            $data['tokens'] -= $cost;
        }
        $this->cache->set($key, $data, $window);
        // Calculate when bucket will be full
        $tokens_needed = $limit - $data['tokens'];
        $seconds_to_full = $tokens_needed / $refill_rate;
        $reset = (int) ($now + $seconds_to_full);
        return ['allowed' => $allowed, 'limit' => $limit, 'remaining' => (int) $data['tokens'], 'reset' => $reset];
    }
    /**
     * @inheritDoc
     */
    public function reset(string $identifier): void
    {
        $this->cache->delete($identifier);
    }
}