<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Sales;

use App\Models\AiLead;
use App\Services\Sales\LeadCaptureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit coverage for LeadCaptureService — DB-backed because the service is
 * intentionally a thin Eloquent layer (no business logic beyond status
 * transition gating + dedupe). The controller test covers integration; this
 * suite is for the deterministic state-machine edges.
 */
class LeadCaptureServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_capture_returns_false_for_blank_required_args(): void
    {
        $service = new LeadCaptureService;

        $this->assertFalse($service->capture('', 'demo.myshopify.com', 'a@b.com'));
        $this->assertFalse($service->capture('sess', '', 'a@b.com'));
        $this->assertFalse($service->capture('sess', 'demo.myshopify.com', ''));
    }

    public function test_capture_persists_with_normalised_email(): void
    {
        $service = new LeadCaptureService;

        $lead = $service->capture('sess-cap', 'demo.myshopify.com', '  USER@Example.COM  ');

        $this->assertNotFalse($lead);
        $this->assertSame('user@example.com', $lead->email);
        $this->assertSame(AiLead::STATUS_NEW, $lead->status);
        $this->assertSame(AiLead::SOURCE_MANUAL_INPUT, $lead->source);
    }

    public function test_capture_returns_false_on_duplicate(): void
    {
        $service = new LeadCaptureService;

        $first = $service->capture('sess-dup', 'demo.myshopify.com', 'foo@example.com');
        $second = $service->capture('sess-dup', 'demo.myshopify.com', 'foo@example.com');

        $this->assertNotFalse($first);
        $this->assertFalse($second);
        $this->assertSame(1, AiLead::query()->where('email', 'foo@example.com')->count());
    }

    public function test_is_captured_returns_false_for_blank_session(): void
    {
        $service = new LeadCaptureService;
        $this->assertFalse($service->isCaptured(''));
    }

    public function test_is_captured_reflects_persisted_state(): void
    {
        $service = new LeadCaptureService;
        $this->assertFalse($service->isCaptured('new-sess'));

        $service->capture('new-sess', 'demo.myshopify.com', 'has@example.com');
        $this->assertTrue($service->isCaptured('new-sess'));
    }

    public function test_update_status_stamps_recovery_sent_at_only_on_recovery_transition(): void
    {
        $service = new LeadCaptureService;
        $lead = $service->capture('sess-status', 'demo.myshopify.com', 'st@example.com');
        $this->assertNotFalse($lead);

        $service->updateStatus((int) $lead->id, AiLead::STATUS_RECOVERY_SENT);
        $lead->refresh();
        $this->assertSame(AiLead::STATUS_RECOVERY_SENT, $lead->status);
        $this->assertNotNull($lead->recovery_sent_at);

        // Transitioning to converted preserves the timestamp.
        $original = $lead->recovery_sent_at;
        $service->updateStatus((int) $lead->id, AiLead::STATUS_CONVERTED);
        $lead->refresh();
        $this->assertSame(AiLead::STATUS_CONVERTED, $lead->status);
        $this->assertNotNull($lead->recovery_sent_at);
        $this->assertTrue($lead->recovery_sent_at->equalTo($original));
    }

    public function test_update_status_is_noop_for_unknown_status_or_zero_id(): void
    {
        $service = new LeadCaptureService;
        $lead = $service->capture('sess-noop', 'demo.myshopify.com', 'noop@example.com');
        $this->assertNotFalse($lead);

        $service->updateStatus((int) $lead->id, 'not_a_real_status');
        $lead->refresh();
        $this->assertSame(AiLead::STATUS_NEW, $lead->status);

        $service->updateStatus(0, AiLead::STATUS_RECOVERY_SENT);
        // Existing lead untouched.
        $this->assertSame(AiLead::STATUS_NEW, $lead->refresh()->status);
    }
}
