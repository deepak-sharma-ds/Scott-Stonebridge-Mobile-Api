<?php

namespace Tests\Feature\Services\EmailReading;

use App\Jobs\EmailReading\WriteHistoryDigestJob;
use App\Models\EmailReadingDelivery;
use App\Models\EmailReadingProduct;
use App\Services\EmailReading\EmailReadingDeliveryLifecycle;
use App\Services\EmailReading\HistoryDigestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse as ChatResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * The History Digest: written once, in the background, after a delivery is
 * sent, from the response as sent. It never blocks or fails sending.
 */
class HistoryDigestTest extends TestCase
{
    use RefreshDatabase;

    private const DIGEST = 'Scott told the client that a change of direction is coming and drew the Moon.';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'email_reading.history.digest.enabled' => true,
            'email_reading.queue.connection' => 'sync',
            'email_reading.fulfillment.enabled' => false,
        ]);
    }

    private function delivery(array $overrides = []): EmailReadingDelivery
    {
        static $lineItem = 5000;

        $product = EmailReadingProduct::firstOrCreate(['shopify_product_id' => 111], [
            'name' => 'Love Reading',
            'slug' => 'love-reading',
            'questions_schema' => [],
            'prompt_template' => 'Write a reading for {{ $customer_name }}.',
            'email_subject' => 'Your reading',
            'is_active' => true,
            'is_automation_enabled' => true,
        ]);

        return EmailReadingDelivery::create(array_merge([
            'shopify_order_id' => 9001,
            'shopify_line_item_id' => $lineItem++,
            'email_reading_product_id' => $product->id,
            'customer_email' => 'buyer@example.com',
            'customer_name' => 'Jane Doe',
            'questions' => [],
            'ai_response' => '<h3>Your Path</h3><p>The Moon shows a <strong>change of direction</strong>.</p>',
            'status' => EmailReadingDelivery::STATUS_SENT,
            'sent_at' => now(),
        ], $overrides));
    }

    private function digestResponse(string $content = self::DIGEST): ChatResponse
    {
        return ChatResponse::fake(['choices' => [['message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop']]]);
    }

    private function lifecycle(): EmailReadingDeliveryLifecycle
    {
        return app(EmailReadingDeliveryLifecycle::class);
    }

    public function test_the_digest_is_written_from_the_response_as_sent(): void
    {
        OpenAI::fake([$this->digestResponse()]);
        $delivery = $this->delivery();

        $this->assertTrue(app(HistoryDigestService::class)->write($delivery));

        $delivery->refresh();
        $this->assertSame(self::DIGEST, $delivery->history_digest);
        $this->assertNotNull($delivery->history_digest_generated_at);
        $this->assertFalse($delivery->history_digest_is_manual);

        OpenAI::assertSent(Chat::class, function (string $method, array $parameters): bool {
            $user = $parameters['messages'][1]['content'];

            return $parameters['model'] === 'gpt-4.1-mini'
                && str_contains($user, 'The Moon shows a change of direction')
                && ! str_contains($user, '<strong>')
                && str_contains($user, 'Love Reading');
        });
    }

    public function test_an_admin_edit_before_sending_is_what_gets_summarised(): void
    {
        OpenAI::fake([$this->digestResponse()]);
        $delivery = $this->delivery(['ai_response' => '<p>Edited by the admin: the Sun is coming.</p>']);

        app(HistoryDigestService::class)->write($delivery);

        OpenAI::assertSent(Chat::class, fn (string $method, array $parameters): bool => str_contains($parameters['messages'][1]['content'], 'Edited by the admin: the Sun is coming.'));
    }

    public function test_an_existing_digest_is_never_overwritten(): void
    {
        OpenAI::fake([$this->digestResponse()]);
        $delivery = $this->delivery([
            'history_digest' => 'Written by hand.',
            'history_digest_is_manual' => true,
        ]);

        $this->assertFalse(app(HistoryDigestService::class)->write($delivery));

        $delivery->refresh();
        $this->assertSame('Written by hand.', $delivery->history_digest);
        $this->assertTrue($delivery->history_digest_is_manual);
        OpenAI::assertNotSent(Chat::class);
    }

    public function test_a_delivery_that_is_not_sent_gets_no_digest(): void
    {
        OpenAI::fake([$this->digestResponse()]);
        $delivery = $this->delivery(['status' => EmailReadingDelivery::STATUS_GENERATED, 'sent_at' => null]);

        $this->assertFalse(app(HistoryDigestService::class)->write($delivery));

        $this->assertNull($delivery->fresh()->history_digest);
        OpenAI::assertNotSent(Chat::class);
    }

    public function test_a_delivery_without_a_response_gets_no_digest(): void
    {
        OpenAI::fake([$this->digestResponse()]);
        $delivery = $this->delivery(['ai_response' => null]);

        $this->assertFalse(app(HistoryDigestService::class)->write($delivery));

        OpenAI::assertNotSent(Chat::class);
    }

    public function test_a_disabled_digest_makes_no_call(): void
    {
        config(['email_reading.history.digest.enabled' => false]);
        OpenAI::fake([$this->digestResponse()]);

        $this->assertFalse(app(HistoryDigestService::class)->write($this->delivery()));

        OpenAI::assertNotSent(Chat::class);
    }

    public function test_an_empty_model_answer_throws_so_the_job_retries(): void
    {
        OpenAI::fake([$this->digestResponse('   ')]);
        $delivery = $this->delivery();

        $this->expectException(RuntimeException::class);

        try {
            app(HistoryDigestService::class)->write($delivery);
        } finally {
            $this->assertNull($delivery->fresh()->history_digest);
        }
    }

    public function test_finishing_a_send_queues_the_digest_job(): void
    {
        Queue::fake([WriteHistoryDigestJob::class]);
        $delivery = $this->delivery([
            'status' => EmailReadingDelivery::STATUS_SENDING,
            'claim_token' => 'token-1',
            'claimed_at' => now(),
            'sent_at' => now(),
        ]);

        $this->assertTrue($this->lifecycle()->markSent($delivery->id, 'token-1'));

        Queue::assertPushed(WriteHistoryDigestJob::class, fn (WriteHistoryDigestJob $job): bool => $job->deliveryId === $delivery->id);
    }

    public function test_a_disabled_digest_queues_nothing(): void
    {
        config(['email_reading.history.digest.enabled' => false]);
        Queue::fake([WriteHistoryDigestJob::class]);
        $delivery = $this->delivery([
            'status' => EmailReadingDelivery::STATUS_SENDING,
            'claim_token' => 'token-1',
            'claimed_at' => now(),
        ]);

        $this->lifecycle()->markSent($delivery->id, 'token-1');

        Queue::assertNotPushed(WriteHistoryDigestJob::class);
    }

    public function test_marking_sent_manually_queues_the_digest_when_a_response_exists(): void
    {
        Queue::fake([WriteHistoryDigestJob::class]);
        $delivery = $this->delivery(['status' => EmailReadingDelivery::STATUS_GENERATED, 'sent_at' => null]);

        $this->lifecycle()->markSentManually($delivery, fulfil: false);

        Queue::assertPushed(WriteHistoryDigestJob::class, fn (WriteHistoryDigestJob $job): bool => $job->deliveryId === $delivery->id);
    }

    public function test_marking_sent_manually_without_a_response_queues_no_digest(): void
    {
        Queue::fake([WriteHistoryDigestJob::class]);
        $delivery = $this->delivery(['status' => EmailReadingDelivery::STATUS_PENDING, 'ai_response' => null, 'sent_at' => null]);

        $this->lifecycle()->markSentManually($delivery, fulfil: false);

        Queue::assertNotPushed(WriteHistoryDigestJob::class);
    }

    public function test_the_digest_is_stored_end_to_end_when_a_send_finishes(): void
    {
        OpenAI::fake([$this->digestResponse()]);
        $delivery = $this->delivery([
            'status' => EmailReadingDelivery::STATUS_SENDING,
            'claim_token' => 'token-1',
            'claimed_at' => now(),
        ]);

        $this->lifecycle()->markSent($delivery->id, 'token-1');

        $delivery->refresh();
        $this->assertSame(EmailReadingDelivery::STATUS_SENT, $delivery->status);
        $this->assertSame(self::DIGEST, $delivery->history_digest);
    }

    public function test_a_resend_does_not_rewrite_the_digest(): void
    {
        OpenAI::fake([]);
        $delivery = $this->delivery([
            'status' => EmailReadingDelivery::STATUS_SENDING,
            'claim_token' => 'token-2',
            'claimed_at' => now(),
            'history_digest' => 'The first digest.',
            'history_digest_generated_at' => now()->subDay(),
        ]);

        $this->lifecycle()->markSent($delivery->id, 'token-2');

        $this->assertSame('The first digest.', $delivery->fresh()->history_digest);
        OpenAI::assertNotSent(Chat::class);
    }

    public function test_an_openai_failure_never_affects_the_sent_delivery(): void
    {
        OpenAI::fake([new RuntimeException('OpenAI is down')]);
        $delivery = $this->delivery([
            'status' => EmailReadingDelivery::STATUS_SENDING,
            'claim_token' => 'token-3',
            'claimed_at' => now(),
        ]);

        $this->assertTrue($this->lifecycle()->markSent($delivery->id, 'token-3'));

        $delivery->refresh();
        $this->assertSame(EmailReadingDelivery::STATUS_SENT, $delivery->status);
        $this->assertNull($delivery->history_digest);
    }

    public function test_the_job_logs_a_warning_and_leaves_the_digest_empty_when_it_fails_for_good(): void
    {
        $delivery = $this->delivery();

        (new WriteHistoryDigestJob($delivery->id))->failed(new RuntimeException('gave up'));

        $delivery->refresh();
        $this->assertNull($delivery->history_digest);
        $this->assertSame(EmailReadingDelivery::STATUS_SENT, $delivery->status);
    }
}
