<?php

namespace Tests\Feature\Services\EmailReading;

use App\Models\EmailReadingDelivery;
use App\Models\EmailReadingProduct;
use App\Services\EmailReading\EmailReadingGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;
use Tests\TestCase;

class EmailReadingGenerationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function delivery(array $productAttributes = []): EmailReadingDelivery
    {
        $product = EmailReadingProduct::create(array_merge([
            'shopify_product_id' => 111,
            'name' => 'Love Reading',
            'slug' => 'love-reading',
            'questions_schema' => [],
            'prompt_template' => 'Customer: {{ $customer_name }}.',
            'email_subject' => 'Your reading',
        ], $productAttributes));

        return EmailReadingDelivery::create([
            'shopify_order_id' => 9001,
            'shopify_line_item_id' => 1,
            'email_reading_product_id' => $product->id,
            'customer_email' => 'buyer@example.com',
            'customer_name' => 'Jane Doe',
            'questions' => [],
            'status' => EmailReadingDelivery::STATUS_PENDING,
        ]);
    }

    private function fakeReply(string $content): void
    {
        OpenAI::fake([CreateResponse::fake(['choices' => [['message' => ['role' => 'assistant', 'content' => $content]]]])]);
    }

    public function test_system_prompt_includes_shared_contract_and_voice_rules_in_request(): void
    {
        $this->fakeReply("## Reading\n\nContent.");
        $delivery = $this->delivery();

        app(EmailReadingGenerationService::class)->generate($delivery->load('product'));

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
        $this->fakeReply("## Custom\n\nContent.");
        $delivery = $this->delivery([
            'formatting_contract' => "## SPECIAL PRODUCT CONTRACT\nFollow this special instruction.",
        ]);

        app(EmailReadingGenerationService::class)->generate($delivery->load('product'));

        OpenAI::assertSent(Chat::class, function (string $method, array $parameters): bool {
            $systemMessage = collect($parameters['messages'])->firstWhere('role', 'system')['content'] ?? '';

            return $method === 'create'
                && str_contains($systemMessage, 'SPECIAL PRODUCT CONTRACT')
                && str_contains($systemMessage, 'British English spelling')
                && ! str_contains($systemMessage, 'From My Heart to Yours');
        });
    }

    public function test_a_generated_reading_markdown_is_converted_to_sanitized_html_on_delivery(): void
    {
        $this->fakeReply("## Your Question\n\n**A key message.**\n\n### From My Heart to Yours\n\nParting blessing.");
        $delivery = $this->delivery();

        app(EmailReadingGenerationService::class)->generate($delivery->load('product'));

        $saved = $delivery->fresh()->ai_response;

        $this->assertStringContainsString('<h2>Your Question</h2>', $saved);
        $this->assertStringContainsString('<strong>A key message.</strong>', $saved);
        $this->assertStringContainsString('<h3>From My Heart to Yours</h3>', $saved);
        $this->assertStringContainsString('<p>Parting blessing.</p>', $saved);
    }

    public function test_raw_html_in_ai_output_is_stripped_when_stored(): void
    {
        $this->fakeReply("## Section\n\n<script>alert('xss')</script><div class=\"danger\">Text</div>\n\nParagraph with <b>inline html</b>.");
        $delivery = $this->delivery();

        app(EmailReadingGenerationService::class)->generate($delivery->load('product'));

        $saved = $delivery->fresh()->ai_response;

        $this->assertStringNotContainsString('<script>', $saved);
        $this->assertStringNotContainsString('</script>', $saved);
        $this->assertStringNotContainsString('<div', $saved);
        $this->assertStringNotContainsString('</div>', $saved);
        $this->assertStringNotContainsString('<b>', $saved);
        $this->assertStringNotContainsString('</b>', $saved);
        $this->assertStringContainsString('<h2>Section</h2>', $saved);
        $this->assertStringContainsString('inline html', $saved);
    }

    public function test_the_regenerate_preview_returns_sanitized_html_from_markdown(): void
    {
        $this->fakeReply("## Preview Heading\n\nFirst paragraph.\n\n### From My Heart to Yours\n\nClosing.");
        $delivery = $this->delivery();

        $result = app(EmailReadingGenerationService::class)->previewForDelivery($delivery->load('product'));

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

    public function test_the_product_run_test_uses_contract_and_returns_sanitized_html(): void
    {
        $this->fakeReply("## Test Heading\n\nTested response.");
        $product = $this->delivery()->product;

        $result = app(EmailReadingGenerationService::class)->testForProduct($product, []);

        $this->assertStringContainsString('<h2>Test Heading</h2>', $result['content']);
        $this->assertStringContainsString('<p>Tested response.</p>', $result['content']);

        OpenAI::assertSent(Chat::class, function (string $method, array $parameters): bool {
            $systemMessage = collect($parameters['messages'])->firstWhere('role', 'system')['content'] ?? '';

            return $method === 'create'
                && str_contains($systemMessage, 'From My Heart to Yours')
                && str_contains($systemMessage, 'British English spelling');
        });
    }

    public function test_old_no_markdown_wording_is_gone_from_system_prompt(): void
    {
        $systemPrompt = (string) config('email_reading.system_prompt');

        $this->assertStringNotContainsString('no markdown', $systemPrompt);
        $this->assertStringNotContainsString('no headings', $systemPrompt);
    }
}
