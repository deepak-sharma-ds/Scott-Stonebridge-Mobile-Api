<?php

namespace Tests\Feature\Webhook\Campaign;

use App\Jobs\Campaign\NotifyCampaignFailureJob;
use App\Jobs\Campaign\SendCampaignEmailJob;
use App\Models\CampaignDelivery;
use App\Models\CampaignProduct;
use App\Models\CampaignProductResponse;
use App\Models\MarketingCampaign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CampaignPaidWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    private function activeCampaignWithResponse(string $campaignKey = 'spring-sale', int $productId = 111): CampaignProduct
    {
        $campaign = MarketingCampaign::create([
            'campaign_key' => $campaignKey,
            'name' => 'Spring Sale',
            'status' => MarketingCampaign::STATUS_ACTIVE,
        ]);

        $campaignProduct = CampaignProduct::create([
            'marketing_campaign_id' => $campaign->id,
            'shopify_product_id' => $productId,
            'shopify_variant_id' => 5001,
        ]);

        CampaignProductResponse::create([
            'campaign_product_id' => $campaignProduct->id,
            'source' => CampaignProductResponse::SOURCE_AI,
            'body' => 'Generated marketing copy.',
            'generated_at' => now(),
        ]);

        return $campaignProduct;
    }

    private function orderPayload(array $lineItems, int $orderId = 9001): array
    {
        return [
            'id' => $orderId,
            'email' => 'buyer@example.com',
            'customer' => ['first_name' => 'Jane', 'last_name' => 'Doe'],
            'line_items' => $lineItems,
        ];
    }

    private function lineItem(int $productId, int $lineItemId, ?string $campaignKey): array
    {
        $properties = [];
        if ($campaignKey !== null) {
            $properties[] = ['name' => '_campaign_key', 'value' => $campaignKey];
        }

        return [
            'id' => $lineItemId,
            'product_id' => $productId,
            'properties' => $properties,
        ];
    }

    public function test_resolves_active_campaign_and_creates_pending_delivery(): void
    {
        Queue::fake();
        $campaignProduct = $this->activeCampaignWithResponse();

        $response = $this->postJson(route('webhook.shopify.campaign'), $this->orderPayload([
            $this->lineItem(111, 501, 'spring-sale'),
        ]));

        $response->assertOk();
        $this->assertDatabaseHas('campaign_deliveries', [
            'shopify_order_id' => 9001,
            'shopify_line_item_id' => 501,
            'campaign_product_id' => $campaignProduct->id,
            'status' => CampaignDelivery::STATUS_PENDING,
            'customer_email' => 'buyer@example.com',
        ]);

        $delivery = CampaignDelivery::where('shopify_line_item_id', 501)->firstOrFail();
        $this->assertNotNull($delivery->scheduled_at);

        Queue::assertPushed(SendCampaignEmailJob::class, fn ($job) => $job->deliveryId === $delivery->id);
    }

    public function test_does_not_dispatch_send_job_for_failed_delivery(): void
    {
        Queue::fake();
        $this->activeCampaignWithResponse();

        $this->postJson(route('webhook.shopify.campaign'), $this->orderPayload([
            $this->lineItem(111, 510, null),
        ]));

        Queue::assertNotPushed(SendCampaignEmailJob::class);
    }

    public function test_missing_campaign_key_creates_failed_delivery_and_notifies_admin(): void
    {
        Queue::fake();
        $this->activeCampaignWithResponse();

        $this->postJson(route('webhook.shopify.campaign'), $this->orderPayload([
            $this->lineItem(111, 502, null),
        ]));

        $this->assertDatabaseHas('campaign_deliveries', [
            'shopify_line_item_id' => 502,
            'status' => CampaignDelivery::STATUS_FAILED,
            'error_message' => 'missing _campaign_key',
        ]);

        $delivery = CampaignDelivery::where('shopify_line_item_id', 502)->firstOrFail();
        Queue::assertPushed(NotifyCampaignFailureJob::class, fn ($job) => $job->deliveryId === $delivery->id);
    }

    public function test_unknown_campaign_key_creates_failed_delivery(): void
    {
        $this->activeCampaignWithResponse();

        $this->postJson(route('webhook.shopify.campaign'), $this->orderPayload([
            $this->lineItem(111, 503, 'does-not-exist'),
        ]));

        $this->assertDatabaseHas('campaign_deliveries', [
            'shopify_line_item_id' => 503,
            'status' => CampaignDelivery::STATUS_FAILED,
            'error_message' => 'unknown campaign_key: does-not-exist',
        ]);
    }

    public function test_inactive_campaign_creates_failed_delivery(): void
    {
        $campaign = MarketingCampaign::create([
            'campaign_key' => 'draft-campaign',
            'name' => 'Draft',
            'status' => MarketingCampaign::STATUS_DRAFT,
        ]);
        CampaignProduct::create([
            'marketing_campaign_id' => $campaign->id,
            'shopify_product_id' => 111,
            'shopify_variant_id' => 5001,
        ]);

        $this->postJson(route('webhook.shopify.campaign'), $this->orderPayload([
            $this->lineItem(111, 504, 'draft-campaign'),
        ]));

        $this->assertDatabaseHas('campaign_deliveries', [
            'shopify_line_item_id' => 504,
            'status' => CampaignDelivery::STATUS_FAILED,
            'error_message' => 'campaign not active: draft-campaign',
        ]);
    }

    public function test_missing_response_creates_failed_delivery(): void
    {
        $campaign = MarketingCampaign::create([
            'campaign_key' => 'no-response-yet',
            'name' => 'No Response',
            'status' => MarketingCampaign::STATUS_ACTIVE,
        ]);
        CampaignProduct::create([
            'marketing_campaign_id' => $campaign->id,
            'shopify_product_id' => 111,
            'shopify_variant_id' => 5001,
        ]);

        $this->postJson(route('webhook.shopify.campaign'), $this->orderPayload([
            $this->lineItem(111, 505, 'no-response-yet'),
        ]));

        $this->assertDatabaseHas('campaign_deliveries', [
            'shopify_line_item_id' => 505,
            'status' => CampaignDelivery::STATUS_FAILED,
            'error_message' => 'no response generated for pairing',
        ]);
    }

    public function test_duplicate_webhook_id_does_not_duplicate_deliveries(): void
    {
        $this->activeCampaignWithResponse();

        $payload = $this->orderPayload([$this->lineItem(111, 506, 'spring-sale')]);

        $headers = ['X-Shopify-Webhook-Id' => 'wh-dup-1'];

        $this->postJson(route('webhook.shopify.campaign'), $payload, $headers)->assertOk();
        $this->postJson(route('webhook.shopify.campaign'), $payload, $headers)->assertOk();

        $this->assertDatabaseCount('campaign_deliveries', 1);
    }

    public function test_schedule_is_computed_once_per_order_and_reused_across_line_items(): void
    {
        $productA = $this->activeCampaignWithResponse('spring-sale', 111);
        $campaignB = MarketingCampaign::create([
            'campaign_key' => 'summer-sale',
            'name' => 'Summer Sale',
            'status' => MarketingCampaign::STATUS_ACTIVE,
        ]);
        $productB = CampaignProduct::create([
            'marketing_campaign_id' => $campaignB->id,
            'shopify_product_id' => 222,
            'shopify_variant_id' => 5002,
        ]);
        CampaignProductResponse::create([
            'campaign_product_id' => $productB->id,
            'source' => CampaignProductResponse::SOURCE_AI,
            'body' => 'Summer copy.',
            'generated_at' => now(),
        ]);

        $this->postJson(route('webhook.shopify.campaign'), $this->orderPayload([
            $this->lineItem(111, 507, 'spring-sale'),
            $this->lineItem(222, 508, 'summer-sale'),
        ]))->assertOk();

        $deliveries = CampaignDelivery::whereIn('shopify_line_item_id', [507, 508])->get();
        $this->assertCount(2, $deliveries);
        $this->assertTrue($deliveries[0]->scheduled_at->equalTo($deliveries[1]->scheduled_at));
    }

    public function test_order_with_only_reading_product_creates_zero_campaign_deliveries(): void
    {
        $this->activeCampaignWithResponse('spring-sale', 111);

        $this->postJson(route('webhook.shopify.campaign'), $this->orderPayload([
            $this->lineItem(999, 509, null),
        ]))->assertOk();

        $this->assertDatabaseCount('campaign_deliveries', 0);
    }
}
