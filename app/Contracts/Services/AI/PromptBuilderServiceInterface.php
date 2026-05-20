<?php

declare(strict_types=1);

namespace App\Contracts\Services\AI;

use App\DTOs\Chat\ChatContextDTO;
use App\DTOs\Chat\IntentDTO;
use App\Models\AiConversation;

interface PromptBuilderServiceInterface
{
    /**
     * Assemble the messages array passed to OpenAI chat.completions.
     *
     * @param  array<string, mixed>  $resolvedContext  Output of ShopifyContextService::resolve()
     * @param  list<array<string, mixed>>  $recommendations  Product cards to inject (may be empty)
     * @return list<array{role: string, content: string}>
     */
    public function build(
        AiConversation $conversation,
        ChatContextDTO $context,
        IntentDTO $intent,
        string $userMessage,
        array $resolvedContext = [],
        array $recommendations = [],
    ): array;
}
