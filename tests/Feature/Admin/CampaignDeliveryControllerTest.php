<?php

namespace Tests\Feature\Admin;

use App\Jobs\Campaign\SendCampaignEmailJob;
use App\Models\CampaignDelivery;
use App\Models\CampaignProduct;
use App\Models\CampaignProductResponse;
use App\Models\MarketingCampaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CampaignDeliveryControllerTest extends TestCase
{
    use RefreshDatabase;

    private function campaignProductWithResponse(MarketingCampaign $campaign, int $shopifyProductId = 111): CampaignProduct
    {
        $campaignProduct = CampaignProduct::create([
            'marketing_campaign_id' => $campaign->id,
            'shopify_product_id' => $shopifyProductId,
            'product_title' => 'Love Reading',
        ]);

        CampaignProductResponse::create([
            'campaign_product_id' => $campaignProduct->id,
            'source' => CampaignProductResponse::SOURCE_MANUAL,
            'body' => 'Pre-generated copy.',
        ]);

        return $campaignProduct;
    }

    private function delivery(array $overrides = []): CampaignDelivery
    {
        return CampaignDelivery::create(array_merge([
            'shopify_order_id' => 9001,
            'shopify_line_item_id' => 501,
            'customer_email' => 'buyer@example.com',
            'customer_name' => 'Jane Doe',
            'status' => CampaignDelivery::STATUS_PENDING,
        ], $overrides));
    }

    public function test_index_filters_by_status(): void
    {
        $campaign = MarketingCampaign::create(['campaign_key' => 'spring', 'name' => 'Spring', 'status' => MarketingCampaign::STATUS_ACTIVE]);
        $campaignProduct = $this->campaignProductWithResponse($campaign);

        $this->delivery(['campaign_product_id' => $campaignProduct->id, 'shopify_line_item_id' => 501, 'customer_name' => 'Sent Buyer', 'status' => CampaignDelivery::STATUS_SENT, 'sent_at' => now()]);
        $this->delivery(['campaign_product_id' => $campaignProduct->id, 'shopify_line_item_id' => 502, 'customer_name' => 'Failed Buyer', 'status' => CampaignDelivery::STATUS_FAILED, 'error_message' => 'unknown campaign_key']);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('admin.campaign_deliveries.index', ['status' => CampaignDelivery::STATUS_FAILED]));

        $response->assertOk();
        $response->assertSee('Failed Buyer');
        $response->assertDontSee('Sent Buyer');
    }

    public function test_index_filters_by_campaign(): void
    {
        $campaignA = MarketingCampaign::create(['campaign_key' => 'campaign-a', 'name' => 'Campaign A', 'status' => MarketingCampaign::STATUS_ACTIVE]);
        $campaignB = MarketingCampaign::create(['campaign_key' => 'campaign-b', 'name' => 'Campaign B', 'status' => MarketingCampaign::STATUS_ACTIVE]);
        $productA = $this->campaignProductWithResponse($campaignA, 111);
        $productB = $this->campaignProductWithResponse($campaignB, 222);

        $this->delivery(['campaign_product_id' => $productA->id, 'shopify_line_item_id' => 501, 'customer_name' => 'From A']);
        $this->delivery(['campaign_product_id' => $productB->id, 'shopify_line_item_id' => 502, 'customer_name' => 'From B']);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('admin.campaign_deliveries.index', ['campaign' => $campaignA->id]));

        $response->assertOk();
        $response->assertSee('From A');
        $response->assertDontSee('From B');
    }

    public function test_admin_can_view_delivery_show_page(): void
    {
        $campaign = MarketingCampaign::create(['campaign_key' => 'spring', 'name' => 'Spring', 'status' => MarketingCampaign::STATUS_ACTIVE]);
        $campaignProduct = $this->campaignProductWithResponse($campaign);
        $delivery = $this->delivery(['campaign_product_id' => $campaignProduct->id]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('admin.campaign_deliveries.show', $delivery));

        $response->assertOk();
        $response->assertSee('Pre-generated copy.');
        $response->assertSee('Spring');
    }

    public function test_admin_can_repair_attribution_failure_by_reassigning_campaign_product(): void
    {
        $campaign = MarketingCampaign::create(['campaign_key' => 'spring', 'name' => 'Spring', 'status' => MarketingCampaign::STATUS_ACTIVE]);
        $campaignProduct = $this->campaignProductWithResponse($campaign);
        $delivery = $this->delivery([
            'campaign_product_id' => null,
            'status' => CampaignDelivery::STATUS_FAILED,
            'error_message' => 'unknown campaign_key: spring',
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->put(route('admin.campaign_deliveries.update', $delivery), [
                'customer_email' => $delivery->customer_email,
                'customer_name' => $delivery->customer_name,
                'campaign_product_id' => $campaignProduct->id,
                'status' => CampaignDelivery::STATUS_PENDING,
            ]);

        $response->assertRedirect(route('admin.campaign_deliveries.show', $delivery));
        $this->assertSame($campaignProduct->id, $delivery->fresh()->campaign_product_id);
        $this->assertSame(CampaignDelivery::STATUS_PENDING, $delivery->fresh()->status);
    }

    public function test_send_forces_status_to_pending_and_dispatches_the_job(): void
    {
        Queue::fake();

        $campaign = MarketingCampaign::create(['campaign_key' => 'spring', 'name' => 'Spring', 'status' => MarketingCampaign::STATUS_ACTIVE]);
        $campaignProduct = $this->campaignProductWithResponse($campaign);
        $delivery = $this->delivery([
            'campaign_product_id' => $campaignProduct->id,
            'status' => CampaignDelivery::STATUS_SENT,
            'sent_at' => now(),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->post(route('admin.campaign_deliveries.send', $delivery));

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Delivery re-sent.');
        $this->assertSame(CampaignDelivery::STATUS_PENDING, $delivery->fresh()->status);

        Queue::assertPushed(SendCampaignEmailJob::class, fn ($job) => $job->deliveryId === $delivery->id);
    }

    public function test_send_is_blocked_when_no_campaign_response_is_resolved(): void
    {
        Queue::fake();

        $delivery = $this->delivery([
            'campaign_product_id' => null,
            'status' => CampaignDelivery::STATUS_FAILED,
            'error_message' => 'unknown campaign_key: spring',
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->post(route('admin.campaign_deliveries.send', $delivery));

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertSame(CampaignDelivery::STATUS_FAILED, $delivery->fresh()->status);

        Queue::assertNotPushed(SendCampaignEmailJob::class);
    }

    public function test_cancel_is_allowed_on_a_failed_delivery(): void
    {
        $delivery = $this->delivery(['status' => CampaignDelivery::STATUS_FAILED, 'error_message' => 'unknown campaign_key']);

        $this->actingAs(User::factory()->create())
            ->post(route('admin.campaign_deliveries.cancel', $delivery));

        $this->assertSame(CampaignDelivery::STATUS_CANCELLED, $delivery->fresh()->status);
    }

    public function test_cancel_is_a_no_op_on_a_sent_delivery(): void
    {
        $delivery = $this->delivery(['status' => CampaignDelivery::STATUS_SENT, 'sent_at' => now()]);

        $this->actingAs(User::factory()->create())
            ->post(route('admin.campaign_deliveries.cancel', $delivery));

        $this->assertSame(CampaignDelivery::STATUS_SENT, $delivery->fresh()->status);
    }

    public function test_destroy_is_blocked_on_a_sent_delivery(): void
    {
        $delivery = $this->delivery(['status' => CampaignDelivery::STATUS_SENT, 'sent_at' => now()]);

        $response = $this->actingAs(User::factory()->create())
            ->delete(route('admin.campaign_deliveries.destroy', $delivery));

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertDatabaseHas('campaign_deliveries', ['id' => $delivery->id]);
    }

    public function test_destroy_is_allowed_on_a_cancelled_delivery(): void
    {
        $delivery = $this->delivery(['status' => CampaignDelivery::STATUS_CANCELLED]);

        $response = $this->actingAs(User::factory()->create())
            ->delete(route('admin.campaign_deliveries.destroy', $delivery));

        $response->assertRedirect(route('admin.campaign_deliveries.index'));
        $this->assertDatabaseMissing('campaign_deliveries', ['id' => $delivery->id]);
    }

    public function test_export_streams_a_csv_respecting_the_status_filter(): void
    {
        $campaign = MarketingCampaign::create(['campaign_key' => 'spring', 'name' => 'Spring', 'status' => MarketingCampaign::STATUS_ACTIVE]);
        $campaignProduct = $this->campaignProductWithResponse($campaign);

        $this->delivery(['campaign_product_id' => $campaignProduct->id, 'shopify_line_item_id' => 501, 'status' => CampaignDelivery::STATUS_SENT, 'sent_at' => now()]);
        $this->delivery(['campaign_product_id' => $campaignProduct->id, 'shopify_line_item_id' => 502, 'status' => CampaignDelivery::STATUS_FAILED]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('admin.campaign_deliveries.export', ['status' => CampaignDelivery::STATUS_SENT]));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');

        $csv = $response->streamedContent();
        $this->assertStringContainsString('Campaign Key', $csv);
        $this->assertStringContainsString('501', $csv);
        $this->assertStringNotContainsString('502', $csv);
    }
}
