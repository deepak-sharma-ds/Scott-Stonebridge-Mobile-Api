<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Contracts\Services\AI\AnalyticsServiceInterface;
use App\Contracts\Services\AI\ConversationServiceInterface;
use App\Contracts\Services\AI\IntentDetectionServiceInterface;
use App\Contracts\Services\AI\PromptBuilderServiceInterface;
use App\Contracts\Services\AI\SafetyServiceInterface;
use App\Contracts\Services\AI\ShopifyContextServiceInterface;
use App\Contracts\Services\AI\StreamingServiceInterface;
use App\DTOs\Chat\AIResponseDTO;
use App\DTOs\Chat\ChatContextDTO;
use App\DTOs\Chat\ChatRequestDTO;
use App\DTOs\Chat\IntentDTO;
use App\Exceptions\AI\AIException;
use App\Models\AiConversation;
use App\Services\AI\Streaming\ChunkEmitter;
use App\Services\AI\Tools\ToolDefinitions;
use App\Services\AI\Tools\ToolExecutor;
use App\Services\Base\BaseService;
use App\Services\CurrencyCountryMapService;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\StreamResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Server-Sent Events streaming with OpenAI function-calling.
 *
 * Pipeline per turn:
 *   1. safety check + intent detection (kept for analytics + sales triggers).
 *   2. system prompt built via PromptBuilder (no PRODUCTS block — tools fetch
 *      live data instead).
 *   3. OpenAI createStreamed with the full MCP tools[] array.
 *   4. Streaming loop accumulates text deltas → emits `type:text` chunks AND
 *      collects tool_calls. When `finish_reason=tool_calls` is reached we
 *      execute each tool via ToolExecutor (which emits its own typed chunk
 *      AND returns a short messageForAi string), push role:tool messages
 *      into the next OpenAI call, and re-stream. Capped at 4 loops.
 *   5. Persist assistant message + analytics + emit `type:done`.
 */
class StreamingService extends BaseService implements StreamingServiceInterface
{
    private const MAX_TOOL_LOOPS = 4;

    public function __construct(
        private readonly SafetyServiceInterface $safety,
        private readonly IntentDetectionServiceInterface $intent,
        private readonly ShopifyContextServiceInterface $contextResolver,
        private readonly PromptBuilderServiceInterface $promptBuilder,
        private readonly ConversationServiceInterface $conversations,
        private readonly AnalyticsServiceInterface $analytics,
        private readonly ChunkEmitter $emitter,
        private readonly ToolDefinitions $toolDefinitions,
        private readonly ToolExecutor $toolExecutor,
        private readonly CustomerPersonalizationService $personalization,
        private readonly ChatbotConfigRepository $chatbotConfig,
    ) {
        parent::__construct();
    }

    /**
     * Intents where a signed-in customer's order summary is worth the extra
     * Customer Account API call. Kept narrow so ordinary turns take no hit.
     *
     * @var list<string>
     */
    private const PERSONALISED_INTENTS = [
        IntentDTO::INTENT_GREETING,
        IntentDTO::INTENT_RECOMMENDATION,
        IntentDTO::INTENT_UPSELL_OPPORTUNITY,
        IntentDTO::INTENT_CROSS_SELL_OPPORTUNITY,
    ];

