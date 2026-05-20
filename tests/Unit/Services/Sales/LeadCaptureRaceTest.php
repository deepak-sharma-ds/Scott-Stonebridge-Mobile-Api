<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Sales;

use App\Models\AiLead;
use App\Services\Sales\LeadCaptureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Regression coverage for the SELECT-then-INSERT race in
 * LeadCaptureService::capture(). The previous implementation logged a
 * `Lead capture race or constraint violation` warning every time the
 * happy duplicate path landed in the catch block. The post-fix code uses
 * firstOrCreate so duplicates return false silently with no log noise.
 */
class LeadCaptureRaceTest extends TestCase
{
    use RefreshDatabase;

    private LeadCaptureService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new LeadCaptureService;
    }

    public function test_duplicate_session_email_returns_false_without_warning_log(): void
    {
        AiLead::factory()->create([
            'session_id' => 'race-session',
            'email' => 'dup@example.com',
            'shop_domain' => 'demo.myshopify.com',
            'status' => AiLead::STATUS_NEW,
        ]);

        Log::shouldReceive('warning')->never();
        Log::shouldReceive('info')->never();
        // Allow other channel calls without strict expectations.
        Log::shouldReceive('channel')->andReturnSelf();

        $result = $this->svc->capture(
            sessionId: 'race-session',
            shopDomain: 'demo.myshopify.com',
            email: 'dup@example.com',
        );

        $this->assertFalse($result);
        $this->assertSame(1, AiLead::query()->count(), 'No duplicate row written.');
    }

    public function test_first_capture_succeeds_and_returns_lead(): void
    {
        $lead = $this->svc->capture(
            sessionId: 'fresh-session',
            shopDomain: 'demo.myshopify.com',
            email: 'fresh@example.com',
            name: 'Fresh Lead',
            cartSnapshot: ['item_count' => 1, 'total' => 9.99],
            source: AiLead::SOURCE_PROACTIVE_TRIGGER,
        );

        $this->assertInstanceOf(AiLead::class, $lead);
        $this->assertSame('fresh@example.com', $lead->email);
        $this->assertSame(AiLead::STATUS_NEW, $lead->status);
        $this->assertNotNull($lead->cart_snapshot_json);
    }

    public function test_case_and_whitespace_normalised_on_email(): void
    {
        $first = $this->svc->capture(
            sessionId: 'norm-session',
            shopDomain: 'demo.myshopify.com',
            email: '  Mixed@Example.COM  ',
        );

        $second = $this->svc->capture(
            sessionId: 'norm-session',
            shopDomain: 'demo.myshopify.com',
            email: 'mixed@example.com',
        );

        $this->assertInstanceOf(AiLead::class, $first);
        $this->assertFalse($second, 'Normalised duplicate must be detected.');
        $this->assertSame(1, AiLead::query()->count());
    }
}
