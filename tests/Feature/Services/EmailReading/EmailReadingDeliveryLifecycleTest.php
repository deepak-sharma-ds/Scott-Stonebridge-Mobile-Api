<?php

namespace Tests\Feature\Services\EmailReading;

use App\Jobs\EmailReading\MarkReadingOrderFulfilledJob;
use App\Jobs\EmailReading\NotifyReadingFailureJob;
use App\Jobs\EmailReading\ProcessEmailReadingOrderJob;
use App\Jobs\EmailReading\SendEmailReadingJob;
use App\Mail\EmailReadingMail;
use App\Models\EmailReadingDelivery;
use App\Models\EmailReadingProduct;
use App\Services\EmailReading\EmailReadingDeliveryLifecycle;
use App\Services\EmailReading\EmailReadingGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use RuntimeException;
use Tests\TestCase;

class EmailReadingDeliveryLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function lifecycle(): EmailReadingDeliveryLifecycle
    {
        return app(EmailReadingDeliveryLifecycle::class);
    }

    private function product(): EmailReadingProduct
    {
        return EmailReadingProduct::firstOrCreate(['shopify_product_id' => 111], [
            'name' => 'Love Reading',
            'slug' => 'love-reading',
            'questions_schema' => [],
            'prompt_template' => 'Write a reading for {{ $customer_name }}.',
            'email_subject' => 'Your reading',
            'is_active' => true,
            'is_automation_enabled' => true,
        ]);
    }

    private function delivery(array $overrides = []): EmailReadingDelivery
    {
        static $lineItem = 1000;

        return EmailReadingDelivery::create(array_merge([
            'shopify_order_id' => 9001,
            'shopify_line_item_id' => $lineItem++,
            'email_reading_product_id' => $this->product()->id,
            'customer_email' => 'buyer@example.com',
            'customer_name' => 'Jane Doe',
            'questions' => [],
            'ai_response' => 'Your personal reading.',
            'status' => EmailReadingDelivery::STATUS_GENERATED,
            'scheduled_at' => now()->subMinute(),
        ], $overrides));
    }

    private function runSendJob(EmailReadingDelivery $delivery): void
    {
        $job = Queue::pushed(SendEmailReadingJob::class)->last();
        $this->assertNotNull($job, 'expected a send job to have been queued');
        $job->handle($this->lifecycle());
    }

    // ---------------------------------------------------------------
    // The four scenarios from the admin-action review
    // ---------------------------------------------------------------

    public function test_rescheduling_later_stops_the_original_time_from_sending(): void
    {
        Queue::fake();
        $delivery = $this->delivery(['scheduled_at' => now()->subMinute()]);

        $this->lifecycle()->reschedule($delivery, now()->addDays(3), null);

        $this->assertSame(0, $this->lifecycle()->dispatchDue());
        Queue::assertNothingPushed();

        $this->travelTo(now()->addDays(3)->addMinute());

        $this->assertSame(1, $this->lifecycle()->dispatchDue());
        Queue::assertPushed(SendEmailReadingJob::class, 1);
    }

    public function test_send_now_leaves_no_stale_job_that_can_send_a_second_email(): void
    {
        Mail::fake();
        Queue::fake();
        $delivery = $this->delivery(['scheduled_at' => now()->addDays(5)]);

        $this->lifecycle()->sendNow($delivery);

        $this->assertSame(EmailReadingDelivery::STATUS_SENDING, $delivery->fresh()->status);
        Queue::assertPushed(SendEmailReadingJob::class, 1);

        $this->runSendJob($delivery);

        Mail::assertSent(EmailReadingMail::class, 1);
        $this->assertSame(EmailReadingDelivery::STATUS_SENT, $delivery->fresh()->status);

        // The dispatcher has nothing to claim, and neither a duplicate of the
        // real job nor a legacy delayed job (no claim token) can send again.
        $this->assertSame(0, $this->lifecycle()->dispatchDue());
        $this->runSendJob($delivery);
        (new SendEmailReadingJob($delivery->id))->handle($this->lifecycle());

        Mail::assertSent(EmailReadingMail::class, 1);
    }

    public function test_cancel_then_reactivate_sends_when_due(): void
    {
        Queue::fake();
        $delivery = $this->delivery(['scheduled_at' => now()->subMinute()]);

        $this->assertTrue($this->lifecycle()->cancel($delivery));
        $this->assertSame(EmailReadingDelivery::STATUS_CANCELLED, $delivery->fresh()->status);
        $this->assertSame(0, $this->lifecycle()->dispatchDue());

        $this->assertSame(EmailReadingDelivery::STATUS_GENERATED, $this->lifecycle()->reactivate($delivery));

        // No job was consumed by the cancel, so nothing needs re-creating.
        $this->assertSame(1, $this->lifecycle()->dispatchDue());
        Queue::assertPushed(SendEmailReadingJob::class, 1);
    }

    public function test_rescheduling_earlier_makes_it_due_immediately(): void
    {
        Queue::fake();
        $delivery = $this->delivery(['scheduled_at' => now()->addDays(5)]);

        $this->assertSame(0, $this->lifecycle()->dispatchDue());

        $this->lifecycle()->reschedule($delivery, now()->subMinute(), null);

        $this->assertSame(1, $this->lifecycle()->dispatchDue());
        Queue::assertPushed(SendEmailReadingJob::class, 1);
    }

    // ---------------------------------------------------------------
    // Claim / freeze guards
    // ---------------------------------------------------------------

    public function test_only_one_claim_can_win_a_due_delivery(): void
    {
        $delivery = $this->delivery();

        $first = $this->lifecycle()->claim($delivery->id, [EmailReadingDelivery::STATUS_GENERATED]);
        $second = $this->lifecycle()->claim($delivery->id, [EmailReadingDelivery::STATUS_GENERATED]);

        $this->assertNotNull($first);
        $this->assertNull($second);
    }

    public function test_dispatcher_does_not_claim_a_delivery_rescheduled_into_the_future_after_selection(): void
    {
        $delivery = $this->delivery(['scheduled_at' => now()->addHour()]);

        $this->assertNull($this->lifecycle()->claim($delivery->id, [EmailReadingDelivery::STATUS_GENERATED], dueOnly: true));
    }

    public function test_a_sending_delivery_is_frozen_for_every_admin_action(): void
    {
        $delivery = $this->delivery(['status' => EmailReadingDelivery::STATUS_SENDING, 'claim_token' => 'tok', 'claimed_at' => now()]);

        foreach ([
            fn () => $this->lifecycle()->cancel($delivery),
            fn () => $this->lifecycle()->reschedule($delivery, now()->addDay(), null),
            fn () => $this->lifecycle()->sendNow($delivery),
            fn () => $this->lifecycle()->reactivate($delivery),
            fn () => $this->lifecycle()->markSentManually($delivery),
        ] as $action) {
            try {
                $action();
                $this->fail('Expected the sending delivery to be frozen.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('being sent right now', $e->getMessage());
            }
        }

        $this->assertFalse($this->lifecycle()->canDelete($delivery));
        $this->assertSame(EmailReadingDelivery::STATUS_SENDING, $delivery->fresh()->status);
    }

    public function test_send_now_refuses_cancelled_and_already_sent_without_resend(): void
    {
        $cancelled = $this->delivery(['status' => EmailReadingDelivery::STATUS_CANCELLED]);
        $sent = $this->delivery(['status' => EmailReadingDelivery::STATUS_SENT, 'sent_at' => now()]);

        try {
            $this->lifecycle()->sendNow($cancelled);
            $this->fail('cancelled must be reactivated first');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Reactivate', $e->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->lifecycle()->sendNow($sent);
    }

    public function test_resend_of_a_sent_delivery_sends_a_second_email_exactly_once(): void
    {
        Mail::fake();
        Queue::fake();
        $delivery = $this->delivery(['status' => EmailReadingDelivery::STATUS_SENT, 'sent_at' => now()->subDay()]);

        $this->lifecycle()->sendNow($delivery, resend: true);
        $this->runSendJob($delivery);

        Mail::assertSent(EmailReadingMail::class, 1);
        $this->assertSame(EmailReadingDelivery::STATUS_SENT, $delivery->fresh()->status);
    }

    public function test_rescheduling_a_sent_delivery_is_rejected(): void
    {
        $delivery = $this->delivery(['status' => EmailReadingDelivery::STATUS_SENT, 'sent_at' => now()]);

        $this->expectException(RuntimeException::class);
        $this->lifecycle()->reschedule($delivery, now()->addDay(), null);
    }

    // ---------------------------------------------------------------
    // Send idempotency + recovery
    // ---------------------------------------------------------------

    public function test_a_retry_after_the_mail_already_left_finalizes_without_sending_again(): void
    {
        Mail::fake();
        Queue::fake();
        $delivery = $this->delivery();
        $token = $this->lifecycle()->claim($delivery->id, [EmailReadingDelivery::STATUS_GENERATED]);
        // Simulates: Mail::send succeeded and recorded sent_at, then the worker died before finalizing.
        $this->lifecycle()->recordMailSent($delivery->id, $token);

        (new SendEmailReadingJob($delivery->id, $token))->handle($this->lifecycle());

        Mail::assertNothingSent();
        $this->assertSame(EmailReadingDelivery::STATUS_SENT, $delivery->fresh()->status);
        Queue::assertPushed(MarkReadingOrderFulfilledJob::class, 1);
    }

    public function test_a_stale_token_job_never_sends(): void
    {
        Mail::fake();
        $delivery = $this->delivery();
        $this->lifecycle()->claim($delivery->id, [EmailReadingDelivery::STATUS_GENERATED]);

        (new SendEmailReadingJob($delivery->id, 'an-old-token'))->handle($this->lifecycle());

        Mail::assertNothingSent();
        $this->assertSame(EmailReadingDelivery::STATUS_SENDING, $delivery->fresh()->status);
    }

    public function test_send_job_failure_marks_failed_and_notifies_only_for_the_owning_claim(): void
    {
        Queue::fake();
        $delivery = $this->delivery();
        $token = $this->lifecycle()->claim($delivery->id, [EmailReadingDelivery::STATUS_GENERATED]);

        (new SendEmailReadingJob($delivery->id, 'stale'))->failed(new RuntimeException('boom'));
        Queue::assertNotPushed(NotifyReadingFailureJob::class);

        (new SendEmailReadingJob($delivery->id, $token))->failed(new RuntimeException('mail provider down'));

        $delivery->refresh();
        $this->assertSame(EmailReadingDelivery::STATUS_FAILED, $delivery->status);
        $this->assertStringContainsString('mail provider down', (string) $delivery->error_message);
        Queue::assertPushed(NotifyReadingFailureJob::class, 1);
    }

    public function test_stuck_sending_is_released_once_then_failed_and_escalated(): void
    {
        Queue::fake();
        $delivery = $this->delivery();
        $this->lifecycle()->claim($delivery->id, [EmailReadingDelivery::STATUS_GENERATED]);

        // Within the grace period nothing is recovered.
        $this->assertSame(['finalized' => 0, 'released' => 0, 'failed' => 0], $this->lifecycle()->recoverStuck());

        $this->travel(20)->minutes();
        $this->assertSame(['finalized' => 0, 'released' => 1, 'failed' => 0], $this->lifecycle()->recoverStuck());

        $delivery->refresh();
        $this->assertSame(EmailReadingDelivery::STATUS_GENERATED, $delivery->status);
        $this->assertNull($delivery->claim_token);
        $this->assertSame(1, $delivery->release_count);

        // Re-claimed and stalls a second time: escalate instead of looping.
        $this->lifecycle()->claim($delivery->id, [EmailReadingDelivery::STATUS_GENERATED]);
        $this->travel(20)->minutes();
        $this->assertSame(['finalized' => 0, 'released' => 0, 'failed' => 1], $this->lifecycle()->recoverStuck());

        $this->assertSame(EmailReadingDelivery::STATUS_FAILED, $delivery->fresh()->status);
        Queue::assertPushed(NotifyReadingFailureJob::class, 1);
    }

    public function test_stuck_delivery_whose_mail_already_left_is_finalized_as_sent(): void
    {
        Queue::fake();
        $delivery = $this->delivery();
        $token = $this->lifecycle()->claim($delivery->id, [EmailReadingDelivery::STATUS_GENERATED]);
        $this->lifecycle()->recordMailSent($delivery->id, $token);

        $this->travel(20)->minutes();

        $this->assertSame(['finalized' => 1, 'released' => 0, 'failed' => 0], $this->lifecycle()->recoverStuck());
        $this->assertSame(EmailReadingDelivery::STATUS_SENT, $delivery->fresh()->status);
        Queue::assertPushed(MarkReadingOrderFulfilledJob::class, 1);
    }

    // ---------------------------------------------------------------
    // Manual mark + fulfilment
    // ---------------------------------------------------------------

    public function test_mark_sent_manually_fulfils_by_default_and_flags_the_row(): void
    {
        Queue::fake();
        $delivery = $this->delivery(['status' => EmailReadingDelivery::STATUS_PENDING, 'ai_response' => null]);

        $this->lifecycle()->markSentManually($delivery);

        $delivery->refresh();
        $this->assertSame(EmailReadingDelivery::STATUS_SENT, $delivery->status);
        $this->assertTrue($delivery->sent_manually);
        $this->assertNotNull($delivery->sent_at);
        Queue::assertPushed(MarkReadingOrderFulfilledJob::class, 1);
    }

    public function test_mark_sent_manually_can_skip_fulfilment(): void
    {
        Queue::fake();
        $delivery = $this->delivery();

        $this->lifecycle()->markSentManually($delivery, fulfil: false);

        Queue::assertNotPushed(MarkReadingOrderFulfilledJob::class);
    }

    public function test_mark_sent_manually_is_rejected_for_cancelled(): void
    {
        $delivery = $this->delivery(['status' => EmailReadingDelivery::STATUS_CANCELLED]);

        $this->expectException(RuntimeException::class);
        $this->lifecycle()->markSentManually($delivery);
    }

    public function test_retry_fulfilment_only_for_sent_unfulfilled_deliveries(): void
    {
        Queue::fake();
        $unfulfilled = $this->delivery(['status' => EmailReadingDelivery::STATUS_SENT, 'sent_at' => now(), 'fulfillment_error' => 'Shopify said no']);

        $this->lifecycle()->retryFulfillment($unfulfilled);
        Queue::assertPushed(MarkReadingOrderFulfilledJob::class, 1);

        $fulfilled = $this->delivery(['status' => EmailReadingDelivery::STATUS_SENT, 'sent_at' => now(), 'fulfilled_at' => now()]);
        $this->expectException(RuntimeException::class);
        $this->lifecycle()->retryFulfillment($fulfilled);
    }

    public function test_fulfilment_job_final_failure_stores_the_error_and_notifies_once(): void
    {
        Queue::fake();
        $delivery = $this->delivery(['status' => EmailReadingDelivery::STATUS_SENT, 'sent_at' => now()]);

        (new MarkReadingOrderFulfilledJob($delivery->id))->failed(new RuntimeException('Shopify 500'));

        $delivery->refresh();
        $this->assertStringContainsString('Shopify 500', (string) $delivery->fulfillment_error);
        $this->assertSame(EmailReadingDelivery::STATUS_SENT, $delivery->status);
        $this->assertTrue($delivery->needsFulfillment());
        Queue::assertPushed(NotifyReadingFailureJob::class, 1);
    }

    // ---------------------------------------------------------------
    // Expedite / webhook cancel
    // ---------------------------------------------------------------

    public function test_expedite_covers_a_still_generating_delivery_and_is_honoured_after_generation(): void
    {
        Queue::fake();
        $delivery = $this->delivery(['status' => EmailReadingDelivery::STATUS_PENDING, 'ai_response' => null, 'scheduled_at' => now()->addDays(5)]);

        $this->assertSame(1, $this->lifecycle()->expediteOrder(9001, now()->subMinute()));
        $this->assertNotNull($delivery->fresh()->expedited_at);

        OpenAI::fake([CreateResponse::fake(['choices' => [['message' => ['role' => 'assistant', 'content' => 'Generated reading.']]]])]);
        $this->assertTrue(app(EmailReadingGenerationService::class)->generate($delivery->fresh()->load('product')));

        // The new (expedited) time is on the live row, so the dispatcher claims it now.
        $this->assertSame(1, $this->lifecycle()->dispatchDue());
    }

    public function test_cancelling_for_an_order_skips_sending_and_sent(): void
    {
        $pending = $this->delivery(['status' => EmailReadingDelivery::STATUS_PENDING]);
        $sending = $this->delivery(['status' => EmailReadingDelivery::STATUS_SENDING, 'claim_token' => 't', 'claimed_at' => now()]);
        $sent = $this->delivery(['status' => EmailReadingDelivery::STATUS_SENT, 'sent_at' => now()]);

        $this->assertSame(1, $this->lifecycle()->cancelForOrder(9001));

        $this->assertSame(EmailReadingDelivery::STATUS_CANCELLED, $pending->fresh()->status);
        $this->assertSame(EmailReadingDelivery::STATUS_SENDING, $sending->fresh()->status);
        $this->assertSame(EmailReadingDelivery::STATUS_SENT, $sent->fresh()->status);
    }

    // ---------------------------------------------------------------
    // Generation vs concurrent state changes
    // ---------------------------------------------------------------

    public function test_generation_never_resurrects_a_delivery_cancelled_mid_flight(): void
    {
        OpenAI::fake([CreateResponse::fake(['choices' => [['message' => ['role' => 'assistant', 'content' => 'Late reading.']]]])]);
        $delivery = $this->delivery(['status' => EmailReadingDelivery::STATUS_PENDING, 'ai_response' => null]);
        $loaded = $delivery->load('product');

        // A refund lands while OpenAI is running.
        EmailReadingDelivery::whereKey($delivery->id)->update(['status' => EmailReadingDelivery::STATUS_CANCELLED]);

        $this->assertFalse(app(EmailReadingGenerationService::class)->generate($loaded));

        $delivery->refresh();
        $this->assertSame(EmailReadingDelivery::STATUS_CANCELLED, $delivery->status);
        $this->assertNull($delivery->ai_response);
    }

    public function test_send_now_on_a_pending_reading_generates_synchronously_then_sends(): void
    {
        Mail::fake();
        Queue::fake();
        OpenAI::fake([CreateResponse::fake(['choices' => [['message' => ['role' => 'assistant', 'content' => 'Fresh reading.']]]])]);
        $delivery = $this->delivery(['status' => EmailReadingDelivery::STATUS_PENDING, 'ai_response' => null]);

        $this->lifecycle()->sendNow($delivery);

        $this->assertSame('Fresh reading.', $delivery->fresh()->ai_response);
        $this->assertSame(EmailReadingDelivery::STATUS_SENDING, $delivery->fresh()->status);

        $this->runSendJob($delivery);
        Mail::assertSent(EmailReadingMail::class, 1);
    }

    public function test_send_now_generation_failure_leaves_the_delivery_pending_and_queues_nothing(): void
    {
        Queue::fake();
        OpenAI::fake([CreateResponse::fake(['choices' => [['message' => ['role' => 'assistant', 'content' => '']]]])]);
        $delivery = $this->delivery(['status' => EmailReadingDelivery::STATUS_PENDING, 'ai_response' => null]);

        try {
            $this->lifecycle()->sendNow($delivery);
            $this->fail('generation failure should surface to the admin');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('empty content', $e->getMessage());
        }

        $this->assertSame(EmailReadingDelivery::STATUS_PENDING, $delivery->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_process_job_generates_but_never_queues_a_send(): void
    {
        Queue::fake();
        OpenAI::fake([CreateResponse::fake(['choices' => [['message' => ['role' => 'assistant', 'content' => 'Reading body.']]]])]);
        $delivery = $this->delivery(['status' => EmailReadingDelivery::STATUS_PENDING, 'ai_response' => null]);

        (new ProcessEmailReadingOrderJob($delivery->id))->handle(app(EmailReadingGenerationService::class));

        $this->assertSame(EmailReadingDelivery::STATUS_GENERATED, $delivery->fresh()->status);
        Queue::assertNotPushed(SendEmailReadingJob::class);
    }

    public function test_reactivating_a_failed_delivery_without_a_response_requeues_generation(): void
    {
        Queue::fake();
        $delivery = $this->delivery(['status' => EmailReadingDelivery::STATUS_FAILED, 'ai_response' => null, 'error_message' => 'x']);

        $this->assertSame(EmailReadingDelivery::STATUS_PENDING, $this->lifecycle()->reactivate($delivery));

        $this->assertNull($delivery->fresh()->error_message);
        Queue::assertPushed(ProcessEmailReadingOrderJob::class, 1);
    }
}
