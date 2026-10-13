<?php

namespace Tests\Feature\Admin;

use App\Jobs\Campaign\SendCampaignEmailJob;
use App\Models\CampaignDelivery;
use App\Models\CampaignProduct;
use App\Models\CampaignProductResponse;
use App\Models\MarketingCampaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
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

    public function test_show_renders_the_formatted_response_body(): void
    {
        $campaign = MarketingCampaign::create(['campaign_key' => 'spring', 'name' => 'Spring', 'status' => MarketingCampaign::STATUS_ACTIVE]);
        $campaignProduct = $this->campaignProductWithResponse($campaign);
        $campaignProduct->response->update(['body' => '<h2>Heading</h2><p>Copy</p><script>steal()</script>']);
        $delivery = $this->delivery(['campaign_product_id' => $campaignProduct->id]);

        $this->actingAs(User::factory()->create())->get(route('admin.campaign_deliveries.show', $delivery))
            ->assertOk()
            ->assertSee('<h2>Heading</h2>', false)
            ->assertDontSee('steal()', false);
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

    public function test_admin_can_repair_attribution_failure_by_re_pairing_then_reactivating(): void
    {
        Queue::fake();

        $campaign = MarketingCampaign::create(['campaign_key' => 'spring', 'name' => 'Spring', 'status' => MarketingCampaign::STATUS_ACTIVE]);
        $campaignProduct = $this->campaignProductWithResponse($campaign);
        $delivery = $this->delivery([
            'campaign_product_id' => null,
            'status' => CampaignDelivery::STATUS_FAILED,
            'error_message' => 'unknown campaign_key: spring',
        ]);
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->put(route('admin.campaign_deliveries.update', $delivery), [
                'customer_email' => $delivery->customer_email,
                'customer_name' => $delivery->customer_name,
                'campaign_product_id' => $campaignProduct->id,
            ])
            ->assertRedirect(route('admin.campaign_deliveries.show', $delivery));

        // Re-pairing alone never changes state: the admin reactivates explicitly.
        $this->assertSame($campaignProduct->id, $delivery->fresh()->campaign_product_id);
        $this->assertSame(CampaignDelivery::STATUS_FAILED, $delivery->fresh()->status);

        $this->actingAs($admin)
            ->post(route('admin.campaign_deliveries.reactivate', $delivery))
            ->assertSessionHas('success');

        $this->assertSame(CampaignDelivery::STATUS_PENDING, $delivery->fresh()->status);
        $this->assertNull($delivery->fresh()->error_message);

        // The dispatcher (not the admin action) owns sending: nothing is queued here.
        Queue::assertNotPushed(SendCampaignEmailJob::class);
    }

    public function test_updating_a_delivery_never_dispatches_a_send(): void
    {
        Queue::fake();

        $campaign = MarketingCampaign::create(['campaign_key' => 'spring', 'name' => 'Spring', 'status' => MarketingCampaign::STATUS_ACTIVE]);
        $campaignProduct = $this->campaignProductWithResponse($campaign);
        $delivery = $this->delivery(['campaign_product_id' => $campaignProduct->id, 'status' => CampaignDelivery::STATUS_PENDING]);

        $this->actingAs(User::factory()->create())
            ->put(route('admin.campaign_deliveries.update', $delivery), [
                'customer_email' => $delivery->customer_email,
                'customer_name' => 'Updated Name',
                'campaign_product_id' => $campaignProduct->id,
                'status' => CampaignDelivery::STATUS_SENT,
            ]);

        $this->assertSame('Updated Name', $delivery->fresh()->customer_name);
        // A posted status is ignored: state only changes through explicit actions.
        $this->assertSame(CampaignDelivery::STATUS_PENDING, $delivery->fresh()->status);

        Queue::assertNotPushed(SendCampaignEmailJob::class);
    }

    public function test_resend_claims_the_delivery_and_dispatches_one_token_bound_job(): void
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

        $delivery->refresh();
        $this->assertSame(CampaignDelivery::STATUS_SENDING, $delivery->status);
        $this->assertNotNull($delivery->claim_token);

        Queue::assertPushed(
            SendCampaignEmailJob::class,
            fn ($job) => $job->deliveryId === $delivery->id && $job->claimToken === $delivery->claim_token
        );
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

    public function test_preview_endpoint_requires_authentication(): void
    {
        $campaign = MarketingCampaign::create(['campaign_key' => 'spring', 'name' => 'Spring', 'status' => MarketingCampaign::STATUS_ACTIVE]);
        $campaignProduct = $this->campaignProductWithResponse($campaign);
        $delivery = $this->delivery(['campaign_product_id' => $campaignProduct->id]);

        $this->get(route('admin.campaign_deliveries.preview', $delivery))
            ->assertRedirect(route('login'));

        $this->post(route('admin.campaign_deliveries.preview', $delivery))
            ->assertRedirect(route('login'));
    }

    public function test_preview_renders_real_mailable_html_without_side_effects(): void
    {
        Mail::fake();

        $campaign = MarketingCampaign::create(['campaign_key' => 'spring', 'name' => 'Spring', 'status' => MarketingCampaign::STATUS_ACTIVE]);
        $campaignProduct = $this->campaignProductWithResponse($campaign);
        $campaignProduct->update(['email_content' => '<p>Special offer for {{ $productTitle }}.</p>']);
        $campaignProduct->response->update(['body' => '<h2>Campaign Special</h2><p>Special offer text.</p>']);
        $delivery = $this->delivery([
            'campaign_product_id' => $campaignProduct->id,
            'customer_name' => 'VIP Customer',
            'status' => CampaignDelivery::STATUS_PENDING,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('admin.campaign_deliveries.preview', $delivery));

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertSee('Special offer for')
            ->assertSee('Campaign Special')
            ->assertSee('Special offer text.');

        $fresh = $delivery->fresh();
        $this->assertSame(CampaignDelivery::STATUS_PENDING, $fresh->status);
        $this->assertSame('VIP Customer', $fresh->customer_name);
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_preview_supports_unsaved_overrides_without_persisting(): void
    {
        Mail::fake();

        $campaign = MarketingCampaign::create(['campaign_key' => 'spring', 'name' => 'Spring', 'status' => MarketingCampaign::STATUS_ACTIVE]);
        $product1 = $this->campaignProductWithResponse($campaign, 111);
        $product1->update(['email_content' => '<p>Product 1 copy.</p>']);
        $product1->response->update(['body' => '<h2>Product One</h2><p>Body 1.</p>']);

        $product2 = $this->campaignProductWithResponse($campaign, 222);
        $product2->update(['email_content' => '<p>Enjoy {{ $productTitle }}.</p>']);
        $product2->response->update(['body' => '<h2>Product Two</h2><p>Body 2.</p>']);

        $delivery = $this->delivery([
            'campaign_product_id' => $product1->id,
            'customer_name' => 'Original Customer',
            'status' => CampaignDelivery::STATUS_PENDING,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->post(route('admin.campaign_deliveries.preview', $delivery), [
                'customer_name' => 'Overridden Customer',
                'customer_email' => 'overridden@example.com',
                'campaign_product_id' => $product2->id,
            ]);

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertSee('Product Two')
            ->assertSee('Body 2.')
            ->assertDontSee('Product One');

        $fresh = $delivery->fresh();
        $this->assertSame($product1->id, $fresh->campaign_product_id);
        $this->assertSame('Original Customer', $fresh->customer_name);
        $this->assertSame(CampaignDelivery::STATUS_PENDING, $fresh->status);
        Mail::assertNothingSent();
        Mail::assertNothingQueued();

        // Also test unassigning the campaign product without saving
        $unassignedResponse = $this->actingAs(User::factory()->create())
            ->post(route('admin.campaign_deliveries.preview', $delivery), [
                'campaign_product_id' => '',
            ]);

        $unassignedResponse->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertDontSee('Product One')
            ->assertDontSee('Product Two');

        $this->assertSame($product1->id, $delivery->fresh()->campaign_product_id);
    }

    public function test_show_and_edit_pages_embed_sandboxed_preview_iframe_with_controls(): void
    {
        $campaign = MarketingCampaign::create(['campaign_key' => 'spring', 'name' => 'Spring', 'status' => MarketingCampaign::STATUS_ACTIVE]);
        $campaignProduct = $this->campaignProductWithResponse($campaign);
        $delivery = $this->delivery(['campaign_product_id' => $campaignProduct->id]);

        $showResponse = $this->actingAs(User::factory()->create())
            ->get(route('admin.campaign_deliveries.show', $delivery));

        $showResponse->assertOk()
            ->assertSee('sandbox="allow-same-origin"', false)
            ->assertSee('Desktop', false)
            ->assertSee('Mobile', false)
            ->assertSee(route('admin.campaign_deliveries.preview', $delivery), false);

        $editResponse = $this->actingAs(User::factory()->create())
            ->get(route('admin.campaign_deliveries.edit', $delivery));

        $editResponse->assertOk()
            ->assertSee('sandbox="allow-same-origin"', false)
            ->assertSee('Desktop', false)
            ->assertSee('Mobile', false)
            ->assertSee(route('admin.campaign_deliveries.preview', $delivery), false);
    }
}
