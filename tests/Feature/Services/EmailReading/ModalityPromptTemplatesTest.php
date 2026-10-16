<?php

declare(strict_types=1);

namespace Tests\Feature\Services\EmailReading;

use App\Models\EmailReadingDelivery;
use App\Models\EmailReadingProduct;
use App\Services\EmailReading\EmailReadingGenerationService;
use App\Support\ReadingPromptTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Responses\Moderations\CreateResponse as ModerationResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ModalityPromptTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private function fakeReply(): void
    {
        OpenAI::fake([CreateResponse::fake(['choices' => [['message' => ['role' => 'assistant', 'content' => "### Reading\n\nBody.\n\n### From My Heart to Yours\n\nBlessings."], 'finish_reason' => 'stop']]])]);
    }

    private function userPrompt(array $parameters): string
    {
        return (string) collect($parameters['messages'])->firstWhere('role', 'user')['content'];
    }

    public function test_customer_answers_reach_the_model_unescaped(): void
    {
        $this->fakeReply();
        $product = new EmailReadingProduct(['prompt_template' => 'Question: {{ $q1 }}']);

        app(EmailReadingGenerationService::class)->testForProduct($product, ['q1' => "Will Tom & Jerry's shop <thrive>?"]);

        OpenAI::assertSent(Chat::class, fn (string $method, array $parameters): bool => str_contains($this->userPrompt($parameters), "Will Tom & Jerry's shop <thrive>?")
            && ! str_contains($this->userPrompt($parameters), '&#039;')
            && ! str_contains($this->userPrompt($parameters), '&amp;'));
    }

    public function test_templates_can_use_todays_date_to_anchor_a_year_ahead(): void
    {
        Carbon::setTestNow('2026-10-13 09:00:00');
        $this->fakeReply();
        $product = new EmailReadingProduct(['prompt_template' => 'Today is {{ $today }} ({{ $current_year }}).']);

        app(EmailReadingGenerationService::class)->testForProduct($product, []);

        OpenAI::assertSent(Chat::class, fn (string $method, array $parameters): bool => str_contains($this->userPrompt($parameters), 'Today is 13 October 2026 (2026).'));
        Carbon::setTestNow();
    }

    public function test_a_customer_answer_cannot_override_the_reserved_date_variables(): void
    {
        Carbon::setTestNow('2026-10-13 09:00:00');
        $categories = array_fill_keys(['hate', 'harassment', 'illicit', 'self-harm', 'sexual', 'violence'], false);
        OpenAI::fake([
            ModerationResponse::fake(['results' => [['categories' => $categories, 'category_scores' => array_map(fn () => 0.001, $categories), 'flagged' => false]]]),
            CreateResponse::fake(['choices' => [['message' => ['role' => 'assistant', 'content' => "### Reading\n\nBody.\n\n### From My Heart to Yours\n\nBlessings."], 'finish_reason' => 'stop']]]),
        ]);
        $delivery = $this->deliveryWithTemplate('Today is {{ $today }}.', ['today' => 'a long time ago']);

        app(EmailReadingGenerationService::class)->generate($delivery->load('product'));

        OpenAI::assertSent(Chat::class, fn (string $method, array $parameters): bool => str_contains($this->userPrompt($parameters), 'Today is 13 October 2026.'));
        Carbon::setTestNow();
    }

    /**
     * slug => [answers the product captures, phrases the rendered prompt must contain (lowercase), phrases it must not contain (lowercase)]
     *
     * @return array<string,array{0:string,1:int,2:list<string>,3:list<string>}>
     */
    public static function modalityExpectations(): array
    {
        $expectations = [
            'future-two-question-email-reading' => [2, ['tarot', 'future', 'the star', 'three of cups', 'synthes'], []],
            'gold-three-question-email-reading' => [3, ['tarot', 'three cards', 'one card for each', 'synthes', 'the star', 'three of cups'], []],
            'love-relationships-two-question-email-reading' => [3, ['relationship', 'communication', 'energy', 'both people'], []],
            'messages-from-heaven-two-question-email-reading' => [3, ['spirit', 'loved one', 'comfort', 'passed'], ['tarot', 'oracle']],
            'platinum-five-question-email-reading' => [5, ['tarot', 'five cards', 'one card for each', 'synthes', 'the star', 'three of cups'], []],
            'silver-one-question-email-reading' => [1, ['tarot', 'single card', 'the star', 'three of cups'], []],
            'you-me-love-reading' => [3, ['connection', 'energy', 'communication', 'both'], []],
            '12-month-deep-astrology-outlook' => [3, ['four', 'quarter', 'season', '13 october 2026', 'sun sign'], ['month by month']],
        ];

        $dataSets = [];
        foreach ($expectations as $slug => $row) {
            $dataSets[$slug] = [$slug, ...$row];
        }

        return $dataSets;
    }

    /**
     * @param  list<string>  $mustContain
     * @param  list<string>  $mustNotContain
     */
    #[DataProvider('modalityExpectations')]
    public function test_each_product_prompt_has_authentic_modality_content(string $slug, int $answerCount, array $mustContain, array $mustNotContain): void
    {
        Carbon::setTestNow('2026-10-13 09:00:00');
        $definition = collect(ReadingPromptTemplates::all())->firstWhere('slug', $slug);
        $this->assertNotNull($definition, "No prompt definition for {$slug}");

        $answers = [];
        for ($i = 1; $i <= $answerCount; $i++) {
            $answers["q{$i}"] = "ANSWER_Q{$i}";
        }

        $this->fakeReply();
        app(EmailReadingGenerationService::class)->testForProduct(
            new EmailReadingProduct(['prompt_template' => $definition['prompt_template']]),
            $answers,
            'Jane Doe'
        );

        $prompt = '';
        OpenAI::assertSent(Chat::class, function (string $method, array $parameters) use (&$prompt): bool {
            $prompt = $this->userPrompt($parameters);

            return true;
        });

        $lower = strtolower($prompt);
        foreach ($answers as $key => $value) {
            $this->assertStringContainsString($value, $prompt, "{$key} placeholder did not resolve for {$slug}");
        }
        $this->assertStringContainsString('Jane Doe', $prompt);
        $this->assertStringNotContainsString('{{', $prompt);
        $this->assertStringNotContainsString('$q', $prompt);
        foreach ($mustContain as $phrase) {
            $this->assertStringContainsString($phrase, $lower, "{$slug} prompt should mention '{$phrase}'");
        }
        foreach ($mustNotContain as $phrase) {
            $this->assertStringNotContainsString($phrase, $lower, "{$slug} prompt must not mention '{$phrase}'");
        }

        Carbon::setTestNow();
    }

    public function test_all_eight_reading_products_have_a_definition_with_a_real_token_budget(): void
    {
        $definitions = collect(ReadingPromptTemplates::all());

        $this->assertCount(8, $definitions);
        $this->assertCount(8, $definitions->pluck('shopify_product_id')->unique());
        $this->assertCount(8, $definitions->pluck('slug')->unique());
        foreach ($definitions as $definition) {
            $this->assertGreaterThanOrEqual(1200, $definition['max_tokens']);
            $this->assertGreaterThan(600, strlen($definition['prompt_template']));
        }
    }

    /**
     * @param  array<string,mixed>  $questions
     */
    private function deliveryWithTemplate(string $template, array $questions = []): EmailReadingDelivery
    {
        $product = EmailReadingProduct::create([
            'shopify_product_id' => 111,
            'name' => 'Test',
            'slug' => 'test',
            'questions_schema' => [],
            'prompt_template' => $template,
            'email_subject' => 'Your reading',
        ]);

        return EmailReadingDelivery::create([
            'shopify_order_id' => 9001,
            'shopify_line_item_id' => 1,
            'email_reading_product_id' => $product->id,
            'customer_email' => 'buyer@example.com',
            'questions' => $questions,
            'status' => EmailReadingDelivery::STATUS_PENDING,
        ]);
    }
}
