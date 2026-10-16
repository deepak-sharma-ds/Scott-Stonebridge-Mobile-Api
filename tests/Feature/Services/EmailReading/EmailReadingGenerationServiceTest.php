<?php

declare(strict_types=1);

namespace Tests\Feature\Services\EmailReading;

use App\Jobs\EmailReading\NotifyReadingFailureJob;
use App\Models\EmailReadingDelivery;
use App\Models\EmailReadingProduct;
use App\Services\EmailReading\EmailReadingDeliveryLifecycle;
use App\Services\EmailReading\EmailReadingGenerationService;
use App\Support\GeneratedBodyValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;
use Tests\TestCase;

class EmailReadingGenerationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function delivery(array $productAttributes = [], array $deliveryAttributes = []): EmailReadingDelivery
    {
        $product = EmailReadingProduct::create(array_merge([
            'shopify_product_id' => 111,
            'name' => 'Love Reading',
            'slug' => 'love-reading',
            'questions_schema' => [],
            'prompt_template' => 'Customer: {{ $customer_name }}.',
            'email_subject' => 'Your reading',
        ], $productAttributes));

        return EmailReadingDelivery::create(array_merge([
            'shopify_order_id' => 9001,
            'shopify_line_item_id' => 1,
            'email_reading_product_id' => $product->id,
            'customer_email' => 'buyer@example.com',
            'customer_name' => 'Jane Doe',
            'questions' => [],
            'status' => EmailReadingDelivery::STATUS_PENDING,
        ], $deliveryAttributes));
    }

    private function validMarkdown(): string
    {
        return "## Your Reading\n\nA clear path opens before you.\n\n### From My Heart to Yours\n\nWalk forward with joy.";
    }

    private function fakeReply(string $content, string $finishReason = 'stop'): void
    {
        OpenAI::fake([CreateResponse::fake(['choices' => [['message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => $finishReason]]])]);
    }

    public function test_system_prompt_includes_shared_contract_and_voice_rules_in_request(): void
    {
        $this->fakeReply($this->validMarkdown());
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

    public function test_default_contract_asks_for_a_personal_letter_and_forbids_ai_visual_tells(): void
    {
        $this->fakeReply($this->validMarkdown());
        $delivery = $this->delivery();

        app(EmailReadingGenerationService::class)->generate($delivery->load('product'));

        OpenAI::assertSent(Chat::class, function (string $method, array $parameters): bool {
            $systemMessage = strtolower(collect($parameters['messages'])->firstWhere('role', 'system')['content'] ?? '');

            return str_contains($systemMessage, 'personal letter')
                && str_contains($systemMessage, 'flowing paragraphs')
                && str_contains($systemMessage, '###')
                && str_contains($systemMessage, 'do not use blockquotes')
                && str_contains($systemMessage, 'tables')
                && str_contains($systemMessage, 'bullet')
                && str_contains($systemMessage, 'horizontal')
                && ! str_contains($systemMessage, 'bold or blockquote');
        });
    }

    public function test_system_prompt_carries_the_persona_and_compliance_boundaries(): void
    {
        $this->fakeReply($this->validMarkdown());
        $delivery = $this->delivery();

        app(EmailReadingGenerationService::class)->generate($delivery->load('product'));

        OpenAI::assertSent(Chat::class, function (string $method, array $parameters): bool {
            $system = strtolower(collect($parameters['messages'])->firstWhere('role', 'system')['content'] ?? '');

            return str_contains($system, 'scott stonebridge')
                && str_contains($system, 'compassion')
                && str_contains($system, 'never diagnose')
                && str_contains($system, 'lottery')
                && str_contains($system, 'trial')
                && str_contains($system, 'free will')
                && str_contains($system, 'doctor')
                && str_contains($system, 'solicitor')
                && str_contains($system, 'financial adviser')
                && ! str_contains($system, 'tarot');
        });
    }

    public function test_compliance_boundaries_stay_in_the_prompt_when_a_product_overrides_the_contract(): void
    {
        $this->fakeReply("## Custom\n\nContent.");
        $delivery = $this->delivery(['formatting_contract' => 'SPECIAL PRODUCT CONTRACT']);

        app(EmailReadingGenerationService::class)->generate($delivery->load('product'));

        OpenAI::assertSent(Chat::class, function (string $method, array $parameters): bool {
            $system = strtolower(collect($parameters['messages'])->firstWhere('role', 'system')['content'] ?? '');

            return str_contains($system, 'special product contract') && str_contains($system, 'never diagnose');
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
        $this->fakeReply("## Section\n\n<script>alert('xss')</script><div class=\"danger\">Text</div>\n\nParagraph with <b>inline html</b>.\n\n### From My Heart to Yours\n\nPeace be with you.");
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
        $this->assertTrue($result['is_valid']);
        $this->assertEmpty($result['problems']);

        OpenAI::assertSent(Chat::class, function (string $method, array $parameters): bool {
            $systemMessage = collect($parameters['messages'])->firstWhere('role', 'system')['content'] ?? '';

            return $method === 'create'
                && str_contains($systemMessage, 'From My Heart to Yours')
                && str_contains($systemMessage, 'British English spelling');
        });
    }

    public function test_the_product_run_test_uses_contract_and_returns_sanitized_html(): void
    {
        $this->fakeReply("## Test Heading\n\nTested response.\n\n### From My Heart to Yours\n\nClosing.");
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

    // -------------------------------------------------------------------------
    // Ticket 05: Validation, Retry and Failure tests
    // -------------------------------------------------------------------------

    public function test_truncation_failure_retries_with_higher_max_tokens_and_succeeds(): void
    {
        // First reply: finish_reason length. Second reply: valid with stop.
        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [['message' => ['role' => 'assistant', 'content' => "## Cut off\n\nSome text."], 'finish_reason' => 'length']],
            ]),
            CreateResponse::fake([
                'choices' => [['message' => ['role' => 'assistant', 'content' => $this->validMarkdown()], 'finish_reason' => 'stop']],
            ]),
        ]);

        $delivery = $this->delivery();
        $success = app(EmailReadingGenerationService::class)->generate($delivery->load('product'));

        $this->assertTrue($success);
        $this->assertSame(EmailReadingDelivery::STATUS_GENERATED, $delivery->fresh()->status);

        // Assert 2 calls were made, and the second call had increased max_tokens
        $calls = [];
        OpenAI::assertSent(Chat::class, function (string $method, array $parameters) use (&$calls): bool {
            $calls[] = $parameters;

            return true;
        });

        $this->assertCount(2, $calls);
        $this->assertSame(1500, $calls[0]['max_tokens']);
        $this->assertGreaterThan(1500, $calls[1]['max_tokens']);
        $this->assertSame(2250, $calls[1]['max_tokens']); // 1500 * 1.5
    }

    public function test_leftover_markdown_failure_retries_and_succeeds(): void
    {
        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [['message' => ['role' => 'assistant', 'content' => "## Heading\n\nBroken **bold syntax.\n\n### From My Heart to Yours\n\nClosing."], 'finish_reason' => 'stop']],
            ]),
            CreateResponse::fake([
                'choices' => [['message' => ['role' => 'assistant', 'content' => $this->validMarkdown()], 'finish_reason' => 'stop']],
            ]),
        ]);

        $delivery = $this->delivery();
        $success = app(EmailReadingGenerationService::class)->generate($delivery->load('product'));

        $this->assertTrue($success);
        $this->assertSame(EmailReadingDelivery::STATUS_GENERATED, $delivery->fresh()->status);
    }

    public function test_missing_closing_section_failure_retries_and_succeeds(): void
    {
        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [['message' => ['role' => 'assistant', 'content' => "## Heading\n\nNo closing section provided."], 'finish_reason' => 'stop']],
            ]),
            CreateResponse::fake([
                'choices' => [['message' => ['role' => 'assistant', 'content' => $this->validMarkdown()], 'finish_reason' => 'stop']],
            ]),
        ]);

        $delivery = $this->delivery();
        $success = app(EmailReadingGenerationService::class)->generate($delivery->load('product'));

        $this->assertTrue($success);
        $this->assertSame(EmailReadingDelivery::STATUS_GENERATED, $delivery->fresh()->status);
    }

    public function test_duplicated_greeting_failure_retries_and_succeeds(): void
    {
        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [['message' => ['role' => 'assistant', 'content' => "Dear Jane,\n\n## Heading\n\nText.\n\n### From My Heart to Yours\n\nClosing."], 'finish_reason' => 'stop']],
            ]),
            CreateResponse::fake([
                'choices' => [['message' => ['role' => 'assistant', 'content' => $this->validMarkdown()], 'finish_reason' => 'stop']],
            ]),
        ]);

        $delivery = $this->delivery();
        $success = app(EmailReadingGenerationService::class)->generate($delivery->load('product'));

        $this->assertTrue($success);
        $this->assertSame(EmailReadingDelivery::STATUS_GENERATED, $delivery->fresh()->status);
    }

    public function test_duplicated_signoff_failure_retries_and_succeeds(): void
    {
        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [['message' => ['role' => 'assistant', 'content' => "## Heading\n\nText.\n\n### From My Heart to Yours\n\nClosing.\n\nWarm blessings,\nScott Stonebridge"], 'finish_reason' => 'stop']],
            ]),
            CreateResponse::fake([
                'choices' => [['message' => ['role' => 'assistant', 'content' => $this->validMarkdown()], 'finish_reason' => 'stop']],
            ]),
        ]);

        $delivery = $this->delivery();
        $success = app(EmailReadingGenerationService::class)->generate($delivery->load('product'));

        $this->assertTrue($success);
        $this->assertSame(EmailReadingDelivery::STATUS_GENERATED, $delivery->fresh()->status);
    }

    public function test_second_validation_failure_marks_delivery_failed_and_notifies_admin_once(): void
    {
        Queue::fake();

        // Both attempts fail due to missing closing section and duplicated greeting
        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [['message' => ['role' => 'assistant', 'content' => "Dear Jane,\n\nFirst invalid attempt."], 'finish_reason' => 'stop']],
            ]),
            CreateResponse::fake([
                'choices' => [['message' => ['role' => 'assistant', 'content' => "Dear Jane,\n\nSecond invalid attempt."], 'finish_reason' => 'stop']],
            ]),
        ]);

        $delivery = $this->delivery();
        $success = app(EmailReadingGenerationService::class)->generate($delivery->load('product'));

        $this->assertFalse($success);

        $fresh = $delivery->fresh();
        $this->assertSame(EmailReadingDelivery::STATUS_FAILED, $fresh->status);
        $this->assertStringContainsString('Validation failed:', (string) $fresh->error_message);
        $this->assertStringContainsString(GeneratedBodyValidator::PROBLEM_MISSING_CLOSING_SECTION, (string) $fresh->error_message);
        $this->assertStringContainsString(GeneratedBodyValidator::PROBLEM_DUPLICATED_GREETING, (string) $fresh->error_message);

        Queue::assertPushed(NotifyReadingFailureJob::class, 1);
        Queue::assertPushed(NotifyReadingFailureJob::class, function (NotifyReadingFailureJob $job) use ($delivery): bool {
            return $job->deliveryId === $delivery->id
                && str_contains($job->reason, 'Validation failed:');
        });
    }

    public function test_second_validation_failure_never_overwrites_cancelled_or_sent_state(): void
    {
        Queue::fake();

        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [['message' => ['role' => 'assistant', 'content' => "Dear Jane,\n\nFirst invalid attempt."], 'finish_reason' => 'stop']],
            ]),
            CreateResponse::fake([
                'choices' => [['message' => ['role' => 'assistant', 'content' => "Dear Jane,\n\nSecond invalid attempt."], 'finish_reason' => 'stop']],
            ]),
        ]);

        $delivery = $this->delivery();
        // A customer refund / cancel lands while generation is running
        EmailReadingDelivery::whereKey($delivery->id)->update(['status' => EmailReadingDelivery::STATUS_CANCELLED]);

        $success = app(EmailReadingGenerationService::class)->generate($delivery->load('product'));

        $this->assertFalse($success);
        $this->assertSame(EmailReadingDelivery::STATUS_CANCELLED, $delivery->fresh()->status);
        Queue::assertNotPushed(NotifyReadingFailureJob::class);
    }

    public function test_failed_reading_is_never_dispatched_by_due_dispatcher(): void
    {
        $delivery = $this->delivery([
            'status' => EmailReadingDelivery::STATUS_FAILED,
        ]);
        $delivery->update([
            'status' => EmailReadingDelivery::STATUS_FAILED,
            'scheduled_at' => now()->subHour(),
            'error_message' => 'Validation failed: testing',
        ]);

        $dispatched = app(EmailReadingDeliveryLifecycle::class)->dispatchDue();

        $this->assertSame(0, $dispatched);
        $this->assertSame(EmailReadingDelivery::STATUS_FAILED, $delivery->fresh()->status);
    }

    public function test_admin_regenerate_preview_returns_validation_problems_without_saving(): void
    {
        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [['message' => ['role' => 'assistant', 'content' => "Dear Friend,\n\nText without closing section."], 'finish_reason' => 'stop']],
            ]),
        ]);

        $delivery = $this->delivery([], ['ai_response' => '<p>Original response</p>']);

        $result = app(EmailReadingGenerationService::class)->previewForDelivery($delivery->load('product'));

        $this->assertFalse($result['is_valid']);
        $this->assertNotEmpty($result['problems']);
        $this->assertContains(GeneratedBodyValidator::PROBLEM_DUPLICATED_GREETING, $result['problems']);
        $this->assertContains(GeneratedBodyValidator::PROBLEM_MISSING_CLOSING_SECTION, $result['problems']);

        // Database row was not touched
        $this->assertSame('<p>Original response</p>', $delivery->fresh()->ai_response);
        $this->assertSame(EmailReadingDelivery::STATUS_PENDING, $delivery->fresh()->status);
    }
}
