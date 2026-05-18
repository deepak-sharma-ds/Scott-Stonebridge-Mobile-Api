<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Sales;

use App\Services\Sales\UpsellService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Mocks\MockShopifyClient;
use Tests\TestCase;

/**
 * UpsellService unit coverage. Storefront client is fully mocked.
 */
class UpsellServiceTest extends TestCase
{
    use RefreshDatabase;

    private MockShopifyClient $shopify;

    private UpsellService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->shopify = new MockShopifyClient;
        $this->service = new UpsellService($this->shopify);

        config(['sales.upsell.default_free_shipping_threshold' => 50.00]);
        config(['sales.upsell.max_results' => 3]);
    }

    public function test_get_upsells_returns_empty_for_empty_cart(): void
    {
        $this->assertSame([], $this->service->getUpsells([], 'demo.myshopify.com'));
    }

    public function test_get_upsells_returns_empty_for_empty_shop(): void
    {
        $this->assertSame([], $this->service->getUpsells([['product_id' => 'x']], ''));
    }

    public function test_get_upsells_swallows_storefront_failure_and_returns_empty(): void
    {
        // No mock configured -> MockShopifyClient throws.
        $result = $this->service->getUpsells(
            [['product_id' => 'gid://shopify/Product/1']],
            'demo.myshopify.com',
        );

        $this->assertSame([], $result);
    }

    public function test_get_free_shipping_gap_returns_null_when_threshold_met(): void
    {
        $this->assertNull($this->service->getFreeShippingGap(80.00, 'demo.myshopify.com'));
    }

    public function test_get_free_shipping_gap_returns_difference(): void
    {
        $this->assertSame(20.00, $this->service->getFreeShippingGap(30.00, 'demo.myshopify.com'));
    }

    public function test_get_free_shipping_gap_returns_null_when_threshold_unset(): void
    {
        config(['sales.upsell.default_free_shipping_threshold' => 0]);
        $this->assertNull($this->service->getFreeShippingGap(10.00, 'demo.myshopify.com'));
    }

    public function test_get_cross_sells_excludes_anchor_product(): void
    {
        $this->shopify->mockResponse('storefront/products/get_product_recommendations', [
            'data' => [
                'productRecommendations' => [
                    [
                        'id' => 'gid://shopify/Product/anchor',
                        'title' => 'Self',
                        'handle' => 'self',
                        'availableForSale' => true,
                        'priceRange' => ['minVariantPrice' => ['amount' => '1.00', 'currencyCode' => 'GBP']],
                        'variants' => ['edges' => [['node' => [
                            'id' => 'v', 'availableForSale' => true,
                            'price' => ['amount' => '1.00', 'currencyCode' => 'GBP'],
                        ]]]],
                    ],
                    [
                        'id' => 'gid://shopify/Product/other',
                        'title' => 'Other',
                        'handle' => 'other',
                        'availableForSale' => true,
                        'priceRange' => ['minVariantPrice' => ['amount' => '2.00', 'currencyCode' => 'GBP']],
                        'variants' => ['edges' => [['node' => [
                            'id' => 'v2', 'availableForSale' => true,
                            'price' => ['amount' => '2.00', 'currencyCode' => 'GBP'],
                        ]]]],
                    ],
                ],
            ],
        ]);

        $out = $this->service->getCrossSells('gid://shopify/Product/anchor', 'demo.myshopify.com', 'GBP');

        $this->assertCount(1, $out);
        $this->assertSame('gid://shopify/Product/other', $out[0]->id);
    }
}
