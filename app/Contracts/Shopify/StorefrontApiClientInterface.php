<?php

namespace App\Contracts\Shopify;

interface StorefrontApiClientInterface extends ShopifyClientInterface
{
    /**
     * Query with currency context
     *
     * @param string $queryPath Path to the GraphQL query file
     * @param array $variables Query variables
     * @param string|null $currencyCode Currency code (ISO 4217)
     * @return array Response data
     */
    public function queryWithCurrency(string $queryPath, array $variables = [], ?string $currencyCode = null): array;

    /**
     * Query with automatic caching based on resource type
     *
     * @param string $queryPath Path to the GraphQL query file
     * @param array $variables Query variables
     * @param string $resourceType Resource type for cache tagging
     * @return array Response data
     */
    public function queryWithCache(string $queryPath, array $variables = [], string $resourceType = 'storefront'): array;

    /**
     * Query with both currency context and caching
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
    ): array;
}
