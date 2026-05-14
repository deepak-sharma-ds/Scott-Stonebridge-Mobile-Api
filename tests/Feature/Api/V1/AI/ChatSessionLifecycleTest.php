<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\AI;

use App\Jobs\AI\GenerateConversationSummaryJob;
use App\Jobs\AI\NotifyEscalationJob;
use App\Models\AiConversation;
use App\Models\AiMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Covers the full session lifecycle endpoints (start / history / end /
 * escalate). Message + streaming flows are exercised in ChatMessageTest.
 */
class ChatSessionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_start_session_creates_active_conversation(): void
    {
        $response = $this->postJson('/api/v1/ai/chat/start', [
            'shop_domain' => 'demo.myshopify.com',
            'page_type' => 'product',
            'locale' => 'en',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => ['session_id', 'shop_domain', 'page_type', 'status', 'started_at'],
            'meta' => ['correlation_id', 'timestamp', 'version'],
        ]);

        $sessionId = $response->json('data.session_id');
        $this->assertIsString($sessionId);

        $this->assertDatabaseHas('ai_conversations', [
            'session_id' => $sessionId,
            'shop_domain' => 'demo.myshopify.com',
            'page_type' => 'product',
            'status' => AiConversation::STATUS_ACTIVE,
        ]);
    }

    public function test_history_endpoint_returns_paginated_messages(): void
    {
        $conversation = AiConversation::factory()->create();
        AiMessage::factory()->for($conversation, 'conversation')->user()->count(2)->create();
        AiMessage::factory()->for($conversation, 'conversation')->assistant()->count(1)->create();

        $response = $this->getJson('/api/v1/ai/chat/history/'.$conversation->session_id);

        $response->assertStatus(200);
        $response->assertJsonCount(3, 'data');
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [['id', 'role', 'message', 'usage']],
            'meta' => ['pagination' => ['current_page', 'per_page', 'last_page', 'total', 'has_more']],
        ]);
    }

    public function test_history_endpoint_returns_404_for_unknown_session(): void
    {
        $response = $this->getJson('/api/v1/ai/chat/history/00000000-0000-0000-0000-000000000000');

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
    }

    public function test_end_session_marks_conversation_ended_and_dispatches_summary_job(): void
    {
        Queue::fake();
        $conversation = AiConversation::factory()->create();

        $response = $this->postJson('/api/v1/ai/chat/end', [
            'session_id' => $conversation->session_id,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', AiConversation::STATUS_ENDED);

        $this->assertDatabaseHas('ai_conversations', [
            'id' => $conversation->id,
            'status' => AiConversation::STATUS_ENDED,
        ]);

        Queue::assertPushed(GenerateConversationSummaryJob::class);
    }

    public function test_escalate_marks_conversation_escalated_and_dispatches_notify_job(): void
    {
        Queue::fake();
        Mail::fake();
        $conversation = AiConversation::factory()->create();

        $response = $this->postJson('/api/v1/ai/chat/escalate', [
            'session_id' => $conversation->session_id,
            'reason' => 'Refund issue',
            'customer_email' => 'cust@example.com',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', AiConversation::STATUS_ESCALATED);

        Queue::assertPushed(NotifyEscalationJob::class);
    }

    public function test_start_session_rejects_missing_shop_domain(): void
    {
        $response = $this->postJson('/api/v1/ai/chat/start', []);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
    }
}
