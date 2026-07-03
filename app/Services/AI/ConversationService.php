<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Contracts\Services\AI\ConversationServiceInterface;
use App\DTOs\Chat\AIResponseDTO;
use App\DTOs\Chat\ChatContextDTO;
use App\DTOs\Chat\IntentDTO;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Services\Base\BaseService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

/**
 * Persistence layer for AI conversations. Wraps Eloquent operations so that
 * higher-level services interact with intent-shaped methods rather than the
 * raw models. No business logic beyond translation between DTOs and rows.
 */
class ConversationService extends BaseService implements ConversationServiceInterface
{
    public function start(
        string $shopDomain,
        ?string $shopifyCustomerId = null,
        ?string $pageType = null,
        ?string $locale = null,
        array $metadata = [],
    ): AiConversation {
        $conversation = AiConversation::create([
            'session_id' => (string) Str::uuid(),
            'shop_domain' => $shopDomain,
            'shopify_customer_id' => $shopifyCustomerId,
            'page_type' => $pageType,
            'locale' => $locale,
            'status' => AiConversation::STATUS_ACTIVE,
            'metadata' => $metadata ?: null,
            'started_at' => now(),
        ]);

        $this->logInfo('AI conversation started', [
            'session_id' => $conversation->session_id,
            'shop_domain' => $shopDomain,
        ], 'ai');

        return $conversation;
    }

    public function findBySession(string $sessionId): ?AiConversation
    {
        return AiConversation::where('session_id', $sessionId)->first();
    }

    /**
     * Atomically adopt or fetch a conversation by the caller-supplied
     * session_id. Used by the self-heal path when a widget keeps sending a
     * stale id (e.g. after a dev backend URL swap). Re-using the same id
     * keeps the front-end's localStorage stable, so the user never sees
     * "Conversation not found".
     *
     * @param  array<string, mixed>  $metadata
     */
    public function adoptSession(
        string $sessionId,
        string $shopDomain,
        ?string $shopifyCustomerId = null,
        ?string $pageType = null,
        ?string $locale = null,
        array $metadata = [],
    ): AiConversation {
        /** @var AiConversation $conversation */
        $conversation = AiConversation::firstOrCreate(
            ['session_id' => $sessionId],
            [
                'shop_domain' => $shopDomain,
                'shopify_customer_id' => $shopifyCustomerId,
                'page_type' => $pageType,
                'locale' => $locale,
                'status' => AiConversation::STATUS_ACTIVE,
                'metadata' => $metadata !== [] ? $metadata + ['adopted' => true] : ['adopted' => true],
                'started_at' => now(),
            ],
        );

        if ($conversation->wasRecentlyCreated) {
            $this->logInfo('AI conversation adopted (self-heal)', [
                'session_id' => $conversation->session_id,
                'shop_domain' => $shopDomain,
            ], 'ai');
        }

        return $conversation;
    }

    public function recordUserMessage(
        AiConversation $conversation,
        string $message,
        IntentDTO $intent,
        ChatContextDTO $context,
    ): AiMessage {
        return $conversation->messages()->create([
            'role' => AiMessage::ROLE_USER,
            'message' => $message,
            'intent' => $intent->name,
            'metadata' => [
                'confidence' => $intent->confidence,
                'detected_by' => $intent->detectedBy,
                'page_type' => $context->pageType,
                'product_handle' => $context->product?->handle,
                'cart_item_count' => $context->cart?->itemCount,
            ],
        ]);
    }

    public function recordAssistantMessage(AiConversation $conversation, AIResponseDTO $response): AiMessage
    {
        return $conversation->messages()->create([
            'role' => AiMessage::ROLE_ASSISTANT,
            'message' => $response->content,
            'intent' => $response->intent,
            'prompt_tokens' => $response->promptTokens(),
            'completion_tokens' => $response->completionTokens(),
            'latency_ms' => $response->latencyMs,
            'metadata' => [
                'model' => $response->model,
                'finish_reason' => $response->finishReason,
                'product_ids' => array_map(fn ($p) => $p->id, $response->products),
                // B1 — compact refs to entities shown this turn so a later turn
                // can resolve "add the second one" / "tell me about that".
                'shown_entities' => $response->shownEntities,
            ],
        ]);
    }

    public function end(AiConversation $conversation): AiConversation
    {
        $conversation->update([
            'status' => AiConversation::STATUS_ENDED,
            'ended_at' => now(),
        ]);

        return $conversation->fresh() ?? $conversation;
    }

    public function escalate(AiConversation $conversation): AiConversation
    {
        $conversation->update(['status' => AiConversation::STATUS_ESCALATED]);

        return $conversation->fresh() ?? $conversation;
    }

    public function history(AiConversation $conversation, int $perPage = 50): LengthAwarePaginator
    {
        return $conversation->messages()
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate($perPage);
    }

    public function historyTailAsMessages(AiConversation $conversation, int $tail): array
    {
        return $conversation->messages()
            ->whereIn('role', [AiMessage::ROLE_USER, AiMessage::ROLE_ASSISTANT])
            ->latest('id')
            ->limit($tail * 2)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (AiMessage $m): array => [
                'role' => $m->role,
                'content' => $this->messageContentWithShownEntities($m),
            ])
            ->all();
    }

    /**
     * B1 — for assistant turns, append a terse "[Shown to customer: …]" hint so
     * the model can resolve references to previously displayed products/orders
     * ("add the second one"). Read-time only; the stored message is untouched.
     */
    private function messageContentWithShownEntities(AiMessage $message): string
    {
        if ($message->role !== AiMessage::ROLE_ASSISTANT) {
            return (string) $message->message;
        }

        $shown = $message->metadata['shown_entities'] ?? [];
        if (! is_array($shown) || $shown === []) {
            return (string) $message->message;
        }

        $parts = [];
        foreach ($shown as $group) {
            if (! is_array($group) || empty($group['items']) || ! is_array($group['items'])) {
                continue;
            }
            $type = (string) ($group['type'] ?? 'items');
            $labels = [];
            foreach ($group['items'] as $item) {
                if (! is_array($item)) {
                    continue;
                }
                if (isset($item['order_number'])) {
                    $labels[] = 'Order '.$item['order_number'];
                } elseif (isset($item['title'])) {
                    $meta = [];
                    if (isset($item['handle'])) {
                        $meta[] = 'handle: '.$item['handle'];
                    }
                    if (isset($item['variant_id'])) {
                        $meta[] = 'variant: '.$item['variant_id'];
                    }
                    $labels[] = $meta === []
                        ? sprintf('"%s"', $item['title'])
                        : sprintf('"%s" (%s)', $item['title'], implode(', ', $meta));
                }
            }
            if ($labels !== []) {
                $parts[] = $type.': '.implode(', ', $labels);
            }
        }

        if ($parts === []) {
            return (string) $message->message;
        }

        return trim((string) $message->message)."\n[Shown to customer — ".implode(' | ', $parts).']';
    }
}
