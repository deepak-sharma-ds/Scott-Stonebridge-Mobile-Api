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
}
