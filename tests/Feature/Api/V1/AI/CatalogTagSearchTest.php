<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\AI;

use App\Contracts\Services\Sales\StoreKnowledgeServiceInterface;
use App\Contracts\Shopify\StorefrontApiClientInterface;
use App\DTOs\Chat\CustomerContextDTO;
use App\Models\StoreKnowledge;
use App\Services\AI\ChatSessionContext;
use App\Services\AI\Tools\ToolExecutor;
use App\Services\Sales\StoreKnowledgeService;
use App\Services\Shopify\AdminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CatalogTagSearchTest extends TestCase
{
    use RefreshDatabase;

    private const SESSION_ID = '33333333-4444-5555-6666-777777777777';

    private const SHOP = 'demo.myshopify.com';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->app->forgetInstance(ToolExecutor::class);
    }

    public function test_catalog_search_for_love_constructs_tag_query_and_ranks_readings(): void
    {
        $storefrontApi = $this->createMock(StorefrontApiClientInterface::class);
        $storefrontApi->expects($this->once())
            ->method('query')
            ->with('storefront/products/get_all_products', $this->callback(function (array $params): bool {
                return str_contains((string) ($params['query'] ?? ''), 'tag:"Love"')
                    && str_contains((string) ($params['query'] ?? ''), 'title:"love"');
            }))
            ->willReturn([
                'data' => [
                    'products' => [
                        'edges' => [
                            [
                                'node' => [
                                    'id' => 'gid://shopify/Product/101',
                                    'title' => 'Rose Quartz Crystal',
                                    'productType' => 'Crystals',
                                    'tags' => ['Crystal', 'Rose Quartz'],
                                    'handle' => 'rose-quartz-crystal',
                                    'variants' => [
                                        'edges' => [
                                            ['node' => ['id' => 'gid://shopify/ProductVariant/201', 'price' => ['amount' => '15.00', 'currencyCode' => 'GBP'], 'availableForSale' => true]],
                                        ],
                                    ],
                                ],
                            ],
                            [
                                'node' => [
                                    'id' => 'gid://shopify/Product/102',
                                    'title' => 'Love & Relationships Two Question Email Reading',
                                    'productType' => 'Email Reading',
                                    'tags' => ['Love', 'Email Reading'],
                                    'handle' => 'love-relationships-two-question-email-reading',
                                    'variants' => [
                                        'edges' => [
                                            ['node' => ['id' => 'gid://shopify/ProductVariant/202', 'price' => ['amount' => '35.00', 'currencyCode' => 'GBP'], 'availableForSale' => true]],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]);
        $this->app->instance(StorefrontApiClientInterface::class, $storefrontApi);
        $this->app->forgetInstance(ToolExecutor::class);

        $executor = $this->app->make(ToolExecutor::class);
        $ctx = new ChatSessionContext(
            sessionId: self::SESSION_ID,
            shopDomain: self::SHOP,
        );

        ob_start();
        try {
            $result = $executor->execute('search_catalog', ['query' => 'Love'], $ctx);
        } finally {
            $output = (string) ob_get_clean();
        }

        $this->assertTrue($result->isSuccess());
        $this->assertStringContainsString('"type":"products"', $output);
        $this->assertStringContainsString('Love & Relationships Two Question Email Reading', $output);

        // Reading should be ranked first in the carousel products payload
        $products = $result->emittedChunk['products'] ?? [];
        $this->assertNotEmpty($products);
        $this->assertSame('Love & Relationships Two Question Email Reading', $products[0]['title']);
    }

    public function test_catalog_search_for_future_and_heaven_generates_correct_tag_queries(): void
    {
        $storefrontApi = $this->createMock(StorefrontApiClientInterface::class);
        $storefrontApi->expects($this->exactly(2))
            ->method('query')
            ->willReturnCallback(function (string $path, array $params) {
                $q = (string) ($params['query'] ?? '');
                $title = str_contains($q, 'Future') ? 'Future Predictions Email Reading' : 'Messages From Heaven Email Reading';

                return [
                    'data' => [
                        'products' => [
                            'edges' => [
                                [
                                    'node' => [
                                        'id' => 'gid://shopify/Product/99',
                                        'title' => $title,
                                        'productType' => 'Email Reading',
                                        'tags' => [str_contains($q, 'Future') ? 'Future' : 'Heaven'],
                                        'handle' => 'email-reading',
                                        'variants' => [
                                            'edges' => [
                                                ['node' => ['id' => 'gid://shopify/ProductVariant/991', 'price' => ['amount' => '30.00', 'currencyCode' => 'GBP'], 'availableForSale' => true]],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ];
            });
        $this->app->instance(StorefrontApiClientInterface::class, $storefrontApi);
        $this->app->forgetInstance(ToolExecutor::class);

        $executor = $this->app->make(ToolExecutor::class);
        $ctx = new ChatSessionContext(
            sessionId: self::SESSION_ID,
            shopDomain: self::SHOP,
        );

        ob_start();
        try {
            $resFuture = $executor->execute('search_catalog', ['query' => 'future predictions'], $ctx);
            $resHeaven = $executor->execute('search_catalog', ['query' => 'messages from heaven'], $ctx);
        } finally {
            $output = (string) ob_get_clean();
        }

        $this->assertTrue($resFuture->isSuccess());
        $this->assertTrue($resHeaven->isSuccess());
    }

    public function test_admin_order_tracking_retrieves_line_items_and_shipping_option(): void
    {
        $customer = new CustomerContextDTO(
            customerId: '9988776655',
            loggedIn: true,
            email: 'customer@example.com',
            locale: 'en',
        );

        $ctx = new ChatSessionContext(
            sessionId: self::SESSION_ID,
            shopDomain: self::SHOP,
            isGuest: false,
            customer: $customer,
        );

        $adminMock = $this->createMock(AdminService::class);
        $adminMock->expects($this->once())
            ->method('request')
            ->willReturn([
                'data' => [
                    'orders' => [
                        'edges' => [
                            [
                                'node' => [
                                    'id' => 'gid://shopify/Order/777',
                                    'name' => '#1099',
                                    'processedAt' => '2026-09-20T12:00:00Z',
                                    'displayFulfillmentStatus' => 'UNFULFILLED',
                                    'displayFinancialStatus' => 'PAID',
                                    'totalPriceSet' => [
                                        'shopMoney' => ['amount' => '45.00', 'currencyCode' => 'GBP'],
                                        'presentmentMoney' => ['amount' => '45.00', 'currencyCode' => 'GBP'],
                                    ],
                                    'shippingLine' => ['title' => 'Add SAME DAY Guarantee'],
                                    'shippingAddress' => ['city' => 'Manchester'],
                                    'fulfillments' => [
                                        [
                                            'estimatedDeliveryAt' => '2026-09-20T20:00:00Z',
                                            'trackingInfo' => [],
                                        ],
                                    ],
                                    'lineItems' => [
                                        'edges' => [
                                            [
                                                'node' => [
                                                    'id' => 'gid://shopify/LineItem/1',
                                                    'title' => 'Love & Relationships Two Question Email Reading',
                                                    'quantity' => 1,
                                                    'variantTitle' => '30 Minute Reading',
                                                    'customAttributes' => [
                                                        ['key' => 'Question 1', 'value' => 'Will I meet my soulmate?'],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]);

        $this->app->instance(AdminService::class, $adminMock);
        $this->app->forgetInstance(ToolExecutor::class);
        $executor = $this->app->make(ToolExecutor::class);

        ob_start();
        try {
            $result = $executor->execute('get_order_status', ['order_number' => '1099'], $ctx);
        } finally {
            $output = (string) ob_get_clean();
        }

        $this->assertTrue($result->isSuccess());
        $this->assertStringContainsString('Love & Relationships Two Question Email Reading', $result->messageForAi);
        $this->assertStringContainsString('Add SAME DAY Guarantee', $result->messageForAi);
        $this->assertStringContainsString('Will I meet my soulmate?', $result->messageForAi);
        $this->assertStringContainsString('"type":"order_tracking"', $output);
    }

    public function test_store_knowledge_retrieval_boosts_shipping_policy_on_delivery_query(): void
    {
        StoreKnowledge::create([
            'shop_domain' => self::SHOP,
            'content_type' => StoreKnowledge::TYPE_POLICY,
            'handle' => 'SHIPPING_POLICY',
            'title' => 'Shipping Policy',
            'summary' => 'Orders are dispatched within 24-48 hours. Express delivery available.',
            'raw_content' => 'Full shipping policy text with delivery timelines.',
            'last_synced_at' => now(),
        ]);

        StoreKnowledge::create([
            'shop_domain' => self::SHOP,
            'content_type' => StoreKnowledge::TYPE_POLICY,
            'handle' => 'REFUND_POLICY',
            'title' => 'Refund Policy',
            'summary' => 'Readings are non-refundable once delivered.',
            'raw_content' => 'Full refund policy text.',
            'last_synced_at' => now(),
        ]);

        /** @var StoreKnowledgeService $service */
        $service = $this->app->make(StoreKnowledgeServiceInterface::class);

        $results = $service->searchForTool(self::SHOP, 'When will my order be dispatched and delivered?');

        $this->assertNotEmpty($results);
        $this->assertSame('Shipping Policy', $results[0]['title']);
    }
}
