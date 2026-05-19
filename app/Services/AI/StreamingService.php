<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Contracts\Services\AI\AnalyticsServiceInterface;
use App\Contracts\Services\AI\ConversationServiceInterface;
use App\Contracts\Services\AI\IntentDetectionServiceInterface;
use App\Contracts\Services\AI\ProductRecommendationServiceInterface;
use App\Contracts\Services\AI\PromptBuilderServiceInterface;
use App\Contracts\Services\AI\SafetyServiceInterface;
use App\Contracts\Services\AI\ShopifyContextServiceInterface;
use App\Contracts\Services\AI\StreamingServiceInterface;
use App\DTOs\Chat\AIResponseDTO;
use App\DTOs\Chat\ChatRequestDTO;
use App\DTOs\Chat\IntentDTO;
use App\DTOs\Chat\ProductRecommendationDTO;
use App\Exceptions\AI\AIException;
use App\Models\AiConversation;
use App\Services\Base\BaseService;
use OpenAI\Laravel\Facades\OpenAI;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Server-Sent Events streaming for chat responses.
 *
 * The controller hands us a validated ChatRequestDTO. We run the full pipeline
 * (safety → intent → context → prompt → OpenAI createStreamed) and emit each
 * delta as an SSE event. A heartbeat keeps proxies (nginx, Cloudflare) from
 * timing the connection out. When the stream completes we persist the full
 * assistant message and fire analytics events.
 */
class StreamingService extends BaseService implements StreamingServiceInterface
{
    public function __construct(
        private readonly SafetyServiceInterface $safety,
        private readonly IntentDetectionServiceInterface $intent,
        private readonly ShopifyContextServiceInterface $contextResolver,
        private readonly ProductRecommendationServiceInterface $recommendations,
        private readonly PromptBuilderServiceInterface $promptBuilder,
        private readonly ConversationServiceInterface $conversations,
        private readonly AnalyticsServiceInterface $analytics,
    ) {
        parent::__construct();
    }

    public function stream(ChatRequestDTO $request): StreamedResponse
    {
        $conversation = $this->conversations->findBySession($request->sessionId);
        if ($conversation === null || ! $conversation->isActive()) {
            throw new AIException('Conversation not found or already ended.', 404, 'conversation_not_found');
        }

        // Run the synchronous pipeline OUTSIDE the streaming callback so any
        // failure surfaces as a normal JSON error envelope rather than as a
        // half-flushed SSE stream.
        $sanitized = $this->safety->sanitize($request->message);
        $this->safety->assertSafe($sanitized);
        $this->safety->assertWithinLimits($request->sessionId, $request->ipAddress);

        $intent = $this->intent->detect($sanitized, $request->context);
        $context = $this->contextResolver->resolve($request->context, $intent, $request->accessToken);
        $products = $intent->name === IntentDTO::INTENT_RECOMMENDATION
            ? $this->recommendations->search($sanitized, $request->context)
            : [];

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
            recommendations: $products,
        );

        $sessionId = $request->sessionId;

        $response = new StreamedResponse(function () use ($messages, $intent, $products, $conversation, $sessionId) {
            // Keep the streaming loop running even if the client disconnects.
            // Without this, a closed browser tab / curl --max-time mid-stream
            // would SIGPIPE the PHP worker the moment we try to flush(), and
            // the post-loop persistence (recordAssistantMessage) would never
            // run — chat history would be missing that assistant turn.
            ignore_user_abort(true);
            $this->pipeOpenAi($messages, $intent, $products, $conversation, $sessionId);
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @param  list<ProductRecommendationDTO>  $products
     */
    private function pipeOpenAi(array $messages, IntentDTO $intent, array $products, AiConversation $conversation, string $sessionId): void
    {
        $model = (string) config('chatbot.models.default');
        $maxOutput = (int) config('chatbot.tokens.output_budget', 600);
        $start = microtime(true);
        $buffer = '';
        $promptTokens = 0;
        $completionTokens = 0;
        $finishReason = null;

        $this->emitInit($intent, $products);
        $lastHeartbeat = microtime(true);

        $streamAborted = false;

        try {
            $stream = OpenAI::chat()->createStreamed([
                'model' => $model,
                'messages' => $messages,
                'temperature' => 0.4,
                'max_tokens' => $maxOutput,
                'stream_options' => ['include_usage' => true],
            ]);

            foreach ($stream as $chunk) {
                $choice = $chunk->choices[0] ?? null;
                $delta = $choice?->delta?->content;
                if ($delta !== null && $delta !== '') {
                    $buffer .= $delta;
                    $this->emit('delta', ['content' => $delta]);
                }

                if ($choice && isset($choice->finishReason)) {
                    $finishReason = $choice->finishReason;
                }

                $usage = $chunk->usage ?? null;
                if ($usage !== null) {
                    $promptTokens = (int) ($usage->promptTokens ?? $promptTokens);
                    $completionTokens = (int) ($usage->completionTokens ?? $completionTokens);
                }

                if (microtime(true) - $lastHeartbeat > 15) {
                    echo ": keepalive\n\n";
                    @ob_flush();
                    flush();
                    $lastHeartbeat = microtime(true);
                }
            }
        } catch (Throwable $e) {
            $streamAborted = true;
            $this->logErrorWithException('SSE stream aborted', $e, [
                'session_id' => $sessionId,
                'buffer_chars' => mb_strlen($buffer),
            ]);
            $this->emit('error', [
                'message' => 'AI provider error',
                'code' => 'ai_service_unavailable',
            ]);
            $this->analytics->record(AnalyticsServiceInterface::EVENT_AI_ERROR, $sessionId, [
                'error' => $e->getMessage(),
            ]);
        }

        // Persist whatever we managed to receive — even on abort. Without
        // this, a partial OpenAI failure leaves the user's question in
        // ai_messages with no assistant follow-up, breaking history and
        // making the next turn confusing for the model. Tag aborted writes
        // in metadata so the frontend / analytics can distinguish them.
        if ($streamAborted && $buffer === '') {
            return;
        }

        $latency = (int) round((microtime(true) - $start) * 1000);
        $usage = [
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $promptTokens + $completionTokens,
        ];

        $assistant = new AIResponseDTO(
            content: $buffer,
            intent: $intent->name,
            products: $products,
            usage: $usage,
            latencyMs: $latency,
            model: $model,
            finishReason: $streamAborted ? 'aborted' : $finishReason,
        );

        $this->conversations->recordAssistantMessage($conversation, $assistant);

        $this->analytics->record(AnalyticsServiceInterface::EVENT_MESSAGE_RECEIVED, $sessionId, [
            'intent' => $intent->name,
            'usage' => $usage,
            'latency_ms' => $latency,
            'product_count' => count($products),
            'aborted' => $streamAborted,
        ]);

        if (! $streamAborted) {
            $this->emit('done', [
                'usage' => $usage,
                'latency_ms' => $latency,
                'products' => array_map(fn ($p) => $p->toArray(), $products),
            ]);
        }
    }

    /**
     * @param  list<ProductRecommendationDTO>  $products
     */
    private function emitInit(IntentDTO $intent, array $products): void
    {
        $this->emit('init', [
            'intent' => $intent->name,
            'product_count' => count($products),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function emit(string $event, array $payload): void
    {
        $json = json_encode(array_merge(['event' => $event], $payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }
        echo 'data: '.$json."\n\n";
        @ob_flush();
        flush();
    }
}
