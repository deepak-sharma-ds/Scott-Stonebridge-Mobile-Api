<?php

declare(strict_types=1);

namespace Tests\Feature\Services\EmailReading;

use App\Mail\ComplianceHoldAdminMail;
use App\Mail\EmailReadingMail;
use App\Models\EmailReadingDelivery;
use App\Models\EmailReadingProduct;
use App\Models\User;
use App\Services\EmailReading\EmailReadingDeliveryLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Resources\Embeddings;
use OpenAI\Responses\Chat\CreateResponse as ChatResponse;
use OpenAI\Responses\Embeddings\CreateResponse as EmbeddingResponse;
use OpenAI\Responses\Moderations\CreateResponse as ModerationResponse;
use Tests\TestCase;

/**
 * End-to-end: Shopify webhook -> generation (compliance gate, freshness,
 * epistolary validation) -> dispatcher -> email, and the admin review path.
 * Queues run synchronously so the whole chain executes inside each test.
 */
class ReadingPipelineIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'shopify.webhook_inbox.connection' => 'sync',
            'email_reading.queue.connection' => 'sync',
            'email_reading.schedule.enabled' => false,
            'email_reading.fulfillment.enabled' => false,
            'email_reading.test_emails' => [],
            'email_reading.admin_notify_email' => 'ops@example.com',
        ]);
        Mail::fake();

        EmailReadingProduct::create([
            'shopify_product_id' => 111,
            'name' => 'Silver One Question',
            'slug' => 'silver',
            'questions_schema' => [['key' => 'Q1', 'label' => 'Question', 'required' => true]],
            'prompt_template' => 'Customer: {{ $customer_name }}. Question: {{ $q1 ?? "" }}',
            'email_subject' => 'Your reading',
            'is_active' => true,
            'is_automation_enabled' => true,
        ]);
    }

    private function placeOrder(string $question, int $orderId = 9001, int $lineItemId = 501): void
    {
        $this->postJson(route('webhook.shopify.reading'), [
            'id' => $orderId,
            'email' => 'buyer@example.com',
            'customer' => ['first_name' => 'Jane', 'last_name' => 'Doe'],
            'line_items' => [[
                'id' => $lineItemId,
                'product_id' => 111,
                'properties' => [['name' => 'Q1', 'value' => $question]],
            ]],
            'shipping_lines' => [],
        ], ['X-Shopify-Webhook-Id' => "wh-{$orderId}-{$lineItemId}"])->assertOk();
    }

    private function cleanModeration(): ModerationResponse
    {
        $categories = array_fill_keys(['hate', 'harassment', 'illicit', 'illicit/violent', 'self-harm', 'self-harm/intent', 'self-harm/instructions', 'sexual', 'violence'], false);

        return ModerationResponse::fake(['results' => [[
            'categories' => $categories,
            'category_scores' => array_map(fn () => 0.001, $categories),
            'flagged' => false,
        ]]]);
    }

    private function letter(string $card): ChatResponse
    {
        return ChatResponse::fake(['choices' => [['message' => ['role' => 'assistant', 'content' => "### Your Question\n\nThe {$card} sits at the heart of your reading, and it speaks of gentle change arriving in its own time.\n\nTrust the small steps you are already taking.\n\n### From My Heart to Yours\n\nWalk forward with warmth and confidence."], 'finish_reason' => 'stop']]]);
    }

    /**
     * @param  list<float>  $vector
     */
    private function embedding(array $vector): EmbeddingResponse
    {
        return EmbeddingResponse::fake(['data' => [['embedding' => $vector]]]);
    }

    private function dispatchDue(): int
    {
        return app(EmailReadingDeliveryLifecycle::class)->dispatchDue();
    }

    public function test_a_clean_order_becomes_an_epistolary_reading_and_is_emailed(): void
    {
        OpenAI::fake([$this->cleanModeration(), $this->letter('Moon'), $this->embedding([0.2, 0.8])]);

        $this->placeOrder('Will I find happiness in my new home?');

        $delivery = EmailReadingDelivery::firstOrFail();
        $this->assertSame(EmailReadingDelivery::STATUS_GENERATED, $delivery->status);
        $body = (string) $delivery->ai_response;
        $this->assertStringContainsString('The Moon', $body);
        $this->assertStringContainsString('From My Heart to Yours', $body);
        foreach (['<blockquote', '<ul', '<ol', '<table', '<hr'] as $artefact) {
            $this->assertStringNotContainsString($artefact, $body);
        }
        $this->assertSame('The Moon', $delivery->theme_summary);
        $this->assertEquals([0.2, 0.8], $delivery->embedding);
        Mail::assertNothingSent();

        $this->assertSame(1, $this->dispatchDue());

        $this->assertSame(EmailReadingDelivery::STATUS_SENT, $delivery->fresh()->status);
        Mail::assertSent(EmailReadingMail::class, fn (EmailReadingMail $mail): bool => $mail->hasTo('buyer@example.com'));
    }

    public function test_a_critical_order_is_held_alerts_the_admin_and_is_only_sent_after_review(): void
    {
        OpenAI::fake([$this->letter('Star'), $this->embedding([0.5, 0.5])]);

        $this->placeOrder('Do I have cancer? I just want to die.');

        $delivery = EmailReadingDelivery::firstOrFail();
        $this->assertSame(EmailReadingDelivery::STATUS_COMPLIANCE_HOLD, $delivery->status);
        $this->assertStringContainsString('Medical', (string) $delivery->compliance_flagged_reason);
        $this->assertNull($delivery->ai_response);
        OpenAI::assertNotSent(Chat::class);
        Mail::assertSent(ComplianceHoldAdminMail::class, fn (ComplianceHoldAdminMail $mail): bool => $mail->hasTo('ops@example.com'));
        $this->assertSame(0, $this->dispatchDue());
        Mail::assertNotSent(EmailReadingMail::class);

        $admin = User::factory()->create();
        $this->actingAs($admin)
            ->post(route('admin.email_readings.approve_compliance', $delivery))
            ->assertSessionHas('success');

        $delivery->refresh();
        $this->assertSame(EmailReadingDelivery::STATUS_GENERATED, $delivery->status);
        $this->assertNotNull($delivery->compliance_reviewed_at);
        $this->assertSame(1, $this->dispatchDue());
        Mail::assertSent(EmailReadingMail::class, fn (EmailReadingMail $mail): bool => $mail->hasTo('buyer@example.com'));
    }

    public function test_a_held_order_the_admin_cancels_is_never_emailed(): void
    {
        OpenAI::fake();

        $this->placeOrder('Should I stop taking my medication?');

        $delivery = EmailReadingDelivery::firstOrFail();
        $this->assertSame(EmailReadingDelivery::STATUS_COMPLIANCE_HOLD, $delivery->status);

        $this->actingAs(User::factory()->create())
            ->post(route('admin.email_readings.cancel', $delivery))
            ->assertSessionHas('success');

        $this->assertSame(EmailReadingDelivery::STATUS_CANCELLED, $delivery->fresh()->status);
        $this->assertSame(0, $this->dispatchDue());
        Mail::assertNotSent(EmailReadingMail::class);
    }

    public function test_a_repeat_customer_gets_the_theme_line_and_a_distinct_reading_with_its_embedding_stored(): void
    {
        $earlier = EmailReadingDelivery::create([
            'shopify_order_id' => 8000,
            'shopify_line_item_id' => 400,
            'email_reading_product_id' => EmailReadingProduct::firstOrFail()->id,
            'customer_email' => 'buyer@example.com',
            'customer_name' => 'Jane Doe',
            'questions' => [],
            'ai_response' => '<h3>Your Path</h3><p>The Star shines over the Three of Cups.</p>',
            'theme_summary' => 'The Star, Three of Cups',
            'embedding' => [1.0, 0.0],
            'status' => EmailReadingDelivery::STATUS_SENT,
            'sent_at' => now()->subWeek(),
        ]);
        OpenAI::fake([
            $this->cleanModeration(),
            $this->letter('Star'), $this->embedding([0.99, 0.1]),
            $this->letter('Tower'), $this->embedding([0.0, 1.0]),
        ]);

        $this->placeOrder('Will I find happiness in my new home?', 9002, 502);

        $delivery = EmailReadingDelivery::where('shopify_line_item_id', 502)->firstOrFail();
        $this->assertNotSame($earlier->id, $delivery->id);
        OpenAI::assertSent(Chat::class, 2);
        OpenAI::assertSent(Chat::class, fn (string $method, array $parameters): bool => str_contains(
            (string) collect($parameters['messages'])->firstWhere('role', 'user')['content'],
            'Previous reading for this client explored: The Star, Three of Cups.'
        ));
        OpenAI::assertSent(Chat::class, fn (string $method, array $parameters): bool => ($parameters['presence_penalty'] ?? null) === 0.5);
        OpenAI::assertSent(Embeddings::class, 2);

        $this->assertSame(EmailReadingDelivery::STATUS_GENERATED, $delivery->status);
        $this->assertStringContainsString('The Tower', (string) $delivery->ai_response);
        $this->assertStringNotContainsString('The Star', (string) $delivery->ai_response);
        $this->assertSame('The Tower', $delivery->theme_summary);
        $this->assertEquals([0.0, 1.0], $delivery->embedding);
    }
}
