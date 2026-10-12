<?php

namespace Tests\Feature\Services\CampaignEmail;

use App\Jobs\Campaign\MarkCampaignOrderFulfilledJob;
use App\Jobs\Campaign\NotifyCampaignFailureJob;
use App\Jobs\Campaign\SendCampaignEmailJob;
use App\Mail\CampaignEmailMail;
use App\Models\CampaignDelivery;
use App\Models\CampaignProduct;
use App\Models\CampaignProductResponse;
use App\Models\MarketingCampaign;
use App\Services\CampaignEmail\CampaignDeliveryLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class CampaignDeliveryLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function lifecycle(): CampaignDeliveryLifecycle
    {
        return app(CampaignDeliveryLifecycle::class);
    }

    private function campaignProduct(bool $withResponse = true): CampaignProduct
    {
        $campaign = MarketingCampaign::firstOrCreate(['campaign_key' => 'spring-sale'], [
            'name' => 'Spring Sale',
            'status' => MarketingCampaign::STATUS_ACTIVE,
        ]);

        $product = CampaignProduct::firstOrCreate(
            ['marketing_campaign_id' => $campaign->id, 'shopify_product_id' => 111],
            ['shopify_variant_id' => 5001]
        );

        if ($withResponse && ! $product->response) {
            CampaignProductResponse::create([
                'campaign_product_id' => $product->id,
                'source' => CampaignProductResponse::SOURCE_MANUAL,
                'body' => 'Shared campaign copy.',
            ]);
        }

        return $product->refresh();
    }

    private function delivery(array $overrides = []): CampaignDelivery
    {
        static $lineItem = 2000;

        return CampaignDelivery::create(array_merge([
            'shopify_order_id' => 9001,
            'shopify_line_item_id' => $lineItem++,
            'campaign_product_id' => $this->campaignProduct()->id,
            'customer_email' => 'buyer@example.com',
            'status' => CampaignDelivery::STATUS_PENDING,
            'scheduled_at' => now()->subMinute(),
        ], $overrides));
    }

    private function runSendJob(): void
    {
        $job = Queue::pushed(SendCampaignEmailJob::class)->last();
        $this->assertNotNull($job, 'expected a send job to have been queued');
        $job->handle($this->lifecycle());
    }

    // ---- the four scenarios ----

    public function test_rescheduling_later_stops_the_original_time_from_sending(): void
    {
        Queue::fake();
        $delivery = $this->delivery(['scheduled_at' => now()->subMinute()]);

        $this->lifecycle()->reschedule($delivery, now()->addDays(3), null);

        $this->assertSame(0, $this->lifecycle()->dispatchDue());
        Queue::assertNothingPushed();

        $this->travelTo(now()->addDays(3)->addMinute());

        $this->assertSame(1, $this->lifecycle()->dispatchDue());
        Queue::assertPushed(SendCampaignEmailJob::class, 1);
    }

    public function test_send_now_leaves_no_stale_job_that_can_send_a_second_email(): void
    {
        Mail::fake();
        Queue::fake();
        $delivery = $this->delivery(['scheduled_at' => now()->addDays(5)]);

        $this->lifecycle()->sendNow($delivery);

        $this->assertSame(CampaignDelivery::STATUS_SENDING, $delivery->fresh()->status);
        Queue::assertPushed(SendCampaignEmailJob::class, 1);

        $this->runSendJob();

        Mail::assertSent(CampaignEmailMail::class, 1);
        $this->assertSame(CampaignDelivery::STATUS_SENT, $delivery->fresh()->status);

        $this->assertSame(0, $this->lifecycle()->dispatchDue());
        $this->runSendJob();
        (new SendCampaignEmailJob($delivery->id))->handle($this->lifecycle());

        Mail::assertSent(CampaignEmailMail::class, 1);
    }

    public function test_cancel_then_reactivate_sends_when_due(): void
    {
        Queue::fake();
        $delivery = $this->delivery();

        $this->assertTrue($this->lifecycle()->cancel($delivery));
        $this->assertSame(0, $this->lifecycle()->dispatchDue());

        $this->assertSame(CampaignDelivery::STATUS_PENDING, $this->lifecycle()->reactivate($delivery));

        $this->assertSame(1, $this->lifecycle()->dispatchDue());
        Queue::assertPushed(SendCampaignEmailJob::class, 1);
    }

    public function test_rescheduling_earlier_makes_it_due_immediately(): void
    {
        Queue::fake();
        $delivery = $this->delivery(['scheduled_at' => now()->addDays(5)]);

        $this->assertSame(0, $this->lifecycle()->dispatchDue());

        $this->lifecycle()->reschedule($delivery, now()->subMinute(), null);

        $this->assertSame(1, $this->lifecycle()->dispatchDue());
    }

    // ---- guards ----

    public function test_an_unresolved_attribution_failure_is_never_dispatched(): void
    {
        Queue::fake();
        $this->delivery(['status' => CampaignDelivery::STATUS_FAILED, 'campaign_product_id' => null]);

        $this->assertSame(0, $this->lifecycle()->dispatchDue());
    }

    public function test_a_sending_delivery_is_frozen_for_every_admin_action(): void
    {
        $delivery = $this->delivery(['status' => CampaignDelivery::STATUS_SENDING, 'claim_token' => 'tok', 'claimed_at' => now()]);

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
    }

    public function test_send_now_requires_a_resolved_campaign_response(): void
    {
        $delivery = $this->delivery(['campaign_product_id' => null, 'status' => CampaignDelivery::STATUS_FAILED]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No campaign response resolved');
        $this->lifecycle()->sendNow($delivery);
    }

    public function test_reactivating_an_unresolved_failure_is_refused_until_it_is_re_paired(): void
    {
        $delivery = $this->delivery(['campaign_product_id' => null, 'status' => CampaignDelivery::STATUS_FAILED]);

        $this->expectException(RuntimeException::class);
        $this->lifecycle()->reactivate($delivery);
    }

    public function test_a_cancelled_delivery_must_be_reactivated_before_sending(): void
    {
        $delivery = $this->delivery(['status' => CampaignDelivery::STATUS_CANCELLED]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Reactivate');
        $this->lifecycle()->sendNow($delivery);
    }

    public function test_resend_sends_a_second_email_exactly_once(): void
    {
        Mail::fake();
        Queue::fake();
        $delivery = $this->delivery(['status' => CampaignDelivery::STATUS_SENT, 'sent_at' => now()->subDay()]);

        $this->lifecycle()->sendNow($delivery, resend: true);
        $this->runSendJob();

        Mail::assertSent(CampaignEmailMail::class, 1);
        $this->assertSame(CampaignDelivery::STATUS_SENT, $delivery->fresh()->status);
    }

    public function test_only_one_claim_can_win_a_due_delivery(): void
    {
        $delivery = $this->delivery();

        $this->assertNotNull($this->lifecycle()->claim($delivery->id, [CampaignDelivery::STATUS_PENDING]));
        $this->assertNull($this->lifecycle()->claim($delivery->id, [CampaignDelivery::STATUS_PENDING]));
    }

    // ---- recovery ----

    public function test_stuck_sending_is_released_once_then_failed_and_escalated(): void
    {
        Queue::fake();
        $delivery = $this->delivery();
        $this->lifecycle()->claim($delivery->id, [CampaignDelivery::STATUS_PENDING]);

        $this->travel(20)->minutes();
        $this->assertSame(['finalized' => 0, 'released' => 1, 'failed' => 0], $this->lifecycle()->recoverStuck());
        $this->assertSame(CampaignDelivery::STATUS_PENDING, $delivery->fresh()->status);

        $this->lifecycle()->claim($delivery->id, [CampaignDelivery::STATUS_PENDING]);
        $this->travel(20)->minutes();
        $this->assertSame(['finalized' => 0, 'released' => 0, 'failed' => 1], $this->lifecycle()->recoverStuck());

        $this->assertSame(CampaignDelivery::STATUS_FAILED, $delivery->fresh()->status);
        Queue::assertPushed(NotifyCampaignFailureJob::class, 1);
    }

    public function test_stuck_delivery_whose_mail_already_left_is_finalized_as_sent(): void
    {
        Queue::fake();
        $delivery = $this->delivery();
        $token = $this->lifecycle()->claim($delivery->id, [CampaignDelivery::STATUS_PENDING]);
        $this->lifecycle()->recordMailSent($delivery->id, $token);

        $this->travel(20)->minutes();

        $this->assertSame(['finalized' => 1, 'released' => 0, 'failed' => 0], $this->lifecycle()->recoverStuck());
        $this->assertSame(CampaignDelivery::STATUS_SENT, $delivery->fresh()->status);
        Queue::assertPushed(MarkCampaignOrderFulfilledJob::class, 1);
    }

    // ---- manual mark + fulfilment ----

    public function test_mark_sent_manually_fulfils_by_default_and_can_skip(): void
    {
        Queue::fake();
        $first = $this->delivery();
        $second = $this->delivery();

        $this->lifecycle()->markSentManually($first);
        $this->lifecycle()->markSentManually($second, fulfil: false);

        $this->assertTrue($first->fresh()->sent_manually);
        $this->assertSame(CampaignDelivery::STATUS_SENT, $second->fresh()->status);
        Queue::assertPushed(MarkCampaignOrderFulfilledJob::class, 1);
    }

    public function test_fulfilment_job_final_failure_stores_the_error_and_notifies_once(): void
    {
        Queue::fake();
        $delivery = $this->delivery(['status' => CampaignDelivery::STATUS_SENT, 'sent_at' => now()]);

        (new MarkCampaignOrderFulfilledJob($delivery->id))->failed(new RuntimeException('Shopify 500'));

        $this->assertTrue($delivery->fresh()->needsFulfillment());
        $this->assertStringContainsString('Shopify 500', (string) $delivery->fresh()->fulfillment_error);
        Queue::assertPushed(NotifyCampaignFailureJob::class, 1);

        $this->lifecycle()->retryFulfillment($delivery);
        Queue::assertPushed(MarkCampaignOrderFulfilledJob::class, 1);
    }

    public function test_delete_is_refused_for_sending_and_sent(): void
    {
        $sent = $this->delivery(['status' => CampaignDelivery::STATUS_SENT, 'sent_at' => now()]);
        $cancelled = $this->delivery(['status' => CampaignDelivery::STATUS_CANCELLED]);

        $this->assertFalse($this->lifecycle()->canDelete($sent));
        $this->assertTrue($this->lifecycle()->canDelete($cancelled));
    }

    public function test_campaign_response_edits_reach_unsent_deliveries_at_send_time(): void
    {
        Mail::fake();
        Queue::fake();
        $delivery = $this->delivery();
        $delivery->campaignProduct->response->update(['body' => 'Edited copy after purchase.']);

        $this->lifecycle()->sendNow($delivery);
        $this->runSendJob();

        Mail::assertSent(CampaignEmailMail::class, function (CampaignEmailMail $mail) {
            return str_contains($mail->content()->with['campaignBody'], 'Edited copy after purchase.');
        });
    }
}
