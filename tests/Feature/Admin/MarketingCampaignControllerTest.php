<?php

namespace Tests\Feature\Admin;

use App\Mail\CampaignEmailMail;
use App\Models\CampaignDelivery;
use App\Models\CampaignProduct;
use App\Models\CampaignProductResponse;
use App\Models\MarketingCampaign;
use App\Models\UnlistedProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use Tests\TestCase;

class MarketingCampaignControllerTest extends TestCase
{
    use RefreshDatabase;

    private function fakeOpenAiResponse(string $content = "## Heading\n\nGenerated marketing copy.\n\n### From My Heart to Yours\n\nParting blessings."): void
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

    public function test_linking_a_product_defaults_to_manual_with_no_response_when_body_left_blank(): void
    {
        $campaign = MarketingCampaign::create(['campaign_key' => 'campaign-a', 'name' => 'A', 'status' => MarketingCampaign::STATUS_DRAFT]);

        $this->actingAs(User::factory()->create())
            ->post(route('admin.marketing-campaigns.products.store', $campaign), [
                'shopify_product_id' => 999,
                'shopify_variant_id' => 5001,
            ]);

        $campaignProduct = CampaignProduct::where('shopify_product_id', 999)->firstOrFail();
        $this->assertNull($campaignProduct->response);
    }

    public function test_linking_a_product_with_a_manual_body_saves_the_response_immediately(): void
    {
        $campaign = MarketingCampaign::create(['campaign_key' => 'campaign-a', 'name' => 'A', 'status' => MarketingCampaign::STATUS_DRAFT]);

        $this->actingAs(User::factory()->create())
            ->post(route('admin.marketing-campaigns.products.store', $campaign), [
                'shopify_product_id' => 999,
                'shopify_variant_id' => 5001,
                'source' => CampaignProductResponse::SOURCE_MANUAL,
                'body' => 'Hand-written copy at link time.',
            ]);

        $campaignProduct = CampaignProduct::where('shopify_product_id', 999)->firstOrFail();
        $this->assertDatabaseHas('campaign_product_responses', [
            'campaign_product_id' => $campaignProduct->id,
            'source' => CampaignProductResponse::SOURCE_MANUAL,
            'body' => 'Hand-written copy at link time.',
        ]);
    }

    public function test_linking_a_product_with_ai_source_only_saves_the_prompt_and_does_not_call_openai(): void
    {
        $campaign = MarketingCampaign::create(['campaign_key' => 'campaign-a', 'name' => 'A', 'status' => MarketingCampaign::STATUS_DRAFT]);

        $this->actingAs(User::factory()->create())
            ->post(route('admin.marketing-campaigns.products.store', $campaign), [
                'shopify_product_id' => 999,
                'shopify_variant_id' => 5001,
                'source' => CampaignProductResponse::SOURCE_AI,
                'prompt_template' => 'Write about {{ $productTitle }}.',
            ]);

        $campaignProduct = CampaignProduct::where('shopify_product_id', 999)->firstOrFail();
        $this->assertSame('Write about {{ $productTitle }}.', $campaignProduct->prompt_template);
        $this->assertNull($campaignProduct->response);
        $this->assertDatabaseCount('campaign_product_responses', 0);
    }

    public function test_generate_produces_proposed_body_and_validation_without_saving_and_accept_saves_response(): void
    {
        $this->fakeOpenAiResponse("## Love Reading\n\nDiscover your future with our love reading.\n\n### From My Heart to Yours\n\nParting blessings.");

        $campaign = MarketingCampaign::create(['campaign_key' => 'campaign-a', 'name' => 'A', 'status' => MarketingCampaign::STATUS_DRAFT]);
        $campaignProduct = CampaignProduct::create([
            'marketing_campaign_id' => $campaign->id,
            'shopify_product_id' => 111,
            'product_title' => 'Love Reading',
        ]);

        $user = User::factory()->create();

        // 1. Generate: produces proposed body without saving to DB
        $generateResponse = $this->actingAs($user)
            ->postJson(route('admin.marketing-campaigns.products.generate', [$campaign, $campaignProduct]));

        $generateResponse->assertOk()
            ->assertJson([
                'success' => true,
                'is_valid' => true,
                'problems' => [],
            ]);

        $proposedHtml = $generateResponse->json('content');
        $this->assertStringContainsString('<h2>Love Reading</h2>', $proposedHtml);
        $this->assertStringContainsString('<h3>From My Heart to Yours</h3>', $proposedHtml);
        $this->assertDatabaseCount('campaign_product_responses', 0);

        // 2. Accept: saves proposed body
        $acceptResponse = $this->actingAs($user)
            ->post(route('admin.marketing-campaigns.products.respond', [$campaign, $campaignProduct]), [
                'source' => CampaignProductResponse::SOURCE_AI,
                'body' => $proposedHtml,
                'model_used' => 'gpt-4o-mini',
                'prompt_tokens' => 12,
                'completion_tokens' => 8,
            ]);

        $acceptResponse->assertRedirect(route('admin.marketing-campaigns.show', $campaign));

        $this->assertDatabaseCount('campaign_product_responses', 1);
        $this->assertDatabaseHas('campaign_product_responses', [
            'campaign_product_id' => $campaignProduct->id,
            'source' => CampaignProductResponse::SOURCE_AI,
            'body' => $proposedHtml,
            'model_used' => 'gpt-4o-mini',
        ]);
    }

