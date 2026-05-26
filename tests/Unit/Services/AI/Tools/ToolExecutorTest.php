<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AI\Tools;

use App\Contracts\Services\Sales\UpsellServiceInterface;
use App\Exceptions\AI\AuthRequiredException;
use App\Models\AiConversation;
use App\Models\AiCustomerSession;
use App\Services\AI\ChatSessionContext;
use App\Services\AI\MCP\CustomerMcpClient;
use App\Services\AI\MCP\StorefrontMcpClient;
use App\Services\AI\Streaming\ChunkEmitter;
use App\Services\AI\Tools\ToolExecutor;
use App\Services\AI\Tools\ToolResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

    public function test_update_cart_emits_cart_state(): void
    {
        $this->storefront->method('callTool')->willReturn([
            'cart' => [
                'id' => 'c1', 'currency_code' => 'GBP', 'checkout_url' => 'https://demo/checkout',
                'lines' => [['merchandise_id' => 'v1', 'quantity' => 1, 'cost' => ['total' => ['amount' => '10.00']]]],
            ],
        ]);

        $output = $this->invoke('update_cart', ['cart_id' => 'c1', 'lines' => [['merchandise_id' => 'v1', 'quantity' => 1]]]);

        $this->assertStringContainsString('"type":"cart_state"', $output);
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

    public function test_start_checkout_synthesises_checkout_link_from_cart(): void
    {
        // start_checkout is internal — it calls get_cart upstream and surfaces
        // the cart's hosted checkout URL.
        $this->storefront
            ->expects($this->once())
            ->method('callTool')
            ->with('get_cart', ['cart_id' => 'c1'], self::SHOP)
            ->willReturn([
                'cart' => [
                    'id' => 'c1',
                    'total_quantity' => 2,
                    'cost' => ['subtotal_amount' => ['amount' => '64.50', 'currency' => 'GBP']],
                    'checkout_url' => 'https://demo.myshopify.com/checkouts/xyz',
                ],
            ]);

        $output = $this->invoke('start_checkout', ['cart_id' => 'c1']);

        $this->assertStringContainsString('"type":"checkout_link"', $output);
        $this->assertStringContainsString('"checkout_url":"https://demo.myshopify.com/checkouts/xyz"', $output);
        $this->assertStringContainsString('"total_amount":64.5', $output);
        $this->assertStringContainsString('"currency":"GBP"', $output);
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

    private function invoke(string $toolName, array $args): string
    {
        ob_start();
        try {
            $result = $this->executor->execute($toolName, $args, $this->ctx());
            $this->assertInstanceOf(ToolResult::class, $result);
        } finally {
            $output = (string) ob_get_clean();
        }

        return $output;
    }

    private function ctx(): ChatSessionContext
    {
        return new ChatSessionContext(
            sessionId: self::SESSION_ID,
            shopDomain: self::SHOP,
        );
    }
}
