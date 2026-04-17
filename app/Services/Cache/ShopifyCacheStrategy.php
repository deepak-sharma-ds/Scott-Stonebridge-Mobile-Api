<?php

namespace App\Services\Cache;

use App\Contracts\Cache\CacheStrategyInterface;

class ShopifyCacheStrategy implements CacheStrategyInterface
{
    /**
     * Cache key prefix for all Shopify operations
     */
    private const CACHE_PREFIX = 'shopify';

    /**
     * Operations that should be cached
     */
    private const CACHEABLE_OPERATIONS = [
        'product.list',
        'product.get',
        'product.search',
        'product.featured',
        'collection.list',
        'collection.get',
        'currency.list',
        'cart.get',
    ];

    /**
     * Resource type mapping for cache tags
     */
    private const RESOURCE_TYPE_MAP = [
        'product.list' => 'product',
        'product.get' => 'product',
        'product.search' => 'product',
        'product.featured' => 'product',
        'collection.list' => 'collection',
        'collection.get' => 'collection',
        'currency.list' => 'currency',
        'cart.get' => 'cart',
    ];

    /**
     * Generate cache key for an operation
     *
     * @param string $operation Operation name (e.g., 'product.get', 'cart.fetch')
     * @param array $params Operation parameters
     * @return string Cache key
     */
    public function getCacheKey(string $operation, array $params): string
    {
        // Start with prefix and operation
        $keyParts = [self::CACHE_PREFIX, $operation];

        // Add currency to key if present
        if (isset($params['currency'])) {
            $keyParts[] = strtolower($params['currency']);
        }

        // Sort params for consistent key generation
        ksort($params);

        // Add relevant params to key (exclude currency as it's already added)
        $relevantParams = array_filter($params, function ($key) {
            return $key !== 'currency';
        }, ARRAY_FILTER_USE_KEY);

        // Create a hash of the parameters for compact key
        if (!empty($relevantParams)) {
            $keyParts[] = md5(json_encode($relevantParams));
        }

        return implode(':', $keyParts);
    }

    /**
     * Get cache tags for an operation
     *
     * @param string $operation Operation name
     * @param array $params Operation parameters
     * @return array Cache tags
     */
    public function getCacheTags(string $operation, array $params): array
    {
        $tags = [];

        // Add resource type tag
        $resourceType = $this->getResourceType($operation);
        if ($resourceType) {
            $tags[] = self::CACHE_PREFIX . ':' . $resourceType;
        }

        // Add currency tag if present
        if (isset($params['currency'])) {
            $tags[] = self::CACHE_PREFIX . ':currency:' . strtolower($params['currency']);
        }

        // Add operation-specific tags
        $tags[] = self::CACHE_PREFIX . ':operation:' . $operation;

        return $tags;
    }

    /**
     * Get cache TTL for an operation
     *
     * @param string $operation Operation name
     * @return int TTL in seconds
     */
    public function getCacheTTL(string $operation): int
    {
        $resourceType = $this->getResourceType($operation);

        if (!$resourceType) {
            return 0;
        }

        // Get TTL from config based on resource type
        $ttl = config("shopify.cache.ttl.{$resourceType}", 0);

        return (int) $ttl;
    }

    /**
     * Determine if an operation should be cached
     *
     * @param string $operation Operation name
     * @return bool
     */
    public function shouldCache(string $operation): bool
    {
        // Check if caching is globally enabled
        if (!config('shopify.cache.enabled', true)) {
            return false;
        }

        // Check if operation is in cacheable list
        return in_array($operation, self::CACHEABLE_OPERATIONS, true);
    }

    /**
     * Get resource type from operation name
     *
     * @param string $operation Operation name
     * @return string|null Resource type or null if not mapped
     */
    private function getResourceType(string $operation): ?string
    {
        return self::RESOURCE_TYPE_MAP[$operation] ?? null;
    }
}

