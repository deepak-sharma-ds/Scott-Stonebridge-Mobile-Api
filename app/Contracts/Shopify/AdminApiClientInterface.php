<?php

namespace App\Contracts\Shopify;

interface AdminApiClientInterface extends ShopifyClientInterface
{
    /**
     * Query with automatic caching based on resource type
     *
     * @param string $queryPath Path to the GraphQL query file
     * @param array $variables Query variables
     * @param string $resourceType Resource type for cache tagging
     * @return array Response data
     */
    public function queryWithCache(string $queryPath, array $variables = [], string $resourceType = 'admin'): array;
}
