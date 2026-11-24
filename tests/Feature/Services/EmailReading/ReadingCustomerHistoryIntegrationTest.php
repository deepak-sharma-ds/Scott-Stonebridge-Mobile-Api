<?php

declare(strict_types=1);

namespace Tests\Feature\Services\EmailReading;

use App\Models\EmailReadingDelivery;
use App\Models\EmailReadingProduct;
use App\Models\User;
use App\Services\EmailReading\CustomerHistoryService;
use App\Services\EmailReading\EmailReadingDeliveryLifecycle;
use App\Services\EmailReading\EmailReadingGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse as ChatResponse;
use OpenAI\Responses\Embeddings\CreateResponse as EmbeddingResponse;
use OpenAI\Responses\Moderations\CreateResponse as ModerationResponse;
use Tests\TestCase;

/**
 * End-to-end Customer History: Shopify webhook -> generation (with history) ->
 * dispatcher -> email -> History Digest -> the customer's next order. Queues
 * run synchronously and OpenAI is faked; the fakes are consumed in call order.
 */
class ReadingCustomerHistoryIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const DIGEST_ONE = 'Scott told the client that a move abroad is favoured and drew the Moon.';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'shopify.webhook_inbox.connection' => 'sync',
            'email_reading.queue.connection' => 'sync',
            'email_reading.schedule.enabled' => false,
            'email_reading.fulfillment.enabled' => false,
            'email_reading.test_emails' => [],
            'email_reading.admin_notify_email' => 'ops@example.com',
            'email_reading.history.digest.enabled' => true,
            'email_reading.history.max_tokens' => 6000,
            'email_reading.history.max_entries' => null,
            'email_reading.history.max_age_days' => null,
        ]);
        Mail::fake();

        foreach ([111 => 'Love Reading', 222 => 'Tarot Reading'] as $shopifyId => $name) {
            EmailReadingProduct::create([
                'shopify_product_id' => $shopifyId,
                'name' => $name,
                'slug' => 'product-'.$shopifyId,
                'questions_schema' => [['key' => 'Q1', 'label' => 'Your question', 'required' => true]],
                'prompt_template' => 'Customer: {{ $customer_name }}. Question: {{ $q1 ?? "" }}',
                'email_subject' => 'Your reading',
                'is_active' => true,
                'is_automation_enabled' => true,
            ]);
        }
    }

    private function placeOrder(int $orderId, int $productId, string $question, int $customerId = 5001): void
    {
        $this->postJson(route('webhook.shopify.reading'), [
            'id' => $orderId,
            'email' => 'buyer@example.com',
            'customer' => ['id' => $customerId, 'first_name' => 'Jane', 'last_name' => 'Doe'],
            'line_items' => [[
                'id' => $orderId * 10,
                'product_id' => $productId,
                'properties' => [['name' => 'Q1', 'value' => $question]],
            ]],
            'shipping_lines' => [],
        ], ['X-Shopify-Webhook-Id' => "wh-{$orderId}"])->assertOk();
    }

    private function deliveryForOrder(int $orderId): EmailReadingDelivery
    {
        return EmailReadingDelivery::where('shopify_order_id', $orderId)->firstOrFail();
    }

    private function moderation(): ModerationResponse
    {
        $categories = array_fill_keys(['hate', 'harassment', 'illicit', 'illicit/violent', 'self-harm', 'self-harm/intent', 'self-harm/instructions', 'sexual', 'violence'], false);

        return ModerationResponse::fake(['results' => [[
            'categories' => $categories,
            'category_scores' => array_map(fn () => 0.001, $categories),
            'flagged' => false,
        ]]]);
    }

    private function letter(string $card): ChatResponse
    {
        return ChatResponse::fake(['choices' => [['message' => ['role' => 'assistant', 'content' => "### Your Question\n\nThe {$card} sits at the heart of your reading, and it speaks of gentle change arriving in its own time.\n\nTrust the small steps you are already taking.\n\n### From My Heart to Yours\n\nWalk forward with warmth and confidence."], 'finish_reason' => 'stop']]]);
    }

    private function digest(string $text = self::DIGEST_ONE): ChatResponse
    {
        return ChatResponse::fake(['choices' => [['message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => 'stop']]]);
    }

    /**
     * @param  list<float>  $vector
     */
    private function embedding(array $vector): EmbeddingResponse
    {
        return EmbeddingResponse::fake(['data' => [['embedding' => $vector]]]);
    }

    private function dispatchDue(): int
    {
        return app(EmailReadingDeliveryLifecycle::class)->dispatchDue();
    }

    /**
     * @param  array<string,mixed>  $parameters
     */
    private function userMessage(array $parameters): string
    {
        return (string) collect($parameters['messages'])->firstWhere('role', 'user')['content'];
    }

    /**
     * @param  array<string,mixed>  $parameters
     */
    private function systemMessage(array $parameters): string
    {
        return (string) collect($parameters['messages'])->firstWhere('role', 'system')['content'];
    }

    public function test_a_second_product_order_is_given_the_first_orders_answers_and_digest(): void
    {
        OpenAI::fake([
            $this->moderation(), $this->letter('Moon'), $this->embedding([1.0, 0.0]),
            $this->digest(),
            $this->moderation(), $this->letter('Star'), $this->embedding([0.0, 1.0]),
        ]);

        $this->placeOrder(9001, 111, 'Should I move to Spain?');
        $first = $this->deliveryForOrder(9001);
        $this->assertSame(EmailReadingDelivery::STATUS_GENERATED, $first->status);
        $this->assertNull($first->history_included_ids);

        $this->assertSame(1, $this->dispatchDue());
        $first->refresh();
        $this->assertSame(EmailReadingDelivery::STATUS_SENT, $first->status);
        $this->assertSame(self::DIGEST_ONE, $first->history_digest);

        $this->placeOrder(9002, 222, 'Will I be happy there?');
        $second = $this->deliveryForOrder(9002);

        $this->assertSame(EmailReadingDelivery::STATUS_GENERATED, $second->status);
        $this->assertSame([$first->id], $second->history_included_ids);
        $this->assertSame(0, $second->history_dropped_count);
        $this->assertGreaterThan(0, $second->history_tokens);

        OpenAI::assertSent(Chat::class, function (string $method, array $parameters): bool {
            $user = $this->userMessage($parameters);

            return str_contains($user, 'Question: Will I be happy there?')
                && str_contains($user, '<customer_history>')
                && str_contains($user, 'Love Reading (delivered)')
                && str_contains($user, 'Should I move to Spain?')
                && str_contains($user, 'What Scott told them: '.self::DIGEST_ONE)
                && str_contains($user, 'Previous reading for this client explored: The Moon.')
                && str_contains($this->systemMessage($parameters), 'Customer history rules');
        });

        // The first order and the digest call never carry a history block.
        OpenAI::assertSent(Chat::class, 3);
        $withHistory = 0;
        OpenAI::assertSent(Chat::class, function (string $method, array $parameters) use (&$withHistory): bool {
            $withHistory += str_contains($this->userMessage($parameters), '<customer_history>') ? 1 : 0;

            return true;
        });
        $this->assertSame(1, $withHistory);
    }

    public function test_orders_placed_close_together_see_each_other_only_in_one_direction(): void
    {
        OpenAI::fake([
            $this->moderation(), $this->letter('Moon'), $this->embedding([1.0, 0.0]),
            $this->moderation(), $this->letter('Star'), $this->embedding([0.0, 1.0]),
            // Regenerating the EARLIER delivery after the later one exists.
            $this->moderation(), $this->letter('Sun'), $this->embedding([0.7, -0.7]),
        ]);

        $this->placeOrder(9001, 111, 'Should I move to Spain?');
        $this->placeOrder(9002, 222, 'Will I be happy there?');

        $earlier = $this->deliveryForOrder(9001);
        $later = $this->deliveryForOrder(9002);

        $this->assertSame([$earlier->id], $later->history_included_ids);
        OpenAI::assertSent(Chat::class, function (string $method, array $parameters): bool {
            $user = $this->userMessage($parameters);

            return str_contains($user, 'Question: Will I be happy there?')
                && str_contains($user, 'Love Reading (ordered, not yet delivered)')
                && str_contains($user, 'Should I move to Spain?')
                && ! str_contains($user, 'What Scott told them:');
        });

        $this->assertTrue(app(EmailReadingGenerationService::class)->generate($earlier->fresh()->load('product')));

        $earlier->refresh();
        $this->assertNull($earlier->history_included_ids);
        $this->assertNull($earlier->history_dropped_count);
        // Both generations of the earlier order (first and regenerated) asked
        // about Spain, and neither ever carried a history block.
        OpenAI::assertSent(Chat::class, 3);
        $spainRequests = 0;
        $spainRequestsWithHistory = 0;
        OpenAI::assertSent(Chat::class, function (string $method, array $parameters) use (&$spainRequests, &$spainRequestsWithHistory): bool {
            $user = $this->userMessage($parameters);

            if (str_contains($user, 'Question: Should I move to Spain?')) {
                $spainRequests++;
                $spainRequestsWithHistory += str_contains($user, '<customer_history>') ? 1 : 0;
            }

            return true;
        });
        $this->assertSame(2, $spainRequests);
        $this->assertSame(0, $spainRequestsWithHistory);
    }

    public function test_a_held_order_never_enters_history_until_it_has_been_sent(): void
    {
        OpenAI::fake([
            // Order 2 is clean; the held order 1 makes no model call at all.
            $this->moderation(), $this->letter('Moon'), $this->embedding([1.0, 0.0]),
            // The held order after admin approval (the gate is skipped).
            $this->letter('Star'), $this->embedding([0.0, 1.0]),
            // Digests when both are sent.
            $this->digest('A digest for the second order.'), $this->digest('A digest for the held order.'),
        ]);

        $this->placeOrder(9001, 111, 'Do I have cancer? I just want to die.');
        $held = $this->deliveryForOrder(9001);
        $this->assertSame(EmailReadingDelivery::STATUS_COMPLIANCE_HOLD, $held->status);

        $this->placeOrder(9002, 222, 'Will my business thrive?');
        $second = $this->deliveryForOrder(9002);

        $this->assertNull($second->history_included_ids);
        OpenAI::assertSent(Chat::class, 1);
        OpenAI::assertSent(Chat::class, fn (string $method, array $parameters): bool => ! str_contains($this->userMessage($parameters), 'cancer')
            && ! str_contains($this->userMessage($parameters), '<customer_history>'));

        $this->actingAs(User::factory()->create())
            ->post(route('admin.email_readings.approve_compliance', $held))
            ->assertSessionHas('success');

        $held->refresh();
        $this->assertSame(EmailReadingDelivery::STATUS_GENERATED, $held->status);
        $this->assertNotNull($held->compliance_flagged_reason);

        $history = app(CustomerHistoryService::class);
        $this->assertSame([$second->id], $history->forCustomer('buyer@example.com', 5001)->includedIds);

        $this->assertSame(2, $this->dispatchDue());

        $this->assertSame(EmailReadingDelivery::STATUS_SENT, $held->fresh()->status);
        $this->assertEqualsCanonicalizing(
            [$held->id, $second->id],
            $history->forCustomer('buyer@example.com', 5001)->includedIds
        );
        $this->assertStringContainsString('cancer', $history->forCustomer('buyer@example.com', 5001)->block);
    }

    public function test_a_returning_customer_matched_by_customer_id_after_changing_email_gets_the_history(): void
    {
        OpenAI::fake([
            $this->moderation(), $this->letter('Moon'), $this->embedding([1.0, 0.0]),
            $this->digest(),
            $this->moderation(), $this->letter('Star'), $this->embedding([0.0, 1.0]),
        ]);

        $this->placeOrder(9001, 111, 'Should I move to Spain?');
        $this->dispatchDue();

        $this->postJson(route('webhook.shopify.reading'), [
            'id' => 9002,
            'email' => 'new-address@example.com',
            'customer' => ['id' => 5001, 'first_name' => 'Jane', 'last_name' => 'Doe'],
            'line_items' => [['id' => 90020, 'product_id' => 222, 'properties' => [['name' => 'Q1', 'value' => 'Will I be happy there?']]]],
            'shipping_lines' => [],
        ], ['X-Shopify-Webhook-Id' => 'wh-9002'])->assertOk();

        $this->assertSame([$this->deliveryForOrder(9001)->id], $this->deliveryForOrder(9002)->history_included_ids);
        $this->assertSame('buyer@example.com', $this->deliveryForOrder(9001)->customer_email);
    }
}
