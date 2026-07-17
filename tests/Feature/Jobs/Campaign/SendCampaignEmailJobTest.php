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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class SendCampaignEmailJobTest extends TestCase
{
    use RefreshDatabase;

    private function pendingDelivery(): CampaignDelivery
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

        CampaignProductResponse::create([
            'campaign_product_id' => $campaignProduct->id,
            'ai_response' => 'Generated marketing copy.',
            'generated_at' => now(),
        ]);

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

    public function test_sends_email_marks_sent_and_dispatches_fulfillment(): void
    {
        Mail::fake();
        Queue::fake();
        $delivery = $this->pendingDelivery();

        (new SendCampaignEmailJob($delivery->id))->handle();

        Mail::assertSent(CampaignEmailMail::class, fn ($mail) => $mail->delivery->is($delivery));

        $delivery->refresh();
        $this->assertSame(CampaignDelivery::STATUS_SENT, $delivery->status);
        $this->assertNotNull($delivery->sent_at);

        Queue::assertPushed(MarkCampaignOrderFulfilledJob::class, fn ($job) => $job->deliveryId === $delivery->id);
    }

    public function test_skips_already_sent_delivery(): void
    {
        Mail::fake();
        $delivery = $this->pendingDelivery();
        $delivery->forceFill(['status' => CampaignDelivery::STATUS_SENT, 'sent_at' => now()])->save();

        (new SendCampaignEmailJob($delivery->id))->handle();

        Mail::assertNothingSent();
    }

    public function test_skips_non_pending_delivery(): void
    {
        Mail::fake();
        $delivery = $this->pendingDelivery();
        $delivery->forceFill(['status' => CampaignDelivery::STATUS_FAILED])->save();

        (new SendCampaignEmailJob($delivery->id))->handle();

        Mail::assertNothingSent();
    }

    public function test_failed_marks_delivery_failed_and_notifies_admin(): void
    {
        Queue::fake();
        $delivery = $this->pendingDelivery();

        (new SendCampaignEmailJob($delivery->id))->failed(new RuntimeException('mail provider down'));

        $delivery->refresh();
        $this->assertSame(CampaignDelivery::STATUS_FAILED, $delivery->status);
        $this->assertStringContainsString('mail provider down', (string) $delivery->error_message);

        Queue::assertPushed(NotifyCampaignFailureJob::class, fn ($job) => $job->deliveryId === $delivery->id);
    }
}
