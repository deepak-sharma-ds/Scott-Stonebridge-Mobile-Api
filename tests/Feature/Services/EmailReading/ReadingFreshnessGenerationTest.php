<?php

declare(strict_types=1);

namespace Tests\Feature\Services\EmailReading;

use App\Models\EmailReadingDelivery;
use App\Models\EmailReadingProduct;
use App\Services\EmailReading\EmailReadingGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Resources\Embeddings;
use OpenAI\Responses\Chat\CreateResponse as ChatResponse;
use OpenAI\Responses\Embeddings\CreateResponse as EmbeddingResponse;
use Tests\TestCase;

class ReadingFreshnessGenerationTest extends TestCase
{
    use RefreshDatabase;

    private function pendingDelivery(): EmailReadingDelivery
    {
        $product = EmailReadingProduct::firstOrCreate(['shopify_product_id' => 111], [
            'name' => 'Gold Reading',
            'slug' => 'gold',
            'questions_schema' => [],
            'prompt_template' => 'Customer: {{ $customer_name }}.',
            'email_subject' => 'Your reading',
        ]);

        static $lineItem = 7000;

        return EmailReadingDelivery::create([
            'shopify_order_id' => 9001,
            'shopify_line_item_id' => $lineItem++,
            'email_reading_product_id' => $product->id,
            'customer_email' => 'buyer@example.com',
            'customer_name' => 'Jane Doe',
            'questions' => [],
            'status' => EmailReadingDelivery::STATUS_PENDING,
        ]);
    }

    private function earlierReading(array $overrides = []): EmailReadingDelivery
    {
        $delivery = $this->pendingDelivery();
        $delivery->update(array_merge([
            'status' => EmailReadingDelivery::STATUS_SENT,
            'ai_response' => '<h3>Your Path</h3><p>The Star shines over the Three of Cups.</p>',
            'theme_summary' => 'The Star, Three of Cups',
            'embedding' => [1.0, 0.0],
        ], $overrides));

        return $delivery;
    }

    private function chat(string $card): ChatResponse
    {
        return ChatResponse::fake(['choices' => [['message' => ['role' => 'assistant', 'content' => "### Your Reading\n\nThe {$card} appears for you today.\n\n### From My Heart to Yours\n\nWalk forward with joy."], 'finish_reason' => 'stop']]]);
    }

    /**
     * @param  list<float>  $vector
     */
    private function embedding(array $vector): EmbeddingResponse
    {
        return EmbeddingResponse::fake(['data' => [['embedding' => $vector]]]);
    }

    private function generate(EmailReadingDelivery $delivery): bool
    {
        return app(EmailReadingGenerationService::class)->generate($delivery->load('product'));
    }

    private function userPrompt(array $parameters): string
    {
        return (string) collect($parameters['messages'])->firstWhere('role', 'user')['content'];
    }

    public function test_a_returning_customers_prompt_carries_the_compact_theme_line(): void
    {
        $this->earlierReading();
        OpenAI::fake([$this->chat('Moon'), $this->embedding([0.0, 1.0])]);

        $this->generate($this->pendingDelivery());

        OpenAI::assertSent(Chat::class, fn (string $method, array $parameters): bool => str_contains($this->userPrompt($parameters), 'Previous reading for this client explored: The Star, Three of Cups.')
            && str_contains($this->userPrompt($parameters), 'Provide fresh cards'));
    }

    public function test_a_first_time_customers_prompt_has_no_history_line(): void
    {
        OpenAI::fake([$this->chat('Moon'), $this->embedding([0.0, 1.0])]);

        $this->generate($this->pendingDelivery());

        OpenAI::assertSent(Chat::class, fn (string $method, array $parameters): bool => ! str_contains($this->userPrompt($parameters), 'Previous reading'));
        OpenAI::assertSent(Embeddings::class);
    }

    public function test_the_reading_embedding_and_theme_summary_are_stored(): void
    {
        OpenAI::fake([$this->chat('Moon'), $this->embedding([0.25, 0.75])]);
        $delivery = $this->pendingDelivery();

        $this->generate($delivery);

        $delivery->refresh();
        $this->assertSame(EmailReadingDelivery::STATUS_GENERATED, $delivery->status);
        $this->assertSame([0.25, 0.75], $delivery->embedding);
        $this->assertSame('The Moon', $delivery->theme_summary);
    }

