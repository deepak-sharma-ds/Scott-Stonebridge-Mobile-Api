<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AI\Tools;

use App\Contracts\Services\Sales\UpsellServiceInterface;
use App\DTOs\Chat\CartContextDTO;
use App\Exceptions\AI\AuthRequiredException;
use App\Models\AiConversation;
use App\Models\AiCustomerSession;
use App\Services\AI\ChatSessionContext;
use App\Services\AI\MCP\CustomerAccountGraphClient;
use App\Services\AI\MCP\CustomerMcpClient;
use App\Services\AI\MCP\StorefrontMcpClient;
use App\Services\AI\Streaming\ChunkEmitter;
use App\Services\AI\Tools\ToolExecutor;
use App\Services\AI\Tools\ToolResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

class ToolExecutorTest extends TestCase
{
    use RefreshDatabase;

    private const SESSION_ID = '11111111-2222-3333-4444-555555555555';

    private const SHOP = 'demo.myshopify.com';

    private StorefrontMcpClient&MockObject $storefront;

    private CustomerMcpClient&MockObject $customer;

    private UpsellServiceInterface&MockObject $upsell;

    private ChunkEmitter $emitter;

    private ToolExecutor $executor;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->storefront = $this->createMock(StorefrontMcpClient::class);
        $this->customer = $this->createMock(CustomerMcpClient::class);
        $this->upsell = $this->createMock(UpsellServiceInterface::class);
        $this->emitter = new ChunkEmitter;

        $this->executor = new ToolExecutor(
            $this->storefront,
            $this->customer,
            $this->emitter,
            $this->upsell,
        );

