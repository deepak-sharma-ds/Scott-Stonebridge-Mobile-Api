<?php

namespace Tests\Feature\Webhook\Campaign;

use App\Models\CampaignDelivery;
use App\Models\CampaignProduct;
use App\Models\MarketingCampaign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignOrderCancelledWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    private ?CampaignProduct $campaignProduct = null;

    private function campaignProduct(): CampaignProduct
    {
        if ($this->campaignProduct) {
            return $this->campaignProduct;
        }

        $campaign = MarketingCampaign::create([
            'campaign_key' => 'spring-sale',
            'name' => 'Spring Sale',
            'status' => MarketingCampaign::STATUS_ACTIVE,
        ]);

        return $this->campaignProduct = CampaignProduct::create([
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

    public function test_order_cancelled_cancels_all_unsent_deliveries(): void
    {
        $first = $this->delivery(['shopify_line_item_id' => 501]);
        $second = $this->delivery(['shopify_line_item_id' => 502]);

        $response = $this->postJson(
            route('webhook.shopify.campaign.cancelled'),
            ['order' => ['id' => 9001]],
            ['X-Shopify-Topic' => 'orders/cancelled']
        );

        $response->assertOk();
        $response->assertJson(['message' => 'OK', 'cancelled' => 2]);

        $this->assertSame(CampaignDelivery::STATUS_CANCELLED, $first->refresh()->status);
        $this->assertSame(CampaignDelivery::STATUS_CANCELLED, $second->refresh()->status);
    }

    public function test_order_cancelled_leaves_already_sent_delivery_untouched(): void
    {
        $sent = $this->delivery([
            'status' => CampaignDelivery::STATUS_SENT,
            'sent_at' => now()->subHour(),
        ]);

        $this->postJson(
            route('webhook.shopify.campaign.cancelled'),
            ['order' => ['id' => 9001]],
            ['X-Shopify-Topic' => 'orders/cancelled']
        )->assertOk();

        $this->assertSame(CampaignDelivery::STATUS_SENT, $sent->refresh()->status);
    }

    public function test_refund_cancels_only_refunded_line_item(): void
    {
        $refunded = $this->delivery(['shopify_line_item_id' => 501]);
        $untouched = $this->delivery(['shopify_line_item_id' => 502]);

        $response = $this->postJson(
            route('webhook.shopify.campaign.cancelled'),
            [
                'refund' => [
                    'order_id' => 9001,
                    'refund_line_items' => [
                        ['line_item_id' => 501],
                    ],
                ],
            ],
            ['X-Shopify-Topic' => 'refunds/create']
        );

        $response->assertOk();
        $response->assertJson(['message' => 'OK', 'cancelled' => 1]);

        $this->assertSame(CampaignDelivery::STATUS_CANCELLED, $refunded->refresh()->status);
        $this->assertSame(CampaignDelivery::STATUS_PENDING, $untouched->refresh()->status);
    }

    public function test_order_with_only_reading_deliveries_does_not_error_or_cancel_campaign(): void
    {
        $response = $this->postJson(
            route('webhook.shopify.campaign.cancelled'),
            ['order' => ['id' => 9002]],
            ['X-Shopify-Topic' => 'orders/cancelled']
        );

        $response->assertOk();
        $response->assertJson(['message' => 'OK', 'cancelled' => 0]);
    }
}
