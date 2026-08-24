<?php

declare(strict_types=1);

namespace App\Contracts\Services\AI;

use App\DTOs\Chat\AIResponseDTO;
use App\DTOs\Chat\ChatContextDTO;
use App\DTOs\Chat\IntentDTO;
use App\Models\AiConversation;
use App\Models\AiMessage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ConversationServiceInterface
{
    /**
     * Create a brand new conversation row.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function start(string $shopDomain, ?string $shopifyCustomerId = null, ?string $pageType = null, ?string $locale = null, array $metadata = []): AiConversation;

    /**
     * Lazily create a conversation using the caller-supplied session_id.
     *
     * Used by the stream + message endpoints to self-heal when the widget
     * sends a session_id that the backend doesn't know — typically because
     * the dev backend URL was swapped and the storefront kept its cached
     * id in localStorage. Re-using the supplied id keeps the widget's
     * client-side state stable; subsequent turns find the conversation
     * normally.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function adoptSession(string $sessionId, string $shopDomain, ?string $shopifyCustomerId = null, ?string $pageType = null, ?string $locale = null, array $metadata = []): AiConversation;

    public function findBySession(string $sessionId): ?AiConversation;

    public function recordUserMessage(AiConversation $conversation, string $message, IntentDTO $intent, ChatContextDTO $context): AiMessage;

    public function recordAssistantMessage(AiConversation $conversation, AIResponseDTO $response): AiMessage;

    public function end(AiConversation $conversation): AiConversation;

    public function escalate(AiConversation $conversation): AiConversation;

    /**
     * @return LengthAwarePaginator<AiMessage>
     */
    public function history(AiConversation $conversation, int $perPage = 50): LengthAwarePaginator;

    /**
     * @return list<array{role: string, content: string}>
     */
    public function historyTailAsMessages(AiConversation $conversation, int $tail): array;

    /**
     * Variant ids shown to the customer (products, product details) across
     * the same history-tail window historyTailAsMessages() renders into the
     * model's own visible context — i.e. exactly what the model can already
     * "remember" seeing. Used to guard update_cart against a hallucinated
     * variant id (see ToolExecutor::handleUpdateCart()).
     *
     * @return array<string, true>
     */
    public function recentShownVariantIds(AiConversation $conversation, int $tail): array;
}
