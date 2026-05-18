<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\AI;

use App\Contracts\Services\AI\ChatbotServiceInterface;
use App\Contracts\Services\AI\ConversationServiceInterface;
use App\Contracts\Services\AI\EscalationServiceInterface;
use App\DTOs\Chat\ChatRequestDTO;
use App\Exceptions\AI\AIException;
use App\Http\Controllers\Base\BaseApiController;
use App\Http\Requests\AI\EndSessionRequest;
use App\Http\Requests\AI\EscalateRequest;
use App\Http\Requests\AI\SendMessageRequest;
use App\Http\Requests\AI\StartSessionRequest;
use App\Http\Resources\AI\ConversationResource;
use App\Http\Resources\AI\MessageResource;
use App\Http\Resources\AI\ProductRecommendationResource;
use App\Models\AiConversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * REST controller for the AI chatbot.
 *
 * Routes:
 *   POST /api/v1/ai/chat/start       -> startSession
 *   POST /api/v1/ai/chat/message     -> message  (non-streamed)
 *   GET  /api/v1/ai/chat/history/{session} -> history
 *   POST /api/v1/ai/chat/end         -> end
 *   POST /api/v1/ai/chat/escalate    -> escalate
 *
 * Streaming endpoint lives in StreamController so the response object can be
 * returned without journeying through the JSON serializer.
 */
class ChatController extends BaseApiController
{
    public function __construct(
        private readonly ChatbotServiceInterface $chatbot,
        private readonly ConversationServiceInterface $conversations,
        private readonly EscalationServiceInterface $escalation,
    ) {}

    public function start(StartSessionRequest $request): JsonResponse
    {
        try {
            $payload = $request->validated();
            // Phase F — surface Accept-Language so the orchestrator can
            // pick a locale when neither shopify_locale nor locale was sent.
            $payload['accept_language'] = $request->header('Accept-Language');
            $conversation = $this->chatbot->startSession($payload);
        } catch (AIException $e) {
            return $this->error($e->getMessage(), $e->errorContext(), ['error_code' => $e->errorCode()], $e->httpStatus());
        } catch (Throwable $e) {
            report($e);

            return $this->error('Failed to start AI chat session.', [], ['error_code' => 'chat_start_failed'], 500);
        }

        return $this->success(
            'Chat session started.',
            new ConversationResource($conversation),
            statusCode: 201,
        );
    }

    public function message(SendMessageRequest $request): JsonResponse
    {
        $data = $request->validated();
        $dto = ChatRequestDTO::fromArray([
            'session_id' => $data['session_id'],
            'message' => $data['message'],
            'context' => $data['context'] ?? [],
            'access_token' => $request->bearerToken(),
            'ip_address' => $request->ip(),
        ]);

        try {
            $response = $this->chatbot->handleMessage($dto);
        } catch (AIException $e) {
            return $this->error($e->getMessage(), $e->errorContext(), ['error_code' => $e->errorCode()], $e->httpStatus());
        } catch (Throwable $e) {
            report($e);

            return $this->error('AI chat failed.', [], ['error_code' => 'ai_unexpected_error'], 500);
        }

        return $this->success('Message processed.', [
            'reply' => $response->content,
            'intent' => $response->intent,
            'products' => ProductRecommendationResource::collection($response->products),
            'usage' => $response->usage,
            'latency_ms' => $response->latencyMs,
            'model' => $response->model,
        ]);
    }

    public function history(string $session, Request $request): JsonResponse
    {
        $conversation = $this->conversations->findBySession($session);
        if ($conversation === null) {
            return $this->notFound('Conversation not found.');
        }

        $perPage = max(1, min(100, (int) $request->input('per_page', 50)));
        $paginator = $this->conversations->history($conversation, $perPage);

        return $this->successWithPagination(
            'History fetched.',
            MessageResource::collection($paginator->items()),
            [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'has_more' => $paginator->hasMorePages(),
            ],
        );
    }

    public function end(EndSessionRequest $request): JsonResponse
    {
        try {
            $conversation = $this->chatbot->endSession($request->validated()['session_id']);
        } catch (AIException $e) {
            return $this->error($e->getMessage(), $e->errorContext(), ['error_code' => $e->errorCode()], $e->httpStatus());
        } catch (Throwable $e) {
            report($e);

            return $this->error('Failed to end AI chat session.', [], ['error_code' => 'chat_end_failed'], 500);
        }

        return $this->success('Chat session ended.', new ConversationResource($conversation));
    }

    public function escalate(EscalateRequest $request): JsonResponse
    {
        $data = $request->validated();
        $conversation = $this->conversations->findBySession($data['session_id']);
        if ($conversation === null) {
            return $this->notFound('Conversation not found.');
        }

        try {
            $this->escalation->trigger($conversation, $data['reason'], $data['customer_email'] ?? null);
        } catch (Throwable $e) {
            report($e);

            return $this->error('Failed to escalate chat session.', [], ['error_code' => 'chat_escalate_failed'], 500);
        }

        /** @var AiConversation $fresh */
        $fresh = $conversation->fresh() ?? $conversation;

        return $this->success('Chat escalated to human support.', new ConversationResource($fresh));
    }
}
