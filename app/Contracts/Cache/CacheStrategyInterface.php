<?php

namespace App\Contracts\Cache;

interface CacheStrategyInterface
{
    /**
     * Generate cache key for an operation
     *
     * @param string $operation Operation name (e.g., 'product.get', 'cart.fetch')
     * @param array $params Operation parameters
     * @return string Cache key
     */
    public function getCacheKey(string $operation, array $params): string;

    /**
     * Get cache tags for an operation
     *
     * @param string $operation Operation name
     * @param array $params Operation parameters
     * @return array Cache tags
     */
    public function getCacheTags(string $operation, array $params): array;

    /**
     * Get cache TTL for an operation
     *
     * @param string $operation Operation name
     * @return int TTL in seconds
     */
    public function getCacheTTL(string $operation): int;

    /**
     * Determine if an operation should be cached
     *
     * @param string $operation Operation name
     * @return bool
     */
    public function shouldCache(string $operation): bool;
}
