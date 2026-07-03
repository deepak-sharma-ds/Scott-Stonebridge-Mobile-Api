<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AI;

use App\Models\AiConversation;
use App\Models\AiCustomerSession;
use App\Services\AI\CustomerPersonalizationService;
use App\Services\AI\MCP\CustomerAccountGraphClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * E1 — signed-in order summary. Uses a valid token read-only, NEVER refreshes,
 * and fetches no PII beyond order number / total / date.
 */
class CustomerPersonalizationServiceTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP = 'demo.myshopify.com';

    private const SESSION = 'sess-personalise-1';

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_returns_summary_for_valid_token(): void
    {
        AiConversation::factory()->create(['session_id' => self::SESSION]);
        AiCustomerSession::create([
            'session_id' => self::SESSION,
            'customer_access_token' => 'shcat_valid',
            'expires_at' => now()->addHour(),
        ]);

        $graph = Mockery::mock(CustomerAccountGraphClient::class);
        $graph->shouldReceive('query')->once()->andReturn([
            'customer' => ['orders' => ['edges' => [
                ['node' => ['number' => '2201', 'processedAt' => '2026-06-01T10:00:00Z', 'totalPrice' => ['amount' => '42.00', 'currencyCode' => 'GBP']]],
                ['node' => ['number' => '2180', 'processedAt' => '2026-05-01T10:00:00Z', 'totalPrice' => ['amount' => '19.99', 'currencyCode' => 'GBP']]],
            ]]],
        ]);

        $service = new CustomerPersonalizationService($graph);
        $summary = $service->summaryFor(self::SESSION, self::SHOP);

        $this->assertIsArray($summary);
        $this->assertSame(2, $summary['order_count']);
        $this->assertSame('2201', $summary['recent_orders'][0]['number']);
        $this->assertSame('42.00', $summary['recent_orders'][0]['total']);
        $this->assertSame('2026-06-01', $summary['recent_orders'][0]['date']);
    }

    public function test_skips_and_never_refreshes_when_token_expired(): void
    {
        AiConversation::factory()->create(['session_id' => self::SESSION]);
        AiCustomerSession::create([
            'session_id' => self::SESSION,
            'customer_access_token' => 'shcat_expired',
            'expires_at' => now()->subMinute(),
        ]);

        $graph = Mockery::mock(CustomerAccountGraphClient::class);
        // Expired token must NOT trigger any Customer Account API call —
        // personalisation must never spend a single-use refresh token.
        $graph->shouldNotReceive('query');

        $service = new CustomerPersonalizationService($graph);

        $this->assertNull($service->summaryFor(self::SESSION, self::SHOP));
    }

    public function test_returns_null_when_no_session(): void
    {
        $graph = Mockery::mock(CustomerAccountGraphClient::class);
        $graph->shouldNotReceive('query');

        $service = new CustomerPersonalizationService($graph);

        $this->assertNull($service->summaryFor('missing-session', self::SHOP));
    }
}
