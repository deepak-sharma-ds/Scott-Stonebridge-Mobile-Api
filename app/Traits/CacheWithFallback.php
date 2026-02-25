<?php

namespace App\Traits;

use Illuminate\Support\Facades\Cache;

/**
 * Cache With Fallback Trait
 * 
 * Provides cache functionality with automatic fallback for stores
 * that don't support tagging (like database or file cache).
 */
trait CacheWithFallback
{
    /**
     * Cache with fallback for stores that don't support tagging
     * 
     * @param string $key Cache key
     * @param int $ttl Time to live in seconds
     * @param callable $callback Callback to execute if cache miss
     * @param array $tags Cache tags (optional, ignored if not supported)
     * @return mixed
     */
    protected function cacheWithFallback(string $key, int $ttl, callable $callback, array $tags = [])
    {
        try {
            // Try to use cache with tags (Redis, Memcached)
            if (!empty($tags)) {
                return Cache::tags($tags)->remember($key, $ttl, $callback);
            }
            
            // No tags provided, use simple cache
            return Cache::remember($key, $ttl, $callback);
        } catch (\BadMethodCallException $e) {
            // Fallback to simple cache without tags (Database, File)
            return Cache::remember($key, $ttl, $callback);
        }
    }

    /**
     * Forget cache with fallback for stores that don't support tagging
     * 
     * @param string|array $keyOrTags Cache key or tags
     * @return bool
     */
    protected function forgetCacheWithFallback($keyOrTags): bool
    {
        try {
            if (is_array($keyOrTags)) {
                // Try to flush by tags
                return Cache::tags($keyOrTags)->flush();
            }
            
            // Forget by key
            return Cache::forget($keyOrTags);
        } catch (\BadMethodCallException $e) {
            // Fallback to forget by key if tags not supported
            if (is_string($keyOrTags)) {
                return Cache::forget($keyOrTags);
            }
            
            // Can't flush by tags, return false
            return false;
        }
    }
}
