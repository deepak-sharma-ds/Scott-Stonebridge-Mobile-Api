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
 *   - injectUpsellContext() — complementary products for sales intents
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
        ?array $customerSummary = null,
    ): array {
        $tail = (int) config('chatbot.tokens.history_tail', 10);
        $template = (string) config('chatbot.prompts.system_template', 'ai.prompts.system');

        $upsells = $this->maybeFetchUpsells($intent, $context);
        $upsellBlock = $this->injectUpsellContext($intent, $context, $upsells);
        $localeBlock = $this->injectLocaleRule($context->locale);
        $customerBlock = $this->injectCustomerContext($customerSummary);
        $knowledgeResult = $this->fetchKnowledgeResult($intent, $context, $userMessage);
        $knowledgeSnippets = $knowledgeResult['snippets'];
        $knowledgeDegraded = $knowledgeResult['degraded'];

        $render = function (string $knowledgeSnippets) use ($template, $context, $intent, $resolvedContext, $recommendations, $upsellBlock, $localeBlock, $customerBlock, $knowledgeDegraded): string {
            return View::make($template, [
                'shop' => $context->shopDomain ?? config('shopify.store_domain'),
                'intent' => $intent->name,
                'page_type' => $context->pageType,
                'currency' => $context->currency,
                'locale' => $context->locale,
                'resolved_context' => $resolvedContext,
                'products' => $recommendations,
                // Phase 2 blocks — Blade renders each conditionally.
                'upsell_block' => $upsellBlock,
                'knowledge_block' => $this->wrapKnowledgeSnippets($knowledgeSnippets, $knowledgeDegraded),
                'locale_block' => $localeBlock,
                'customer_block' => $customerBlock,
            ])->render();
        };

        $systemBody = $render($knowledgeSnippets);

        // Hard-enforce the prompt budget — soft-warning let oversized prompts
        // through to OpenAI (saw 1071/800 in live smoke), inflating cost and
        // confusing the model when truncation happened upstream.
        $systemBody = $this->enforceSystemPromptBudget($systemBody, $knowledgeSnippets, $render);

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

        if ($upsells === []) {
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
        $lines[] = 'Customers frequently pair these products with items in the current cart:';
        $lines = array_merge($lines, $productLines);

        $lines[] = '';
        $lines[] = 'Rules:';
        $lines[] = '- Suggest at most 2 of the above products naturally in your reply.';
        $lines[] = '- Never suggest products outside this list.';

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
    public function injectStoreKnowledge(IntentDTO $intent, ChatContextDTO $context, string $userMessage = ''): string
    {
        $result = $this->fetchKnowledgeResult($intent, $context, $userMessage);

        return $this->wrapKnowledgeSnippets($result['snippets'], $result['degraded']);
    }

    /**
     * Raw, newline-joined knowledge rows from StoreKnowledgeService, ranked
     * highest-relevance first — unwrapped so enforceSystemPromptBudget() can
     * drop the lowest-ranked (trailing) rows without touching the grounding
     * instructions in wrapKnowledgeSnippets(). Also reports whether semantic
     * search degraded to keyword-only this call, so a failed embedding call
     * doesn't silently masquerade as "no information exists".
     *
     * @return array{snippets: string, degraded: bool}
     */
    private function fetchKnowledgeResult(IntentDTO $intent, ChatContextDTO $context, string $userMessage): array
    {
        $shopDomain = (string) ($context->shopDomain ?? config('shopify.store_domain'));
        if ($shopDomain === '') {
            return ['snippets' => '', 'degraded' => false];
        }

        // Only forward the user message when present so legacy callers
        // and mocks that stub the 2-arg signature keep matching. The
        // service signature itself widened with an optional 3rd arg.
        $snippets = $userMessage !== ''
            ? $this->knowledge->getKnowledgeForPrompt($shopDomain, [$intent->name], $userMessage)
            : $this->knowledge->getKnowledgeForPrompt($shopDomain, [$intent->name]);

        return ['snippets' => $snippets, 'degraded' => $this->knowledge->wasLastRetrievalDegraded()];
    }

    private function wrapKnowledgeSnippets(string $snippets, bool $degraded = false): string
    {
        if ($snippets === '' && ! $degraded) {
            return '';
        }

        $lines = ['STORE KNOWLEDGE:'];
        $lines[] = $snippets !== '' ? $snippets : '(no rows retrieved this turn)';
        $lines[] = '';
        $lines[] = 'Use this to answer questions about store policies, pages, and content.';
        $lines[] = 'Do not answer policy or store questions from memory — use only the above.';
        $lines[] = 'If information is not present above, say you do not have that detail.';

        if ($degraded) {
            $lines[] = '';
            $lines[] = 'NOTE: Semantic search was unavailable this turn, so the above may be incomplete.';
            $lines[] = 'If it does not answer the question, say your search may be incomplete and offer to check again — do not state that no information exists.';
        }

        return implode("\n", $lines);
    }

    /**
     * E1 — CUSTOMER CONTEXT block for a signed-in customer. Privacy-safe:
     * only order numbers, totals, and dates (no name / email / line items).
     * Empty string when there is no summary so the Blade section is skipped.
     *
     * @param  array<string, mixed>|null  $summary  {order_count, recent_orders:[{number,total,currency,date}]}
     */
    public function injectCustomerContext(?array $summary): string
    {
        $recent = $summary['recent_orders'] ?? null;
        if (! is_array($recent) || $recent === []) {
            return '';
        }

        $lines = ['CUSTOMER CONTEXT:'];
        $lines[] = 'This customer is signed in. Recent orders (newest first):';
        foreach ($recent as $order) {
            if (! is_array($order) || empty($order['number'])) {
                continue;
            }
            $total = isset($order['total']) ? trim(($order['currency'] ?? '').' '.$order['total']) : null;
            $date = $order['date'] ?? null;
            $lines[] = trim(sprintf(
                '- Order #%s%s%s',
                $order['number'],
                $total !== null && $total !== '' ? " — {$total}" : '',
                $date !== null ? " (placed {$date})" : '',
            ));
        }

        $lines[] = '';
        $lines[] = 'Use this to greet them warmly and offer relevant help (order status, reorders, complements to past purchases).';
        $lines[] = 'Never invent orders or details beyond what is listed here, and do not read the list back verbatim unless asked.';

        return implode("\n", $lines);
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
     * Cart items must be present; an empty cart yields no suggestions.
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
     * budget. STORE KNOWLEDGE rows are ranked highest-relevance first, so an
     * oversized prompt is shrunk by dropping the lowest-ranked (trailing)
     * rows one at a time and re-rendering — never by cutting the rendered
     * prompt string itself, which previously risked slicing HARD RULES,
     * CUSTOMER CONTEXT, or the LANGUAGE RULE off mid-sentence since they sit
     * after STORE KNOWLEDGE in the template. Falls back to a blind tail-cut
     * only if the prompt is still oversized with zero knowledge rows left —
     * i.e. the non-knowledge portion of the prompt alone exceeds the budget.
     *
     * @param  \Closure(string): string  $render  Re-renders the full system
     *                                            prompt for a given knowledge-snippets string.
     */
    private function enforceSystemPromptBudget(string $systemBody, string $knowledgeSnippets, \Closure $render): string
    {
        $maxTokens = (int) config('sales.prompt_guard.system_prompt_max_tokens', 800);
        if ($maxTokens <= 0) {
            return $systemBody;
        }

        $estimated = $this->estimatedTokens($systemBody);
        if ($estimated <= $maxTokens) {
            return $systemBody;
        }

        $lines = $knowledgeSnippets === '' ? [] : explode("\n", $knowledgeSnippets);
        $droppedRows = 0;
        while ($lines !== [] && $this->estimatedTokens($systemBody) > $maxTokens) {
            array_pop($lines);
            $droppedRows++;
            $systemBody = $render(implode("\n", $lines));
        }

        if ($this->estimatedTokens($systemBody) <= $maxTokens) {
            if ($droppedRows > 0) {
                $this->logWarning('Dropped lowest-ranked store knowledge rows to fit system prompt budget', [
                    'dropped_rows' => $droppedRows,
                    'max_tokens' => $maxTokens,
                ], 'ai');
            }

            return $systemBody;
        }

        // The non-knowledge portion of the prompt alone exceeds the budget —
        // knowledge is already fully dropped, so fall back to a blind cut as
        // a last resort rather than sending an unbounded prompt to OpenAI.
        $marker = "\n\n[CONTEXT TRUNCATED TO FIT TOKEN BUDGET]";
        $maxChars = $maxTokens * self::CHARS_PER_TOKEN - mb_strlen($marker);
        $trimmed = mb_substr($systemBody, 0, max(0, $maxChars)).$marker;

        $this->logWarning('System prompt truncated to token budget after dropping all store knowledge', [
            'estimated_tokens' => $this->estimatedTokens($systemBody),
            'max_tokens' => $maxTokens,
            'dropped_rows' => $droppedRows,
            'truncated_chars' => mb_strlen($systemBody) - mb_strlen($trimmed),
        ], 'ai');

        return $trimmed;
    }

    private function estimatedTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / self::CHARS_PER_TOKEN);
    }
}
