<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Contracts\Services\AI\AIResponseServiceInterface;
use App\Contracts\Services\AI\AnalyticsServiceInterface;
use App\Contracts\Services\AI\ChatbotServiceInterface;
use App\Contracts\Services\AI\ConversationServiceInterface;
use App\Contracts\Services\AI\IntentDetectionServiceInterface;
use App\Contracts\Services\AI\ProductRecommendationServiceInterface;
use App\Contracts\Services\AI\PromptBuilderServiceInterface;
use App\Contracts\Services\AI\SafetyServiceInterface;
use App\Contracts\Services\AI\ShopifyContextServiceInterface;
use App\DTOs\Chat\AIResponseDTO;
use App\DTOs\Chat\ChatRequestDTO;
use App\DTOs\Chat\IntentDTO;
use App\Exceptions\AI\AIException;
use App\Jobs\AI\GenerateConversationSummaryJob;
use App\Models\AiConversation;
use App\Services\Base\BaseService;

/**
 * The single entry point used by ChatController. Coordinates safety →
 * intent → context → recommendation → prompt → OpenAI → persist → analytics.
 * Keeps the orchestration logic OUT of controllers and out of the streaming
 * service so both can call the same building blocks.
 */
class ChatbotService extends BaseService implements ChatbotServiceInterface
{
    public function __construct(
        private readonly SafetyServiceInterface $safety,
        private readonly IntentDetectionServiceInterface $intentDetection,
        private readonly ShopifyContextServiceInterface $contextResolver,
        private readonly ProductRecommendationServiceInterface $recommendations,
        private readonly PromptBuilderServiceInterface $promptBuilder,
        private readonly AIResponseServiceInterface $aiResponse,
        private readonly ConversationServiceInterface $conversations,
        private readonly AnalyticsServiceInterface $analytics,
    ) {
        parent::__construct();
    }

    public function startSession(array $payload): AiConversation
    {
        $shopDomain = (string) ($payload['shop_domain'] ?? config('shopify.store_domain'));
        $conversation = $this->conversations->start(
            shopDomain: $shopDomain,
            shopifyCustomerId: $payload['shopify_customer_id'] ?? null,
            pageType: $payload['page_type'] ?? null,
            locale: $payload['locale'] ?? null,
            metadata: (array) ($payload['metadata'] ?? []),
        );

        $this->analytics->record(AnalyticsServiceInterface::EVENT_SESSION_STARTED, $conversation->session_id, [
            'shop_domain' => $shopDomain,
            'page_type' => $payload['page_type'] ?? null,
        ]);

        return $conversation;
    }

    public function handleMessage(ChatRequestDTO $request): AIResponseDTO
    {
        $conversation = $this->conversations->findBySession($request->sessionId);
        if ($conversation === null || ! $conversation->isActive()) {
            throw new AIException('Conversation not found or already ended.', 404, 'conversation_not_found');
        }

        $sanitized = $this->safety->sanitize($request->message);
        $this->safety->assertSafe($sanitized);
        $this->safety->assertWithinLimits($request->sessionId, $request->ipAddress);

        $intent = $this->intentDetection->detect($sanitized, $request->context);
        $context = $this->contextResolver->resolve($request->context, $intent, $request->accessToken);
        $products = $intent->name === IntentDTO::INTENT_RECOMMENDATION
            ? $this->recommendations->search($sanitized, $request->context)
            : [];

        $this->conversations->recordUserMessage($conversation, $sanitized, $intent, $request->context);
        $this->analytics->record(AnalyticsServiceInterface::EVENT_MESSAGE_SENT, $request->sessionId, [
            'intent' => $intent->name,
            'confidence' => $intent->confidence,
        ]);

        $messages = $this->promptBuilder->build(
            conversation: $conversation,
            context: $request->context,
            intent: $intent,
            userMessage: $sanitized,
            resolvedContext: $context,
            recommendations: $products,
        );

        $response = $this->aiResponse->complete($messages, $intent, $products);

        $this->conversations->recordAssistantMessage($conversation, $response);

        $this->analytics->record(AnalyticsServiceInterface::EVENT_MESSAGE_RECEIVED, $request->sessionId, [
            'intent' => $intent->name,
            'usage' => $response->usage,
            'latency_ms' => $response->latencyMs,
            'product_count' => count($products),
        ]);

        if (! empty($products)) {
            $this->analytics->record(AnalyticsServiceInterface::EVENT_RECOMMENDATION_SERVED, $request->sessionId, [
                'product_ids' => array_map(fn ($p) => $p->id, $products),
            ]);
        }

        return $response;
    }

    public function endSession(string $sessionId): AiConversation
    {
        $conversation = $this->conversations->findBySession($sessionId);
        if ($conversation === null) {
            throw new AIException('Conversation not found.', 404, 'conversation_not_found');
        }

        $conversation = $this->conversations->end($conversation);

        GenerateConversationSummaryJob::dispatch($conversation->id)
            ->onConnection((string) config('chatbot.queue.connection', 'redis'))
            ->onQueue((string) config('chatbot.queue.name', 'ai'));

        $this->analytics->record(AnalyticsServiceInterface::EVENT_SESSION_ENDED, $sessionId, []);

        return $conversation;
    }
}