    public function test_a_reading_too_similar_to_an_earlier_one_is_regenerated_once_with_a_presence_penalty(): void
    {
        $this->earlierReading();
        OpenAI::fake([$this->chat('Star'), $this->embedding([0.99, 0.1]), $this->chat('Tower'), $this->embedding([0.0, 1.0])]);
        $delivery = $this->pendingDelivery();

        $this->generate($delivery);

        OpenAI::assertSent(Chat::class, 2);
        OpenAI::assertSent(Chat::class, fn (string $method, array $parameters): bool => ($parameters['presence_penalty'] ?? null) === 0.5);
        $delivery->refresh();
        $this->assertStringContainsString('Tower', (string) $delivery->ai_response);
        $this->assertEquals([0.0, 1.0], $delivery->embedding);
        $this->assertSame('The Tower', $delivery->theme_summary);
    }

    public function test_the_first_attempt_carries_no_presence_penalty(): void
    {
        $this->earlierReading();
        OpenAI::fake([$this->chat('Moon'), $this->embedding([0.0, 1.0])]);

        $this->generate($this->pendingDelivery());

        OpenAI::assertSent(Chat::class, fn (string $method, array $parameters): bool => ! array_key_exists('presence_penalty', $parameters));
    }

    public function test_a_distinct_reading_is_not_regenerated(): void
    {
        $this->earlierReading();
        OpenAI::fake([$this->chat('Moon'), $this->embedding([0.1, 0.99])]);

        $this->generate($this->pendingDelivery());

        OpenAI::assertSent(Chat::class, 1);
    }

    public function test_the_retry_result_is_kept_even_when_still_similar_and_never_retried_twice(): void
    {
        $this->earlierReading();
        OpenAI::fake([$this->chat('Star'), $this->embedding([0.99, 0.1]), $this->chat('Sun'), $this->embedding([0.98, 0.1])]);
        $delivery = $this->pendingDelivery();

        $this->assertTrue($this->generate($delivery));

        OpenAI::assertSent(Chat::class, 2);
        $delivery->refresh();
        $this->assertSame(EmailReadingDelivery::STATUS_GENERATED, $delivery->status);
        $this->assertStringContainsString('Sun', (string) $delivery->ai_response);
        $this->assertSame([0.98, 0.1], $delivery->embedding);
    }

    public function test_an_invalid_retry_keeps_the_valid_first_reading(): void
    {
        $this->earlierReading();
        $broken = ChatResponse::fake(['choices' => [['message' => ['role' => 'assistant', 'content' => "- a bullet\n- another"], 'finish_reason' => 'stop']]]);
        OpenAI::fake([$this->chat('Star'), $this->embedding([0.99, 0.1]), $broken]);
        $delivery = $this->pendingDelivery();

        $this->assertTrue($this->generate($delivery));

        $delivery->refresh();
        $this->assertSame(EmailReadingDelivery::STATUS_GENERATED, $delivery->status);
        $this->assertStringContainsString('Star', (string) $delivery->ai_response);
        $this->assertSame([0.99, 0.1], $delivery->embedding);
    }

    public function test_an_embeddings_outage_never_fails_the_delivery_or_triggers_a_retry(): void
    {
        $this->earlierReading();
        OpenAI::fake([$this->chat('Star'), new \RuntimeException('embeddings down')]);
        $delivery = $this->pendingDelivery();

        $this->assertTrue($this->generate($delivery));

        OpenAI::assertSent(Chat::class, 1);
        $delivery->refresh();
        $this->assertSame(EmailReadingDelivery::STATUS_GENERATED, $delivery->status);
        $this->assertNull($delivery->embedding);
    }

    public function test_the_freshness_protocol_can_be_switched_off(): void
    {
        config(['email_reading.freshness.enabled' => false]);
        $this->earlierReading();
        OpenAI::fake([$this->chat('Star')]);
        $delivery = $this->pendingDelivery();

        $this->assertTrue($this->generate($delivery));

        OpenAI::assertNotSent(Embeddings::class);
        OpenAI::assertSent(Chat::class, fn (string $method, array $parameters): bool => ! str_contains($this->userPrompt($parameters), 'Previous reading'));
    }

    public function test_the_admin_regenerate_preview_also_gets_the_theme_line_without_saving_anything(): void
    {
        $this->earlierReading();
        OpenAI::fake([$this->chat('Moon')]);
        $delivery = $this->pendingDelivery();

        app(EmailReadingGenerationService::class)->previewForDelivery($delivery->load('product'));

        OpenAI::assertSent(Chat::class, fn (string $method, array $parameters): bool => str_contains($this->userPrompt($parameters), 'Previous reading for this client explored'));
        OpenAI::assertNotSent(Embeddings::class);
        $this->assertNull($delivery->fresh()->embedding);
    }
}
