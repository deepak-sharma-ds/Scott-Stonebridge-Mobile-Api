<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Contracts\Services\AI\ConversationServiceInterface;
use App\Contracts\Services\AI\PromptBuilderServiceInterface;
use App\DTOs\Chat\ChatContextDTO;
use App\DTOs\Chat\IntentDTO;
use App\Models\AiConversation;
use App\Services\Base\BaseService;
use Illuminate\Support\Facades\View;

/**
 * Assembles the final `messages` array passed to OpenAI. Renders the static
 * system prompt from a Blade view, injects the resolved Shopify context as a
 * tightly-formatted JSON block, then appends the truncated conversation tail
 * and the current user message.
 *
 * Keeps prompts small — never embeds full product descriptions, full policy
 * pages, or the raw Shopify GraphQL responses.
 */
class PromptBuilderService extends BaseService implements PromptBuilderServiceInterface
{
    public function __construct(
        private readonly ConversationServiceInterface $conversations,
    ) {
        parent::__construct();
    }

    public function build(
        AiConversation $conversation,
        ChatContextDTO $context,
        IntentDTO $intent,
        string $userMessage,
        array $resolvedContext = [],
        array $recommendations = [],
    ): array {
        $tail = (int) config('chatbot.tokens.history_tail', 10);
        $template = (string) config('chatbot.prompts.system_template', 'ai.prompts.system');

        $systemBody = View::make($template, [
            'shop' => $context->shopDomain ?? config('shopify.store_domain'),
            'intent' => $intent->name,
            'page_type' => $context->pageType,
            'currency' => $context->currency,
            'locale' => $context->locale,
            'resolved_context' => $resolvedContext,
            'products' => $recommendations,
        ])->render();

        $messages = [
            ['role' => 'system', 'content' => $systemBody],
        ];

        foreach ($this->conversations->historyTailAsMessages($conversation, $tail) as $past) {
            $messages[] = $past;
        }

        $messages[] = ['role' => 'user', 'content' => $userMessage];

        return $messages;
    }
}
