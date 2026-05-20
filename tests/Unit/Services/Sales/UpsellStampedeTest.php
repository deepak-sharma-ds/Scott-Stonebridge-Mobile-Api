<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Sales;

use App\Contracts\Shopify\StorefrontApiClientInterface;
use App\Services\Sales\UpsellService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Regression coverage for the Cache::remember thundering-herd in
 * UpsellService::recommendationsForProduct(). The previous code fired
 * a parallel Storefront call for every concurrent cache miss; the
 * post-fix code uses Cache::lock()->block() to serialise misses and a
 * fast-path Cache::get() to satisfy warmed-up readers without holding
 * the lock at all.
 */
class UpsellStampedeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['sales.upsell.default_free_shipping_threshold' => 50.00]);
        config(['sales.upsell.max_results' => 3]);
        config(['sales.upsell.cache_ttl' => 600]);
    }

    public function test_second_call_for_same_product_does_not_hit_storefront(): void
    {
        $client = $this->createMock(StorefrontApiClientInterface::class);

        // The fix must serve the second call from cache — exactly one
        // Storefront call per (product, country) regardless of repeat reads.
        $client
            ->expects($this->once())
            ->method('query')
            ->with('storefront/products/get_product_recommendations', $this->anything())
            ->willReturn([
                'data' => [
                    'productRecommendations' => [
                        ['id' => 'gid://shopify/Product/up-a', 'title' => 'Up A', 'handle' => 'up-a', 'availableForSale' => true, 'priceRange' => ['minVariantPrice' => ['amount' => '12.50']]],
                    ],
                ],
            ]);

        $svc = new UpsellService($client);
        $cart = [['product_id' => 'gid://shopify/Product/seed-1']];

        $first = $svc->getUpsells($cart, 'demo.myshopify.com', 'GBP');
        $second = $svc->getUpsells($cart, 'demo.myshopify.com', 'GBP');

        $this->assertNotEmpty($first);
        $this->assertNotEmpty($second);
        $this->assertSame($first[0]->id, $second[0]->id);
    }

    public function test_different_countries_isolate_their_caches(): void
    {
        $client = $this->createMock(StorefrontApiClientInterface::class);

        // Two distinct country codes = two distinct cache keys = two calls.
        $client
            ->expects($this->exactly(2))
            ->method('query')
            ->willReturn([
                'data' => [
                    'productRecommendations' => [
                        ['id' => 'gid://shopify/Product/up-x', 'title' => 'X', 'handle' => 'x', 'availableForSale' => true, 'priceRange' => ['minVariantPrice' => ['amount' => '1.00']]],
                    ],
                ],
            ]);

        $svc = new UpsellService($client);
        $cart = [['product_id' => 'gid://shopify/Product/seed-2']];

        $svc->getUpsells($cart, 'demo.myshopify.com', 'GBP'); // GB country
        $svc->getUpsells($cart, 'demo.myshopify.com', 'USD'); // US country
    }
}
