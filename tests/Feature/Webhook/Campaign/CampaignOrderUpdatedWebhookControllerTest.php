<?php

namespace Tests\Feature\Webhook\Campaign;

use App\Jobs\Campaign\SendCampaignEmailJob;
use App\Models\CampaignDelivery;
use App\Models\CampaignProduct;
use App\Models\MarketingCampaign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CampaignOrderUpdatedWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    private function campaignProduct(): CampaignProduct
    {
        $campaign = MarketingCampaign::create([
            'campaign_key' => 'spring-sale',
            'name' => 'Spring Sale',
            'status' => MarketingCampaign::STATUS_ACTIVE,
        ]);

        return CampaignProduct::create([
            'marketing_campaign_id' => $campaign->id,
            'shopify_product_id' => 111,
            'shopify_variant_id' => 5001,
        ]);
    }

    private function delivery(array $overrides = []): CampaignDelivery
    {
        return CampaignDelivery::create(array_merge([
            'shopify_order_id' => 9001,
            'shopify_line_item_id' => 501,
            'campaign_product_id' => $this->campaignProduct()->id,
            'customer_email' => 'buyer@example.com',
            'status' => CampaignDelivery::STATUS_PENDING,
            'scheduled_at' => now()->addDays(5),
        ], $overrides));
    }

    private function orderPayload(int $orderId, array $shippingLines): array
    {
        return [
            'id' => $orderId,
            'email' => 'buyer@example.com',
            'shipping_lines' => $shippingLines,
        ];
    }

    private function sameDayShippingLine(): array
    {
        return ['title' => 'SAME DAY GUARANTEE - Via Email', 'is_removed' => false];
    }

    public function test_reschedules_pending_deliveries_on_same_day_upgrade(): void
    {
        Queue::fake();
        $delivery = $this->delivery();

        $response = $this->postJson(route('webhook.shopify.campaign.updated'), $this->orderPayload(9001, [
            $this->sameDayShippingLine(),
        ]));

        $response->assertOk();
        $delivery->refresh();

        $this->assertNotNull($delivery->expedited_at);
        $this->assertTrue($delivery->scheduled_at->lessThan(now()->addDays(1)));

        Queue::assertPushed(SendCampaignEmailJob::class, fn ($job) => $job->deliveryId === $delivery->id);
    }

    public function test_does_not_reschedule_already_sent_delivery(): void
    {
        Queue::fake();
        $delivery = $this->delivery([
            'status' => CampaignDelivery::STATUS_SENT,
            'sent_at' => now()->subHour(),
            'scheduled_at' => now()->subHour(),
        ]);

        $this->postJson(route('webhook.shopify.campaign.updated'), $this->orderPayload(9001, [
            $this->sameDayShippingLine(),
        ]))->assertOk();

        $this->assertNull($delivery->refresh()->expedited_at);
        Queue::assertNotPushed(SendCampaignEmailJob::class);
    }

    public function test_ignores_order_without_same_day_upgrade(): void
    {
        Queue::fake();
        $delivery = $this->delivery();

        $this->postJson(route('webhook.shopify.campaign.updated'), $this->orderPayload(9001, [
            ['title' => 'Standard Shipping', 'is_removed' => false],
        ]))->assertOk();

        $this->assertNull($delivery->refresh()->expedited_at);
        Queue::assertNotPushed(SendCampaignEmailJob::class);
    }

    public function test_order_with_only_reading_deliveries_does_not_error_or_expedite_campaign(): void
    {
        Queue::fake();

        $response = $this->postJson(route('webhook.shopify.campaign.updated'), $this->orderPayload(9002, [
            $this->sameDayShippingLine(),
        ]));

        $response->assertOk();
        $response->assertJson(['message' => 'Nothing to expedite']);
        Queue::assertNotPushed(SendCampaignEmailJob::class);
    }
}
