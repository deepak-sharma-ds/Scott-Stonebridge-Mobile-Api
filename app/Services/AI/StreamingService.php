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
    ) {
        parent::__construct();
    }

    public function stream(ChatRequestDTO $request): StreamedResponse
    {
        $conversation = $this->conversations->findBySession($request->sessionId);
        if ($conversation === null || ! $conversation->isActive()) {
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

        $messages = $this->promptBuilder->build(
            conversation: $conversation,
            context: $request->context,
            intent: $intent,
            userMessage: $sanitized,
            resolvedContext: $context,
            recommendations: [],
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
        $tools = $this->toolDefinitions->all();
        $startedAt = microtime(true);

        $assistantText = '';
        $promptTokens = 0;
        $completionTokens = 0;
        $finishReason = null;
        $aborted = false;

        try {
            for ($loop = 0; $loop < self::MAX_TOOL_LOOPS; $loop++) {
                $payload = [
                    'model' => $model,
                    'messages' => $messages,
                    'tools' => $tools,
                    'temperature' => 0.4,
                    'max_tokens' => $maxOutput,
                    'stream_options' => ['include_usage' => true],
                ];

                $turnText = '';
                $toolCalls = [];
                $turnFinish = null;

                $stream = OpenAI::chat()->createStreamed($payload);
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
}
