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
use App\DTOs\Chat\ChatRequestDTO;
use App\DTOs\Chat\IntentDTO;
use App\Exceptions\AI\AIException;
use App\Models\AiConversation;
use App\Services\AI\Streaming\ChunkEmitter;
use App\Services\AI\Tools\ToolDefinitions;
use App\Services\AI\Tools\ToolExecutor;
use App\Services\Base\BaseService;
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

        // E1 — only fetch the signed-in order summary for intents that benefit,
        // so ordinary turns never pay the Customer Account API round-trip.
        $customerSummary = null;
        if (in_array($intent->name, self::PERSONALISED_INTENTS, true)) {
            $shopDomain = (string) ($request->context->shopDomain ?? $conversation->shop_domain);
            $customerSummary = $this->personalization->summaryFor($request->sessionId, $shopDomain);
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

        $sessionCtx = new ChatSessionContext(
            sessionId: $request->sessionId,
            shopDomain: (string) ($request->context->shopDomain ?? $conversation->shop_domain),
            cartId: $request->context->cart?->id,
            locale: $request->context->locale ?? 'en',
            pageType: $request->context->pageType,
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
                usleep(200_000 * $attempt);
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
     * Reduce a rich SSE chunk to the few identifiers needed to resolve later
     * references ("the second one", "add that"). Returns null for chunk types
     * that carry nothing referenceable.
     *
     * @param  array<string, mixed>  $chunk
     * @return array{type:string, items:list<array<string,mixed>>}|null
     */
    private function summariseShownChunk(array $chunk): ?array
    {
        $cap = 5;
        $keep = static fn ($v): bool => $v !== null && $v !== '';

        switch ($chunk['type'] ?? null) {
            case 'products':
                $items = [];
                foreach (array_slice($chunk['products'] ?? [], 0, $cap) as $p) {
                    if (! is_array($p)) {
                        continue;
                    }
                    $item = array_filter([
                        'title' => $p['title'] ?? null,
                        'handle' => $p['handle'] ?? null,
                        'variant_id' => $p['variant_id'] ?? null,
                    ], $keep);
                    if ($item !== []) {
                        $items[] = $item;
                    }
                }

                return $items === [] ? null : ['type' => 'products', 'items' => $items];

            case 'product_detail':
                $p = $chunk['product'] ?? null;
                if (! is_array($p)) {
                    return null;
                }
                $variantId = $p['variant_id'] ?? ($p['variants'][0]['id'] ?? null);
                $item = array_filter([
                    'title' => $p['title'] ?? null,
                    'handle' => $p['handle'] ?? null,
                    'variant_id' => $variantId,
                ], $keep);

                return $item === [] ? null : ['type' => 'product', 'items' => [$item]];

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
