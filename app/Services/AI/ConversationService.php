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
                'content' => $m->message,
            ])
            ->all();
    }
}
