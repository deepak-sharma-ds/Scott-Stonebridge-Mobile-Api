<?php

namespace Tests\Feature\Jobs\Campaign;

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

class SendCampaignEmailJobTest extends TestCase
{
    use RefreshDatabase;

    private function lifecycle(): CampaignDeliveryLifecycle
    {
        return app(CampaignDeliveryLifecycle::class);
    }

    private function pendingDelivery(bool $withResponse = true): CampaignDelivery
    {
        $campaign = MarketingCampaign::create([
            'campaign_key' => 'spring-sale',
            'name' => 'Spring Sale',
            'status' => MarketingCampaign::STATUS_ACTIVE,
        ]);

        $campaignProduct = CampaignProduct::create([
            'marketing_campaign_id' => $campaign->id,
            'shopify_product_id' => 111,
            'shopify_variant_id' => 5001,
            'email_subject' => 'Your Spring Surprise',
        ]);

        if ($withResponse) {
            CampaignProductResponse::create([
                'campaign_product_id' => $campaignProduct->id,
                'source' => CampaignProductResponse::SOURCE_AI,
                'body' => 'Generated marketing copy.',
                'generated_at' => now(),
            ]);
        }

        return CampaignDelivery::create([
            'shopify_order_id' => 9001,
            'shopify_line_item_id' => 501,
            'campaign_product_id' => $campaignProduct->id,
            'customer_email' => 'buyer@example.com',
            'customer_name' => 'Jane Doe',
            'status' => CampaignDelivery::STATUS_PENDING,
            'scheduled_at' => now(),
        ]);
    }

    private function claim(CampaignDelivery $delivery): string
    {
        return (string) $this->lifecycle()->claim($delivery->id, [CampaignDelivery::STATUS_PENDING]);
    }

    public function test_sends_email_marks_sent_and_dispatches_fulfillment(): void
    {
        Mail::fake();
        Queue::fake();
        $delivery = $this->pendingDelivery();
        $token = $this->claim($delivery);

        (new SendCampaignEmailJob($delivery->id, $token))->handle($this->lifecycle());

        Mail::assertSent(CampaignEmailMail::class, fn ($mail) => $mail->delivery->is($delivery));

        $delivery->refresh();
        $this->assertSame(CampaignDelivery::STATUS_SENT, $delivery->status);
        $this->assertNotNull($delivery->sent_at);
        $this->assertNull($delivery->claim_token);

        Queue::assertPushed(MarkCampaignOrderFulfilledJob::class, fn ($job) => $job->deliveryId === $delivery->id);
    }

    public function test_skips_already_sent_delivery(): void
    {
        Mail::fake();
        $delivery = $this->pendingDelivery();
        $delivery->forceFill(['status' => CampaignDelivery::STATUS_SENT, 'sent_at' => now()])->save();

        (new SendCampaignEmailJob($delivery->id, 'any-token'))->handle($this->lifecycle());

        Mail::assertNothingSent();
    }

    public function test_skips_an_unclaimed_pending_delivery(): void
    {
        Mail::fake();
        $delivery = $this->pendingDelivery();

        (new SendCampaignEmailJob($delivery->id, 'forged-token'))->handle($this->lifecycle());

        Mail::assertNothingSent();
        $this->assertSame(CampaignDelivery::STATUS_PENDING, $delivery->fresh()->status);
    }

    public function test_legacy_delayed_job_without_a_claim_token_is_a_no_op(): void
    {
        Mail::fake();
        $delivery = $this->pendingDelivery();

        (new SendCampaignEmailJob($delivery->id))->handle($this->lifecycle());

        Mail::assertNothingSent();
        $this->assertSame(CampaignDelivery::STATUS_PENDING, $delivery->fresh()->status);
    }

    public function test_a_missing_response_at_send_time_fails_the_delivery_and_notifies_admin(): void
    {
        Mail::fake();
        Queue::fake();
        $delivery = $this->pendingDelivery(withResponse: false);
        $token = $this->claim($delivery);

        (new SendCampaignEmailJob($delivery->id, $token))->handle($this->lifecycle());

        Mail::assertNothingSent();
        $this->assertSame(CampaignDelivery::STATUS_FAILED, $delivery->fresh()->status);
        Queue::assertPushed(NotifyCampaignFailureJob::class, 1);
    }

    public function test_failed_marks_delivery_failed_and_notifies_admin(): void
    {
        Queue::fake();
        $delivery = $this->pendingDelivery();
        $token = $this->claim($delivery);

        (new SendCampaignEmailJob($delivery->id, $token))->failed(new RuntimeException('mail provider down'));

        $delivery->refresh();
        $this->assertSame(CampaignDelivery::STATUS_FAILED, $delivery->status);
        $this->assertStringContainsString('mail provider down', (string) $delivery->error_message);

        Queue::assertPushed(NotifyCampaignFailureJob::class, fn ($job) => $job->deliveryId === $delivery->id);
    }

    public function test_failed_for_a_released_claim_does_not_touch_the_delivery_or_alert(): void
    {
        Queue::fake();
        $delivery = $this->pendingDelivery();
        $this->claim($delivery);

        (new SendCampaignEmailJob($delivery->id, 'old-token'))->failed(new RuntimeException('late failure'));

        $this->assertSame(CampaignDelivery::STATUS_SENDING, $delivery->fresh()->status);
        Queue::assertNotPushed(NotifyCampaignFailureJob::class);
    }
}
