<?php

namespace Tests\Feature\Services\CampaignEmail;

use App\Models\CampaignProduct;
use App\Models\CampaignProductResponse;
use App\Models\MarketingCampaign;
use App\Services\CampaignEmail\CampaignResponseGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;
use Tests\TestCase;

class CampaignResponseGenerationServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function campaignProduct(array $attributes = []): CampaignProduct
    {
        $campaign = MarketingCampaign::create([
            'campaign_key' => 'spring-vip-'.fake()->unique()->slug(),
            'name' => 'Spring VIP Event',
            'status' => MarketingCampaign::STATUS_ACTIVE,
        ]);

        return CampaignProduct::create(array_merge([
            'marketing_campaign_id' => $campaign->id,
            'shopify_product_id' => 12345,
            'shopify_variant_id' => 67890,
            'product_title' => 'Crystal Pendant',
            'prompt_template' => 'Write a marketing email for {{ $productTitle }}.',
        ], $attributes));
    }

    private function fakeReply(string $content): void
    {
        OpenAI::fake([CreateResponse::fake(['choices' => [['message' => ['role' => 'assistant', 'content' => $content]]]])]);
    }

    public function test_system_prompt_includes_shared_contract_and_voice_rules_in_request(): void
    {
        $this->fakeReply("## Crystal Pendant\n\nSpecial message.");
        $product = $this->campaignProduct();

        app(CampaignResponseGenerationService::class)->generate($product);

        OpenAI::assertSent(Chat::class, function (string $method, array $parameters): bool {
            $systemMessage = collect($parameters['messages'])->firstWhere('role', 'system')['content'] ?? '';

            return $method === 'create'
                && str_contains($systemMessage, 'From My Heart to Yours')
                && str_contains($systemMessage, 'British English spelling')
                && ! str_contains($systemMessage, 'no markdown')
                && ! str_contains($systemMessage, 'no headings');
        });
    }

    public function test_system_prompt_uses_product_formatting_contract_override_when_present(): void
    {
        $this->fakeReply("## Custom Campaign Product\n\nContent.");
        $product = $this->campaignProduct([
            'formatting_contract' => "## SPECIAL CAMPAIGN CONTRACT\nFollow this custom structure.",
        ]);

        app(CampaignResponseGenerationService::class)->generate($product);

        OpenAI::assertSent(Chat::class, function (string $method, array $parameters): bool {
            $systemMessage = collect($parameters['messages'])->firstWhere('role', 'system')['content'] ?? '';

            return $method === 'create'
                && str_contains($systemMessage, 'SPECIAL CAMPAIGN CONTRACT')
                && str_contains($systemMessage, 'British English spelling')
                && ! str_contains($systemMessage, 'From My Heart to Yours');
        });
    }

    public function test_a_generated_campaign_response_markdown_is_converted_to_sanitized_html_and_keeps_ai_source(): void
    {
        $this->fakeReply("## Product Highlight\n\n**Special discount.**\n\n### From My Heart to Yours\n\nWarm parting blessings.");
        $product = $this->campaignProduct();

        app(CampaignResponseGenerationService::class)->generate($product);

        $response = $product->fresh()->response;

        $this->assertNotNull($response);
        $this->assertSame(CampaignProductResponse::SOURCE_AI, $response->source);
        $this->assertStringContainsString('<h2>Product Highlight</h2>', $response->body);
        $this->assertStringContainsString('<strong>Special discount.</strong>', $response->body);
        $this->assertStringContainsString('<h3>From My Heart to Yours</h3>', $response->body);
        $this->assertStringContainsString('<p>Warm parting blessings.</p>', $response->body);
    }

    public function test_raw_html_in_ai_output_is_stripped_when_stored(): void
    {
        $this->fakeReply("## Section\n\n<script>alert('xss')</script><div class=\"danger\">Text</div>\n\nParagraph with <b>inline html</b>.");
        $product = $this->campaignProduct();

        app(CampaignResponseGenerationService::class)->generate($product);

        $saved = $product->fresh()->response->body;

        $this->assertStringNotContainsString('<script>', $saved);
        $this->assertStringNotContainsString('</script>', $saved);
        $this->assertStringNotContainsString('<div', $saved);
        $this->assertStringNotContainsString('</div>', $saved);
        $this->assertStringNotContainsString('<b>', $saved);
        $this->assertStringNotContainsString('</b>', $saved);
        $this->assertStringContainsString('<h2>Section</h2>', $saved);
        $this->assertStringContainsString('inline html', $saved);
    }

    public function test_the_preview_returns_sanitized_html_from_markdown_with_contract_and_voice_rules(): void
    {
        $this->fakeReply("## Preview Heading\n\nFirst paragraph.\n\n### From My Heart to Yours\n\nClosing.");
        $product = $this->campaignProduct();

        $result = app(CampaignResponseGenerationService::class)->preview($product);

        $this->assertStringContainsString('<h2>Preview Heading</h2>', $result['content']);
        $this->assertStringContainsString('<p>First paragraph.</p>', $result['content']);
        $this->assertStringContainsString('<h3>From My Heart to Yours</h3>', $result['content']);

        OpenAI::assertSent(Chat::class, function (string $method, array $parameters): bool {
            $systemMessage = collect($parameters['messages'])->firstWhere('role', 'system')['content'] ?? '';

            return $method === 'create'
                && str_contains($systemMessage, 'From My Heart to Yours')
                && str_contains($systemMessage, 'British English spelling');
        });
    }

    public function test_preview_uses_product_formatting_contract_override_when_present(): void
    {
        $this->fakeReply("## Custom Preview\n\nCustom content.");
        $product = $this->campaignProduct([
            'formatting_contract' => "## CUSTOM PREVIEW CONTRACT\nCustom layout.",
        ]);

        $result = app(CampaignResponseGenerationService::class)->preview($product);

        $this->assertStringContainsString('<h2>Custom Preview</h2>', $result['content']);

        OpenAI::assertSent(Chat::class, function (string $method, array $parameters): bool {
            $systemMessage = collect($parameters['messages'])->firstWhere('role', 'system')['content'] ?? '';

            return $method === 'create'
                && str_contains($systemMessage, 'CUSTOM PREVIEW CONTRACT')
                && str_contains($systemMessage, 'British English spelling')
                && ! str_contains($systemMessage, 'From My Heart to Yours');
        });
    }

    public function test_old_no_markdown_wording_is_gone_from_system_prompt(): void
    {
        $systemPrompt = (string) config('campaign_email.system_prompt');

        $this->assertStringNotContainsString('no markdown', $systemPrompt);
        $this->assertStringNotContainsString('no headings', $systemPrompt);
    }

    public function test_preview_returns_validation_result_problems_and_finish_reason(): void
    {
        $this->fakeReply("## Valid Heading\n\nBody copy.\n\n### From My Heart to Yours\n\nParting blessing.");
        $product = $this->campaignProduct();

        $result = app(CampaignResponseGenerationService::class)->preview($product);

        $this->assertTrue($result['is_valid']);
        $this->assertSame([], $result['problems']);
        $this->assertSame('stop', $result['finish_reason']);
        $this->assertStringContainsString('<h2>Valid Heading</h2>', $result['content']);
        $this->assertStringContainsString('<h3>From My Heart to Yours</h3>', $result['content']);
    }

    public function test_preview_detects_validation_problems_when_closing_section_is_missing(): void
    {
        $this->fakeReply("## Heading\n\nBody copy without closing section.");
        $product = $this->campaignProduct();

        $result = app(CampaignResponseGenerationService::class)->preview($product);

        $this->assertFalse($result['is_valid']);
        $this->assertNotEmpty($result['problems']);
        $this->assertStringContainsString('From My Heart to Yours', $result['problems'][0]);
    }

    public function test_preview_supports_ad_hoc_prompt_and_contract_overrides(): void
    {
        $this->fakeReply("## Ad-Hoc Output\n\nCustom body.\n\n### Custom Final Section\n\nGoodbye.");
        $product = $this->campaignProduct();

        $result = app(CampaignResponseGenerationService::class)->preview(
            $product,
            'Prompt override for {{ $productTitle }}',
            "## AD-HOC CONTRACT\nMust include a final section headed 'Custom Final Section'."
        );

        $this->assertTrue($result['is_valid']);
        $this->assertSame([], $result['problems']);

        OpenAI::assertSent(Chat::class, function (string $method, array $parameters): bool {
            $userMessage = collect($parameters['messages'])->firstWhere('role', 'user')['content'] ?? '';
            $systemMessage = collect($parameters['messages'])->firstWhere('role', 'system')['content'] ?? '';

            return $method === 'create'
                && str_contains($userMessage, 'Prompt override for Crystal Pendant')
                && str_contains($systemMessage, 'AD-HOC CONTRACT');
        });
    }
}
