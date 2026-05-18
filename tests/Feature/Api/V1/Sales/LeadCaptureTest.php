<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Sales;

use App\Jobs\AI\StoreAnalyticsJob;
use App\Models\AiConversation;
use App\Models\AiLead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Phase B — POST /api/v1/ai/leads/capture coverage.
 *
 * Endpoint must:
 *   - persist a new (session, email) lead and return 201 with the resource
 *   - return 200 + duplicate=true on a same-session re-submit (no row inserted)
 *   - reject unknown session_id and invalid email at validation time
 *   - dispatch the funnel analytics event on success only
 */
class LeadCaptureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_captures_new_lead_with_cart_snapshot(): void
    {
        Queue::fake();
        $conversation = AiConversation::factory()->create();

        $response = $this->postJson('/api/v1/ai/leads/capture', [
            'session_id' => $conversation->session_id,
            'shop_domain' => $conversation->shop_domain,
            'email' => 'cust@example.com',
            'name' => 'Jane Doe',
            'cart_snapshot' => [
                'items' => [['quantity' => 1]],
                'item_count' => 2,
                'total_price' => 49.99,
            ],
            'source' => AiLead::SOURCE_MANUAL_INPUT,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.captured', true);
        $response->assertJsonPath('data.email', 'cust@example.com');
        $response->assertJsonPath('data.has_cart', true);
        $response->assertJsonPath('data.source', AiLead::SOURCE_MANUAL_INPUT);
        $response->assertJsonPath('data.status', AiLead::STATUS_NEW);

        $this->assertDatabaseHas('ai_leads', [
            'session_id' => $conversation->session_id,
            'email' => 'cust@example.com',
            'name' => 'Jane Doe',
            'source' => AiLead::SOURCE_MANUAL_INPUT,
            'status' => AiLead::STATUS_NEW,
        ]);

        Queue::assertPushed(
            StoreAnalyticsJob::class,
            fn (StoreAnalyticsJob $job): bool => $job->event === 'lead_captured'
                && $job->sessionId === $conversation->session_id
        );
    }

    public function test_duplicate_session_email_returns_200_and_does_not_dispatch_analytics(): void
    {
        Queue::fake();
        $conversation = AiConversation::factory()->create();

        AiLead::factory()
            ->forSession($conversation->session_id)
            ->create([
                'shop_domain' => $conversation->shop_domain,
                'email' => 'dup@example.com',
            ]);

        $response = $this->postJson('/api/v1/ai/leads/capture', [
            'session_id' => $conversation->session_id,
            'shop_domain' => $conversation->shop_domain,
            'email' => 'dup@example.com',
            'source' => AiLead::SOURCE_MANUAL_INPUT,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.captured', false);
        $response->assertJsonPath('data.duplicate', true);

        $this->assertSame(1, AiLead::query()->where('email', 'dup@example.com')->count());

        Queue::assertNotPushed(StoreAnalyticsJob::class);
    }

    public function test_email_capture_is_case_insensitive(): void
    {
        Queue::fake();
        $conversation = AiConversation::factory()->create();

        AiLead::factory()
            ->forSession($conversation->session_id)
            ->create([
                'shop_domain' => $conversation->shop_domain,
                'email' => 'mix@example.com',
            ]);

        $response = $this->postJson('/api/v1/ai/leads/capture', [
            'session_id' => $conversation->session_id,
            'shop_domain' => $conversation->shop_domain,
            'email' => 'MIX@Example.COM',
            'source' => AiLead::SOURCE_MANUAL_INPUT,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.duplicate', true);
        $this->assertSame(1, AiLead::query()->where('email', 'mix@example.com')->count());
    }

    public function test_rejects_invalid_email(): void
    {
        $conversation = AiConversation::factory()->create();

        $response = $this->postJson('/api/v1/ai/leads/capture', [
            'session_id' => $conversation->session_id,
            'shop_domain' => $conversation->shop_domain,
            'email' => 'not-an-email',
            'source' => AiLead::SOURCE_MANUAL_INPUT,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
    }

    public function test_rejects_unknown_session_id(): void
    {
        $response = $this->postJson('/api/v1/ai/leads/capture', [
            'session_id' => '00000000-0000-0000-0000-000000000000',
            'shop_domain' => 'demo.myshopify.com',
            'email' => 'ghost@example.com',
            'source' => AiLead::SOURCE_MANUAL_INPUT,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
    }

    public function test_rejects_invalid_source(): void
    {
        $conversation = AiConversation::factory()->create();

        $response = $this->postJson('/api/v1/ai/leads/capture', [
            'session_id' => $conversation->session_id,
            'shop_domain' => $conversation->shop_domain,
            'email' => 'foo@example.com',
            'source' => 'unknown_source',
        ]);

        $response->assertStatus(422);
    }

    public function test_accepts_lead_without_optional_fields(): void
    {
        Queue::fake();
        $conversation = AiConversation::factory()->create();

        $response = $this->postJson('/api/v1/ai/leads/capture', [
            'session_id' => $conversation->session_id,
            'shop_domain' => $conversation->shop_domain,
            'email' => 'minimal@example.com',
            'source' => AiLead::SOURCE_ESCALATION,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.captured', true);
        $response->assertJsonPath('data.has_cart', false);

        $this->assertDatabaseHas('ai_leads', [
            'email' => 'minimal@example.com',
            'source' => AiLead::SOURCE_ESCALATION,
            'name' => null,
            'cart_snapshot_json' => null,
        ]);
    }
}
