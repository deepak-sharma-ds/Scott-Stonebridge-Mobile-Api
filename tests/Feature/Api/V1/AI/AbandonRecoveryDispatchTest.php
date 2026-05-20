<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\AI;

use App\Jobs\Sales\SendAbandonRecoveryEmailJob;
use App\Models\AiConversation;
use App\Models\AiLead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Phase B / Step 4 — POST /api/v1/ai/chat/end must dispatch
 * SendAbandonRecoveryEmailJob with a 30-minute delay when a captured lead
 * has a non-empty cart and is still in status='new'. Other paths must NOT
 * dispatch.
 */
class AbandonRecoveryDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_end_session_dispatches_recovery_job_with_delay_when_lead_has_cart(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-05-15 12:00:00');

        $conversation = AiConversation::factory()->create();
        $lead = AiLead::factory()
            ->forSession($conversation->session_id)
            ->withCart(itemCount: 2, totalPrice: 49.99)
            ->create(['shop_domain' => $conversation->shop_domain]);

        $response = $this->postJson('/api/v1/ai/chat/end', [
            'session_id' => $conversation->session_id,
        ]);

        $response->assertOk();

        Queue::assertPushed(
            SendAbandonRecoveryEmailJob::class,
            fn (SendAbandonRecoveryEmailJob $job): bool => $job->leadId === (int) $lead->id
                && $job->delay !== null
                && Carbon::parse($job->delay)->equalTo(Carbon::parse('2026-05-15 12:30:00'))
        );

        Carbon::setTestNow();
    }

    public function test_end_session_does_not_dispatch_when_no_lead_captured(): void
    {
        Queue::fake();
        $conversation = AiConversation::factory()->create();

        $response = $this->postJson('/api/v1/ai/chat/end', [
            'session_id' => $conversation->session_id,
        ]);

        $response->assertOk();
        Queue::assertNotPushed(SendAbandonRecoveryEmailJob::class);
    }

    public function test_end_session_does_not_dispatch_when_lead_has_no_cart(): void
    {
        Queue::fake();
        $conversation = AiConversation::factory()->create();
        AiLead::factory()
            ->forSession($conversation->session_id)
            ->create([
                'shop_domain' => $conversation->shop_domain,
                'cart_snapshot_json' => null,
            ]);

        $response = $this->postJson('/api/v1/ai/chat/end', [
            'session_id' => $conversation->session_id,
        ]);

        $response->assertOk();
        Queue::assertNotPushed(SendAbandonRecoveryEmailJob::class);
    }

    public function test_end_session_does_not_dispatch_when_lead_already_recovery_sent(): void
    {
        Queue::fake();
        $conversation = AiConversation::factory()->create();
        AiLead::factory()
            ->forSession($conversation->session_id)
            ->withCart()
            ->recoverySent()
            ->create(['shop_domain' => $conversation->shop_domain]);

        $response = $this->postJson('/api/v1/ai/chat/end', [
            'session_id' => $conversation->session_id,
        ]);

        $response->assertOk();
        Queue::assertNotPushed(SendAbandonRecoveryEmailJob::class);
    }

    public function test_end_session_does_not_dispatch_for_empty_cart_item_count(): void
    {
        Queue::fake();
        $conversation = AiConversation::factory()->create();
        AiLead::factory()
            ->forSession($conversation->session_id)
            ->create([
                'shop_domain' => $conversation->shop_domain,
                'cart_snapshot_json' => ['items' => [], 'item_count' => 0, 'total_price' => 0],
            ]);

        $response = $this->postJson('/api/v1/ai/chat/end', [
            'session_id' => $conversation->session_id,
        ]);

        $response->assertOk();
        Queue::assertNotPushed(SendAbandonRecoveryEmailJob::class);
    }
}