    public function stream(ChatRequestDTO $request): StreamedResponse
    {
        $conversation = $this->conversations->findBySession($request->sessionId);
        if ($conversation === null) {
            // Self-heal: widget kept a stale session_id from a previous dev
            // backend (URL swap, fresh DB). Adopt the id into a fresh row
            // so the SSE turn proceeds without forcing the storefront to
            // clear localStorage. An explicitly ended conversation still
            // 404s below — that close was intentional.
            $shopDomain = (string) ($request->context->shopDomain ?? config('shopify.store_domain'));
            $conversation = $this->conversations->adoptSession(
                sessionId: $request->sessionId,
                shopDomain: $shopDomain,
                pageType: $request->context->pageType,
                locale: $request->context->locale,
            );
        }
        if (! $conversation->isActive()) {
            throw new AIException('Conversation not found or already ended.', 404, 'conversation_not_found');
        }

        $sanitized = $this->safety->sanitize($request->message);
        $this->safety->assertSafe($sanitized);
        $this->safety->assertWithinLimits($request->sessionId, $request->ipAddress);

        $intent = $this->intent->detect($sanitized, $request->context);
        $context = $this->contextResolver->resolve($request->context, $intent, $request->accessToken);

        $this->conversations->recordUserMessage($conversation, $sanitized, $intent, $request->context);
        $this->analytics->record(AnalyticsServiceInterface::EVENT_INTENT_DETECTED, $request->sessionId, [
            'intent' => $intent->name,
            'confidence' => $intent->confidence,
            'detected_by' => $intent->detectedBy,
        ]);

        $isGuest = ($request->context->customer !== null && ! $request->context->customer->loggedIn && empty($request->accessToken));

        // E1 — only fetch the signed-in order summary for intents that benefit,
        // so ordinary turns never pay the Customer Account API round-trip.
        $customerSummary = null;
        if (! $isGuest && in_array($intent->name, self::PERSONALISED_INTENTS, true)) {
            $shopDomain = (string) ($request->context->shopDomain ?? $conversation->shop_domain);
            $customerSummary = $this->personalization->summaryFor($request->sessionId, $shopDomain, $isGuest);
        }

        $messages = $this->promptBuilder->build(
            conversation: $conversation,
            context: $request->context,
            intent: $intent,
            userMessage: $sanitized,
            resolvedContext: $context,
            recommendations: [],
            customerSummary: $customerSummary,
        );

        $tail = (int) config('chatbot.tokens.history_tail', 10);
        $initialShownVariants = $this->conversations->recentShownVariantIds($conversation, $tail);
        $inboundVariants = $this->extractInboundVariantIds($sanitized, $request->context);
        foreach ($inboundVariants as $vid => $true) {
            $initialShownVariants[$vid] = true;
        }

        $currency = (string) ($request->context->currency ?? $request->context->cart?->currency ?? 'GBP');
        if ($currency === '') {
            $currency = 'GBP';
        }
        $country = CurrencyCountryMapService::getCountryCode($currency);

        $sessionCtx = new ChatSessionContext(
            sessionId: $request->sessionId,
            shopDomain: (string) ($request->context->shopDomain ?? $conversation->shop_domain),
            cartId: $request->context->cart?->id,
            // Bridges an already-logged-in storefront customer straight
            // through to the tool layer, skipping the Customer MCP OAuth
            // popup for this turn — see ADR 0008. The storefront must
            // actually send the shopper's Shopify Customer Account access
            // token as `Authorization: Bearer <token>`; until the theme
            // does so, this stays null and behaviour is unchanged.
            customerAccessToken: $request->accessToken === '' ? null : $request->accessToken,
            locale: $request->context->locale ?? 'en',
            pageType: $request->context->pageType,
            // The live storefront cart snapshot IS the single source of
            // truth (ADR 0010) — get_cart/update_cart/suggest_upsell read
            // it directly instead of maintaining a separate Shopify cart.
            cartSnapshot: $request->context->cart,
            // Seeds the same "what has the model already been shown"
            // window historyTailAsMessages() renders into its own visible
            // context, so update_cart's guard never rejects a legitimate
            // reference the model can actually see.
            shownVariantIds: $initialShownVariants,
            isGuest: $isGuest,
            currency: $currency,
            country: $country,
        );

        $response = new StreamedResponse(function () use ($messages, $intent, $conversation, $sessionCtx) {
            ignore_user_abort(true);
            $this->runToolLoop($messages, $intent, $conversation, $sessionCtx);
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     */
    private function runToolLoop(array $messages, IntentDTO $intent, AiConversation $conversation, ChatSessionContext $ctx): void
    {
        $model = (string) config('chatbot.models.default');
        $maxOutput = (int) config('chatbot.tokens.output_budget', 600);
        $temperature = (float) config('chatbot.generation.temperature', 0.6);
        $tools = $this->toolDefinitions->all();
        $startedAt = microtime(true);

        $assistantText = '';
        $promptTokens = 0;
        $completionTokens = 0;
        $finishReason = null;
        $aborted = false;

        // B1 — compact record of entities surfaced to the customer this turn
        // (products / orders / cart), persisted so the next turn can resolve
        // references like "add the second one".
        $shownEntities = [];

        try {
            for ($loop = 0; $loop < self::MAX_TOOL_LOOPS; $loop++) {
                $payload = [
                    'model' => $model,
                    'messages' => $messages,
                    'tools' => $tools,
                    'temperature' => $temperature,
                    'max_tokens' => $maxOutput,
                    'stream_options' => ['include_usage' => true],
                ];

                $turnText = '';
                $toolCalls = [];
                $turnFinish = null;

                $stream = $this->openStreamWithRetry($payload);
                foreach ($stream as $chunk) {
                    $choice = $chunk->choices[0] ?? null;
                    if ($choice !== null) {
                        $delta = $choice->delta ?? null;
                        $content = $delta?->content;
                        if (is_string($content) && $content !== '') {
                            $turnText .= $content;
                            $this->emitter->emitText($content);
                        }

                        $tcDeltas = $delta?->toolCalls ?? null;
                        if (is_array($tcDeltas)) {
                            foreach ($tcDeltas as $tc) {
                                $this->mergeToolCall($toolCalls, $tc);
                            }
                        }

                        if (isset($choice->finishReason) && $choice->finishReason !== null) {
                            $turnFinish = (string) $choice->finishReason;
                        }
                    }

                    $usage = $chunk->usage ?? null;
                    if ($usage !== null) {
                        $promptTokens = (int) ($usage->promptTokens ?? $promptTokens);
                        $completionTokens = (int) ($usage->completionTokens ?? $completionTokens);
                    }
                }

                $assistantText .= $turnText;
                $finishReason = $turnFinish ?? $finishReason;

                if ($turnFinish !== 'tool_calls' || $toolCalls === []) {
                    break;
                }

                $messages[] = [
                    'role' => 'assistant',
                    'content' => $turnText !== '' ? $turnText : null,
                    'tool_calls' => array_values($toolCalls),
                ];

                foreach ($toolCalls as $tc) {
                    $name = $tc['function']['name'] ?? '';
                    if ($name === '') {
                        continue;
                    }
                    $args = $this->decodeArguments($tc['function']['arguments'] ?? '');
                    $result = $this->toolExecutor->execute($name, $args, $ctx);

                    $this->collectShownEntities($shownEntities, $result->emittedChunk);
                    // Extends the model's own visible "shown" window for the
                    // REST of this turn's tool calls too — e.g. search_catalog
                    // then update_cart in the same response — so the guard in
                    // handleUpdateCart() isn't limited to prior-turn history.
                    $ctx = $ctx->withAdditionalShownVariantIds($this->variantIdsFromShownEntities($shownEntities));

                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $tc['id'] ?? '',
                        'content' => $result->messageForAi,
                    ];
                }
            }
        } catch (Throwable $e) {
            $aborted = true;
            $this->logErrorWithException('SSE stream aborted', $e, [
                'session_id' => $ctx->sessionId,
                'buffer_chars' => mb_strlen($assistantText),
            ]);
            $this->emitter->emit('error', [
                'message' => 'AI provider error',
                'code' => 'ai_service_unavailable',
            ]);
            $this->analytics->record(AnalyticsServiceInterface::EVENT_AI_ERROR, $ctx->sessionId, [
                'error' => $e->getMessage(),
            ]);
        }

        if ($aborted && $assistantText === '') {
            return;
        }

        // F1 — the tool loop hit its iteration cap while the model still wanted
        // more tool calls. Rather than stop silently, surface one friendly
        // continuation so the customer knows to nudge again.
        if (! $aborted && $finishReason === 'tool_calls') {
            $capMessage = "I'm still working through that — could you nudge me again in a moment?";
            $this->emitter->emitText($capMessage);
            $assistantText = $assistantText === '' ? $capMessage : $assistantText."\n\n".$capMessage;
        }

        $latency = (int) round((microtime(true) - $startedAt) * 1000);
        $usage = [
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $promptTokens + $completionTokens,
        ];

        $assistant = new AIResponseDTO(
            content: $assistantText,
            intent: $intent->name,
            products: [],
            usage: $usage,
            latencyMs: $latency,
            model: $model,
            finishReason: $aborted ? 'aborted' : $finishReason,
            shownEntities: $shownEntities,
        );

        $this->conversations->recordAssistantMessage($conversation, $assistant);

        $this->analytics->record(AnalyticsServiceInterface::EVENT_MESSAGE_RECEIVED, $ctx->sessionId, [
            'intent' => $intent->name,
            'usage' => $usage,
            'latency_ms' => $latency,
            'aborted' => $aborted,
        ]);

        if (! $aborted) {
            $this->emitter->emitDone();
        }
    }

    /**
     * Accumulate streaming tool_call deltas keyed by `index`. Each chunk
     * may carry partial id/name/arguments — concatenate as they arrive.
     *
     * @param  array<int, array<string, mixed>>  $toolCalls
     */
    private function mergeToolCall(array &$toolCalls, mixed $tc): void
    {
        $idx = is_object($tc) ? ($tc->index ?? null) : ($tc['index'] ?? null);
        if (! is_int($idx)) {
            return;
        }

        $toolCalls[$idx] ??= ['id' => '', 'type' => 'function', 'function' => ['name' => '', 'arguments' => '']];

        $id = is_object($tc) ? ($tc->id ?? null) : ($tc['id'] ?? null);
        if (is_string($id) && $id !== '') {
            $toolCalls[$idx]['id'] .= $id;
        }

        $type = is_object($tc) ? ($tc->type ?? null) : ($tc['type'] ?? null);
        if (is_string($type) && $type !== '') {
            $toolCalls[$idx]['type'] = $type;
        }

        $fn = is_object($tc) ? ($tc->function ?? null) : ($tc['function'] ?? null);
        if ($fn === null) {
            return;
        }
        $name = is_object($fn) ? ($fn->name ?? null) : ($fn['name'] ?? null);
        if (is_string($name) && $name !== '') {
            $toolCalls[$idx]['function']['name'] .= $name;
        }
        $args = is_object($fn) ? ($fn->arguments ?? null) : ($fn['arguments'] ?? null);
        if (is_string($args) && $args !== '') {
            $toolCalls[$idx]['function']['arguments'] .= $args;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeArguments(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Open the OpenAI stream, retrying transient failures BEFORE any bytes are
     * streamed. Safe because a failed establishment has emitted nothing, so a
     * retry cannot duplicate already-streamed text. A mid-stream failure is not
     * retried here — it propagates to the caller's catch (hard-failure path).
     *
     * @param  array<string, mixed>  $payload
     */
    private function openStreamWithRetry(array $payload): StreamResponse
    {
        $maxAttempts = max(1, (int) config('chatbot.generation.stream_max_attempts', 2));
        $attempt = 0;

        while (true) {
            $attempt++;
            try {
                return OpenAI::chat()->createStreamed($payload);
            } catch (Throwable $e) {
                if ($attempt >= $maxAttempts) {
                    throw $e;
                }
                $this->logWarning('OpenAI stream establishment failed; retrying', [
                    'attempt' => $attempt,
                    'max_attempts' => $maxAttempts,
                    'error' => $e->getMessage(),
                ], 'ai');
                usleep($this->chatbotConfig->streamingRetryBackoffBaseMs() * 1000 * $attempt);
            }
        }
    }

    /**
     * B1 — append a compact summary of a tool's emitted chunk to the running
     * shown-entities list (skips chunks that surface nothing referenceable).
     *
     * @param  list<array<string, mixed>>  $shownEntities
     * @param  array<string, mixed>  $chunk
     */
    private function collectShownEntities(array &$shownEntities, array $chunk): void
    {
        $summary = $this->summariseShownChunk($chunk);
        if ($summary !== null) {
            $shownEntities[] = $summary;
        }
    }

    /**
     * Flattens every variant_id out of the running shown-entities list —
     * used to extend ChatSessionContext::$shownVariantIds so update_cart's
     * guard (ADR 0010) covers products surfaced earlier in this same turn.
     *
     * @param  list<array<string, mixed>>  $shownEntities
     * @return array<string, true>
     */
    private function variantIdsFromShownEntities(array $shownEntities): array
    {
        $ids = [];
        foreach ($shownEntities as $group) {
            foreach ((array) ($group['items'] ?? []) as $item) {
                $variantId = is_array($item) ? ($item['variant_id'] ?? null) : null;
                if (is_string($variantId) && $variantId !== '') {
                    $ids[$variantId] = true;
                }
            }
        }

        return $ids;
    }

    /**
     * @return array<string, true>
     */
    private function extractInboundVariantIds(string $message, ChatContextDTO $context): array
    {
        $ids = [];

        // 1. Extract GIDs from message (e.g. gid://shopify/ProductVariant/41603517317294)
        if (preg_match_all('#gid://shopify/ProductVariant/(\d+)#', $message, $matches)) {
            foreach ($matches[0] as $gid) {
                $ids[$gid] = true;
            }
            foreach ($matches[1] as $bare) {
                $ids[$bare] = true;
            }
        }

        // 2. Extract numeric variant IDs explicitly mentioned in message
        if (preg_match_all('/(?:product_variant_id|variant_id|variant)\s*[:=]?\s*(?:gid:\/\/[^\s,\)]+\/)?(\d{6,})/i', $message, $matches)) {
            foreach ($matches[1] as $num) {
                $ids[$num] = true;
                $ids["gid://shopify/ProductVariant/{$num}"] = true;
            }
        }

        // 3. Extract from product page context
        if ($context->product !== null) {
            if ($context->product->id !== null && $context->product->id !== '') {
                $ids[$context->product->id] = true;
            }
            foreach ($context->product->variants as $v) {
                if (is_array($v)) {
                    $vid = (string) ($v['id'] ?? $v['variant_id'] ?? '');
                    if ($vid !== '') {
                        $ids[$vid] = true;
                    }
                }
            }
        }

        // 4. Extract from cart snapshot items
        if ($context->cart !== null) {
            foreach ($context->cart->items as $item) {
                if (is_array($item)) {
                    $vid = (string) ($item['variant_id'] ?? $item['id'] ?? '');
                    if ($vid !== '') {
                        $ids[$vid] = true;
                    }
                    $pid = (string) ($item['product_id'] ?? '');
                    if ($pid !== '') {
                        $ids[$pid] = true;
                    }
                }
            }
        }

        // 5. Extract from recently viewed
        foreach ($context->recentlyViewed as $rv) {
            $rvStr = (string) $rv;
            if ($rvStr !== '') {
                $ids[$rvStr] = true;
                $ids["gid://shopify/Product/{$rvStr}"] = true;
            }
        }

        return $ids;
    }

    /**
     * Reduce a rich SSE chunk to the few identifiers needed to resolve later
     * references ("the second one", "add that"). Returns null for chunk types
     * that carry nothing referenceable.
     *
     * @param  array<string, mixed>  $chunk
     * @return array{type:string, items:list<array<string,mixed>>}|null
     */
    private function summariseShownChunk(array $chunk): ?array
    {
        $cap = 10;
        $keep = static fn ($v): bool => $v !== null && $v !== '';

        switch ($chunk['type'] ?? null) {
            case 'products':
                $items = [];
                foreach (array_slice($chunk['products'] ?? [], 0, $cap) as $p) {
                    if (! is_array($p)) {
                        continue;
                    }
                    $variants = is_array($p['variants'] ?? null) ? $p['variants'] : [];
                    if ($variants !== []) {
                        foreach ($variants as $v) {
                            if (! is_array($v)) {
                                continue;
                            }
                            $item = array_filter([
                                'title' => $v['title'] ?? $p['title'] ?? null,
                                'handle' => $p['handle'] ?? null,
                                'variant_id' => $v['id'] ?? $v['variant_id'] ?? null,
                            ], $keep);
                            if ($item !== []) {
                                $items[] = $item;
                            }
                        }
                    } else {
                        $item = array_filter([
                            'title' => $p['title'] ?? null,
                            'handle' => $p['handle'] ?? null,
                            'variant_id' => $p['variant_id'] ?? null,
                        ], $keep);
                        if ($item !== []) {
                            $items[] = $item;
                        }
                    }
                }

                return $items === [] ? null : ['type' => 'products', 'items' => $items];

            case 'product_detail':
                $p = $chunk['product'] ?? null;
                if (! is_array($p)) {
                    return null;
                }
                $items = [];
                $variants = is_array($p['variants'] ?? null) ? $p['variants'] : [];
                if ($variants !== []) {
                    foreach ($variants as $v) {
                        if (! is_array($v)) {
                            continue;
                        }
                        $item = array_filter([
                            'title' => $v['title'] ?? $p['title'] ?? null,
                            'handle' => $p['handle'] ?? null,
                            'variant_id' => $v['id'] ?? $v['variant_id'] ?? null,
                        ], $keep);
                        if ($item !== []) {
                            $items[] = $item;
                        }
                    }
                } else {
                    $item = array_filter([
                        'title' => $p['title'] ?? null,
                        'handle' => $p['handle'] ?? null,
                        'variant_id' => $p['variant_id'] ?? null,
                    ], $keep);
                    if ($item !== []) {
                        $items[] = $item;
                    }
                }

                return $items === [] ? null : ['type' => 'product', 'items' => $items];

            case 'cart_action':
                $items = [];
                foreach ((array) ($chunk['items'] ?? []) as $row) {
                    if (is_array($row) && isset($row['variant_id']) && is_string($row['variant_id'])) {
                        $items[] = ['variant_id' => $row['variant_id']];
                    }
                }

                return $items === [] ? null : ['type' => 'cart_action', 'items' => $items];

            case 'order_list':
                $items = [];
                foreach (array_slice($chunk['orders'] ?? [], 0, $cap) as $o) {
                    if (! is_array($o)) {
                        continue;
                    }
                    $number = $o['order_number'] ?? $o['name'] ?? null;
                    if ($keep($number)) {
                        $items[] = ['order_number' => $number];
                    }
                }

                return $items === [] ? null : ['type' => 'orders', 'items' => $items];

            case 'cart_state':
                $cart = $chunk['cart'] ?? null;
                $lines = is_array($cart) ? ($cart['items'] ?? []) : [];
                $items = [];
                foreach (array_slice(is_array($lines) ? $lines : [], 0, $cap) as $line) {
                    if (! is_array($line)) {
                        continue;
                    }
                    $item = array_filter([
                        'title' => $line['title'] ?? null,
                        'variant_id' => $line['variant_id'] ?? null,
                    ], $keep);
                    if ($item !== []) {
                        $items[] = $item;
                    }
                }

                return $items === [] ? null : ['type' => 'cart', 'items' => $items];

            default:
                return null;
        }
    }
}
