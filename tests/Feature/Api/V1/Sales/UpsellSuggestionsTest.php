<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Sales;

use App\Contracts\Shopify\StorefrontApiClientInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Tests\Mocks\MockShopifyClient;
use Tests\TestCase;

/**
 * Phase C — POST /api/v1/ai/upsell/suggestions coverage.
 *
 * Storefront is fully mocked. The endpoint must:
 *   - return upsells + free-shipping gap on happy path
 *   - dedupe against cart product IDs
 *   - cap at config('sales.upsell.max_results')
 *   - never crash when Shopify is empty / cart is empty
 *   - return gap=null when threshold already met
 */
class UpsellSuggestionsTest extends TestCase
{
    use RefreshDatabase;

    private MockShopifyClient $shopify;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        Cache::flush();

        $this->shopify = new MockShopifyClient;
        $this->app->instance(StorefrontApiClientInterface::class, $this->shopify);

        config(['sales.upsell.default_free_shipping_threshold' => 50.00]);
        config(['sales.upsell.max_results' => 3]);
    }

    public function test_returns_upsells_and_free_shipping_gap_on_happy_path(): void
    {
        $this->shopify->mockResponse('storefront/products/get_product_recommendations', [
            'data' => [
                'productRecommendations' => [
                    [
                        'id' => 'gid://shopify/Product/100',
                        'title' => 'Wireless Charger',
                        'handle' => 'wireless-charger',
                        'availableForSale' => true,
                        'featuredImage' => ['url' => 'https://cdn/img.png', 'altText' => 'Charger'],
                        'priceRange' => ['minVariantPrice' => ['amount' => '19.99', 'currencyCode' => 'GBP']],
                        'variants' => ['edges' => [['node' => [
                            'id' => 'gid://shopify/ProductVariant/100-1',
                            'availableForSale' => true,
                            'price' => ['amount' => '19.99', 'currencyCode' => 'GBP'],
                        ]]]],
                    ],
                    [
                        'id' => 'gid://shopify/Product/101',
                        'title' => 'Carry Case',
                        'handle' => 'carry-case',
                        'availableForSale' => true,
                        'featuredImage' => ['url' => 'https://cdn/case.png', 'altText' => null],
                        'priceRange' => ['minVariantPrice' => ['amount' => '12.50', 'currencyCode' => 'GBP']],
                        'variants' => ['edges' => [['node' => [
                            'id' => 'gid://shopify/ProductVariant/101-1',
                            'availableForSale' => true,
                            'price' => ['amount' => '12.50', 'currencyCode' => 'GBP'],
                        ]]]],
                    ],
                ],
            ],
        ]);

        $response = $this->postJson('/api/v1/ai/upsell/suggestions', [
            'session_id' => 'sess-up-1',
            'shop_domain' => 'demo.myshopify.com',
            'cart_items' => [['product_id' => 'gid://shopify/Product/1', 'quantity' => 1]],
            'cart_total' => 38.00,
            'currency' => 'GBP',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonCount(2, 'data.upsells');
        $response->assertJsonPath('data.upsells.0.id', 'gid://shopify/Product/100');
        $response->assertJsonPath('data.upsells.0.title', 'Wireless Charger');
        $response->assertJsonPath('data.upsells.0.variant_id', 'gid://shopify/ProductVariant/100-1');
        $response->assertJsonPath('data.upsells.0.price', '19.99');
        $response->assertJsonPath('data.upsells.0.currency', 'GBP');
        $response->assertJsonPath('data.upsells.0.available', true);
        $response->assertJsonPath('data.free_shipping_gap', 12);
        $response->assertJsonPath('data.threshold', 50);
        $response->assertJsonPath('data.cart_total', 38);
    }

    public function test_dedupes_upsells_against_cart_product_ids(): void
    {
        $this->shopify->mockResponse('storefront/products/get_product_recommendations', [
            'data' => [
                'productRecommendations' => [
                    [
                        'id' => 'gid://shopify/Product/1',
                        'title' => 'In Cart',
                        'handle' => 'in-cart',
                        'availableForSale' => true,
                        'featuredImage' => null,
                        'priceRange' => ['minVariantPrice' => ['amount' => '10.00', 'currencyCode' => 'GBP']],
                        'variants' => ['edges' => [['node' => [
                            'id' => 'v1', 'availableForSale' => true,
                            'price' => ['amount' => '10.00', 'currencyCode' => 'GBP'],
                        ]]]],
                    ],
                    [
                        'id' => 'gid://shopify/Product/2',
                        'title' => 'Not In Cart',
                        'handle' => 'not-in-cart',
                        'availableForSale' => true,
                        'featuredImage' => null,
                        'priceRange' => ['minVariantPrice' => ['amount' => '15.00', 'currencyCode' => 'GBP']],
                        'variants' => ['edges' => [['node' => [
                            'id' => 'v2', 'availableForSale' => true,
                            'price' => ['amount' => '15.00', 'currencyCode' => 'GBP'],
                        ]]]],
                    ],
                ],
            ],
        ]);

        $response = $this->postJson('/api/v1/ai/upsell/suggestions', [
            'session_id' => 'sess-up-2',
            'shop_domain' => 'demo.myshopify.com',
            'cart_items' => [['product_id' => 'gid://shopify/Product/1']],
            'cart_total' => 10.00,
            'currency' => 'GBP',
        ]);

        $response->assertOk();
        $response->assertJsonCount(1, 'data.upsells');
        $response->assertJsonPath('data.upsells.0.id', 'gid://shopify/Product/2');
    }

    public function test_caps_results_at_max_results_config(): void
    {
        config(['sales.upsell.max_results' => 2]);

        $nodes = [];
        for ($i = 10; $i < 16; $i++) {
            $nodes[] = [
                'id' => 'gid://shopify/Product/'.$i,
                'title' => 'Item '.$i,
                'handle' => 'item-'.$i,
                'availableForSale' => true,
                'featuredImage' => null,
                'priceRange' => ['minVariantPrice' => ['amount' => '5.00', 'currencyCode' => 'GBP']],
                'variants' => ['edges' => [['node' => [
                    'id' => 'v'.$i, 'availableForSale' => true,
                    'price' => ['amount' => '5.00', 'currencyCode' => 'GBP'],
                ]]]],
            ];
        }
        $this->shopify->mockResponse('storefront/products/get_product_recommendations', [
            'data' => ['productRecommendations' => $nodes],
        ]);

        $response = $this->postJson('/api/v1/ai/upsell/suggestions', [
            'session_id' => 'sess-up-3',
            'shop_domain' => 'demo.myshopify.com',
            'cart_items' => [['product_id' => 'gid://shopify/Product/1']],
            'cart_total' => 5.00,
            'currency' => 'GBP',
        ]);

        $response->assertOk();
        $response->assertJsonCount(2, 'data.upsells');
    }

    public function test_excludes_unavailable_products(): void
    {
        $this->shopify->mockResponse('storefront/products/get_product_recommendations', [
            'data' => [
                'productRecommendations' => [
                    [
                        'id' => 'gid://shopify/Product/50',
                        'title' => 'Sold Out',
                        'handle' => 'sold-out',
                        'availableForSale' => false,
                        'featuredImage' => null,
                        'priceRange' => ['minVariantPrice' => ['amount' => '5.00', 'currencyCode' => 'GBP']],
                        'variants' => ['edges' => [['node' => [
                            'id' => 'v50', 'availableForSale' => false,
                            'price' => ['amount' => '5.00', 'currencyCode' => 'GBP'],
                        ]]]],
                    ],
                ],
            ],
        ]);

        $response = $this->postJson('/api/v1/ai/upsell/suggestions', [
            'session_id' => 'sess-up-4',
            'shop_domain' => 'demo.myshopify.com',
            'cart_items' => [['product_id' => 'gid://shopify/Product/1']],
            'cart_total' => 5.00,
            'currency' => 'GBP',
        ]);

        $response->assertOk();
        $response->assertJsonCount(0, 'data.upsells');
    }

    public function test_returns_empty_upsells_for_empty_cart_but_still_returns_threshold(): void
    {
        $response = $this->postJson('/api/v1/ai/upsell/suggestions', [
            'session_id' => 'sess-up-5',
            'shop_domain' => 'demo.myshopify.com',
            'cart_items' => [],
            'cart_total' => 0,
        ]);

        $response->assertOk();
        $response->assertJsonCount(0, 'data.upsells');
        $response->assertJsonPath('data.threshold', 50);
        $response->assertJsonPath('data.free_shipping_gap', 50);
    }

    public function test_gap_is_null_when_cart_meets_threshold(): void
    {
        $response = $this->postJson('/api/v1/ai/upsell/suggestions', [
            'session_id' => 'sess-up-6',
            'shop_domain' => 'demo.myshopify.com',
            'cart_items' => [],
            'cart_total' => 75.00,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.free_shipping_gap', null);
    }

    public function test_rejects_missing_required_fields(): void
    {
        $response = $this->postJson('/api/v1/ai/upsell/suggestions', [
            'cart_total' => 5,
        ]);
        $response->assertStatus(422);
    }
}
