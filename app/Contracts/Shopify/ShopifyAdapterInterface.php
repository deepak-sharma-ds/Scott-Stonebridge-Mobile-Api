<?php

declare(strict_types=1);

namespace App\Contracts\Shopify;

interface ShopifyAdapterInterface
{
    /**
     * Execute Admin GraphQL query
     */
    public function adminQuery(string $query, array $variables = []): array;
    
    /**
     * Execute Storefront GraphQL query
     */
    public function storefrontQuery(string $query, array $variables = []): array;
    
    /**
     * Retry a callback with exponential backoff
     */
    public function withRetry(callable $callback, int $maxAttempts = 3): mixed;

    /**
     * Get Theme Asset (REST)
     */
    public function getThemeAsset(string $themeId, string $key): ?array;
}
