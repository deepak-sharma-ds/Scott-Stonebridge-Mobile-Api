<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\AI;

use App\Contracts\Shopify\StorefrontApiClientInterface;
use App\Models\AiConversation;
use App\Models\AiCustomerSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateStreamedResponse;
use OpenAI\Responses\StreamResponse;
use Tests\Mocks\MockShopifyClient;
use Tests\TestCase;

/**
 * End-to-end SSE flow with OpenAI streaming + Shopify MCP fully mocked.
 *
 * Each scenario queues two streamed OpenAI responses:
 *   1. one that asks the model to call a single function tool;
 *   2. one that returns the assistant's follow-up text.
 *
 * Between the two we expect ToolExecutor → MCP HTTP fake → ChunkEmitter to
 * have pushed the typed SSE chunk into the response body.
 */
class ChatStreamMcpTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP = 'demo.myshopify.com';

    private MockShopifyClient $shopify;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        Cache::flush();

        // ShopifyContextService talks to Storefront GraphQL when the user
        // is on a product page. We pin a "home" context so context resolution
        // is a no-op for these tests.
        $this->shopify = new MockShopifyClient;
        $this->app->instance(StorefrontApiClientInterface::class, $this->shopify);
    }

    public function test_search_catalog_emits_products_chunk(): void
    {
        $convo = $this->makeConversation();

        OpenAI::fake([
            $this->streamedToolCall('call_a', 'search_catalog', '{"query":"tarot"}'),
            $this->streamedText('Here are some decks.'),
        ]);

        Http::fake([
            'https://'.self::SHOP.'/api/ucp/mcp' => Http::response($this->jsonRpcResult([
                'products' => [[
                    'id' => 'gid://shopify/Product/1',
                    'title' => 'The Fool Tarot',
                    'handle' => 'the-fool-tarot',
                    'variants' => [['id' => 'gid://shopify/Variant/11', 'price' => '24.99']],
                    'currency_code' => 'GBP',
                ]],
            ])),
        ]);

        $body = $this->stream($convo->session_id, 'show me tarot decks');

        $this->assertStringContainsString('"type":"products"', $body);
        $this->assertStringContainsString('"variant_id":"gid://shopify/Variant/11"', $body);
        $this->assertStringContainsString('"price_minor_units":2499', $body);
        $this->assertStringContainsString('"type":"text"', $body);
        $this->assertStringContainsString('"type":"done"', $body);
    }

    public function test_get_product_emits_product_detail_chunk(): void
    {
        $convo = $this->makeConversation();

        OpenAI::fake([
            $this->streamedToolCall('call_b', 'get_product', '{"product_id":"gid://shopify/Product/9"}'),
            $this->streamedText('Here are the details.'),
        ]);

        Http::fake([
            'https://'.self::SHOP.'/api/ucp/mcp' => Http::response($this->jsonRpcResult([
                'product' => [
                    'id' => 'gid://shopify/Product/9',
                    'title' => 'Crystal Ball',
                    'handle' => 'crystal-ball',
                    'price' => '49.00',
                    'currency_code' => 'GBP',
                    'variants' => [['id' => 'v1', 'price' => 49, 'available' => true]],
                ],
            ])),
        ]);

        $body = $this->stream($convo->session_id, 'show me the crystal ball product');

        $this->assertStringContainsString('"type":"product_detail"', $body);
        $this->assertStringContainsString('"price_minor_units":4900', $body);
    }

    public function test_update_cart_emits_cart_state_chunk(): void
    {
        $convo = $this->makeConversation();

        OpenAI::fake([
            $this->streamedToolCall('call_c', 'update_cart', '{"cart_id":"c1","lines":[{"merchandise_id":"v1","quantity":1}]}'),
            $this->streamedText('Added.'),
        ]);

        Http::fake([
            'https://'.self::SHOP.'/api/mcp' => Http::response($this->jsonRpcResult([
                'cart' => [
                    'id' => 'c1',
                    'currency_code' => 'GBP',
                    'checkout_url' => 'https://demo/checkout',
                    'lines' => [[
                        'merchandise_id' => 'v1', 'quantity' => 1,
                        'merchandise' => ['product' => ['id' => 'p1', 'title' => 'Demo']],
                        'cost' => ['total' => ['amount' => '10.00']],
                    ]],
                ],
            ])),
        ]);

        $body = $this->stream($convo->session_id, 'add to cart please');

        $this->assertStringContainsString('"type":"cart_state"', $body);
        $this->assertStringContainsString('"checkout_url":"https://demo/checkout"', $body);
    }

    public function test_policy_query_emits_policy_answer_chunk(): void
    {
        $convo = $this->makeConversation();

        OpenAI::fake([
            $this->streamedToolCall('call_d', 'search_shop_policies_and_faqs', '{"query":"returns"}'),
            $this->streamedText('Returns are easy.'),
        ]);

        Http::fake([
            'https://'.self::SHOP.'/api/mcp' => Http::response($this->jsonRpcResult([
                'answer' => 'Returns within 30 days.',
                'citations' => [['title' => 'Refund Policy', 'url' => 'https://demo/policies/refund']],
            ])),
        ]);

        $body = $this->stream($convo->session_id, 'what is your return policy?');

        $this->assertStringContainsString('"type":"policy_answer"', $body);
        $this->assertStringContainsString('Returns within 30 days.', $body);
        $this->assertStringContainsString('Refund Policy', $body);
    }

    public function test_unauthenticated_order_query_emits_auth_required_chunk(): void
    {
        $convo = $this->makeConversation();

        OpenAI::fake([
            $this->streamedToolCall('call_e', 'get_order_status', '{"order_number":"1234"}'),
            $this->streamedText('Sign in to view your order.'),
        ]);

        // No HTTP fake needed — auth_required short-circuits before MCP.
        $body = $this->stream($convo->session_id, 'where is my order #1234?');

        $this->assertStringContainsString('"type":"auth_required"', $body);
        $this->assertStringContainsString('"reason":"customer_account"', $body);
        $this->assertStringContainsString('oauth_start_url', $body);
    }

    public function test_authenticated_order_query_emits_order_tracking_chunk(): void
    {
        $convo = $this->makeConversation();
        AiCustomerSession::create([
            'session_id' => $convo->session_id,
            'customer_access_token' => 'shpca_active',
            'expires_at' => now()->addHour(),
        ]);

        OpenAI::fake([
            $this->streamedToolCall('call_f', 'get_order_status', '{"order_number":"1234"}'),
            $this->streamedText('On the way.'),
        ]);

        Http::fake([
            'https://'.self::SHOP.'/.well-known/customer-account-api' => Http::response([
                'mcp_endpoint' => 'https://'.self::SHOP.'/account/customer/mcp',
            ]),
            'https://'.self::SHOP.'/account/customer/mcp' => Http::response($this->jsonRpcResult([
                'order' => [
                    'name' => '#1234',
                    'fulfillment_status' => 'IN_TRANSIT',
                    'financial_status' => 'PAID',
                    'tracking' => ['number' => '1Z999', 'url' => 'https://ups/track', 'company' => 'UPS'],
                ],
            ])),
        ]);

        $body = $this->stream($convo->session_id, 'where is my order #1234?');

        $this->assertStringContainsString('"type":"order_tracking"', $body);
        $this->assertStringContainsString('"status":"in_transit"', $body);
        $this->assertStringContainsString('"tracking_number":"1Z999"', $body);
    }

    public function test_checkout_intent_emits_checkout_link_chunk(): void
    {
        $convo = $this->makeConversation();

        OpenAI::fake([
            $this->streamedToolCall('call_g', 'start_checkout', '{"cart_id":"c1"}'),
            $this->streamedText('Tap to checkout.'),
        ]);

        Http::fake([
            'https://'.self::SHOP.'/api/mcp' => Http::response($this->jsonRpcResult([
                'checkout_url' => 'https://demo.myshopify.com/checkouts/xyz',
                'total_minor_units' => 6450,
                'currency' => 'GBP',
                'item_count' => 2,
            ])),
        ]);

        $body = $this->stream($convo->session_id, 'I want to checkout now please');

        $this->assertStringContainsString('"type":"checkout_link"', $body);
        $this->assertStringContainsString('"checkout_url":"https://demo.myshopify.com/checkouts/xyz"', $body);
    }

    private function makeConversation(): AiConversation
    {
        return AiConversation::factory()->create([
            'shop_domain' => self::SHOP,
            'page_type' => 'home',
            'locale' => 'en',
        ]);
    }

    private function stream(string $sessionId, string $message): string
    {
        $response = $this->postJson("/api/v1/ai/chat/stream/{$sessionId}", [
            'message' => $message,
            'context' => [
                'page_type' => 'home',
                'shop_domain' => self::SHOP,
                'currency' => 'GBP',
                'locale' => 'en',
            ],
        ]);

        $response->assertStatus(200);

        ob_start();
        try {
            $response->baseResponse->sendContent();
        } finally {
            $body = (string) ob_get_clean();
        }

        return $body;
    }

    /**
     * Build a single-chunk-then-done streamed response carrying ONE tool_call.
     */
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

    /**
     * Build a two-chunk streamed response: text delta + stop.
     */
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

    /**
     * @param  list<array<string, mixed>>  $chunks
     */
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

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function jsonRpcResult(array $result): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 'req',
            'result' => $result,
        ];
    }
}