    public function test_regenerating_and_accepting_replaces_it_instead_of_duplicating(): void
    {
        $campaign = MarketingCampaign::create(['campaign_key' => 'campaign-a', 'name' => 'A', 'status' => MarketingCampaign::STATUS_DRAFT]);
        $campaignProduct = CampaignProduct::create([
            'marketing_campaign_id' => $campaign->id,
            'shopify_product_id' => 111,
        ]);
        $user = User::factory()->create();

        $firstBody = '<h2>First draft.</h2><h3>From My Heart to Yours</h3><p>Blessings.</p>';
        $this->actingAs($user)->post(route('admin.marketing-campaigns.products.respond', [$campaign, $campaignProduct]), [
            'source' => CampaignProductResponse::SOURCE_AI,
            'body' => $firstBody,
        ]);

        $secondBody = '<h2>Second draft.</h2><h3>From My Heart to Yours</h3><p>Blessings.</p>';
        $this->actingAs($user)->post(route('admin.marketing-campaigns.products.respond', [$campaign, $campaignProduct]), [
            'source' => CampaignProductResponse::SOURCE_AI,
            'body' => $secondBody,
        ]);

        $this->assertDatabaseCount('campaign_product_responses', 1);
        $this->assertDatabaseHas('campaign_product_responses', [
            'campaign_product_id' => $campaignProduct->id,
            'body' => $secondBody,
        ]);
    }

    public function test_a_manual_body_is_sanitized_when_linking_and_when_responding(): void
    {
        $campaign = MarketingCampaign::create(['campaign_key' => 'campaign-a', 'name' => 'A', 'status' => MarketingCampaign::STATUS_DRAFT]);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('admin.marketing-campaigns.products.store', $campaign), [
            'shopify_product_id' => 999,
            'shopify_variant_id' => 5001,
            'source' => CampaignProductResponse::SOURCE_MANUAL,
            'body' => '<h2>Hello</h2><script>steal()</script><p onclick="x()">Copy</p>',
        ]);
        $campaignProduct = CampaignProduct::where('shopify_product_id', 999)->firstOrFail();
        $this->assertSame('<h2>Hello</h2><p>Copy</p>', $campaignProduct->response->body);

        $this->actingAs($user)->post(route('admin.marketing-campaigns.products.respond', [$campaign, $campaignProduct]), [
            'source' => CampaignProductResponse::SOURCE_MANUAL,
            'body' => '<p>Edited</p><a href="javascript:alert(1)">bad</a>',
        ])->assertSessionHas('success');

