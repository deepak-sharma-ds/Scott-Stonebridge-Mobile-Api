<?php

namespace Tests\Feature\Admin;

use App\Models\CampaignProduct;
use App\Models\MarketingCampaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use Tests\TestCase;

class MarketingCampaignControllerTest extends TestCase
{
    use RefreshDatabase;

    private function fakeOpenAiResponse(string $content = 'Generated marketing copy.'): void
    {
        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [[
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => $content],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 8, 'total_tokens' => 20],
            ]),
        ]);
    }

    public function test_admin_can_create_a_marketing_campaign(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->post(route('admin.marketing-campaigns.store'), [
                'campaign_key' => 'spring-sale',
                'name' => 'Spring Sale',
                'klaviyo_campaign_id' => 'klaviyo-123',
                'status' => MarketingCampaign::STATUS_DRAFT,
            ]);

        $campaign = MarketingCampaign::where('campaign_key', 'spring-sale')->firstOrFail();
        $response->assertRedirect(route('admin.marketing-campaigns.show', $campaign));

        $this->assertDatabaseHas('marketing_campaigns', [
            'campaign_key' => 'spring-sale',
            'name' => 'Spring Sale',
            'klaviyo_campaign_id' => 'klaviyo-123',
            'status' => MarketingCampaign::STATUS_DRAFT,
        ]);
    }

    public function test_campaign_key_must_be_unique(): void
    {
        MarketingCampaign::create([
            'campaign_key' => 'spring-sale',
            'name' => 'Existing',
            'status' => MarketingCampaign::STATUS_DRAFT,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->from(route('admin.marketing-campaigns.create'))
            ->post(route('admin.marketing-campaigns.store'), [
                'campaign_key' => 'spring-sale',
                'name' => 'Another Spring Sale',
                'status' => MarketingCampaign::STATUS_DRAFT,
            ]);

        $response->assertRedirect(route('admin.marketing-campaigns.create'));
        $response->assertSessionHasErrors(['campaign_key']);
        $this->assertDatabaseCount('marketing_campaigns', 1);
    }

    public function test_admin_can_change_campaign_status(): void
    {
        $campaign = MarketingCampaign::create([
            'campaign_key' => 'spring-sale',
            'name' => 'Spring Sale',
            'status' => MarketingCampaign::STATUS_DRAFT,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->put(route('admin.marketing-campaigns.update', $campaign), [
                'campaign_key' => $campaign->campaign_key,
                'name' => $campaign->name,
                'status' => MarketingCampaign::STATUS_ACTIVE,
            ]);

        $response->assertRedirect(route('admin.marketing-campaigns.show', $campaign));
        $this->assertSame(MarketingCampaign::STATUS_ACTIVE, $campaign->fresh()->status);
    }

    public function test_only_active_campaigns_are_returned_by_the_active_scope(): void
    {
        MarketingCampaign::create(['campaign_key' => 'draft-one', 'name' => 'Draft', 'status' => MarketingCampaign::STATUS_DRAFT]);
        $active = MarketingCampaign::create(['campaign_key' => 'active-one', 'name' => 'Active', 'status' => MarketingCampaign::STATUS_ACTIVE]);

        $result = MarketingCampaign::active()->get();

        $this->assertCount(1, $result);
        $this->assertTrue($result->first()->is($active));
    }

    public function test_same_shopify_product_can_be_linked_to_multiple_campaigns(): void
    {
        $campaignA = MarketingCampaign::create(['campaign_key' => 'campaign-a', 'name' => 'A', 'status' => MarketingCampaign::STATUS_DRAFT]);
        $campaignB = MarketingCampaign::create(['campaign_key' => 'campaign-b', 'name' => 'B', 'status' => MarketingCampaign::STATUS_DRAFT]);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('admin.marketing-campaigns.products.store', $campaignA), [
            'shopify_product_id' => 999,
            'shopify_variant_id' => 5001,
        ])->assertRedirect(route('admin.marketing-campaigns.show', $campaignA));

        $this->actingAs($user)->post(route('admin.marketing-campaigns.products.store', $campaignB), [
            'shopify_product_id' => 999,
            'shopify_variant_id' => 5002,
        ])->assertRedirect(route('admin.marketing-campaigns.show', $campaignB));

        $this->assertDatabaseCount('campaign_products', 2);
        $this->assertDatabaseHas('campaign_products', ['marketing_campaign_id' => $campaignA->id, 'shopify_product_id' => 999]);
        $this->assertDatabaseHas('campaign_products', ['marketing_campaign_id' => $campaignB->id, 'shopify_product_id' => 999]);
    }

    public function test_same_product_cannot_be_linked_twice_to_the_same_campaign(): void
    {
        $campaign = MarketingCampaign::create(['campaign_key' => 'campaign-a', 'name' => 'A', 'status' => MarketingCampaign::STATUS_DRAFT]);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('admin.marketing-campaigns.products.store', $campaign), [
            'shopify_product_id' => 999,
            'shopify_variant_id' => 5001,
        ]);

        $response = $this->actingAs($user)
            ->from(route('admin.marketing-campaigns.show', $campaign))
            ->post(route('admin.marketing-campaigns.products.store', $campaign), [
                'shopify_product_id' => 999,
                'shopify_variant_id' => 5002,
            ]);

        $response->assertSessionHasErrors(['shopify_product_id']);
        $this->assertDatabaseCount('campaign_products', 1);
    }

    public function test_admin_can_generate_a_response_for_a_campaign_product_pairing(): void
    {
        $this->fakeOpenAiResponse('Discover your future with our love reading.');

        $campaign = MarketingCampaign::create(['campaign_key' => 'campaign-a', 'name' => 'A', 'status' => MarketingCampaign::STATUS_DRAFT]);
        $campaignProduct = CampaignProduct::create([
            'marketing_campaign_id' => $campaign->id,
            'shopify_product_id' => 111,
            'product_title' => 'Love Reading',
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->post(route('admin.marketing-campaigns.products.generate', [$campaign, $campaignProduct]));

        $response->assertRedirect(route('admin.marketing-campaigns.show', $campaign));

        $this->assertDatabaseHas('campaign_product_responses', [
            'campaign_product_id' => $campaignProduct->id,
            'ai_response' => 'Discover your future with our love reading.',
        ]);
        $this->assertDatabaseCount('campaign_product_responses', 1);
    }

    public function test_regenerating_a_response_replaces_it_instead_of_duplicating(): void
    {
        $campaign = MarketingCampaign::create(['campaign_key' => 'campaign-a', 'name' => 'A', 'status' => MarketingCampaign::STATUS_DRAFT]);
        $campaignProduct = CampaignProduct::create([
            'marketing_campaign_id' => $campaign->id,
            'shopify_product_id' => 111,
        ]);
        $user = User::factory()->create();

        $this->fakeOpenAiResponse('First draft.');
        $this->actingAs($user)->post(route('admin.marketing-campaigns.products.generate', [$campaign, $campaignProduct]));

        $this->fakeOpenAiResponse('Second draft.');
        $this->actingAs($user)->post(route('admin.marketing-campaigns.products.generate', [$campaign, $campaignProduct]));

        $this->assertDatabaseCount('campaign_product_responses', 1);
        $this->assertDatabaseHas('campaign_product_responses', [
            'campaign_product_id' => $campaignProduct->id,
            'ai_response' => 'Second draft.',
        ]);
    }

    public function test_generating_a_response_does_not_change_campaign_status(): void
    {
        $this->fakeOpenAiResponse();

        $campaign = MarketingCampaign::create(['campaign_key' => 'campaign-a', 'name' => 'A', 'status' => MarketingCampaign::STATUS_DRAFT]);
        $campaignProduct = CampaignProduct::create([
            'marketing_campaign_id' => $campaign->id,
            'shopify_product_id' => 111,
        ]);

        $this->actingAs(User::factory()->create())
            ->post(route('admin.marketing-campaigns.products.generate', [$campaign, $campaignProduct]));

        $this->assertSame(MarketingCampaign::STATUS_DRAFT, $campaign->fresh()->status);
    }

    public function test_linking_a_product_requires_a_shopify_variant_id(): void
    {
        $campaign = MarketingCampaign::create(['campaign_key' => 'campaign-a', 'name' => 'A', 'status' => MarketingCampaign::STATUS_DRAFT]);

        $response = $this->actingAs(User::factory()->create())
            ->from(route('admin.marketing-campaigns.show', $campaign))
            ->post(route('admin.marketing-campaigns.products.store', $campaign), [
                'shopify_product_id' => 999,
            ]);

        $response->assertSessionHasErrors(['shopify_variant_id']);
        $this->assertDatabaseCount('campaign_products', 0);
    }

    public function test_campaign_show_page_displays_the_generated_campaign_link(): void
    {
        config()->set('shopify.store_domain', 'test-shop.myshopify.com');

        $campaign = MarketingCampaign::create(['campaign_key' => 'campaign-a', 'name' => 'A', 'status' => MarketingCampaign::STATUS_DRAFT]);
        CampaignProduct::create([
            'marketing_campaign_id' => $campaign->id,
            'shopify_product_id' => 111,
            'shopify_variant_id' => 5001,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('admin.marketing-campaigns.show', $campaign));

        $response->assertOk();
        $response->assertSee('https://test-shop.myshopify.com/cart/5001:1?properties=', false);
    }

    public function test_admin_can_unlink_a_product_from_a_campaign(): void
    {
        $campaign = MarketingCampaign::create(['campaign_key' => 'campaign-a', 'name' => 'A', 'status' => MarketingCampaign::STATUS_DRAFT]);
        $campaignProduct = CampaignProduct::create([
            'marketing_campaign_id' => $campaign->id,
            'shopify_product_id' => 111,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->delete(route('admin.marketing-campaigns.products.destroy', [$campaign, $campaignProduct]));

        $response->assertRedirect(route('admin.marketing-campaigns.show', $campaign));
        $this->assertDatabaseMissing('campaign_products', ['id' => $campaignProduct->id]);
    }
}
