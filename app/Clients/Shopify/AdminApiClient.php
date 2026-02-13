<?php

namespace App\Clients\Shopify;

use App\Clients\Base\BaseShopifyClient;
use App\Contracts\Shopify\AdminApiClientInterface;

class AdminApiClient extends BaseShopifyClient implements AdminApiClientInterface
{

    /**
     * Get the API endpoint URL
     */
    protected function getEndpoint(): string
    {
        $storeDomain = config('shopify.store_domain');
        $apiVersion = config('shopify.api_version', '2024-07');

        return "https://{$storeDomain}/admin/api/{$apiVersion}/graphql.json";
    }

    /**
     * Get the authentication headers
     */
    protected function getAuthHeaders(): array
    {
        return [
            'X-Shopify-Access-Token' => config('shopify.access_token'),
        ];
    }

    /**
     * Get the API type for logging
     */
    protected function getApiType(): string
    {
        return 'admin';
    }

    /**
     * Execute a GraphQL query with optional caching
     *
     * This method extends the base query method to add Admin API specific
     * functionality like automatic cache tagging and circuit breaker support.
     *
     * @param string $queryPath Path to the GraphQL query file (e.g., "admin/customers/get_customer")
     * @param array $variables Query variables
     * @return array Response data
     */
    public function query(string $queryPath, array $variables = []): array
    {
        // Automatically enable circuit breaker if configured and not already set
        if ($this->circuitBreakerName === null && config('shopify.circuit_breaker.enabled', true)) {
            $this->circuitBreakerName = 'shopify_admin_api';
        }

        // Execute with circuit breaker if enabled
        if ($this->circuitBreakerName !== null) {
            return $this->executeWithCircuitBreaker(
                fn() => parent::query($queryPath, $variables),
                $this->circuitBreakerName
            );
        }

        return parent::query($queryPath, $variables);
    }

    /**
     * Query with automatic caching
     *
     * Convenience method that automatically enables caching with default TTL
     * based on the operation type.
     *
     * @param string $queryPath Path to the GraphQL query file
     * @param array $variables Query variables
     * @param string $resourceType Resource type for cache tagging (e.g., 'customer', 'order')
     * @return array Response data
     */
    public function queryWithCache(string $queryPath, array $variables = [], string $resourceType = 'admin'): array
    {
        // Determine TTL based on resource type
        $ttl = $this->getCacheTtlForResource($resourceType);

        // Set cache tags
        $tags = ['shopify', 'admin', $resourceType];

        return $this->withCache($ttl, $tags)->query($queryPath, $variables);
    }

    /**
     * Get cache TTL for a resource type
     *
     * @param string $resourceType
     * @return int TTL in seconds
     */
    protected function getCacheTtlForResource(string $resourceType): int
    {
        $ttlMap = [
            'customer' => config('shopify.cache.ttl.customer', 900),
            'order' => config('shopify.cache.ttl.order', 900),
            'product' => config('shopify.cache.ttl.product', 900),
        ];

        return $ttlMap[$resourceType] ?? 900; // Default 15 minutes
    }
}
