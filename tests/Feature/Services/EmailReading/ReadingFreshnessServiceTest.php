<?php

declare(strict_types=1);

namespace Tests\Feature\Services\EmailReading;

use App\Models\EmailReadingDelivery;
use App\Models\EmailReadingProduct;
use App\Services\EmailReading\ReadingFreshnessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Embeddings;
use OpenAI\Responses\Embeddings\CreateResponse as EmbeddingResponse;
use Tests\TestCase;

class ReadingFreshnessServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ReadingFreshnessService
    {
        return app(ReadingFreshnessService::class);
    }

    private function delivery(array $overrides = []): EmailReadingDelivery
    {
        static $lineItem = 5000;

        $product = EmailReadingProduct::firstOrCreate(['shopify_product_id' => 111], [
            'name' => 'Gold Reading',
            'slug' => 'gold',
            'questions_schema' => [],
            'prompt_template' => 'x',
            'email_subject' => 'Your reading',
        ]);

        return EmailReadingDelivery::create(array_merge([
            'shopify_order_id' => 9001,
            'shopify_line_item_id' => $lineItem++,
            'email_reading_product_id' => $product->id,
            'customer_email' => 'buyer@example.com',
            'customer_name' => 'Jane Doe',
            'questions' => [],
            'ai_response' => '<h3>Your Reading</h3><p>The Star appears.</p>',
            'theme_summary' => 'The Star',
            'status' => EmailReadingDelivery::STATUS_SENT,
        ], $overrides));
    }

    public function test_the_theme_summary_names_the_cards_in_a_reading(): void
    {
        $html = '<h3>Your Career</h3><p>The Star sits beside the Three of Cups, while The High Priestess watches over the King of Wands.</p>'
            .'<h3>From My Heart to Yours</h3><p>Walk forward with joy.</p>';

        $summary = $this->service()->extractThemeSummary($html);

        $this->assertStringContainsString('The Star', $summary);
        $this->assertStringContainsString('Three of Cups', $summary);
        $this->assertStringContainsString('The High Priestess', $summary);
        $this->assertStringNotContainsString('From My Heart to Yours', $summary);
        $this->assertLessThanOrEqual(15, str_word_count($summary));
    }

    public function test_the_theme_summary_falls_back_to_section_headings_without_cards(): void
    {
        $html = '<h3>Moving Home</h3><p>A change is coming.</p><h3>Family Harmony</h3><p>Peace returns.</p><h3>From My Heart to Yours</h3><p>Bless you.</p>';

        $summary = $this->service()->extractThemeSummary($html);

        $this->assertSame('Moving Home, Family Harmony', $summary);
    }

    public function test_cosine_similarity(): void
    {
        $this->assertEqualsWithDelta(1.0, $this->service()->cosine([1.0, 2.0], [2.0, 4.0]), 0.0001);
        $this->assertEqualsWithDelta(0.0, $this->service()->cosine([1.0, 0.0], [0.0, 1.0]), 0.0001);
        $this->assertSame(0.0, $this->service()->cosine([], [1.0]));
        $this->assertSame(0.0, $this->service()->cosine([1.0], [1.0, 2.0]));
        $this->assertSame(0.0, $this->service()->cosine([0.0, 0.0], [1.0, 1.0]));
    }

    public function test_previous_readings_are_the_same_customers_stored_readings_only(): void
    {
        $current = $this->delivery(['status' => EmailReadingDelivery::STATUS_PENDING, 'ai_response' => null]);
        $sent = $this->delivery(['customer_email' => 'Buyer@Example.com']);
        $generatedNotYetSent = $this->delivery(['status' => EmailReadingDelivery::STATUS_GENERATED]);
        $this->delivery(['status' => EmailReadingDelivery::STATUS_CANCELLED]);
        $this->delivery(['status' => EmailReadingDelivery::STATUS_FAILED]);
        $this->delivery(['status' => EmailReadingDelivery::STATUS_COMPLIANCE_HOLD, 'ai_response' => null]);
        $this->delivery(['customer_email' => 'someone-else@example.com']);

        $ids = $this->service()->previousReadings($current)->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$sent->id, $generatedNotYetSent->id], $ids);
    }

    public function test_only_the_newest_readings_up_to_the_history_limit_are_used(): void
    {
        config(['email_reading.freshness.history_limit' => 2]);
        $current = $this->delivery(['status' => EmailReadingDelivery::STATUS_PENDING, 'ai_response' => null]);
        $this->delivery();
        $second = $this->delivery();
        $third = $this->delivery();

        $ids = $this->service()->previousReadings($current)->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$second->id, $third->id], $ids);
    }

    public function test_a_first_time_customer_gets_no_theme_line(): void
    {
        $current = $this->delivery(['status' => EmailReadingDelivery::STATUS_PENDING, 'ai_response' => null]);

        $this->assertNull($this->service()->themeLine($current));
    }

    public function test_a_returning_customer_gets_one_compact_theme_line(): void
    {
        $current = $this->delivery(['status' => EmailReadingDelivery::STATUS_PENDING, 'ai_response' => null]);
        $this->delivery(['theme_summary' => 'The Star, Three of Cups']);
        $this->delivery(['theme_summary' => 'The Tower, Moving Home']);

        $line = (string) $this->service()->themeLine($current);

        $this->assertStringContainsString('Previous reading for this client explored:', $line);
        $this->assertStringContainsString('The Star, Three of Cups', $line);
        $this->assertStringContainsString('The Tower, Moving Home', $line);
        $this->assertStringContainsString('Provide fresh cards, distinct perspectives, and progressive guidance.', $line);
        $this->assertStringNotContainsString("\n", $line);
    }

    public function test_the_theme_line_is_derived_from_the_text_when_a_legacy_reading_has_no_summary(): void
    {
        $current = $this->delivery(['status' => EmailReadingDelivery::STATUS_PENDING, 'ai_response' => null]);
        $this->delivery(['theme_summary' => null, 'ai_response' => '<h3>Your Path</h3><p>The Moon rises over The Lovers.</p>']);

        $line = (string) $this->service()->themeLine($current);

        $this->assertStringContainsString('The Moon', $line);
        $this->assertStringContainsString('The Lovers', $line);
    }

    public function test_embedding_a_reading_returns_the_vector_for_its_plain_text(): void
    {
        OpenAI::fake([EmbeddingResponse::fake(['data' => [['embedding' => [0.5, 0.25]]]])]);

        $vector = $this->service()->embed('<h3>Hello</h3><p>World</p>');

        $this->assertSame([0.5, 0.25], $vector);
        OpenAI::assertSent(Embeddings::class, fn (string $method, array $parameters): bool => $parameters['model'] === 'text-embedding-3-small'
            && ! str_contains($parameters['input'], '<'));
    }

    public function test_an_embedding_outage_returns_null_instead_of_throwing(): void
    {
        OpenAI::fake([new \RuntimeException('down')]);

        $this->assertNull($this->service()->embed('<p>Hello</p>'));
    }

    public function test_the_highest_similarity_against_stored_embeddings_is_reported(): void
    {
        $current = $this->delivery(['status' => EmailReadingDelivery::STATUS_PENDING, 'ai_response' => null]);
        $this->delivery(['embedding' => [0.0, 1.0]]);
        $this->delivery(['embedding' => [1.0, 0.1]]);
        $this->delivery(['embedding' => null]);

        $similarity = $this->service()->highestSimilarity([1.0, 0.0], $this->service()->previousReadings($current));

        $this->assertEqualsWithDelta(0.995, $similarity, 0.01);
    }
}
