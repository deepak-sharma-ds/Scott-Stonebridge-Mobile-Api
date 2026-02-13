<?php

namespace App\Clients\Shopify;

use App\Clients\Base\BaseShopifyClient;
use App\Contracts\Shopify\StorefrontApiClientInterface;

class StorefrontApiClient extends BaseShopifyClient implements StorefrontApiClientInterface
{

    /**
     * Get the API endpoint URL
     */
    protected function getEndpoint(): string
    {
        $storeDomain = config('shopify.store_domain');
        $apiVersion = config('shopify.api_version', '2024-07');

        return "https://{$storeDomain}/api/{$apiVersion}/graphql.json";
    }

    /**
     * Get the authentication headers
     */
    protected function getAuthHeaders(): array
    {
        return [
            'X-Shopify-Storefront-Access-Token' => config('shopify.storefront_access_token'),
        ];
    }

    /**
     * Get the API type for logging
     */
    protected function getApiType(): string
    {
        return 'storefront';
    }

    /**
     * Execute a GraphQL query with optional caching
     *
     * This method extends the base query method to add Storefront API specific
     * functionality like automatic cache tagging, circuit breaker support,
     * and currency context handling.
     *
     * @param string $queryPath Path to the GraphQL query file (e.g., "storefront/products/get_products")
     * @param array $variables Query variables
     * @return array Response data
     */
    public function query(string $queryPath, array $variables = []): array
    {
        // Automatically enable circuit breaker if configured and not already set
        if ($this->circuitBreakerName === null && config('shopify.circuit_breaker.enabled', true)) {
            $this->circuitBreakerName = 'shopify_storefront_api';
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
     * Query with currency context
     *
     * Automatically adds currency context to the query variables if available
     * from the request context.
     *
     * @param string $queryPath Path to the GraphQL query file
     * @param array $variables Query variables
     * @param string|null $currencyCode Currency code (ISO 4217)
     * @return array Response data
     */
    public function queryWithCurrency(string $queryPath, array $variables = [], ?string $currencyCode = null): array
    {
        // Get currency from parameter, request context, or config default
        $currency = $currencyCode 
            ?? request()->header('X-Currency') 
            ?? request()->get('currency')
            ?? config('shopify.currency', 'GBP');

        // Add currency to variables if not already present
        if (!isset($variables['currency'])) {
            $variables['currency'] = $currency;
        }

        // Add currency to cache tags for currency-specific caching
        $tags = array_merge($this->cacheTags, ['currency:' . strtoupper($currency)]);
        $this->cacheTags = $tags;

        return $this->query($queryPath, $variables);
    }

    /**
     * Query with automatic caching
     *
     * Convenience method that automatically enables caching with default TTL
     * based on the operation type.
     *
     * @param string $queryPath Path to the GraphQL query file
     * @param array $variables Query variables
     * @param string $resourceType Resource type for cache tagging (e.g., 'product', 'cart', 'collection')
     * @return array Response data
     */
    public function queryWithCache(string $queryPath, array $variables = [], string $resourceType = 'storefront'): array
    {
        // Determine TTL based on resource type
        $ttl = $this->getCacheTtlForResource($resourceType);

        // Set cache tags
        $tags = ['shopify', 'storefront', $resourceType];

        // Add currency tag if currency is in variables
        if (isset($variables['currency'])) {
            $tags[] = 'currency:' . strtoupper($variables['currency']);
        }

        return $this->withCache($ttl, $tags)->query($queryPath, $variables);
    }

    /**
     * Query with both currency context and caching
     *
     * Combines currency context handling with automatic caching.
     *
     * @param string $queryPath Path to the GraphQL query file
     * @param array $variables Query variables
     * @param string $resourceType Resource type for cache tagging
     * @param string|null $currencyCode Currency code (ISO 4217)
     * @return array Response data
     */
    public function queryWithCurrencyAndCache(
        string $queryPath,
        array $variables = [],
        string $resourceType = 'storefront',
        ?string $currencyCode = null
    ): array {
        // Get currency
        $currency = $currencyCode 
            ?? request()->header('X-Currency') 
            ?? request()->get('currency')
            ?? config('shopify.currency', 'GBP');

        // Add currency to variables
        if (!isset($variables['currency'])) {
            $variables['currency'] = $currency;
        }

        // Determine TTL based on resource type
        $ttl = $this->getCacheTtlForResource($resourceType);

        // Set cache tags including currency
        $tags = ['shopify', 'storefront', $resourceType, 'currency:' . strtoupper($currency)];

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
            'product' => config('shopify.cache.ttl.product', 900),
            'collection' => config('shopify.cache.ttl.collection', 1800),
            'cart' => config('shopify.cache.ttl.cart', 3600),
            'currency' => config('shopify.cache.ttl.currency', 86400),
        ];

        return $ttlMap[$resourceType] ?? 900; // Default 15 minutes
    }

    /**
     * Override cache key generation to include currency
     */
    protected function getCacheKey(string $queryPath, array $variables): string
    {
        // Extract currency from variables if present
        $currency = $variables['currency'] ?? 'default';
        
        // Remove currency from variables for hash calculation to avoid duplication
        $variablesForHash = $variables;
        unset($variablesForHash['currency']);
        
        $variablesHash = md5(json_encode($variablesForHash));
        
        return "shopify:{$this->getApiType()}:{$currency}:{$queryPath}:{$variablesHash}";
    }
}
