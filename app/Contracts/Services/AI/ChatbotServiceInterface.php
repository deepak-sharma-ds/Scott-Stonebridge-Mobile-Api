<?php

declare(strict_types=1);

namespace App\Contracts\Services\AI;

use App\DTOs\Chat\AIResponseDTO;
use App\DTOs\Chat\ChatRequestDTO;
use App\Models\AiConversation;

/**
 * Top-level orchestrator. Owns the full message pipeline:
 * validate -> safety -> intent -> Shopify context -> prompt -> OpenAI ->
 * persist -> analytics. Returns a domain DTO that controllers wrap in the
 * standard API envelope.
 */
interface ChatbotServiceInterface
{
    /**
     * Start a new conversation. Returns the persisted AiConversation row.
     *
     * @param  array<string, mixed>  $payload  ['shop_domain'=>string,'page_type'=>?string,'locale'=>?string,'shopify_customer_id'=>?string,'metadata'=>?array]
     */
    public function startSession(array $payload): AiConversation;

    /**
     * Process a non-streamed message turn end-to-end.
     */
    public function handleMessage(ChatRequestDTO $request): AIResponseDTO;

    /**
     * Mark the conversation finished + dispatch summary/analytics jobs.
     */
    public function endSession(string $sessionId): AiConversation;
}
