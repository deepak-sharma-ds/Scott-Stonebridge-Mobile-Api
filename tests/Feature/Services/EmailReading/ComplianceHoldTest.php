<?php

declare(strict_types=1);

namespace Tests\Feature\Services\EmailReading;

use App\Jobs\EmailReading\NotifyComplianceHoldJob;
use App\Jobs\EmailReading\NotifyReadingFailureJob;
use App\Jobs\EmailReading\ProcessEmailReadingOrderJob;
use App\Models\EmailReadingDelivery;
use App\Models\EmailReadingProduct;
use App\Services\EmailReading\EmailReadingDeliveryLifecycle;
use App\Services\EmailReading\EmailReadingGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse as ChatResponse;
use OpenAI\Responses\Moderations\CreateResponse as ModerationResponse;
use RuntimeException;
use Tests\TestCase;

class ComplianceHoldTest extends TestCase
{
    use RefreshDatabase;

    private function delivery(string $question, array $deliveryAttributes = []): EmailReadingDelivery
    {
        $product = EmailReadingProduct::create([
            'shopify_product_id' => 111,
            'name' => 'Silver One Question',
            'slug' => 'silver-one',
            'questions_schema' => [['key' => 'Q1', 'label' => 'Question', 'required' => true]],
            'prompt_template' => 'Customer: {{ $customer_name }}. Question: {{ $q1 ?? "" }}',
            'email_subject' => 'Your reading',
        ]);

        return EmailReadingDelivery::create(array_merge([
            'shopify_order_id' => 9001,
            'shopify_line_item_id' => 1,
            'email_reading_product_id' => $product->id,
            'customer_email' => 'buyer@example.com',
            'customer_name' => 'Jane Doe',
            'questions' => ['q1' => $question, 'q_1' => $question, '_raw' => ['Q1' => $question]],
            'status' => EmailReadingDelivery::STATUS_PENDING,
        ], $deliveryAttributes));
    }

    private function runJob(EmailReadingDelivery $delivery): void
    {
        (new ProcessEmailReadingOrderJob($delivery->id))->handle(app(EmailReadingGenerationService::class));
    }

    private function cleanModeration(): ModerationResponse
    {
        $categories = array_fill_keys(['hate', 'hate/threatening', 'harassment', 'harassment/threatening', 'illicit', 'illicit/violent', 'self-harm', 'self-harm/intent', 'self-harm/instructions', 'sexual', 'sexual/minors', 'violence', 'violence/graphic'], false);

        return ModerationResponse::fake([
            'results' => [[
                'categories' => $categories,
                'category_scores' => array_map(fn () => 0.001, $categories),
                'flagged' => false,
            ]],
        ]);
    }

    private function chatReply(): ChatResponse
    {
        return ChatResponse::fake(['choices' => [['message' => ['role' => 'assistant', 'content' => "### Your Reading\n\nA clear path opens.\n\n### From My Heart to Yours\n\nWalk forward with joy."], 'finish_reason' => 'stop']]]);
    }

    public function test_a_clean_order_is_generated_as_normal(): void
    {
        Queue::fake();
        OpenAI::fake([$this->cleanModeration(), $this->chatReply()]);
        $delivery = $this->delivery('Will I find happiness in my new home?');

        $this->runJob($delivery);

        $delivery->refresh();
        $this->assertSame(EmailReadingDelivery::STATUS_GENERATED, $delivery->status);
        $this->assertNotEmpty($delivery->ai_response);
        $this->assertNull($delivery->compliance_flagged_reason);
        Queue::assertNotPushed(NotifyComplianceHoldJob::class);
    }

    public function test_a_critical_breach_enters_compliance_hold_without_generating(): void
    {
        Queue::fake();
        OpenAI::fake();
        $delivery = $this->delivery('Do I have cancer? Please tell me what the doctors will say.');

        $this->runJob($delivery);

        $delivery->refresh();
        $this->assertSame(EmailReadingDelivery::STATUS_COMPLIANCE_HOLD, $delivery->status);
        $this->assertStringContainsString('Medical', (string) $delivery->compliance_flagged_reason);
        $this->assertNull($delivery->ai_response);
        OpenAI::assertNotSent(Chat::class);
        Queue::assertPushed(NotifyComplianceHoldJob::class, fn (NotifyComplianceHoldJob $job): bool => $job->deliveryId === $delivery->id);
    }

    private function claimReply(): ChatResponse
    {
        return ChatResponse::fake(['choices' => [['message' => ['role' => 'assistant', 'content' => "### Your Health\n\nI diagnose a heart condition and you must stop your medication.\n\n### From My Heart to Yours\n\nBe well."], 'finish_reason' => 'stop']]]);
    }

    public function test_output_with_a_definitive_medical_claim_after_retry_enters_compliance_hold(): void
    {
        Queue::fake();
        OpenAI::fake([$this->cleanModeration(), $this->claimReply(), $this->claimReply()]);
        $delivery = $this->delivery('Will I feel more positive about life this year?');

        $this->runJob($delivery);

        $delivery->refresh();
        $this->assertSame(EmailReadingDelivery::STATUS_COMPLIANCE_HOLD, $delivery->status);
        $this->assertStringContainsString('medical claim', strtolower((string) $delivery->compliance_flagged_reason));
        $this->assertNull($delivery->ai_response);
        Queue::assertPushed(NotifyComplianceHoldJob::class);
        Queue::assertNotPushed(NotifyReadingFailureJob::class);
    }

    public function test_a_clean_retry_after_a_medical_claim_is_generated_normally(): void
    {
        Queue::fake();
        OpenAI::fake([$this->cleanModeration(), $this->claimReply(), $this->chatReply()]);
        $delivery = $this->delivery('Will I feel more positive about life this year?');

        $this->runJob($delivery);

        $delivery->refresh();
        $this->assertSame(EmailReadingDelivery::STATUS_GENERATED, $delivery->status);
        $this->assertStringNotContainsString('diagnose', (string) $delivery->ai_response);
    }

    public function test_an_admin_reviewed_delivery_skips_the_gate(): void
    {
        Queue::fake();
        OpenAI::fake([$this->chatReply()]);
        $delivery = $this->delivery('Do I have cancer?', ['compliance_reviewed_at' => now()]);

        $this->runJob($delivery);

        $this->assertSame(EmailReadingDelivery::STATUS_GENERATED, $delivery->fresh()->status);
    }

    public function test_the_gate_can_be_disabled_by_config(): void
    {
        config(['email_reading.compliance.enabled' => false]);
        Queue::fake();
        OpenAI::fake([$this->chatReply()]);
        $delivery = $this->delivery('Do I have cancer?');

        $this->runJob($delivery);

        $this->assertSame(EmailReadingDelivery::STATUS_GENERATED, $delivery->fresh()->status);
    }

    public function test_admin_send_now_on_a_breaching_pending_delivery_holds_it_instead_of_generating(): void
    {
        Queue::fake();
        OpenAI::fake();
        $delivery = $this->delivery('I want to die, will things ever get better?');

        try {
            app(EmailReadingDeliveryLifecycle::class)->sendNow($delivery);
            $this->fail('Expected sendNow to refuse a compliance hold.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('compliance', strtolower($e->getMessage()));
        }

        $this->assertSame(EmailReadingDelivery::STATUS_COMPLIANCE_HOLD, $delivery->fresh()->status);
        OpenAI::assertNotSent(Chat::class);
    }
}