        config(['chatbot.mcp.cache_ttl_seconds' => [
            'search_catalog' => 120,
            'get_product' => 300,
            'lookup_catalog' => 300,
            'search_shop_policies_and_faqs' => 900,
        ]]);
    }

    public function test_search_catalog_routes_to_storefront_and_emits_products_chunk(): void
    {
        $this->storefront
            ->expects($this->once())
            ->method('callTool')
            ->with('search_catalog', ['query' => 'tarot'], self::SHOP)
            ->willReturn([
                'products' => [[
                    'id' => 'p1', 'title' => 'Deck', 'handle' => 'deck',
                    'variants' => [['id' => 'v1', 'price' => '24.99']],
                    'currency_code' => 'GBP',
                ]],
            ]);

        $output = $this->invoke('search_catalog', ['query' => 'tarot']);

        $this->assertStringContainsString('"type":"products"', $output);
        $this->assertStringContainsString('"price_minor_units":2499', $output);
    }

    public function test_search_catalog_second_call_hits_cache(): void
    {
        $this->storefront
            ->expects($this->once())   // ← critical: only ONE upstream call
            ->method('callTool')
            ->willReturn(['products' => [['id' => 'p1', 'title' => 't', 'handle' => 'h', 'variants' => [['id' => 'v', 'price' => '1.00']]]]]);

        $this->invoke('search_catalog', ['query' => 'tarot']);
        $this->invoke('search_catalog', ['query' => 'tarot']);
    }

    public function test_get_product_emits_product_detail(): void
    {
        $this->storefront->method('callTool')->willReturn([
            'product' => [
                'id' => 'p9', 'title' => 'Crystal', 'handle' => 'crystal',
                'price' => '49.00', 'currency_code' => 'GBP',
                'variants' => [['id' => 'v1', 'price' => 49, 'available' => true]],
            ],
        ]);

        $output = $this->invoke('get_product_details', ['product_id' => 'p9']);

        $this->assertStringContainsString('"type":"product_detail"', $output);
        $this->assertStringContainsString('"price_minor_units":4900', $output);
    }

    public function test_update_cart_emits_cart_action_for_a_shown_variant(): void
    {
        // update_cart never calls Shopify — the storefront's own cart is the
        // single source of truth (ADR 0010). No mock expectation on
        // $this->storefront is set, so any callTool() invocation would fail
        // the test by itself.
        $ctx = $this->ctx(shownVariantIds: ['gid://shopify/ProductVariant/11' => true]);

        $output = $this->invoke('update_cart', [
            'items' => [['action' => 'add', 'variant_id' => 'gid://shopify/ProductVariant/11', 'quantity' => 2]],
        ], $ctx);

        $this->assertStringContainsString('"type":"cart_action"', $output);
        $this->assertStringContainsString('"variant_id":"gid://shopify/ProductVariant/11"', $output);
        $this->assertStringContainsString('"quantity":2', $output);
    }

    public function test_update_cart_rejects_a_variant_never_shown_or_in_cart(): void
    {
        ob_start();
        try {
            $result = $this->executor->execute(
                'update_cart',
                ['items' => [['action' => 'add', 'variant_id' => 'gid://shopify/ProductVariant/999', 'quantity' => 1]]],
                $this->ctx(),
            );
        } finally {
            ob_end_clean();
        }

        $this->assertFalse($result->isSuccess());
    }

    public function test_update_cart_allows_removing_a_variant_already_in_the_cart_snapshot(): void
    {
        $cart = CartContextDTO::fromArray([
            'id' => null,
            'item_count' => 1,
            'total_price' => '24.99',
            'currency' => 'GBP',
            'items' => [['variant_id' => 'gid://shopify/ProductVariant/11', 'quantity' => 1]],
        ]);

        $output = $this->invoke('update_cart', [
            'items' => [['action' => 'remove', 'variant_id' => 'gid://shopify/ProductVariant/11']],
        ], $this->ctx(cart: $cart));

        $this->assertStringContainsString('"type":"cart_action"', $output);
        $this->assertStringContainsString('"action":"remove"', $output);
    }

    public function test_policy_query_emits_policy_answer(): void
    {
        $this->storefront->method('callTool')->willReturn([
            'answer' => 'Returns within 30 days.',
            'citations' => [['title' => 'Refunds', 'url' => 'https://demo/policies/refund']],
        ]);

        $output = $this->invoke('search_shop_policies_and_faqs', ['query' => 'returns?']);

        $this->assertStringContainsString('"type":"policy_answer"', $output);
        $this->assertStringContainsString('Returns within 30 days.', $output);
    }

    public function test_get_order_status_without_auth_emits_auth_required(): void
    {
        $this->customer->expects($this->never())->method('callTool');

        $output = $this->invoke('get_order_status', ['order_number' => '1234']);

        $this->assertStringContainsString('"type":"auth_required"', $output);
        $this->assertStringContainsString('"reason":"customer_account"', $output);
    }

    public function test_get_order_status_with_auth_emits_order_tracking(): void
    {
        $convo = AiConversation::factory()->create([
            'session_id' => self::SESSION_ID,
            'shop_domain' => self::SHOP,
        ]);
        AiCustomerSession::create([
            'session_id' => $convo->session_id,
            'customer_access_token' => 'shpca_active',
            'expires_at' => now()->addHour(),
        ]);

        $this->customer
            ->expects($this->once())
            ->method('callTool')
            ->with('get_order_status', ['order_number' => '1234'], self::SHOP, 'shpca_active')
            ->willReturn([
                'order' => ['name' => '#1234', 'fulfillment_status' => 'IN_TRANSIT', 'financial_status' => 'PAID'],
            ]);

        $output = $this->invoke('get_order_status', ['order_number' => '1234']);

        $this->assertStringContainsString('"type":"order_tracking"', $output);
        $this->assertStringContainsString('"status":"in_transit"', $output);
    }

    public function test_customer_mcp_401_falls_back_to_auth_required(): void
    {
        $convo = AiConversation::factory()->create([
            'session_id' => self::SESSION_ID, 'shop_domain' => self::SHOP,
        ]);
        AiCustomerSession::create([
            'session_id' => $convo->session_id,
            'customer_access_token' => 'expired',
            'expires_at' => now()->addHour(),
        ]);

        $this->customer
            ->method('callTool')
            ->willThrowException(new AuthRequiredException);

        $output = $this->invoke('get_most_recent_order_status', []);

        $this->assertStringContainsString('"type":"auth_required"', $output);
    }

    public function test_list_customer_orders_without_auth_emits_auth_required(): void
    {
        $this->customer->expects($this->never())->method('callTool');

        $output = $this->invoke('list_customer_orders', []);

        $this->assertStringContainsString('"type":"auth_required"', $output);
        $this->assertStringContainsString('"reason":"customer_account"', $output);
    }

    public function test_list_customer_orders_with_auth_emits_order_list(): void
    {
        $convo = AiConversation::factory()->create([
            'session_id' => self::SESSION_ID,
            'shop_domain' => self::SHOP,
        ]);
        AiCustomerSession::create([
            'session_id' => $convo->session_id,
            'customer_access_token' => 'shpca_active',
            'expires_at' => now()->addHour(),
        ]);

        $graph = $this->createMock(CustomerAccountGraphClient::class);
        $graph->expects($this->once())
            ->method('query')
            ->with(self::SHOP, 'shpca_active', $this->anything(), ['first' => 10, 'after' => null])
            ->willReturn([
                'customer' => [
                    'orders' => [
                        'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'CURSOR_2'],
                        'edges' => [
                            ['node' => [
                                'name' => '#22467', 'number' => 22467,
                                'processedAt' => '2026-06-23T10:52:00Z',
                                'fulfillmentStatus' => 'UNFULFILLED', 'financialStatus' => 'PAID',
                                'totalPrice' => ['amount' => '0.00', 'currencyCode' => 'GBP'],
                                'statusPageUrl' => 'https://scottstonebridge.com/account/orders/adc11bfa',
                            ]],
                            ['node' => [
                                'name' => '#22001', 'number' => 22001,
                                'processedAt' => '2026-05-01T09:00:00Z',
                                'fulfillmentStatus' => 'FULFILLED', 'financialStatus' => 'PAID',
                                'totalPrice' => ['amount' => '25.00', 'currencyCode' => 'GBP'],
                                'statusPageUrl' => 'https://scottstonebridge.com/account/orders/bbb222',
                            ]],
                        ],
                    ],
                ],
            ]);

        $executor = new ToolExecutor(
            $this->storefront,
            $this->customer,
            $this->emitter,
            $this->upsell,
            null,
            $graph,
        );

        ob_start();
        try {
            $executor->execute('list_customer_orders', [], $this->ctx());
        } finally {
            $output = (string) ob_get_clean();
        }

        $this->assertStringContainsString('"type":"order_list"', $output);
        $this->assertStringContainsString('"order_number":"22467"', $output);
        $this->assertStringContainsString('"status":"delivered"', $output);
        $this->assertStringContainsString('"order_url":"https://scottstonebridge.com/account/orders/adc11bfa"', $output);
        $this->assertStringContainsString('"has_next_page":true', $output);
        $this->assertStringContainsString('"cursor":"CURSOR_2"', $output);
    }

    public function test_expired_access_token_is_refreshed_as_public_client(): void
    {
        $convo = AiConversation::factory()->create([
            'session_id' => self::SESSION_ID,
            'shop_domain' => self::SHOP,
        ]);
        $session = AiCustomerSession::create([
            'session_id' => $convo->session_id,
            'customer_access_token' => 'shcat_expired',
            'refresh_token' => 'shcrt_valid',
            'expires_at' => now()->subMinutes(5),
            'refresh_token_expires_at' => now()->addDays(13),
        ]);

        config(['chatbot.oauth.client_id' => 'client-123']);

        Http::fake([
            'demo.myshopify.com/.well-known/openid-configuration' => Http::response([
                'authorization_endpoint' => 'https://shopify.com/authentication/1/oauth/authorize',
                'token_endpoint' => 'https://shopify.com/authentication/1/oauth/token',
            ]),
            'shopify.com/authentication/1/oauth/token' => Http::response([
                'access_token' => 'shcat_refreshed',
                'refresh_token' => 'shcrt_rotated',
                'expires_in' => 3600,
                'refresh_token_expires_in' => 1123200,
            ]),
        ]);

        $graph = $this->createMock(CustomerAccountGraphClient::class);
        $graph->expects($this->once())
            ->method('query')
            ->with(self::SHOP, 'shcat_refreshed', $this->anything(), ['first' => 10, 'after' => null])
            ->willReturn([
                'customer' => [
                    'orders' => [
                        'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                        'edges' => [
                            ['node' => [
                                'name' => '#30001', 'number' => 30001,
                                'processedAt' => '2026-06-01T00:00:00Z',
                                'fulfillmentStatus' => 'FULFILLED', 'financialStatus' => 'PAID',
                                'totalPrice' => ['amount' => '10.00', 'currencyCode' => 'GBP'],
                                'statusPageUrl' => 'https://scottstonebridge.com/account/orders/x',
                            ]],
                        ],
                    ],
                ],
            ]);

        $executor = new ToolExecutor(
            $this->storefront,
            $this->customer,
            $this->emitter,
            $this->upsell,
            null,
            $graph,
        );

        ob_start();
        try {
            $executor->execute('list_customer_orders', [], $this->ctx());
        } finally {
            $output = (string) ob_get_clean();
        }

        // Order list rendered off the silently-refreshed access token.
        $this->assertStringContainsString('"type":"order_list"', $output);

        // Refreshed + rotated tokens persisted.
        $session->refresh();
        $this->assertSame('shcat_refreshed', $session->customer_access_token);
        $this->assertSame('shcrt_rotated', $session->refresh_token);
        $this->assertTrue($session->expires_at->isFuture());

        // Shopify Customer Account API refresh is a PUBLIC client grant —
        // client_id in the body, NEVER HTTP Basic auth (that returns 401).
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/oauth/token')) {
                return false;
            }
            $data = $request->data();

            return ($data['grant_type'] ?? null) === 'refresh_token'
                && ($data['client_id'] ?? null) === 'client-123'
                && ($data['refresh_token'] ?? null) === 'shcrt_valid'
                && ! $request->hasHeader('Authorization');
        });
    }

    public function test_start_checkout_emits_checkout_action(): void
    {
        // start_checkout no longer calls Shopify — the storefront navigates
        // to its own /checkout for whatever cart currently exists (ADR 0010).
        // No mock expectation on $this->storefront is set, so any callTool()
        // invocation would fail the test by itself.
        $output = $this->invoke('start_checkout', []);

        $this->assertStringContainsString('"type":"checkout_action"', $output);
        $this->assertStringContainsString('"path":"/checkout"', $output);
    }

    public function test_get_cart_reads_from_storefront_snapshot_without_calling_shopify(): void
    {
        $cart = CartContextDTO::fromArray([
            'id' => null,
            'item_count' => 2,
            'total_price' => '64.50',
            'currency' => 'GBP',
            'items' => [['title' => 'The Fool Tarot', 'quantity' => 2, 'variant_id' => 'gid://shopify/ProductVariant/11']],
        ]);

        // get_cart doesn't emit any SSE chunk — the frontend already knows
        // its own live cart (that's where this snapshot came from). It only
        // needs to answer the model in text. No mock expectation on
        // $this->storefront is set, so any callTool() call would fail this
        // test by itself.
        $result = $this->executor->execute('get_cart', [], $this->ctx(cart: $cart));

        $this->assertTrue($result->isSuccess());
        $this->assertStringContainsString('The Fool Tarot x2', $result->messageForAi);
        $this->assertStringContainsString('GBP 64.50', $result->messageForAi);
    }

    public function test_get_cart_reports_empty_when_no_snapshot_sent(): void
    {
        $result = $this->executor->execute('get_cart', [], $this->ctx());

        $this->assertTrue($result->isSuccess());
        $this->assertStringContainsString('empty', $result->messageForAi);
    }

    public function test_suggest_upsell_reads_cart_items_from_storefront_snapshot(): void
    {
        $cart = CartContextDTO::fromArray([
            'id' => null,
            'item_count' => 1,
            'total_price' => '24.99',
            'currency' => 'GBP',
            'items' => [['product_id' => 'gid://shopify/Product/1', 'quantity' => 1]],
        ]);

        $this->upsell->expects($this->once())
            ->method('getUpsells')
            ->with([['product_id' => 'gid://shopify/Product/1', 'quantity' => 1]], self::SHOP, 'GBP')
            ->willReturn([]);

        // No mock expectation on $this->storefront is set, so any callTool()
        // invocation (the old get_cart round-trip) would fail the test.
        $output = $this->invoke('suggest_upsell', [], $this->ctx(cart: $cart));

        $this->assertStringContainsString('"type":"upsell_offer"', $output);
    }

    public function test_suggest_upsell_errors_on_empty_cart(): void
    {
        ob_start();
        try {
            $result = $this->executor->execute('suggest_upsell', [], $this->ctx());
        } finally {
            ob_end_clean();
        }

        $this->assertFalse($result->isSuccess());
    }

    public function test_suggest_quick_replies_emits_quick_replies(): void
    {
        $output = $this->invoke('suggest_quick_replies', ['replies' => ['Tell me more', 'Add to cart']]);

        $this->assertStringContainsString('"type":"quick_replies"', $output);
        $this->assertStringContainsString('Tell me more', $output);
    }

    public function test_suggest_quick_replies_rejects_single_reply(): void
    {
        $output = $this->invoke('suggest_quick_replies', ['replies' => ['only one']]);

        $this->assertSame('', $output);
    }

    public function test_internal_tools_skip_rate_limit_bucket(): void
    {
        // Burn the bucket — internal tools must still work.
        for ($i = 0; $i < 65; $i++) {
            Cache::increment(sprintf('ai:rate:%s:mcp', self::SESSION_ID));
        }

        ob_start();
        try {
            $result = $this->executor->execute(
                'suggest_quick_replies',
                ['replies' => ['a', 'b']],
                $this->ctx(),
            );
        } finally {
            ob_end_clean();
        }

        $this->assertTrue($result->isSuccess());
    }

    public function test_mcp_rate_limit_kicks_in_after_threshold(): void
    {
        $this->storefront->method('callTool')->willReturn(['products' => []]);

        // Pre-burn the bucket past 60.
        for ($i = 0; $i < 61; $i++) {
            Cache::increment(sprintf('ai:rate:%s:mcp', self::SESSION_ID));
        }

        $output = $this->invoke('search_catalog', ['query' => 'tarot']);

        $this->assertStringContainsString('too many requests', $output);
    }

    private function invoke(string $toolName, array $args, ?ChatSessionContext $ctx = null): string
    {
        ob_start();
        try {
            $result = $this->executor->execute($toolName, $args, $ctx ?? $this->ctx());
            $this->assertInstanceOf(ToolResult::class, $result);
        } finally {
            $output = (string) ob_get_clean();
        }

        return $output;
    }

    private function ctx(?CartContextDTO $cart = null, array $shownVariantIds = []): ChatSessionContext
    {
        return new ChatSessionContext(
            sessionId: self::SESSION_ID,
            shopDomain: self::SHOP,
            cartSnapshot: $cart,
            shownVariantIds: $shownVariantIds,
        );
    }
}
