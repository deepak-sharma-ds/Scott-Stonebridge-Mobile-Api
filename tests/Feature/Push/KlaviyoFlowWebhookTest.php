<?php

declare(strict_types=1);

namespace Tests\Feature\Push;

use App\Jobs\Push\SendPushToRecipientJob;
use App\Models\KlaviyoWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    public function test_missing_title_body_fetches_real_content_from_klaviyo(): void
    {
        Queue::fake();
        Http::fake([
            'a.klaviyo.com/api/flow-messages/MSG1*' => Http::response([
                'data' => [
                    'type' => 'flow-message',
                    'id' => 'MSG1',
                    'attributes' => [
                        'content' => [
                            'subject' => 'You left something in your basket',
                            'preview_text' => 'You can return to your basket at any time.',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $payload = $this->payload();
        unset($payload['title'], $payload['body']);

        $this->withHeaders(['X-Klaviyo-Webhook-Secret' => 'secret-123'])
            ->postJson('/webhook/klaviyo-flow-email', $payload)
            ->assertStatus(200);

        Queue::assertPushed(SendPushToRecipientJob::class, function (SendPushToRecipientJob $job) {
            return $job->title === 'You left something in your basket'
                && $job->body === 'You can return to your basket at any time.';
        });
    }

    public function test_blank_title_body_are_treated_as_missing(): void
    {
        Queue::fake();
        Http::fake([
            'a.klaviyo.com/api/flow-messages/MSG1*' => Http::response([
                'data' => [
                    'type' => 'flow-message',
                    'id' => 'MSG1',
                    'attributes' => ['content' => ['subject' => 'Real subject', 'preview_text' => 'Real preview']],
                ],
            ], 200),
        ]);

        $this->withHeaders(['X-Klaviyo-Webhook-Secret' => 'secret-123'])
            ->postJson('/webhook/klaviyo-flow-email', $this->payload(['title' => '  ', 'body' => '']))
            ->assertStatus(200);

        Queue::assertPushed(SendPushToRecipientJob::class, function (SendPushToRecipientJob $job) {
            return $job->title === 'Real subject' && $job->body === 'Real preview';
        });
    }

    public function test_klaviyo_fetch_failure_falls_back_to_defaults(): void
    {
        config([
            'push.defaults.title' => 'Default Title',
            'push.defaults.body' => 'Default Body',
        ]);
        Queue::fake();
        Http::fake([
            'a.klaviyo.com/api/flow-messages/MSG1*' => Http::response(['errors' => [['status' => 404]]], 404),
        ]);

        $payload = $this->payload();
        unset($payload['title'], $payload['body']);

        $this->withHeaders(['X-Klaviyo-Webhook-Secret' => 'secret-123'])
            ->postJson('/webhook/klaviyo-flow-email', $payload)
            ->assertStatus(200);

        Queue::assertPushed(SendPushToRecipientJob::class, function (SendPushToRecipientJob $job) {
            return $job->title === 'Default Title' && $job->body === 'Default Body';
        });
    }

    public function test_duplicate_preview_text_falls_back_to_template_excerpt(): void
    {
        Queue::fake();
        Http::fake([
            'a.klaviyo.com/api/flow-messages/MSG1*' => Http::response([
                'data' => [
                    'type' => 'flow-message',
                    'id' => 'MSG1',
                    'attributes' => [
                        'content' => [
                            'subject' => 'You left something in your basket',
                            'preview_text' => 'You left something in your basket',
                        ],
                    ],
                    'relationships' => [
                        'template' => ['data' => ['type' => 'template', 'id' => 'TPL1']],
                    ],
                ],
            ], 200),
            'a.klaviyo.com/api/templates/TPL1*' => Http::response([
                'data' => [
                    'type' => 'template',
                    'id' => 'TPL1',
                    'attributes' => [
                        'text' => "[Logo](http://example.com)\n\nYou left something in your basket\n\nHi {{ person.first_name }},\n\nYour cart is still waiting for you, come back any time.",
                    ],
                ],
            ], 200),
        ]);

        $payload = $this->payload();
        unset($payload['title'], $payload['body']);

        $this->withHeaders(['X-Klaviyo-Webhook-Secret' => 'secret-123'])
            ->postJson('/webhook/klaviyo-flow-email', $payload)
            ->assertStatus(200);

        Queue::assertPushed(SendPushToRecipientJob::class, function (SendPushToRecipientJob $job) {
            return $job->title === 'You left something in your basket'
                && $job->body === 'Your cart is still waiting for you, come back any time.';
        });
    }

    public function test_explicit_title_body_skip_klaviyo_fetch(): void
    {
        Queue::fake();
        Http::fake();

        $this->withHeaders(['X-Klaviyo-Webhook-Secret' => 'secret-123'])
            ->postJson('/webhook/klaviyo-flow-email', $this->payload())
            ->assertStatus(200);

        Http::assertNothingSent();
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
