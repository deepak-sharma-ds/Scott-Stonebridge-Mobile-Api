<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Contracts\Services\AI\ConversationServiceInterface;
use App\Contracts\Services\AI\PromptBuilderServiceInterface;
use App\Contracts\Services\Sales\StoreKnowledgeServiceInterface;
use App\Contracts\Services\Sales\UpsellServiceInterface;
use App\DTOs\Chat\ChatContextDTO;
use App\DTOs\Chat\IntentDTO;
use App\DTOs\Sales\UpsellSuggestionDTO;
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
 *
 * Phase 2 additions:
 *   - injectUpsellContext() — products + free-shipping gap for sales intents
 *   - injectStoreKnowledge() — STUB until Step 7/8 wires StoreKnowledgeService
 *   - injectLocaleRule() — language rule appended as final block
 *   - token-budget guard logs a warning when the system prompt exceeds
 *     config('sales.prompt_guard.system_prompt_max_tokens').
 */
class PromptBuilderService extends BaseService implements PromptBuilderServiceInterface
{
    /**
     * Approx chars-per-token used by the budget guard. OpenAI tokens are
     * typically 3.5–4 chars in English; 4 keeps the heuristic conservative.
     */
    private const CHARS_PER_TOKEN = 4;

    public function __construct(
        private readonly ConversationServiceInterface $conversations,
        private readonly UpsellServiceInterface $upsell,
        private readonly StoreKnowledgeServiceInterface $knowledge,
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

        $upsells = $this->maybeFetchUpsells($intent, $context);

        $systemBody = View::make($template, [
            'shop' => $context->shopDomain ?? config('shopify.store_domain'),
            'intent' => $intent->name,
            'page_type' => $context->pageType,
            'currency' => $context->currency,
            'locale' => $context->locale,
            'resolved_context' => $resolvedContext,
            'products' => $recommendations,
            // Phase 2 blocks — Blade renders each conditionally.
            'upsell_block' => $this->injectUpsellContext($intent, $context, $upsells),
            'knowledge_block' => $this->injectStoreKnowledge($intent, $context),
            'locale_block' => $this->injectLocaleRule($context->locale),
        ])->render();

        // Hard-enforce the prompt budget — soft-warning let oversized prompts
        // through to OpenAI (saw 1071/800 in live smoke), inflating cost and
        // confusing the model when truncation happened upstream.
        $systemBody = $this->enforceSystemPromptBudget($systemBody);

        $messages = [
            ['role' => 'system', 'content' => $systemBody],
        ];

        foreach ($this->conversations->historyTailAsMessages($conversation, $tail) as $past) {
            $messages[] = $past;
        }

        $messages[] = ['role' => 'user', 'content' => $userMessage];

        return $messages;
    }

    /**
     * Build the UPSELL CONTEXT block. Empty string when the intent isn't a
     * sales intent OR there's nothing useful to surface.
     *
     * @param  list<UpsellSuggestionDTO>  $upsells
     */
    public function injectUpsellContext(IntentDTO $intent, ChatContextDTO $context, array $upsells): string
    {
        $salesIntents = [
            IntentDTO::INTENT_UPSELL_OPPORTUNITY,
            IntentDTO::INTENT_CROSS_SELL_OPPORTUNITY,
            IntentDTO::INTENT_CART_HELP,
        ];

        if (! in_array($intent->name, $salesIntents, true)) {
            return '';
        }

        $cartTotal = (float) ($context->cart?->totalPrice ?? 0.0);
        $threshold = (float) config('sales.upsell.default_free_shipping_threshold', 0);
        $shopDomain = (string) ($context->shopDomain ?? config('shopify.store_domain'));
        $gap = $threshold > 0 ? $this->upsell->getFreeShippingGap($cartTotal, $shopDomain) : null;
        $visibility = (float) config('sales.upsell.free_ship_gap_visibility', 0.2);
        $mentionGap = $gap !== null && $threshold > 0 && ($gap / $threshold) <= $visibility;

        if ($upsells === [] && $gap === null) {
            return '';
        }

        $productLines = [];
        foreach ($upsells as $u) {
            $productLines[] = sprintf(
                '- %s (handle: %s, price: %s %s)',
                $u->title,
                $u->handle,
                $u->price ?? '?',
                $u->currency,
            );
        }

        $lines = ['UPSELL CONTEXT:'];
        if ($productLines !== []) {
            $lines[] = 'Customers frequently pair these products with items in the current cart:';
            $lines = array_merge($lines, $productLines);
        }
        if ($threshold > 0) {
            $lines[] = sprintf('Free shipping threshold: %.2f', $threshold);
            $lines[] = sprintf('Current cart total: %.2f', $cartTotal);
            if ($gap !== null) {
                $lines[] = sprintf('Gap to free shipping: %.2f', $gap);
            }
        }

        $lines[] = '';
        $lines[] = 'Rules:';
        if ($productLines !== []) {
            $lines[] = '- Suggest at most 2 of the above products naturally in your reply.';
            $lines[] = '- Never suggest products outside this list.';
        }
        if ($threshold > 0 && $gap !== null) {
            $lines[] = $mentionGap
                ? '- The customer is within 20% of free shipping — gently mention how close they are.'
                : '- Do NOT mention the free shipping gap (customer is not close enough).';
        }

        return implode("\n", $lines);
    }

