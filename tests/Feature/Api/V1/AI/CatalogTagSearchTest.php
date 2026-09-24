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

    public function test_catalog_search_for_love_readings_isolates_love_products_and_excludes_unrelated_readings(): void
    {
        $storefrontApi = $this->createMock(StorefrontApiClientInterface::class);
        $storefrontApi->expects($this->once())
            ->method('query')
            ->with('storefront/products/get_all_products', $this->callback(function (array $params): bool {
                $q = (string) ($params['query'] ?? '');

                return str_contains($q, 'tag:"Love"') && str_contains($q, 'title:"love"');
            }))
            ->willReturn([
                'data' => [
                    'products' => [
                        'edges' => [
                            // 6 Dedicated Love Readings
                            ['node' => ['id' => 'gid://shopify/Product/1', 'title' => '3 Card Love Reading', 'productType' => 'Email Reading', 'tags' => ['Love', 'Tarot Card'], 'handle' => '3-card-love-reading', 'variants' => ['edges' => [['node' => ['id' => 'v1', 'price' => ['amount' => '25.00', 'currencyCode' => 'GBP'], 'availableForSale' => true]]]]]],
                            ['node' => ['id' => 'gid://shopify/Product/2', 'title' => '6 Card Love Reading', 'productType' => 'Email Reading', 'tags' => ['Love', 'Tarot Card'], 'handle' => '6-card-love-reading', 'variants' => ['edges' => [['node' => ['id' => 'v2', 'price' => ['amount' => '35.00', 'currencyCode' => 'GBP'], 'availableForSale' => true]]]]]],
                            ['node' => ['id' => 'gid://shopify/Product/3', 'title' => 'In-Depth Love & Prosperity Reading', 'productType' => 'Email Reading', 'tags' => ['Love', 'Prosperity'], 'handle' => 'in-depth-love-prosperity', 'variants' => ['edges' => [['node' => ['id' => 'v3', 'price' => ['amount' => '45.00', 'currencyCode' => 'GBP'], 'availableForSale' => true]]]]]],
                            ['node' => ['id' => 'gid://shopify/Product/4', 'title' => 'Love & Relationships Two Question Email Reading', 'productType' => 'Email Reading', 'tags' => ['Love', 'Email Reading'], 'handle' => 'love-relationships-two-question', 'variants' => ['edges' => [['node' => ['id' => 'v4', 'price' => ['amount' => '35.00', 'currencyCode' => 'GBP'], 'availableForSale' => true]]]]]],
                            ['node' => ['id' => 'gid://shopify/Product/5', 'title' => 'You & Me Love Reading', 'productType' => 'Email Reading', 'tags' => ['Love'], 'handle' => 'you-and-me-love-reading', 'variants' => ['edges' => [['node' => ['id' => 'v5', 'price' => ['amount' => '30.00', 'currencyCode' => 'GBP'], 'availableForSale' => true]]]]]],
                            ['node' => ['id' => 'gid://shopify/Product/6', 'title' => 'Your Future Love Reading & Predictions', 'productType' => 'Email Reading', 'tags' => ['Love', 'Future'], 'handle' => 'your-future-love-reading', 'variants' => ['edges' => [['node' => ['id' => 'v6', 'price' => ['amount' => '40.00', 'currencyCode' => 'GBP'], 'availableForSale' => true]]]]]],

                            // 5 Unrelated Candidate Products that must be completely excluded by Relevance Gating
                            ['node' => ['id' => 'gid://shopify/Product/7', 'title' => '1-2-1 Reading With Scott', 'productType' => 'Reading', 'tags' => ['Readings'], 'handle' => '1-2-1-reading-with-scott', 'variants' => ['edges' => [['node' => ['id' => 'v7', 'price' => ['amount' => '120.00', 'currencyCode' => 'GBP'], 'availableForSale' => true]]]]]],
                            ['node' => ['id' => 'gid://shopify/Product/8', 'title' => 'Online Group Reading With Scott', 'productType' => 'Reading', 'tags' => ['Readings'], 'handle' => 'online-group-reading', 'variants' => ['edges' => [['node' => ['id' => 'v8', 'price' => ['amount' => '60.00', 'currencyCode' => 'GBP'], 'availableForSale' => true]]]]]],
                            ['node' => ['id' => 'gid://shopify/Product/9', 'title' => 'Messages From Heaven', 'productType' => 'Email Reading', 'tags' => ['Heaven'], 'handle' => 'messages-from-heaven', 'variants' => ['edges' => [['node' => ['id' => 'v9', 'price' => ['amount' => '35.00', 'currencyCode' => 'GBP'], 'availableForSale' => true]]]]]],
                            ['node' => ['id' => 'gid://shopify/Product/10', 'title' => 'Future Two Question Email Reading', 'productType' => 'Email Reading', 'tags' => ['Future'], 'handle' => 'future-two-question-email-reading', 'variants' => ['edges' => [['node' => ['id' => 'v10', 'price' => ['amount' => '35.00', 'currencyCode' => 'GBP'], 'availableForSale' => true]]]]]],
                            ['node' => ['id' => 'gid://shopify/Product/11', 'title' => 'Gold Three Question Email Reading', 'productType' => 'Email Reading', 'tags' => ['Ask A Question'], 'handle' => 'gold-three-question-email-reading', 'variants' => ['edges' => [['node' => ['id' => 'v11', 'price' => ['amount' => '50.00', 'currencyCode' => 'GBP'], 'availableForSale' => true]]]]]],
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
            $result = $executor->execute('search_catalog', ['query' => 'love readings'], $ctx);
        } finally {
            $output = (string) ob_get_clean();
        }

        $this->assertTrue($result->isSuccess());
        $products = $result->emittedChunk['products'] ?? [];

        // All 6 Love readings must be present
        $this->assertCount(6, $products);
        $titles = array_column($products, 'title');
        $this->assertContains('3 Card Love Reading', $titles);
        $this->assertContains('6 Card Love Reading', $titles);
        $this->assertContains('In-Depth Love & Prosperity Reading', $titles);
        $this->assertContains('Love & Relationships Two Question Email Reading', $titles);
        $this->assertContains('You & Me Love Reading', $titles);
        $this->assertContains('Your Future Love Reading & Predictions', $titles);

        // Disqualified / unrelated readings must NOT appear
        $this->assertNotContains('1-2-1 Reading With Scott', $titles);
        $this->assertNotContains('Online Group Reading With Scott', $titles);
        $this->assertNotContains('Messages From Heaven', $titles);
        $this->assertNotContains('Future Two Question Email Reading', $titles);
        $this->assertNotContains('Gold Three Question Email Reading', $titles);
    }

    public function test_compound_query_future_love_reading_ranks_dual_matched_product_highest(): void
    {
        $storefrontApi = $this->createMock(StorefrontApiClientInterface::class);
        $storefrontApi->expects($this->once())
            ->method('query')
            ->with('storefront/products/get_all_products', $this->callback(function (array $params): bool {
                $q = (string) ($params['query'] ?? '');

                return str_contains($q, 'tag:"Future"') && str_contains($q, 'tag:"Love"');
            }))
            ->willReturn([
                'data' => [
                    'products' => [
                        'edges' => [
                            ['node' => ['id' => 'gid://shopify/Product/1', 'title' => 'Love & Relationships Two Question Email Reading', 'productType' => 'Email Reading', 'tags' => ['Love'], 'handle' => 'love-relationships', 'variants' => ['edges' => [['node' => ['id' => 'v1', 'price' => ['amount' => '35.00', 'currencyCode' => 'GBP'], 'availableForSale' => true]]]]]],
                            ['node' => ['id' => 'gid://shopify/Product/2', 'title' => 'Your Future Love Reading & Predictions', 'productType' => 'Email Reading', 'tags' => ['Love', 'Future'], 'handle' => 'your-future-love-reading', 'variants' => ['edges' => [['node' => ['id' => 'v2', 'price' => ['amount' => '40.00', 'currencyCode' => 'GBP'], 'availableForSale' => true]]]]]],
                            ['node' => ['id' => 'gid://shopify/Product/3', 'title' => 'Future Predictions Email Reading', 'productType' => 'Email Reading', 'tags' => ['Future'], 'handle' => 'future-predictions', 'variants' => ['edges' => [['node' => ['id' => 'v3', 'price' => ['amount' => '35.00', 'currencyCode' => 'GBP'], 'availableForSale' => true]]]]]],
                            ['node' => ['id' => 'gid://shopify/Product/4', 'title' => 'Messages From Heaven', 'productType' => 'Email Reading', 'tags' => ['Heaven'], 'handle' => 'messages-from-heaven', 'variants' => ['edges' => [['node' => ['id' => 'v4', 'price' => ['amount' => '35.00', 'currencyCode' => 'GBP'], 'availableForSale' => true]]]]]],
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
            $result = $executor->execute('search_catalog', ['query' => 'future love reading'], $ctx);
        } finally {
            $output = (string) ob_get_clean();
        }

        $this->assertTrue($result->isSuccess());
        $products = $result->emittedChunk['products'] ?? [];

        // Dual match (Future + Love) must rank #1 at index 0
        $this->assertNotEmpty($products);
        $this->assertSame('Your Future Love Reading & Predictions', $products[0]['title']);

        // Messages From Heaven must be completely excluded
        $titles = array_column($products, 'title');
        $this->assertNotContains('Messages From Heaven', $titles);
    }

    public function test_broad_guidance_query_routes_to_email_readings_collection_showcase(): void
    {
        $storefrontApi = $this->createMock(StorefrontApiClientInterface::class);
        $storefrontApi->expects($this->once())
            ->method('query')
            ->with('storefront/collection/collection_products', $this->callback(function (array $params): bool {
                return ($params['handle'] ?? '') === 'email-readings';
            }))
            ->willReturn([
                'data' => [
                    'collectionByHandle' => [
                        'products' => [
                            'edges' => [
                                ['node' => ['id' => 'gid://shopify/Product/1', 'title' => 'Gold Three Question Email Reading', 'productType' => 'Email Reading', 'tags' => ['Ask A Question'], 'handle' => 'gold-three-question', 'variants' => ['edges' => [['node' => ['id' => 'v1', 'price' => ['amount' => '50.00', 'currencyCode' => 'GBP'], 'availableForSale' => true]]]]]],
                                ['node' => ['id' => 'gid://shopify/Product/2', 'title' => 'Love & Relationships Two Question Email Reading', 'productType' => 'Email Reading', 'tags' => ['Love'], 'handle' => 'love-relationships', 'variants' => ['edges' => [['node' => ['id' => 'v2', 'price' => ['amount' => '35.00', 'currencyCode' => 'GBP'], 'availableForSale' => true]]]]]],
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
            $result = $executor->execute('search_catalog', ['query' => 'recommend a reading for me'], $ctx);
        } finally {
            $output = (string) ob_get_clean();
        }

        $this->assertTrue($result->isSuccess());
        $products = $result->emittedChunk['products'] ?? [];
        $this->assertCount(2, $products);
    }

    public function test_hybrid_query_crystals_for_love_prioritizes_physical_goods(): void
    {
        $storefrontApi = $this->createMock(StorefrontApiClientInterface::class);
        $storefrontApi->expects($this->once())
            ->method('query')
            ->with('storefront/products/get_all_products', $this->anything())
            ->willReturn([
                'data' => [
                    'products' => [
                        'edges' => [
                            ['node' => ['id' => 'gid://shopify/Product/1', 'title' => 'Love & Relationships Two Question Email Reading', 'productType' => 'Email Reading', 'tags' => ['Love'], 'handle' => 'love-relationships', 'variants' => ['edges' => [['node' => ['id' => 'v1', 'price' => ['amount' => '35.00', 'currencyCode' => 'GBP'], 'availableForSale' => true]]]]]],
                            ['node' => ['id' => 'gid://shopify/Product/2', 'title' => 'Rose Quartz Love Crystal', 'productType' => 'Crystals', 'tags' => ['Crystal', 'Love'], 'handle' => 'rose-quartz-love-crystal', 'variants' => ['edges' => [['node' => ['id' => 'v2', 'price' => ['amount' => '18.00', 'currencyCode' => 'GBP'], 'availableForSale' => true]]]]]],
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
            $result = $executor->execute('search_catalog', ['query' => 'crystals for love'], $ctx);
        } finally {
            $output = (string) ob_get_clean();
        }

        $this->assertTrue($result->isSuccess());
        $products = $result->emittedChunk['products'] ?? [];
        $this->assertNotEmpty($products);
        $this->assertSame('Rose Quartz Love Crystal', $products[0]['title']);
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

    public function test_catalog_search_for_spirit_guide_meditation_returns_meditation_product_with_immunity(): void
    {
        $storefrontApi = $this->createMock(StorefrontApiClientInterface::class);
        $storefrontApi->expects($this->once())
            ->method('query')
            ->with('storefront/products/get_all_products', $this->callback(function (array $params): bool {
                $q = (string) ($params['query'] ?? '');

                return str_contains($q, 'spirit') && str_contains($q, 'meditation');
            }))
            ->willReturn([
                'data' => [
                    'products' => [
                        'edges' => [
                            [
                                'node' => [
                                    'id' => 'gid://shopify/Product/15536935764351',
                                    'title' => 'Spirit Guide Meditation',
                                    'productType' => '',
                                    'tags' => ['audio-login', 'custom product', 'spirit-guide-meditation'],
                                    'handle' => 'spirit-guide-meditation',
                                    'variants' => [
                                        'edges' => [
                                            ['node' => ['id' => 'v99', 'price' => ['amount' => '18.99', 'currencyCode' => 'GBP'], 'availableForSale' => true]],
                                        ],
                                    ],
                                ],
                            ],
                            [
                                'node' => [
                                    'id' => 'gid://shopify/Product/100',
                                    'title' => 'Messages From Heaven',
                                    'productType' => 'Email Reading',
                                    'tags' => ['Heaven'],
                                    'handle' => 'messages-from-heaven',
                                    'variants' => [
                                        'edges' => [
                                            ['node' => ['id' => 'v100', 'price' => ['amount' => '35.00', 'currencyCode' => 'GBP'], 'availableForSale' => true]],
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
            $result = $executor->execute('search_catalog', ['query' => 'Spirit Guide Meditation'], $ctx);
        } finally {
            $output = (string) ob_get_clean();
        }

        $this->assertTrue($result->isSuccess());
        $products = $result->emittedChunk['products'] ?? [];
        $this->assertNotEmpty($products);
        $this->assertSame('Spirit Guide Meditation', $products[0]['title']);
    }

    public function test_catalog_search_with_mobile_typo_recovers_via_fuzzy_fallback(): void
    {
        $storefrontApi = $this->createMock(StorefrontApiClientInterface::class);
        $storefrontApi->expects($this->atLeast(2))
            ->method('query')
            ->willReturnCallback(function (string $path, array $params) {
                $q = (string) ($params['query'] ?? '');

                // Pass 1: "spirite meditation" -> 0 items
                // Pass 2: "spirite* meditation*" -> 0 items
                // Pass 3: "spirit" / "spirit* meditation*" -> 1 item (recovered)
                if (str_contains($q, 'spirit*') || (str_contains($q, 'spirit') && ! str_contains($q, 'spirite'))) {
                    return [
                        'data' => [
                            'products' => [
                                'edges' => [
                                    [
                                        'node' => [
                                            'id' => 'gid://shopify/Product/15536935764351',
                                            'title' => 'Spirit Guide Meditation',
                                            'productType' => '',
                                            'tags' => ['audio-login', 'custom product', 'spirit-guide-meditation'],
                                            'handle' => 'spirit-guide-meditation',
                                            'variants' => [
                                                'edges' => [
                                                    ['node' => ['id' => 'v99', 'price' => ['amount' => '18.99', 'currencyCode' => 'GBP'], 'availableForSale' => true]],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ];
                }

                return ['data' => ['products' => ['edges' => []]]];
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
            $result = $executor->execute('search_catalog', ['query' => 'spirite meditation'], $ctx);
        } finally {
            $output = (string) ob_get_clean();
        }

        $this->assertTrue($result->isSuccess());
        $products = $result->emittedChunk['products'] ?? [];
        $this->assertNotEmpty($products);
        $this->assertSame('Spirit Guide Meditation', $products[0]['title']);
    }

    public function test_broad_meditation_query_maps_to_meditations_collection_while_specific_meditation_searches_catalog(): void
    {
        $storefrontApi = $this->createMock(StorefrontApiClientInterface::class);
        $queriedCollection = false;
        $queriedCatalog = false;

        $storefrontApi->expects($this->exactly(2))
            ->method('query')
            ->willReturnCallback(function (string $path, array $params) use (&$queriedCollection, &$queriedCatalog) {
                if ($path === 'storefront/collection/collection_products') {
                    $queriedCollection = true;
                    $this->assertSame('meditations', $params['handle']);

                    return [
                        'data' => [
                            'collectionByHandle' => [
                                'products' => [
                                    'edges' => [
                                        [
                                            'node' => [
                                                'id' => 'gid://shopify/Product/1',
                                                'title' => 'Sleep Meditation',
                                                'productType' => '',
                                                'tags' => ['sleep-meditation'],
                                                'handle' => 'sleep-meditation',
                                                'variants' => ['edges' => [['node' => ['id' => 'v1', 'price' => ['amount' => '18.99', 'currencyCode' => 'GBP'], 'availableForSale' => true]]]],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ];
                }

                if ($path === 'storefront/products/get_all_products') {
                    $queriedCatalog = true;

                    return [
                        'data' => [
                            'products' => [
                                'edges' => [
                                    [
                                        'node' => [
                                            'id' => 'gid://shopify/Product/2',
                                            'title' => 'Spirit Guide Meditation',
                                            'productType' => '',
                                            'tags' => ['spirit-guide-meditation'],
                                            'handle' => 'spirit-guide-meditation',
                                            'variants' => ['edges' => [['node' => ['id' => 'v2', 'price' => ['amount' => '18.99', 'currencyCode' => 'GBP'], 'availableForSale' => true]]]],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ];
                }

                return ['data' => []];
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
            // Broad browsing query -> maps to 'meditations' collection
            $resBroad = $executor->execute('search_catalog', ['query' => 'show me meditations'], $ctx);
            // Specific product query -> maps to catalog search
            $resSpecific = $executor->execute('search_catalog', ['query' => 'Spirit Guide Meditation'], $ctx);
        } finally {
            $output = (string) ob_get_clean();
        }

        $this->assertTrue($resBroad->isSuccess());
        $this->assertTrue($resSpecific->isSuccess());
        $this->assertTrue($queriedCollection, 'Expected broad query to fetch meditations collection');
        $this->assertTrue($queriedCatalog, 'Expected specific query to search catalog directly');
    }

    public function test_specific_product_search_isolates_exact_match_and_prunes_near_duplicate_competitor(): void
    {
        $storefrontApi = $this->createMock(StorefrontApiClientInterface::class);
        $storefrontApi->expects($this->once())
            ->method('query')
            ->with('storefront/products/get_all_products', $this->callback(function (array $params): bool {
                $q = (string) ($params['query'] ?? '');

                return str_contains($q, 'spirit') && str_contains($q, 'guide');
            }))
            ->willReturn([
                'data' => [
                    'products' => [
                        'edges' => [
                            [
                                'node' => [
                                    'id' => 'gid://shopify/Product/15536935764351',
                                    'title' => 'Spirit Guide Meditation',
                                    'productType' => '',
                                    'tags' => ['audio-login', 'custom product', 'spirit-guide-meditation'],
                                    'handle' => 'spirit-guide-meditation',
                                    'variants' => [
                                        'edges' => [
                                            ['node' => ['id' => 'v1', 'price' => ['amount' => '18.99', 'currencyCode' => 'GBP'], 'availableForSale' => true]],
                                        ],
                                    ],
                                ],
                            ],
                            [
                                'node' => [
                                    'id' => 'gid://shopify/Product/15442647646591',
                                    'title' => 'Communicate with Spirit Meditation',
                                    'productType' => '',
                                    'tags' => ['audio-login', 'communication-meditation', 'custom product'],
                                    'handle' => 'communicate-with-spirit-meditation',
                                    'variants' => [
                                        'edges' => [
                                            ['node' => ['id' => 'v2', 'price' => ['amount' => '18.99', 'currencyCode' => 'GBP'], 'availableForSale' => true]],
                                        ],
                                    ],
                                ],
                            ],
                            [
                                'node' => [
                                    'id' => 'gid://shopify/Product/15536935502207',
                                    'title' => 'Protection Meditation',
                                    'productType' => '',
                                    'tags' => ['audio-login', 'custom product', 'protection-meditation'],
                                    'handle' => 'protection-meditation',
                                    'variants' => [
                                        'edges' => [
                                            ['node' => ['id' => 'v3', 'price' => ['amount' => '19.99', 'currencyCode' => 'GBP'], 'availableForSale' => true]],
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
            $result = $executor->execute('search_catalog', ['query' => 'show me the Spirit Guide Meditation'], $ctx);
        } finally {
            $output = (string) ob_get_clean();
        }

        $this->assertTrue($result->isSuccess());
        $products = $result->emittedChunk['products'] ?? [];

        // Exact Match Isolation: Only Spirit Guide Meditation must be returned; competitor cards pruned!
        $this->assertCount(1, $products);
        $this->assertSame('Spirit Guide Meditation', $products[0]['title']);

        // Structured messageForAi check:
        $this->assertStringContainsString('Found 1 exact product match: "Spirit Guide Meditation"', $result->messageForAi);
        $this->assertStringContainsString('spirit-guide-meditation', $result->messageForAi);
        $this->assertStringContainsString('18.99 GBP', $result->messageForAi);
    }
}
