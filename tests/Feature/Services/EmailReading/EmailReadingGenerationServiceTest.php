<?php

namespace Tests\Feature\Services\EmailReading;

use App\Models\EmailReadingDelivery;
use App\Models\EmailReadingProduct;
use App\Services\EmailReading\EmailReadingGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use Tests\TestCase;

class EmailReadingGenerationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function delivery(): EmailReadingDelivery
    {
        $product = EmailReadingProduct::create([
            'shopify_product_id' => 111,
            'name' => 'Love Reading',
            'slug' => 'love-reading',
            'questions_schema' => [],
            'prompt_template' => 'Customer: {{ $customer_name }}.',
            'email_subject' => 'Your reading',
        ]);

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

    public function test_a_generated_reading_is_stored_as_escaped_paragraph_html(): void
    {
        $this->fakeReply("First paragraph & more.\n\nSecond <b>paragraph</b>.");
        $delivery = $this->delivery();

        app(EmailReadingGenerationService::class)->generate($delivery->load('product'));

        $this->assertSame(
            '<p>First paragraph &amp; more.</p><p>Second &lt;b&gt;paragraph&lt;/b&gt;.</p>',
            $delivery->fresh()->ai_response
        );
    }

    public function test_the_regenerate_preview_returns_paragraph_html_for_the_editor(): void
    {
        $this->fakeReply("One.\n\nTwo.");
        $delivery = $this->delivery();

        $result = app(EmailReadingGenerationService::class)->previewForDelivery($delivery->load('product'));

        $this->assertSame('<p>One.</p><p>Two.</p>', $result['content']);
    }

    public function test_the_product_run_test_still_returns_plain_text(): void
    {
        $this->fakeReply("One.\n\nTwo.");
        $product = $this->delivery()->product;

        $result = app(EmailReadingGenerationService::class)->testForProduct($product, []);

        $this->assertSame("One.\n\nTwo.", $result['content']);
    }
}
