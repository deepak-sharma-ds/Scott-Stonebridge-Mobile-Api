<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AI;

use App\Contracts\Services\AI\ConversationServiceInterface;
use App\DTOs\Chat\AIResponseDTO;
use App\Models\AiConversation;
use App\Models\AiMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B1 — shown-entity memory: assistant turns persist a compact record of what
 * was displayed, and the history tail replays it as a hint so the model can
 * resolve references like "add the second one".
 */
class ConversationServiceTest extends TestCase
{
    use RefreshDatabase;

    private ConversationServiceInterface $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ConversationServiceInterface::class);
    }

    public function test_record_assistant_message_persists_shown_entities(): void
    {
        $conversation = AiConversation::factory()->create();

        $this->service->recordAssistantMessage($conversation, $this->response([
            ['type' => 'products', 'items' => [
                ['title' => 'Amethyst Cluster', 'handle' => 'amethyst-cluster', 'variant_id' => 'gid://shopify/ProductVariant/1'],
            ]],
        ]));

        $message = $conversation->messages()->where('role', AiMessage::ROLE_ASSISTANT)->firstOrFail();
        $this->assertSame(
            'gid://shopify/ProductVariant/1',
            $message->metadata['shown_entities'][0]['items'][0]['variant_id'],
        );
    }

    public function test_history_tail_appends_shown_entities_hint_for_assistant_only(): void
    {
        $conversation = AiConversation::factory()->create();

        $conversation->messages()->create([
            'role' => AiMessage::ROLE_USER,
            'message' => 'show me protection crystals',
        ]);
        $this->service->recordAssistantMessage($conversation, $this->response([
            ['type' => 'products', 'items' => [
                ['title' => 'Black Tourmaline', 'handle' => 'black-tourmaline', 'variant_id' => 'v1'],
                ['title' => 'Selenite Wand', 'handle' => 'selenite-wand', 'variant_id' => 'v2'],
            ]],
        ], content: 'Here are two protective picks.'));

        $tail = $this->service->historyTailAsMessages($conversation, 10);

        $user = $tail[0];
        $assistant = $tail[1];

        // User turn is untouched; assistant turn carries the hint with titles.
        $this->assertStringNotContainsString('[Shown to customer', $user['content']);
        $this->assertStringContainsString('Here are two protective picks.', $assistant['content']);
        $this->assertStringContainsString('[Shown to customer', $assistant['content']);
        $this->assertStringContainsString('Black Tourmaline', $assistant['content']);
        $this->assertStringContainsString('Selenite Wand', $assistant['content']);
    }

    public function test_history_tail_has_no_hint_when_nothing_was_shown(): void
    {
        $conversation = AiConversation::factory()->create();
        $this->service->recordAssistantMessage($conversation, $this->response([], content: 'Sure, happy to help.'));

        $tail = $this->service->historyTailAsMessages($conversation, 10);

        $this->assertSame('Sure, happy to help.', $tail[0]['content']);
    }

    /**
     * @param  list<array{type:string, items:list<array<string,mixed>>}>  $shownEntities
     */
    private function response(array $shownEntities, string $content = 'ok'): AIResponseDTO
    {
        return new AIResponseDTO(
            content: $content,
            intent: 'recommendation',
            products: [],
            usage: ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
            latencyMs: 5,
            model: 'gpt-4.1-mini',
            finishReason: 'stop',
            shownEntities: $shownEntities,
        );
    }
}
