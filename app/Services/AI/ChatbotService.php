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
use App\Contracts\Services\Sales\LeadCaptureServiceInterface;
use App\DTOs\Chat\AIResponseDTO;
use App\DTOs\Chat\ChatRequestDTO;
use App\DTOs\Chat\IntentDTO;
use App\Exceptions\AI\AIException;
use App\Jobs\AI\GenerateConversationSummaryJob;
use App\Jobs\Sales\SendAbandonRecoveryEmailJob;
use App\Jobs\Sales\SyncStoreKnowledgeJob;
use App\Models\AiConversation;
use App\Models\AiLead;
use App\Models\AiMessage;
use App\Models\ShopSetting;
use App\Models\StoreKnowledge;
use App\Services\Base\BaseService;
use Illuminate\Support\Facades\Cache;
use Throwable;

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
        private readonly LeadCaptureServiceInterface $leads,
    ) {
        parent::__construct();
    }

    public function startSession(array $payload): AiConversation
    {
        $shopDomain = (string) ($payload['shop_domain'] ?? config('shopify.store_domain'));
        $shopSetting = $this->resolveShopSetting($shopDomain);

        $locale = $this->resolveLocale($payload, $shopSetting);

        $conversation = $this->conversations->start(
            shopDomain: $shopDomain,
            shopifyCustomerId: $payload['shopify_customer_id'] ?? null,
            pageType: $payload['page_type'] ?? null,
            locale: $locale,
            metadata: (array) ($payload['metadata'] ?? []),
        );

        $this->cacheSessionLocale($conversation->session_id, $locale);

        $this->maybeSeedWelcomeMessage($conversation, $shopSetting, $locale);

        $this->analytics->record(AnalyticsServiceInterface::EVENT_SESSION_STARTED, $conversation->session_id, [
            'shop_domain' => $shopDomain,
            'page_type' => $payload['page_type'] ?? null,
            'locale' => $locale,
        ]);

        $this->maybeQueueFirstRunKnowledgeSync($shopDomain);

        return $conversation;
    }

    /**
     * Phase F locale priority:
     *   1. shopify_locale (payload, from window.Shopify.locale)
     *   2. payload.locale (legacy front-end field)
     *   3. Accept-Language header (first valid tag)
     *   4. shop_settings.default_locale_override
     *   5. config('sales.locale.fallback', 'en')
     *
     * Result is clamped to shop_settings.allowed_locales_json when set;
     * out-of-list values fall through to default_locale_override or fallback.
     *
     * @param  array<string, mixed>  $payload
     */
    private function resolveLocale(array $payload, ?ShopSetting $shopSetting): string
    {
        $candidates = [
            $payload['shopify_locale'] ?? null,
            $payload['locale'] ?? null,
            $this->parseAcceptLanguage((string) ($payload['accept_language'] ?? '')),
            $shopSetting?->default_locale_override,
        ];

        foreach ($candidates as $candidate) {
            $normalised = $this->normaliseLocale($candidate);
            if ($normalised === null) {
                continue;
            }
            if ($shopSetting !== null && ! $shopSetting->locallyAllowed($normalised)) {
                continue;
            }

            return $normalised;
        }

        $fallback = (string) config('sales.locale.fallback', 'en');

        // If the fallback itself isn't allowed but the shop has an override,
        // prefer the override; otherwise let the fallback win regardless.
        if ($shopSetting?->default_locale_override !== null && $shopSetting->locallyAllowed((string) $shopSetting->default_locale_override)) {
            return (string) $shopSetting->default_locale_override;
        }

        return $fallback;
    }

    /**
     * Parse the first valid IETF tag from an Accept-Language header.
     * Returns null when the header is missing or malformed.
     */
    private function parseAcceptLanguage(string $header): ?string
    {
        if ($header === '') {
            return null;
        }

        foreach (explode(',', $header) as $segment) {
            $tag = trim(strtok($segment, ';') ?: '');
            if ($tag === '' || $tag === '*') {
                continue;
            }
            $normalised = $this->normaliseLocale($tag);
            if ($normalised !== null) {
                return $normalised;
            }
        }

        return null;
    }

    /**
     * Normalise to a 2-letter language code lower case (or null if invalid).
     * Drops region subtag ("en-US" -> "en") to match a common Shopify locale
     * format. Tests can still pass "en-GB" and get back "en".
     */
    private function normaliseLocale(?string $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }
        $trimmed = strtolower(trim($raw));
        if ($trimmed === '') {
            return null;
        }
        // Take the language portion only.
        $lang = strtok($trimmed, '-_');
        if ($lang === false || ! preg_match('/^[a-z]{2,3}$/', $lang)) {
            return null;
        }

        return $lang;
    }

    private function resolveShopSetting(string $shopDomain): ?ShopSetting
    {
        if ($shopDomain === '') {
            return null;
        }

        try {
            return ShopSetting::query()->where('shop_domain', $shopDomain)->first();
        } catch (Throwable $e) {
            $this->logWarning('ShopSetting lookup failed', [
                'shop' => $shopDomain,
                'error' => $e->getMessage(),
            ], 'ai');

            return null;
        }
    }

    private function cacheSessionLocale(string $sessionId, string $locale): void
    {
        if ($sessionId === '' || $locale === '') {
            return;
        }

        try {
            $prefix = (string) config('sales.locale.cache_prefix', 'ai:locale');
            $ttl = (int) config('sales.locale.cache_ttl', 7200);
            Cache::put($prefix.':'.$sessionId, $locale, $ttl);
        } catch (Throwable $e) {
            $this->logWarning('Locale cache write failed', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ], 'ai');
        }
    }

    /**
     * Persist a localised welcome message as the first assistant turn
     * when shop_settings carries one for the resolved locale.
     */
    private function maybeSeedWelcomeMessage(AiConversation $conversation, ?ShopSetting $shopSetting, string $locale): void
    {
        if ($shopSetting === null) {
            return;
        }

        $welcome = $shopSetting->welcomeFor($locale);
        if ($welcome === null || trim($welcome) === '') {
            return;
        }

        try {
            $conversation->messages()->create([
                'role' => AiMessage::ROLE_ASSISTANT,
                'message' => $welcome,
                'metadata' => ['source' => 'welcome', 'locale' => $locale],
            ]);
        } catch (Throwable $e) {
            $this->logWarning('Welcome message seed failed', [
                'session_id' => $conversation->session_id,
                'error' => $e->getMessage(),
            ], 'ai');
        }
    }

    /**
     * Phase D — fire a one-off SyncStoreKnowledgeJob the first time we
     * see a shop with no rows in store_knowledge. Subsequent runs are
     * handled by the daily schedule in routes/console.php.
     */
    private function maybeQueueFirstRunKnowledgeSync(string $shopDomain): void
    {
        if ($shopDomain === '') {
            return;
        }

        try {
            $exists = StoreKnowledge::query()->forShop($shopDomain)->exists();
            if ($exists) {
                return;
            }

            SyncStoreKnowledgeJob::dispatch($shopDomain)
                ->onConnection((string) config('sales.queue.connection', 'redis'))
                ->onQueue((string) config('sales.queue.sync', 'sync'));
        } catch (Throwable $e) {
            $this->logWarning('First-run knowledge sync dispatch failed', [
                'shop' => $shopDomain,
                'error' => $e->getMessage(),
            ], 'ai');
        }
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

        $this->maybeDispatchAbandonRecovery($sessionId);

        $this->analytics->record(AnalyticsServiceInterface::EVENT_SESSION_ENDED, $sessionId, []);

        return $conversation;
    }

    /**
     * Dispatch SendAbandonRecoveryEmailJob with a delay if the session has a
     * captured lead in status='new' AND a cart snapshot with item_count > 0.
     *
     * Idempotent — the job itself re-checks status before sending so a
     * duplicate dispatch (e.g. retried endSession call) cannot send twice.
     */
    private function maybeDispatchAbandonRecovery(string $sessionId): void
    {
        try {
            if (! $this->leads->isCaptured($sessionId)) {
                return;
            }

            $lead = AiLead::query()
                ->forSession($sessionId)
                ->statusNew()
                ->latest('id')
                ->first();

            if ($lead === null || ! $lead->hasCartItems()) {
                return;
            }

            $delay = (int) config('sales.leads.recovery_delay_minutes', 30);

            SendAbandonRecoveryEmailJob::dispatch((int) $lead->id)
                ->onConnection((string) config('sales.queue.connection', 'redis'))
                ->onQueue((string) config('sales.queue.recovery', 'recovery'))
                ->delay(now()->addMinutes($delay));
        } catch (Throwable $e) {
            // Never let recovery dispatch break the end-session flow.
            $this->logWarning('Abandon recovery dispatch failed', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ], 'ai');
        }
    }
}
