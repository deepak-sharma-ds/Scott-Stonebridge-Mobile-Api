<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\AI;

use App\Contracts\Services\AI\IntentDetectionServiceInterface;
use App\Contracts\Services\AI\StreamingServiceInterface;
use App\Contracts\Shopify\StorefrontApiClientInterface;
use App\DTOs\Chat\IntentDTO;
use App\Models\AiConversation;
use App\Services\AI\Tools\ToolExecutor;
use App\Services\Shopify\AdminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateStreamedResponse;
use OpenAI\Responses\StreamResponse;
use Tests\Mocks\MockShopifyClient;
use Tests\TestCase;

class ShippingAndOrderDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP = 'demo.myshopify.com';

    private MockShopifyClient $shopify;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        Cache::flush();

        config([
            'shopify.store_domain' => self::SHOP,
            'chatbot.mcp.storefront_endpoint' => 'https://{shop}/api/mcp',
            'chatbot.mcp.ucp_endpoint' => 'https://{shop}/api/mcp',
            'chatbot.mcp.timeout_ms' => 15000,
            'chatbot.mcp.retry_on_status' => [502, 503, 504],
        ]);

        $this->shopify = new MockShopifyClient;
        $this->app->instance(StorefrontApiClientInterface::class, $this->shopify);
        $this->app->forgetInstance(ToolExecutor::class);
        $this->app->forgetInstance(StreamingServiceInterface::class);
    }

    public function test_get_shipping_options_queries_shopify_and_emits_shipping_options_chunk(): void
    {
        $convo = $this->makeConversation();

        OpenAI::fake([
            $this->streamedToolCall('call_ship', 'get_shipping_options', '{}'),
            $this->streamedText('Here are our shipping options.'),
        ]);

        $adminMock = Mockery::mock(AdminService::class);
        $adminMock->shouldReceive('request')->andReturn([
            'data' => [
                'deliveryProfiles' => [
                    'edges' => [
                        [
                            'node' => [
                                'id' => 'gid://shopify/DeliveryProfile/1',
                                'name' => 'Email Readings',
                                'default' => false,
                                'profileLocationGroups' => [
                                    [
                                        'locationGroupZones' => [
                                            'edges' => [
                                                [
                                                    'node' => [
                                                        'zone' => ['name' => 'Worldwide'],
                                                        'methodDefinitions' => [
                                                            'edges' => [
                                                                [
                                                                    'node' => [
                                                                        'name' => 'Standard 7 - 10 days - Via Email',
                                                                        'description' => 'Delivery via email',
                                                                        'active' => true,
                                                                        'rateProvider' => [
                                                                            'price' => ['amount' => '0.0', 'currencyCode' => 'GBP'],
                                                                        ],
                                                                    ],
                                                                ],
                                                                [
                                                                    'node' => [
                                                                        'name' => 'SAME DAY GUARANTEE - Via Email',
                                                                        'description' => 'Within 24hrs or Your Money Back!',
                                                                        'active' => true,
                                                                        'rateProvider' => [
                                                                            'price' => ['amount' => '8.99', 'currencyCode' => 'GBP'],
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
                                ],
                            ],
                        ],
                        [
                            'node' => [
                                'id' => 'gid://shopify/DeliveryProfile/2',
                                'name' => 'General profile',
                                'default' => true,
                                'profileLocationGroups' => [
                                    [
                                        'locationGroupZones' => [
                                            'edges' => [
                                                [
                                                    'node' => [
                                                        'zone' => ['name' => 'United Kingdom'],
                                                        'methodDefinitions' => [
                                                            'edges' => [
                                                                [
                                                                    'node' => [
                                                                        'name' => 'Free Shipping',
                                                                        'description' => 'Tracked shipping across UK',
                                                                        'active' => true,
                                                                        'rateProvider' => [
                                                                            'price' => ['amount' => '0.0', 'currencyCode' => 'GBP'],
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
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $this->app->instance(AdminService::class, $adminMock);
        $this->app->forgetInstance(ToolExecutor::class);

        $body = $this->stream($convo->session_id, 'what shipping options do you have?');

        $this->assertStringContainsString('"type":"shipping_options"', $body);
        $this->assertStringContainsString('SAME DAY GUARANTEE - Via Email', $body);
        $this->assertStringContainsString('Within 24 hours', $body);
        $this->assertStringContainsString('Standard 7 - 10 days - Via Email', $body);
        $this->assertStringContainsString('7–10 days', $body);
        $this->assertStringContainsString('Free Shipping', $body);
        $this->assertStringContainsString('3–7 days', $body);
    }

    public function test_get_order_status_emits_order_tracking_with_expected_delivery_and_created_at(): void
    {
        $convo = $this->makeConversation();

        OpenAI::fake([
            $this->streamedToolCall('call_ord', 'get_order_status', '{"order_number":"1099"}'),
            $this->streamedText('Your order is processing.'),
        ]);

        $adminMock = Mockery::mock(AdminService::class);
        $adminMock->shouldReceive('request')
            ->with(Mockery::on(fn (string $q) => str_contains($q, 'AdminOrdersSearch')), Mockery::any())
            ->andReturn([
                'data' => [
                    'orders' => [
                        'edges' => [
                            [
                                'node' => [
                                    'id' => 'gid://shopify/Order/1099',
                                    'name' => '#1099',
                                    'displayFulfillmentStatus' => 'UNFULFILLED',
                                    'displayFinancialStatus' => 'PAID',
                                    'createdAt' => '2026-10-01T14:00:00Z',
                                    'shippingLine' => [
                                        'title' => 'SAME DAY GUARANTEE - Via Email',
                                    ],
                                    'shippingAddress' => [
                                        'city' => 'London',
                                    ],
                                    'fulfillments' => [],
                                    'lineItems' => [
                                        'edges' => [
                                            [
                                                'node' => [
                                                    'title' => 'Spirit Guide Reading',
                                                    'quantity' => 1,
                                                    'originalUnitPriceSet' => [
                                                        'shopMoney' => [
                                                            'amount' => '35.00',
                                                            'currencyCode' => 'GBP',
                                                        ],
                                                    ],
                                                    'customAttributes' => [],
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

        $body = $this->stream($convo->session_id, 'where is my order #1099?', customer: [
            'customer_id' => '12345',
            'email' => 'customer@example.com',
            'logged_in' => true,
        ]);

        $this->assertStringContainsString('"type":"order_tracking"', $body);
        $this->assertStringContainsString('"order_number":"1099"', $body);
        $this->assertStringContainsString('"created_at":"2026-10-01T14:00:00Z"', $body);
        $this->assertStringContainsString('"shipping_title":"SAME DAY GUARANTEE - Via Email"', $body);
        $this->assertStringContainsString('Within 24 hours', $body);
        $this->assertStringContainsString('2:00 PM', $body);
    }

    public function test_policy_query_on_shipping_never_returns_privacy_policy_due_to_domain_affinity(): void
    {
        $convo = $this->makeConversation();

        OpenAI::fake([
            $this->streamedToolCall('call_pol', 'search_shop_policies_and_faqs', '{"query":"shipping options"}'),
            $this->streamedText('Here are our shipping details.'),
        ]);

        Http::fake([
            'https://'.self::SHOP.'/api/mcp' => Http::response([
                'jsonrpc' => '2.0',
                'id' => 'pol_1',
                'result' => [
                    'answer' => '',
                    'citations' => [],
                ],
            ]),
        ]);

        $this->shopify->mockResponse('storefront/policies/get_all_policies', [
            'data' => [
                'shop' => [
                    'privacyPolicy' => [
                        'title' => 'Privacy Policy',
                        'handle' => 'privacy-policy',
                        'url' => 'https://demo/policies/privacy-policy',
                        'body' => '<p>We collect personal information including your shipping address and payment details to process your order.</p>',
                    ],
                    'shippingPolicy' => null,
                ],
            ],
        ]);

        $adminMock = Mockery::mock(AdminService::class);
        $adminMock->shouldReceive('request')->andReturn([
            'data' => [
                'deliveryProfiles' => [
                    'edges' => [
                        [
                            'node' => [
                                'id' => 'gid://shopify/DeliveryProfile/1',
                                'name' => 'Email Readings',
                                'default' => false,
                                'profileLocationGroups' => [
                                    [
                                        'locationGroupZones' => [
                                            'edges' => [
                                                [
                                                    'node' => [
                                                        'zone' => ['name' => 'Email'],
                                                        'methodDefinitions' => [
                                                            'edges' => [
                                                                [
                                                                    'node' => [
                                                                        'name' => 'Standard 7 - 10 days - Via Email',
                                                                        'description' => null,
                                                                        'active' => true,
                                                                        'rateProvider' => [
                                                                            'price' => ['amount' => '0.0', 'currencyCode' => 'GBP'],
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
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $this->app->instance(AdminService::class, $adminMock);
        $this->app->forgetInstance(ToolExecutor::class);

        $body = $this->stream($convo->session_id, 'what are your shipping options?');

        // Privacy Policy must NEVER be emitted for a shipping query
        $this->assertStringNotContainsString('Privacy Policy', $body);
        $this->assertStringNotContainsString('"type":"policy_answer"', $body);

        // Instead, Policy Domain Affinity fallback to dynamic shipping options succeeded
        $this->assertStringContainsString('"type":"shipping_options"', $body);
        $this->assertStringContainsString('Standard 7 - 10 days - Via Email', $body);
    }

    public function test_get_order_status_calculates_range_delivery_window(): void
    {
        $convo = $this->makeConversation();

        OpenAI::fake([
            $this->streamedToolCall('call_ord_2', 'get_order_status', '{"order_number":"1088"}'),
            $this->streamedText('Your order is in progress.'),
        ]);

        $adminMock = Mockery::mock(AdminService::class);
        $adminMock->shouldReceive('request')
            ->with(Mockery::on(fn (string $q) => str_contains($q, 'AdminOrdersSearch')), Mockery::any())
            ->andReturn([
                'data' => [
                    'orders' => [
                        'edges' => [
                            [
                                'node' => [
                                    'id' => 'gid://shopify/Order/1088',
                                    'name' => '#1088',
                                    'displayFulfillmentStatus' => 'UNFULFILLED',
                                    'displayFinancialStatus' => 'PAID',
                                    'createdAt' => '2026-10-01T10:00:00Z',
                                    'shippingLine' => [
                                        'title' => 'Standard 7 - 10 days - Via Email',
                                    ],
                                    'shippingAddress' => [
                                        'city' => 'Birmingham',
                                    ],
                                    'fulfillments' => [],
                                    'lineItems' => [
                                        'edges' => [
                                            [
                                                'node' => [
                                                    'title' => 'Tarot Reading',
                                                    'quantity' => 1,
                                                    'originalUnitPriceSet' => [
                                                        'shopMoney' => ['amount' => '40.00', 'currencyCode' => 'GBP'],
                                                    ],
                                                    'customAttributes' => [],
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

        $body = $this->stream($convo->session_id, 'where is my order #1088?', customer: [
            'customer_id' => '12345',
            'email' => 'customer@example.com',
            'logged_in' => true,
        ]);

        $this->assertStringContainsString('"type":"order_tracking"', $body);
        $this->assertStringContainsString('"order_number":"1088"', $body);
        $this->assertStringContainsString('"shipping_title":"Standard 7 - 10 days - Via Email"', $body);
        $this->assertStringContainsString('7–10 days', $body);
        $this->assertStringContainsString('8 Oct – 11 Oct 2026', $body);
    }

    public function test_privacy_query_still_returns_privacy_policy_correctly(): void
    {
        $convo = $this->makeConversation();

        $intentMock = Mockery::mock(IntentDetectionServiceInterface::class);
        $intentMock->shouldReceive('detect')->andReturn(new IntentDTO(IntentDTO::INTENT_UNKNOWN, 1.0, [], 'regex'));
        $this->app->instance(IntentDetectionServiceInterface::class, $intentMock);
        $this->app->forgetInstance(StreamingServiceInterface::class);

        OpenAI::fake([
            $this->streamedToolCall('call_priv', 'search_shop_policies_and_faqs', '{"query":"how do you handle my personal data and privacy?"}'),
            $this->streamedText('We protect your personal data.'),
        ]);

        Http::fake([
            'https://'.self::SHOP.'/api/mcp' => Http::response([
                'jsonrpc' => '2.0',
                'id' => 'priv_1',
                'result' => [
                    'answer' => 'We comply with GDPR and UK data protection laws.',
                    'citations' => [['title' => 'Privacy Policy', 'url' => 'https://demo/policies/privacy-policy']],
                ],
            ]),
        ]);

        $this->shopify->mockResponse('storefront/policies/get_all_policies', [
            'data' => [
                'shop' => [
                    'privacyPolicy' => [
                        'title' => 'Privacy Policy',
                        'handle' => 'privacy-policy',
                        'url' => 'https://demo/policies/privacy-policy',
                        'body' => '<p>We protect your personal information in accordance with GDPR and Data Protection laws.</p>',
                    ],
                    'shippingPolicy' => [
                        'title' => 'Shipping Policy',
                        'handle' => 'shipping-policy',
                        'url' => 'https://demo/policies/shipping-policy',
                        'body' => '<p>Shipping takes 3-7 days.</p>',
                    ],
                ],
            ],
        ]);

        $body = $this->stream($convo->session_id, 'how do you handle my personal data and privacy?');

        $this->assertStringContainsString('"type":"policy_answer"', $body);
        $this->assertStringContainsString('Privacy Policy', $body);
        $this->assertStringContainsString('personal information in accordance with GDPR', $body);
    }

    private function makeConversation(): AiConversation
    {
        return AiConversation::factory()->create([
            'shop_domain' => self::SHOP,
            'page_type' => 'home',
            'locale' => 'en',
        ]);
    }

    private function stream(string $sessionId, string $message, array $headers = [], ?array $customer = null): string
    {
        $context = [
            'page_type' => 'home',
            'shop_domain' => self::SHOP,
            'currency' => 'GBP',
            'locale' => 'en',
        ];
        if ($customer !== null) {
            $context['customer'] = $customer;
        }

        $response = $this->postJson("/api/v1/ai/chat/stream/{$sessionId}", [
            'message' => $message,
            'context' => $context,
        ], $headers);

        $response->assertStatus(200);

        ob_start();
        try {
            $response->baseResponse->sendContent();
        } finally {
            $body = (string) ob_get_clean();
        }

        return $body;
    }

    private function streamedToolCall(string $id, string $name, string $argumentsJson): StreamResponse
    {
        return $this->buildStream([
            [
                'id' => 'chatcmpl-tc',
                'object' => 'chat.completion.chunk',
                'created' => 0,
                'model' => 'gpt-4.1-mini',
                'choices' => [[
                    'index' => 0,
                    'delta' => [
                        'role' => 'assistant',
                        'tool_calls' => [[
                            'index' => 0,
                            'id' => $id,
                            'type' => 'function',
                            'function' => ['name' => $name, 'arguments' => $argumentsJson],
                        ]],
                    ],
                    'finish_reason' => null,
                ]],
            ],
            [
                'id' => 'chatcmpl-tc',
                'object' => 'chat.completion.chunk',
                'created' => 0,
                'model' => 'gpt-4.1-mini',
                'choices' => [[
                    'index' => 0,
                    'delta' => new \stdClass,
                    'finish_reason' => 'tool_calls',
                ]],
            ],
        ]);
    }

    private function streamedText(string $text): StreamResponse
    {
        return $this->buildStream([
            [
                'id' => 'chatcmpl-t',
                'object' => 'chat.completion.chunk',
                'created' => 0,
                'model' => 'gpt-4.1-mini',
                'choices' => [[
                    'index' => 0,
                    'delta' => ['role' => 'assistant', 'content' => $text],
                    'finish_reason' => null,
                ]],
            ],
            [
                'id' => 'chatcmpl-t',
                'object' => 'chat.completion.chunk',
                'created' => 0,
                'model' => 'gpt-4.1-mini',
                'choices' => [[
                    'index' => 0,
                    'delta' => new \stdClass,
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ],
        ]);
    }

    private function buildStream(array $chunks): StreamResponse
    {
        $body = '';
        foreach ($chunks as $chunk) {
            $body .= 'data: '.json_encode($chunk)."\n\n";
        }
        $body .= "data: [DONE]\n\n";

        $resource = fopen('php://memory', 'r+');
        fwrite($resource, $body);
        rewind($resource);

        return CreateStreamedResponse::fake($resource);
    }
}
