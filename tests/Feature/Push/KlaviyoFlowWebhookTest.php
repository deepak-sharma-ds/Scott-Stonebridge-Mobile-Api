<?php

declare(strict_types=1);

namespace Tests\Feature\Push;

use App\Jobs\Push\SendPushToRecipientJob;
use App\Models\KlaviyoWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class KlaviyoFlowWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'push.enabled' => true,
            'push.klaviyo.webhook_auth_enabled' => true,
            'push.klaviyo.webhook_secret' => 'secret-123',
            'push.test_emails' => [],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'email' => 'fan@example.com',
            'flow_id' => 'FLOW1',
            'message_id' => 'MSG1',
            'title' => 'A message from Scott',
            'body' => 'Your reading is ready',
            'deep_link' => 'app://readings',
        ], $overrides);
    }

    public function test_valid_secret_accepts_and_queues_push(): void
    {
        Queue::fake();

        $response = $this->withHeaders(['X-Klaviyo-Webhook-Secret' => 'secret-123'])
            ->postJson('/webhook/klaviyo-flow-email', $this->payload());

        $response->assertStatus(200);
        Queue::assertPushed(SendPushToRecipientJob::class);
        $this->assertDatabaseHas('klaviyo_webhook_events', ['recipient_email' => 'fan@example.com']);
    }

    public function test_wrong_secret_rejected(): void
    {
        Queue::fake();

        $this->withHeaders(['X-Klaviyo-Webhook-Secret' => 'wrong'])
            ->postJson('/webhook/klaviyo-flow-email', $this->payload())
            ->assertStatus(401);

        Queue::assertNothingPushed();
    }

    public function test_duplicate_within_hour_is_idempotent(): void
    {
        Queue::fake();

        $headers = ['X-Klaviyo-Webhook-Secret' => 'secret-123'];
        $this->withHeaders($headers)->postJson('/webhook/klaviyo-flow-email', $this->payload())->assertStatus(200);
        $this->withHeaders($headers)->postJson('/webhook/klaviyo-flow-email', $this->payload())->assertStatus(200);

        $this->assertSame(1, KlaviyoWebhookEvent::count());
        Queue::assertPushed(SendPushToRecipientJob::class, 1);
    }

    public function test_missing_email_returns_200_without_queue(): void
    {
        Queue::fake();

        $this->withHeaders(['X-Klaviyo-Webhook-Secret' => 'secret-123'])
            ->postJson('/webhook/klaviyo-flow-email', $this->payload(['email' => '']))
            ->assertStatus(200);

        Queue::assertNothingPushed();
    }

    public function test_kill_switch_makes_endpoint_inert(): void
    {
        config(['push.enabled' => false]);
        Queue::fake();

        $this->withHeaders(['X-Klaviyo-Webhook-Secret' => 'secret-123'])
            ->postJson('/webhook/klaviyo-flow-email', $this->payload())
            ->assertStatus(200);

        Queue::assertNothingPushed();
        $this->assertSame(0, KlaviyoWebhookEvent::count());
    }
}
