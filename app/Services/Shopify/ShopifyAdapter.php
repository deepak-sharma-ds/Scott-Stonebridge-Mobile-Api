<?php

declare(strict_types=1);

namespace App\Services\Shopify;

use App\Contracts\Shopify\ShopifyAdapterInterface;
use App\Exceptions\ShopifyGraphQLException;
use App\Exceptions\ShopifyRateLimitException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PHPShopify\ShopifySDK;

class ShopifyAdapter implements ShopifyAdapterInterface
{
    private const BACKOFF_MS = 500;
    private const RATE_LIMIT_CACHE_KEY = 'shopify_rate_limit';
    
    private ShopifySDK $sdk;
    
    public function __construct()
    {
        $this->sdk = new ShopifySDK([
            'ShopUrl' => config('shopify.shop_url'),
            'AccessToken' => config('shopify.access_token'),
        ]);
    }
    
    /**
     * Execute Admin GraphQL query
     */
    public function adminQuery(string $query, array $variables = []): array
    {
        $this->checkRateLimit();
        
        try {
            $response = $this->sdk->GraphQL->post($query, $variables);
            
            return $this->handleGraphQLResponse($response, $query);
        } catch (\Throwable $e) {
            Log::channel('shopify')->error('Admin GraphQL query failed', [
                'query' => $query,
                'variables' => $variables,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Execute Storefront GraphQL query
     */
    public function storefrontQuery(string $query, array $variables = []): array
    {
        $this->checkRateLimit();
        
        try {
            // Use Storefront API endpoint
            $response = $this->sdk->GraphQL->post($query, $variables, null, [
                'X-Shopify-Storefront-Access-Token' => config('shopify.storefront_token'),
            ]);
            
            return $this->handleGraphQLResponse($response, $query);
        } catch (\Throwable $e) {
            Log::channel('shopify')->error('Storefront GraphQL query failed', [
                'query' => $query,
                'variables' => $variables,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Retry a callback with exponential backoff
     */
    public function withRetry(callable $callback, int $maxAttempts = 3): mixed
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $maxAttempts) {
            try {
                return $callback();
            } catch (ShopifyRateLimitException $e) {
                // Don't retry rate limit errors, throw immediately
                throw $e;
            } catch (ShopifyGraphQLException $e) {
                // Don't retry GraphQL errors (they won't succeed on retry)
                throw $e;
            } catch (\Throwable $e) {
                $attempt++;
                $lastException = $e;

                if ($attempt >= $maxAttempts) {
                    throw $e;
                }

                // Exponential backoff
                $delay = self::BACKOFF_MS * (2 ** ($attempt - 1));
                usleep($delay * 1000);

                Log::warning("Shopify API retry attempt {$attempt}/{$maxAttempts}", [
                    'error' => $e->getMessage(),
                    'delay_ms' => $delay,
                ]);
            }
        }

        throw $lastException;
    }
    
    /**
     * Handle GraphQL response and check for errors
     */
    private function handleGraphQLResponse(array $response, string $query): array
    {
        // Check for top-level errors
        if (isset($response['errors']) && !empty($response['errors'])) {
            throw new ShopifyGraphQLException(
                'GraphQL query returned errors',
                errors: $response['errors'],
                query: $query
            );
        }
        
        // Check for userErrors in mutations
        if (isset($response['data'])) {
            foreach ($response['data'] as $mutationData) {
                if (isset($mutationData['userErrors']) && !empty($mutationData['userErrors'])) {
                    throw new ShopifyGraphQLException(
                        'GraphQL mutation returned user errors',
                        errors: [],
                        userErrors: $mutationData['userErrors'],
                        query: $query
                    );
                }
            }
        }
        
        return $response['data'] ?? [];
    }
    
    /**
     * Check if rate limit is exceeded
     */
    private function checkRateLimit(): void
    {
        $rateLimitData = Cache::get(self::RATE_LIMIT_CACHE_KEY);
        
        if ($rateLimitData && $rateLimitData['exceeded']) {
            $retryAfter = $rateLimitData['retry_after'] ?? 60;
            
            throw new ShopifyRateLimitException(
                retryAfter: $retryAfter
            );
        }
    }

    /**
     * Get Theme Asset (REST)
     */
    public function getThemeAsset(string $themeId, string $key): ?array
    {
        $this->checkRateLimit();

        try {
            // PHPShopify SDK Syntax: $shopify->Theme($id)->Asset->get(['asset[key]' => $key]);
            $response = $this->sdk->Theme($themeId)->Asset->get(['asset[key]' => $key]);
            
            // The SDK usually returns the array directly
            return $response;
        } catch (\Throwable $e) {
            Log::channel('shopify')->error('Failed to fetch theme asset', [
                'theme_id' => $themeId,
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}