        $this->assertSame('<p>Edited</p><a>bad</a>', $campaignProduct->response()->first()->body);
    }

    public function test_a_blank_editor_body_is_rejected_on_respond_and_creates_nothing_on_link(): void
    {
        $campaign = MarketingCampaign::create(['campaign_key' => 'campaign-a', 'name' => 'A', 'status' => MarketingCampaign::STATUS_DRAFT]);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('admin.marketing-campaigns.products.store', $campaign), [
            'shopify_product_id' => 999,
            'shopify_variant_id' => 5001,
            'source' => CampaignProductResponse::SOURCE_MANUAL,
            'body' => '<p>&nbsp;</p>',
        ]);
        $campaignProduct = CampaignProduct::where('shopify_product_id', 999)->firstOrFail();
        $this->assertNull($campaignProduct->response);

        $this->actingAs($user)->post(route('admin.marketing-campaigns.products.respond', [$campaign, $campaignProduct]), [
            'source' => CampaignProductResponse::SOURCE_MANUAL,
            'body' => '<p>&nbsp;</p>',
        ])->assertSessionHasErrors('body');

        $this->assertNull($campaignProduct->response()->first());
    }

    public function test_the_campaign_page_uses_the_editor_for_manual_bodies_and_shows_the_formatted_response(): void
    {
        $campaign = MarketingCampaign::create(['campaign_key' => 'campaign-a', 'name' => 'A', 'status' => MarketingCampaign::STATUS_DRAFT]);
        $campaignProduct = CampaignProduct::create(['marketing_campaign_id' => $campaign->id, 'shopify_product_id' => 111, 'product_title' => 'Love']);
        $campaignProduct->response()->create([
            'source' => CampaignProductResponse::SOURCE_MANUAL,
            'body' => '<h2>Heading</h2><p>Body</p><script>steal()</script>',
        ]);

        $this->actingAs(User::factory()->create())->get(route('admin.marketing-campaigns.show', $campaign))
            ->assertOk()
            ->assertSee('<h2>Heading</h2>', false)
            ->assertDontSee('steal()', false)
            ->assertSee('name="body" id="body" class="form-control" rows="4" data-rich-text', false)
            ->assertSee('&lt;h2&gt;Heading&lt;/h2&gt;', false);
    }

    public function test_an_ai_generated_response_opens_in_the_rich_text_editor_for_manual_edits(): void
    {
        $campaign = MarketingCampaign::create(['campaign_key' => 'campaign-a', 'name' => 'A', 'status' => MarketingCampaign::STATUS_DRAFT]);
        $campaignProduct = CampaignProduct::create(['marketing_campaign_id' => $campaign->id, 'shopify_product_id' => 111, 'product_title' => 'Pendant']);
        $campaignProduct->response()->create([
            'source' => CampaignProductResponse::SOURCE_AI,
            'body' => '<h2>AI Headline</h2><p>AI message body.</p>',
        ]);

        $this->actingAs(User::factory()->create())->get(route('admin.marketing-campaigns.show', $campaign))
            ->assertOk()
            ->assertSee('campaignProductRow', false)
            ->assertSee('name="body" class="form-control" id="body_'.$campaignProduct->id.'" rows="5" data-rich-text', false)
            ->assertSee('&lt;h2&gt;AI Headline&lt;/h2&gt;&lt;p&gt;AI message body.&lt;/p&gt;', false);
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
            ->postJson(route('admin.marketing-campaigns.products.generate', [$campaign, $campaignProduct]))
            ->assertOk();

        $this->assertSame(MarketingCampaign::STATUS_DRAFT, $campaign->fresh()->status);
    }

    public function test_generating_can_use_prompt_template_and_formatting_contract_overrides(): void
    {
        $this->fakeOpenAiResponse("## Custom Header\n\nDraft using new prompt.\n\n### Custom Final Section\n\nBye.");

        $campaign = MarketingCampaign::create(['campaign_key' => 'campaign-a', 'name' => 'A', 'status' => MarketingCampaign::STATUS_DRAFT]);
        $campaignProduct = CampaignProduct::create([
            'marketing_campaign_id' => $campaign->id,
            'shopify_product_id' => 111,
            'product_title' => 'Deck',
            'prompt_template' => 'Old prompt.',
            'formatting_contract' => 'Old contract.',
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->postJson(route('admin.marketing-campaigns.products.generate', [$campaign, $campaignProduct]), [
                'prompt_template' => 'Updated prompt for {{ $productTitle }}.',
                'formatting_contract' => "## CUSTOM CONTRACT\nMust have section headed 'Custom Final Section'.",
            ]);

        $response->assertOk()->assertJson(['success' => true, 'is_valid' => true]);
    }

    public function test_accept_is_blocked_when_body_fails_validation(): void
    {
        $campaign = MarketingCampaign::create(['campaign_key' => 'campaign-a', 'name' => 'A', 'status' => MarketingCampaign::STATUS_DRAFT]);
        $campaignProduct = CampaignProduct::create([
            'marketing_campaign_id' => $campaign->id,
            'shopify_product_id' => 111,
            'prompt_template' => 'Original template',
        ]);
        $user = User::factory()->create();

        $invalidBody = '<h2>Headline</h2><p>Body copy without closing section.</p>';

        $response = $this->actingAs($user)
            ->from(route('admin.marketing-campaigns.show', $campaign))
            ->post(route('admin.marketing-campaigns.products.respond', [$campaign, $campaignProduct]), [
                'source' => CampaignProductResponse::SOURCE_AI,
                'prompt_template' => 'Attempted override',
                'body' => $invalidBody,
            ]);

        $response->assertRedirect(route('admin.marketing-campaigns.show', $campaign));
        $response->assertSessionHasErrors('body');
        $this->assertDatabaseCount('campaign_product_responses', 0);
        $this->assertSame('Original template', $campaignProduct->fresh()->prompt_template);

        $jsonResponse = $this->actingAs($user)
            ->postJson(route('admin.marketing-campaigns.products.respond', [$campaign, $campaignProduct]), [
                'source' => CampaignProductResponse::SOURCE_AI,
                'prompt_template' => 'Attempted override',
                'body' => $invalidBody,
            ]);

        $jsonResponse->assertStatus(422)
            ->assertJson([
                'success' => false,
            ]);
        $this->assertSame('Original template', $campaignProduct->fresh()->prompt_template);
    }

    public function test_validate_body_endpoint_checks_formatting_and_returns_problems(): void
    {
        $campaign = MarketingCampaign::create(['campaign_key' => 'campaign-a', 'name' => 'A', 'status' => MarketingCampaign::STATUS_DRAFT]);
        $campaignProduct = CampaignProduct::create([
            'marketing_campaign_id' => $campaign->id,
            'shopify_product_id' => 111,
        ]);
        $user = User::factory()->create();

        $invalidResponse = $this->actingAs($user)
            ->postJson(route('admin.marketing-campaigns.products.validate-body', [$campaign, $campaignProduct]), [
                'body' => '<p>Dear Customer,</p><p>Text without closing section.</p>',
            ]);

        $invalidResponse->assertOk()
            ->assertJson([
                'success' => true,
                'is_valid' => false,
            ]);
        $this->assertCount(2, $invalidResponse->json('problems'));

        $validResponse = $this->actingAs($user)
            ->postJson(route('admin.marketing-campaigns.products.validate-body', [$campaign, $campaignProduct]), [
                'body' => '<h2>Heading</h2><p>Valid text.</p><h3>From My Heart to Yours</h3><p>Blessings.</p>',
            ]);

        $validResponse->assertOk()
            ->assertJson([
                'success' => true,
                'is_valid' => true,
                'problems' => [],
            ]);
    }

    public function test_integrated_generate_validation_failure_edit_recheck_and_accept_workflow(): void
    {
        $this->fakeOpenAiResponse("## Headline\n\nAI drafted message without closing section.");

        $campaign = MarketingCampaign::create(['campaign_key' => 'campaign-a', 'name' => 'A', 'status' => MarketingCampaign::STATUS_DRAFT]);
        $campaignProduct = CampaignProduct::create([
            'marketing_campaign_id' => $campaign->id,
            'shopify_product_id' => 111,
            'prompt_template' => 'Original prompt',
        ]);
        $user = User::factory()->create();

        $generateResponse = $this->actingAs($user)
            ->postJson(route('admin.marketing-campaigns.products.generate', [$campaign, $campaignProduct]), [
                'prompt_template' => 'Proposed new prompt',
            ]);

        $generateResponse->assertOk()
            ->assertJson([
                'success' => true,
                'is_valid' => false,
            ]);
        $this->assertNotEmpty($generateResponse->json('problems'));
        $this->assertDatabaseCount('campaign_product_responses', 0);
        $this->assertSame('Original prompt', $campaignProduct->fresh()->prompt_template);

        $proposedContent = $generateResponse->json('content');

        $blockedAccept = $this->actingAs($user)
            ->post(route('admin.marketing-campaigns.products.respond', [$campaign, $campaignProduct]), [
                'source' => CampaignProductResponse::SOURCE_AI,
                'prompt_template' => 'Proposed new prompt',
                'body' => $proposedContent,
            ]);

        $blockedAccept->assertSessionHasErrors('body');
        $this->assertDatabaseCount('campaign_product_responses', 0);
        $this->assertSame('Original prompt', $campaignProduct->fresh()->prompt_template);

        $editedBody = $proposedContent.'<h3>From My Heart to Yours</h3><p>Blessings.</p>';

        $recheckResponse = $this->actingAs($user)
            ->postJson(route('admin.marketing-campaigns.products.validate-body', [$campaign, $campaignProduct]), [
                'body' => $editedBody,
            ]);

        $recheckResponse->assertOk()
            ->assertJson([
                'success' => true,
                'is_valid' => true,
                'problems' => [],
            ]);

        $accepted = $this->actingAs($user)
            ->post(route('admin.marketing-campaigns.products.respond', [$campaign, $campaignProduct]), [
                'source' => CampaignProductResponse::SOURCE_AI,
                'prompt_template' => 'Proposed new prompt',
                'body' => $editedBody,
            ]);

        $accepted->assertRedirect(route('admin.marketing-campaigns.show', $campaign));
        $this->assertDatabaseCount('campaign_product_responses', 1);

        $saved = $campaignProduct->fresh()->response;
        $this->assertSame(CampaignProductResponse::SOURCE_AI, $saved->source);
        $this->assertStringContainsString('From My Heart to Yours', $saved->body);
        $this->assertSame('Proposed new prompt', $campaignProduct->fresh()->prompt_template);
    }

    public function test_failure_to_reach_openai_shows_clear_error_and_leaves_existing_response_untouched(): void
    {
        $campaign = MarketingCampaign::create(['campaign_key' => 'campaign-a', 'name' => 'A', 'status' => MarketingCampaign::STATUS_DRAFT]);
        $campaignProduct = CampaignProduct::create([
            'marketing_campaign_id' => $campaign->id,
            'shopify_product_id' => 111,
        ]);
        $existing = $campaignProduct->response()->create([
            'source' => CampaignProductResponse::SOURCE_AI,
            'body' => '<h2>Existing Body</h2><h3>From My Heart to Yours</h3><p>Original.</p>',
        ]);

        OpenAI::fake([
            new \RuntimeException('Connection to OpenAI failed: API key invalid or network down.'),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->postJson(route('admin.marketing-campaigns.products.generate', [$campaign, $campaignProduct]));

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
            ]);
        $this->assertStringContainsString('Connection to OpenAI failed', $response->json('message'));

        $this->assertSame($existing->body, $campaignProduct->fresh()->response->body);
        $this->assertDatabaseCount('campaign_product_responses', 1);
    }

    public function test_linking_a_product_can_persist_formatting_contract_override(): void
    {
        $campaign = MarketingCampaign::create(['campaign_key' => 'campaign-a', 'name' => 'A', 'status' => MarketingCampaign::STATUS_DRAFT]);

        $response = $this->actingAs(User::factory()->create())
            ->post(route('admin.marketing-campaigns.products.store', $campaign), [
                'shopify_product_id' => 999,
                'shopify_variant_id' => 888,
                'product_title' => 'Tarot Deck',
                'prompt_template' => 'Prompt for Tarot Deck',
                'formatting_contract' => '## Custom Formatting Contract',
            ]);

        $response->assertRedirect(route('admin.marketing-campaigns.show', $campaign));
        $this->assertDatabaseHas('campaign_products', [
            'marketing_campaign_id' => $campaign->id,
            'shopify_product_id' => 999,
            'shopify_variant_id' => 888,
            'formatting_contract' => '## Custom Formatting Contract',
        ]);
    }

    public function test_campaign_show_page_displays_formatting_contract_inputs(): void
    {
        $campaign = MarketingCampaign::create(['campaign_key' => 'campaign-a', 'name' => 'A', 'status' => MarketingCampaign::STATUS_DRAFT]);
        CampaignProduct::create([
            'marketing_campaign_id' => $campaign->id,
            'shopify_product_id' => 111,
            'product_title' => 'Pendant',
            'formatting_contract' => '### Custom Contract Override',
        ]);

        $this->actingAs(User::factory()->create())->get(route('admin.marketing-campaigns.show', $campaign))
            ->assertOk()
            ->assertSee('name="formatting_contract" id="formatting_contract"', false)
            ->assertSee('name="formatting_contract" class="form-control"', false)
            ->assertSee('### Custom Contract Override', false);
    }

    public function test_admin_can_save_a_manual_response_without_calling_openai(): void
    {
        $campaign = MarketingCampaign::create(['campaign_key' => 'campaign-a', 'name' => 'A', 'status' => MarketingCampaign::STATUS_DRAFT]);
        $campaignProduct = CampaignProduct::create([
            'marketing_campaign_id' => $campaign->id,
            'shopify_product_id' => 111,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->post(route('admin.marketing-campaigns.products.respond', [$campaign, $campaignProduct]), [
                'source' => CampaignProductResponse::SOURCE_MANUAL,
                'body' => 'Hand-written copy for this product.',
            ]);

        $response->assertRedirect(route('admin.marketing-campaigns.show', $campaign));

        $this->assertDatabaseHas('campaign_product_responses', [
            'campaign_product_id' => $campaignProduct->id,
            'source' => CampaignProductResponse::SOURCE_MANUAL,
            'body' => 'Hand-written copy for this product.',
            'model_used' => null,
            'prompt_tokens' => null,
            'completion_tokens' => null,
            'generated_at' => null,
        ]);
    }

    public function test_manual_response_is_required_when_source_is_manual(): void
    {
        $campaign = MarketingCampaign::create(['campaign_key' => 'campaign-a', 'name' => 'A', 'status' => MarketingCampaign::STATUS_DRAFT]);
        $campaignProduct = CampaignProduct::create([
            'marketing_campaign_id' => $campaign->id,
            'shopify_product_id' => 111,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->from(route('admin.marketing-campaigns.show', $campaign))
            ->post(route('admin.marketing-campaigns.products.respond', [$campaign, $campaignProduct]), [
                'source' => CampaignProductResponse::SOURCE_MANUAL,
            ]);

        $response->assertSessionHasErrors(['body']);
        $this->assertDatabaseCount('campaign_product_responses', 0);
    }

    public function test_switching_an_ai_response_to_manual_clears_ai_metadata(): void
    {
        $campaign = MarketingCampaign::create(['campaign_key' => 'campaign-a', 'name' => 'A', 'status' => MarketingCampaign::STATUS_DRAFT]);
        $campaignProduct = CampaignProduct::create([
            'marketing_campaign_id' => $campaign->id,
            'shopify_product_id' => 111,
        ]);
        CampaignProductResponse::create([
            'campaign_product_id' => $campaignProduct->id,
            'source' => CampaignProductResponse::SOURCE_AI,
            'body' => 'AI draft.',
            'model_used' => 'gpt-4o',
            'prompt_tokens' => 12,
            'completion_tokens' => 8,
            'generated_at' => now(),
        ]);

        $this->actingAs(User::factory()->create())
            ->post(route('admin.marketing-campaigns.products.respond', [$campaign, $campaignProduct]), [
                'source' => CampaignProductResponse::SOURCE_MANUAL,
                'body' => 'Hand-edited replacement.',
            ]);

        $this->assertDatabaseHas('campaign_product_responses', [
            'campaign_product_id' => $campaignProduct->id,
            'source' => CampaignProductResponse::SOURCE_MANUAL,
            'body' => 'Hand-edited replacement.',
            'model_used' => null,
            'prompt_tokens' => null,
            'completion_tokens' => null,
            'generated_at' => null,
        ]);
        $this->assertDatabaseCount('campaign_product_responses', 1);
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

    public function test_available_products_endpoint_returns_unlisted_published_products(): void
    {
        $campaign = MarketingCampaign::create(['campaign_key' => 'campaign-a', 'name' => 'A', 'status' => MarketingCampaign::STATUS_DRAFT]);
        UnlistedProduct::create([
            'shopify_product_id' => 111,
            'title' => 'Love Reading',
            'variants' => [['id' => 11101, 'title' => 'Default Title', 'price' => '19.99', 'available_for_sale' => true]],
            'is_published' => true, 'is_vip' => true,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->getJson(route('admin.marketing-campaigns.products.available', $campaign));

        $response->assertOk();
        $response->assertJson([
            'products' => [
                [
                    'id' => 111,
                    'title' => 'Love Reading',
                    'variants' => [
                        ['id' => 11101, 'title' => 'Default Title', 'price' => '19.99'],
                    ],
                ],
            ],
        ]);
    }

    public function test_available_products_endpoint_excludes_products_already_linked_to_the_campaign(): void
    {
        $campaign = MarketingCampaign::create(['campaign_key' => 'campaign-a', 'name' => 'A', 'status' => MarketingCampaign::STATUS_DRAFT]);
        CampaignProduct::create(['marketing_campaign_id' => $campaign->id, 'shopify_product_id' => 111]);
        UnlistedProduct::create(['shopify_product_id' => 111, 'title' => 'Already Linked', 'is_published' => true, 'is_vip' => true]);
        UnlistedProduct::create(['shopify_product_id' => 222, 'title' => 'Still Available', 'is_published' => true, 'is_vip' => true]);

        $response = $this->actingAs(User::factory()->create())
            ->getJson(route('admin.marketing-campaigns.products.available', $campaign));

        $response->assertOk();
        $ids = collect($response->json('products'))->pluck('id')->all();
        $this->assertSame([222], $ids);
    }

    public function test_available_products_endpoint_excludes_products_not_published_to_online_store(): void
    {
        $campaign = MarketingCampaign::create(['campaign_key' => 'campaign-a', 'name' => 'A', 'status' => MarketingCampaign::STATUS_DRAFT]);
        UnlistedProduct::create(['shopify_product_id' => 111, 'title' => 'Not Published', 'is_published' => false]);

        $response = $this->actingAs(User::factory()->create())
            ->getJson(route('admin.marketing-campaigns.products.available', $campaign));

        $response->assertOk();
        $this->assertSame([], $response->json('products'));
    }

    public function test_available_products_endpoint_prefills_saved_defaults(): void
    {
        $campaign = MarketingCampaign::create(['campaign_key' => 'campaign-a', 'name' => 'A', 'status' => MarketingCampaign::STATUS_DRAFT]);
        UnlistedProduct::create([
            'shopify_product_id' => 111,
            'title' => 'Love Reading',
            'header_image' => 'saved-header.jpg',
            'email_content' => 'Saved content.',
            'email_footer' => 'Saved footer.',
            'is_published' => true, 'is_vip' => true,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->getJson(route('admin.marketing-campaigns.products.available', $campaign));

        $response->assertOk();
        $response->assertJson([
            'products' => [
                [
                    'id' => 111,
                    'header_image' => 'saved-header.jpg',
                    'email_content' => 'Saved content.',
                    'email_footer' => 'Saved footer.',
                ],
            ],
        ]);
    }

    public function test_linking_a_product_saves_its_template_fields_back_onto_the_unlisted_product(): void
    {
        $campaign = MarketingCampaign::create(['campaign_key' => 'campaign-a', 'name' => 'A', 'status' => MarketingCampaign::STATUS_DRAFT]);
        UnlistedProduct::create(['shopify_product_id' => 111, 'title' => 'Love Reading', 'is_published' => true, 'is_vip' => true]);

        $this->actingAs(User::factory()->create())
            ->post(route('admin.marketing-campaigns.products.store', $campaign), [
                'shopify_product_id' => 111,
                'shopify_variant_id' => 5001,
                'email_content' => 'New content.',
                'email_footer' => 'New footer.',
            ]);

        $this->assertDatabaseHas('unlisted_products', [
            'shopify_product_id' => 111,
            'email_content' => 'New content.',
            'email_footer' => 'New footer.',
        ]);
    }

    public function test_linking_a_product_with_no_matching_unlisted_product_does_not_create_one(): void
    {
        $campaign = MarketingCampaign::create(['campaign_key' => 'campaign-a', 'name' => 'A', 'status' => MarketingCampaign::STATUS_DRAFT]);

        $this->actingAs(User::factory()->create())
            ->post(route('admin.marketing-campaigns.products.store', $campaign), [
                'shopify_product_id' => 999,
                'shopify_variant_id' => 5001,
                'email_content' => 'New content.',
            ])->assertRedirect(route('admin.marketing-campaigns.show', $campaign));

        $this->assertDatabaseCount('unlisted_products', 0);
    }

    public function test_linking_a_product_reuses_the_default_header_image_filename_without_a_fresh_upload(): void
    {
        $campaignA = MarketingCampaign::create(['campaign_key' => 'campaign-a', 'name' => 'A', 'status' => MarketingCampaign::STATUS_DRAFT]);
        $campaignB = MarketingCampaign::create(['campaign_key' => 'campaign-b', 'name' => 'B', 'status' => MarketingCampaign::STATUS_DRAFT]);
        UnlistedProduct::create([
            'shopify_product_id' => 111,
            'title' => 'Love Reading',
            'header_image' => 'saved-header.jpg',
            'is_published' => true, 'is_vip' => true,
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('admin.marketing-campaigns.products.store', $campaignA), [
            'shopify_product_id' => 111,
            'shopify_variant_id' => 5001,
            'default_header_image' => 'saved-header.jpg',
        ]);

        $this->assertDatabaseHas('campaign_products', [
            'marketing_campaign_id' => $campaignA->id,
            'shopify_product_id' => 111,
            'header_image' => 'saved-header.jpg',
        ]);

        $this->actingAs($user)->post(route('admin.marketing-campaigns.products.store', $campaignB), [
            'shopify_product_id' => 111,
            'shopify_variant_id' => 5002,
            'default_header_image' => 'saved-header.jpg',
        ]);

        $this->assertDatabaseHas('campaign_products', [
            'marketing_campaign_id' => $campaignB->id,
            'shopify_product_id' => 111,
            'header_image' => 'saved-header.jpg',
        ]);
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

    public function test_preview_campaign_product_requires_authentication(): void
    {
        $campaign = MarketingCampaign::create(['campaign_key' => 'campaign-prev', 'name' => 'Prev', 'status' => MarketingCampaign::STATUS_DRAFT]);
        $campaignProduct = CampaignProduct::create([
            'marketing_campaign_id' => $campaign->id,
            'shopify_product_id' => 111,
            'product_title' => 'VIP Reading',
        ]);

        $response = $this->get(route('admin.marketing-campaigns.products.preview', [$campaign, $campaignProduct]));

        $response->assertRedirect(route('login'));
    }

    public function test_preview_campaign_product_renders_campaign_email_mail_with_sample_data(): void
    {
        $campaign = MarketingCampaign::create(['campaign_key' => 'campaign-prev-2', 'name' => 'Prev 2', 'status' => MarketingCampaign::STATUS_DRAFT]);
        $campaignProduct = CampaignProduct::create([
            'marketing_campaign_id' => $campaign->id,
            'shopify_product_id' => 111,
            'product_title' => 'VIP Reading Product',
        ]);
        CampaignProductResponse::create([
            'campaign_product_id' => $campaignProduct->id,
            'source' => CampaignProductResponse::SOURCE_MANUAL,
            'body' => '<h2>Existing Saved VIP Body</h2><p>Saved copy</p>',
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('admin.marketing-campaigns.products.preview', [$campaign, $campaignProduct]));

        $response->assertOk();
        $content = $response->getContent();
        $this->assertStringContainsString('VIP Reading Product', $content);
        $this->assertStringContainsString('Existing Saved VIP Body', $content);
    }

    public function test_preview_campaign_product_accepts_unsaved_overrides_without_persisting(): void
    {
        $campaign = MarketingCampaign::create(['campaign_key' => 'campaign-prev-3', 'name' => 'Prev 3', 'status' => MarketingCampaign::STATUS_DRAFT]);
        $campaignProduct = CampaignProduct::create([
            'marketing_campaign_id' => $campaign->id,
            'shopify_product_id' => 111,
            'product_title' => 'Initial VIP Product',
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->postJson(route('admin.marketing-campaigns.products.preview', [$campaign, $campaignProduct]), [
                'product_title' => 'Overridden VIP Title',
                'customer_name' => 'Test VIP Customer',
                'email_content' => '<p>Special offer for {{ $productTitle }}</p>',
                'body' => '<h2>Unsaved In-Editor VIP Copy</h2><p>Not persisted yet</p>',
            ]);

        $response->assertOk();
        $content = $response->getContent();
        $this->assertStringContainsString('Overridden VIP Title', $content);
        $this->assertStringContainsString('Special offer for Overridden VIP Title', $content);
        $this->assertStringContainsString('Unsaved In-Editor VIP Copy', $content);

        $this->assertSame(0, CampaignDelivery::count());
        $this->assertSame('Initial VIP Product', $campaignProduct->fresh()->product_title);
    }

    public function test_send_test_campaign_product_returns_json_failure_when_the_mailer_throws(): void
    {
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('SMTP down'));

        $admin = User::factory()->create(['email' => 'admin-campaign@example.com']);
        $campaign = MarketingCampaign::create(['campaign_key' => 'campaign-mail-fail', 'name' => 'Mail Fail', 'status' => MarketingCampaign::STATUS_DRAFT]);
        $campaignProduct = CampaignProduct::create([
            'marketing_campaign_id' => $campaign->id,
            'shopify_product_id' => 111,
            'product_title' => 'Mail Fail Product',
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.marketing-campaigns.products.send-test', [$campaign, $campaignProduct]), ['body' => '<p>Body</p>'])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Failed to send test email: SMTP down');
    }

    public function test_send_test_campaign_product_requires_authentication(): void
    {
        $campaign = MarketingCampaign::create(['campaign_key' => 'campaign-auth-test', 'name' => 'Auth Test Campaign', 'status' => MarketingCampaign::STATUS_DRAFT]);
        $campaignProduct = CampaignProduct::create([
            'marketing_campaign_id' => $campaign->id,
            'shopify_product_id' => 111,
            'product_title' => 'Auth VIP Email Product',
        ]);

        $response = $this->postJson(route('admin.marketing-campaigns.products.send-test', [$campaign, $campaignProduct]), [
            'body' => '<p>Test body</p>',
        ]);

        $response->assertUnauthorized();
    }

    public function test_send_test_campaign_product_returns_unprocessable_entity_if_user_has_no_email(): void
    {
        $admin = User::factory()->create(['email' => '']);
        $campaign = MarketingCampaign::create(['campaign_key' => 'campaign-no-email-test', 'name' => 'No Email Test', 'status' => MarketingCampaign::STATUS_DRAFT]);
        $campaignProduct = CampaignProduct::create([
            'marketing_campaign_id' => $campaign->id,
            'shopify_product_id' => 111,
            'product_title' => 'No Email VIP Product',
        ]);

        $response = $this->actingAs($admin)
            ->postJson(route('admin.marketing-campaigns.products.send-test', [$campaign, $campaignProduct]), [
                'body' => '<p>Test body</p>',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Logged-in user has no email address configured.');
    }

    public function test_send_test_campaign_product_sends_to_authenticated_admin_only_and_creates_no_delivery(): void
    {
        Mail::fake();

        $admin = User::factory()->create(['email' => 'admin-campaign@example.com']);
        $campaign = MarketingCampaign::create(['campaign_key' => 'campaign-send-test', 'name' => 'Send Test Campaign', 'status' => MarketingCampaign::STATUS_DRAFT]);
        $campaignProduct = CampaignProduct::create([
            'marketing_campaign_id' => $campaign->id,
            'shopify_product_id' => 111,
            'product_title' => 'Test VIP Email Product',
        ]);

        $response = $this->actingAs($admin)
            ->postJson(route('admin.marketing-campaigns.products.send-test', [$campaign, $campaignProduct]), [
                'customer_name' => 'Admin Test Target',
                'body' => '<h2>Tested Campaign Body</h2><p>Direct to admin inbox</p>',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Test email sent to admin-campaign@example.com.');

        Mail::assertSent(CampaignEmailMail::class, function (CampaignEmailMail $mail) use ($admin): bool {
            $this->assertTrue($mail->hasTo($admin->email));
            $this->assertSame('Admin Test Target', $mail->delivery->customer_name);
            $this->assertStringContainsString('Tested Campaign Body', (string) $mail->delivery->campaignProduct?->response?->body);

            return true;
        });

        $this->assertSame(0, CampaignDelivery::count());
    }
}
