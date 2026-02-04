<?php

namespace App\Services\Shopify;

use App\DTO\ProductDTO;
use App\Services\CacheService;
use Illuminate\Support\Facades\Http;

class StorefrontService
{
    public function __construct(
        private readonly ShopifyAdapterInterface $adapter,
        private readonly GraphQLLoaderService $queryLoader,
        private readonly CacheService $cacheService
    ) {}
    
    /**
     * Get product by handle
     */
    public function getProductByHandle(
        string $handle,
        ?string $countryCode = null
    ): ?ProductDTO {
        $countryCode = $countryCode ?? 'US';
        $cacheKey = $this->cacheService->productKey($handle, $countryCode);

        return $this->cacheService->remember($cacheKey, function () use ($handle, $countryCode) {
            $query = $this->queryLoader->load('storefront/products/get_product_details');
            
            $variables = [
                'handle' => $handle,
            ];
            
            // Add country context if provided
            if ($countryCode) {
                $variables['country'] = $countryCode;
            }
            
            $response = $this->adapter->storefrontQuery($query, $variables);
            
            if (!isset($response['product'])) {
                return null;
            }
            
            return ProductDTO::fromShopifyNode($response['product']);
        });
    }

    /**
     * Get customer orders
     */
    /**
     * Get customer orders
     */
    public function getOrders(string $accessToken, int $limit = 20, ?string $cursor = null): \Illuminate\Support\Collection
    {
        $variables = [
            'accessToken' => $accessToken,
            'limit' => $limit,
            'after' => $cursor,
        ];

        // Cache for 5 minutes (300 seconds) to avoid stale order status but improve perf
        $cacheKey = $this->cacheService->ordersKey($accessToken, $variables);
        
        return $this->cacheService->remember($cacheKey, function () use ($variables) {
            $query = $this->queryLoader->load('storefront/orders/get_customer_orders');
            $response = $this->adapter->storefrontQuery($query, $variables);
            
            $edges = data_get($response, 'customer.orders.edges', []);
            
            return collect($edges)->map(function ($edge) {
                $node = $edge['node'];
                return \App\DTOs\Shopify\OrderDTO::fromShopifyNode($node);
            });
        }, 300);
    }

    /**
     * Get full order details
     */
    public function getOrder(string $orderId): ?\App\DTOs\Shopify\OrderDTO
    {
        // Cache for 10 minutes
        $cacheKey = $this->cacheService->orderDetailsKey($orderId);

        return $this->cacheService->remember($cacheKey, function () use ($orderId) {
            $query = $this->queryLoader->load('storefront/orders/get_order_details');
            $variables = ['id' => $orderId];
            
            $response = $this->adapter->storefrontQuery($query, $variables);
            $orderNode = data_get($response, 'node');
            
            if (!$orderNode) {
                return null;
            }
            
            return \App\DTOs\Shopify\OrderDTO::fromShopifyNode($orderNode);
        }, 600);
    }
}
