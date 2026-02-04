<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class CacheService
{
    private const DEFAULT_TTL = 3600; // 1 hour

    /**
     * Remember value in cache
     */
    public function remember(string $key, \Closure $callback, ?int $ttl = null): mixed
    {
        return Cache::remember($key, $ttl ?? self::DEFAULT_TTL, $callback);
    }

    /**
     * Generate cache key for product
     */
    public function productKey(string $handle, string $countryCode): string
    {
        return "product.{$handle}.{$countryCode}";
    }

    /**
     * Generate cache key for products list
     */
    public function productsListKey(array $params): string
    {
        ksort($params);
        return 'products.list.' . md5(json_encode($params));
    }

    /**
     * Forget cache key
     */
    public function forget(string $key): bool
    {
        return Cache::forget($key);
    }

    /**
     * Generate cache key for customer orders list
     */
    public function ordersKey(string $accessToken, array $params): string
    {
        ksort($params);
        // Hash token to avoid storing sensitive data in key, but effectively segment by user
        return 'orders.list.' . md5($accessToken . json_encode($params));
    }

    /**
     * Generate cache key for single order
     */
    public function orderDetailsKey(string $orderId): string
    {
        return "order.{$orderId}";
    }
}
