<?php

namespace Tests\Feature\Jobs\Campaign;

use App\Contracts\Services\OrderFulfillmentServiceInterface;
use App\Jobs\Campaign\MarkCampaignOrderFulfilledJob;
use App\Models\CampaignDelivery;
use App\Models\CampaignProduct;
use App\Models\MarketingCampaign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarkCampaignOrderFulfilledJobTest extends TestCase
{
    use RefreshDatabase;

    private function sentDelivery(): CampaignDelivery
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
        ]);

        return CampaignDelivery::create([
            'shopify_order_id' => 9001,
            'shopify_line_item_id' => 501,
            'campaign_product_id' => $campaignProduct->id,
            'customer_email' => 'buyer@example.com',
            'status' => CampaignDelivery::STATUS_SENT,
            'sent_at' => now(),
        ]);
    }

    public function test_fulfills_line_item_and_stamps_fulfilled_at(): void
    {
        $delivery = $this->sentDelivery();

        $this->mock(OrderFulfillmentServiceInterface::class, function ($mock) use ($delivery) {
            $mock->shouldReceive('fulfillLineItems')
                ->once()
                ->with(9001, [$delivery->shopify_line_item_id], false)
                ->andReturn(['fulfilled' => true, 'fulfillment_id' => 'gid://shopify/Fulfillment/1', 'status' => 'SUCCESS']);
        });

        (new MarkCampaignOrderFulfilledJob($delivery->id))->handle(app(OrderFulfillmentServiceInterface::class));

        $this->assertNotNull($delivery->refresh()->fulfilled_at);
    }

    public function test_is_idempotent_when_already_fulfilled(): void
    {
        $delivery = $this->sentDelivery();
        $delivery->forceFill(['fulfilled_at' => now()])->save();

        $this->mock(OrderFulfillmentServiceInterface::class, function ($mock) {
            $mock->shouldNotReceive('fulfillLineItems');
        });

        (new MarkCampaignOrderFulfilledJob($delivery->id))->handle(app(OrderFulfillmentServiceInterface::class));
    }

    public function test_does_nothing_when_fulfillment_disabled(): void
    {
        config(['campaign_email.fulfillment.enabled' => false]);
        $delivery = $this->sentDelivery();

        $this->mock(OrderFulfillmentServiceInterface::class, function ($mock) {
            $mock->shouldNotReceive('fulfillLineItems');
        });

        (new MarkCampaignOrderFulfilledJob($delivery->id))->handle(app(OrderFulfillmentServiceInterface::class));

        $this->assertNull($delivery->refresh()->fulfilled_at);
    }
}