    /**
     * Inject the STORE KNOWLEDGE block — relevant summaries from the
     * store_knowledge table, picked by intent via
     * config('sales.knowledge.intent_content_map') and bounded by
     * config('sales.knowledge.prompt_block_max_tokens').
     *
     * Empty when no rows match (e.g. greetings, unknown intents) so the
     * Blade template gracefully skips the section.
     */
    public function injectStoreKnowledge(IntentDTO $intent, ChatContextDTO $context): string
    {
        $shopDomain = (string) ($context->shopDomain ?? config('shopify.store_domain'));
        if ($shopDomain === '') {
            return '';
        }

        $snippets = $this->knowledge->getKnowledgeForPrompt($shopDomain, [$intent->name]);
        if ($snippets === '') {
            return '';
        }

        return implode("\n", [
            'STORE KNOWLEDGE:',
            $snippets,
            '',
            'Use this to answer questions about store policies, pages, and content.',
            'Do not answer policy or store questions from memory — use only the above.',
            'If information is not present above, say you do not have that detail.',
        ]);
    }

    /**
     * Final language directive. Renders the LANGUAGE RULE block when a
     * locale is known. Default 'en' is still emitted so the model has an
     * unambiguous instruction.
     */
    public function injectLocaleRule(?string $locale): string
    {
        $resolved = $locale !== null && $locale !== ''
            ? $locale
            : (string) config('sales.locale.fallback', 'en');

        return implode("\n", [
            'LANGUAGE RULE:',
            sprintf('Respond exclusively in: %s', $resolved),
            'Do not switch languages mid-conversation.',
            sprintf('Format all prices and dates according to the regional convention for %s.', $resolved),
            'Keep product names in their original language unless a localised name is provided.',
        ]);
    }

    /**
     * Pull upsells from Shopify when the detected intent calls for them.
     * Cart items must be present — empty cart on cart_help still benefits
     * from the threshold-only context block.
     *
     * @return list<UpsellSuggestionDTO>
     */
    private function maybeFetchUpsells(IntentDTO $intent, ChatContextDTO $context): array
    {
        $salesIntents = [
            IntentDTO::INTENT_UPSELL_OPPORTUNITY,
            IntentDTO::INTENT_CROSS_SELL_OPPORTUNITY,
            IntentDTO::INTENT_CART_HELP,
        ];
        if (! in_array($intent->name, $salesIntents, true)) {
            return [];
        }

        $shopDomain = (string) ($context->shopDomain ?? config('shopify.store_domain'));
        $currency = $context->currency;

        if ($intent->name === IntentDTO::INTENT_CROSS_SELL_OPPORTUNITY
            && $context->product?->id !== null) {
            return $this->upsell->getCrossSells($context->product->id, $shopDomain, $currency);
        }

        $cartItems = $context->cart?->items ?? [];
        if ($cartItems === []) {
            return [];
        }

        return $this->upsell->getUpsells($cartItems, $shopDomain, $currency);
    }

    /**
     * Return the system prompt body trimmed to fit the configured token
     * budget. When the rendered prompt overflows, truncate from the tail
     * (knowledge / upsell blocks sit at the bottom of the system template)
     * and append a marker so the model knows context was cut. Reserve a
     * small headroom for the marker itself.
     */
    private function enforceSystemPromptBudget(string $systemBody): string
    {
        $maxTokens = (int) config('sales.prompt_guard.system_prompt_max_tokens', 800);
        if ($maxTokens <= 0) {
            return $systemBody;
        }

        $estimated = (int) ceil(mb_strlen($systemBody) / self::CHARS_PER_TOKEN);
        if ($estimated <= $maxTokens) {
            return $systemBody;
        }

        $marker = "\n\n[CONTEXT TRUNCATED TO FIT TOKEN BUDGET]";
        $maxChars = $maxTokens * self::CHARS_PER_TOKEN - mb_strlen($marker);
        $trimmed = mb_substr($systemBody, 0, max(0, $maxChars)).$marker;

        $this->logWarning('System prompt truncated to token budget', [
            'estimated_tokens' => $estimated,
            'max_tokens' => $maxTokens,
            'truncated_chars' => mb_strlen($systemBody) - mb_strlen($trimmed),
        ], 'ai');

        return $trimmed;
    }
}
