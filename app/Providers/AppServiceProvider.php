<?php

namespace App\Providers;

use App\Clients\Shopify\AdminApiClient;
use App\Clients\Shopify\StorefrontApiClient;
use App\Contracts\Cache\CacheStrategyInterface;
use App\Contracts\Services\AI\AIResponseServiceInterface;
use App\Contracts\Services\AI\AnalyticsServiceInterface;
use App\Contracts\Services\AI\ChatbotServiceInterface;
use App\Contracts\Services\AI\ConversationServiceInterface;
use App\Contracts\Services\AI\EscalationServiceInterface;
use App\Contracts\Services\AI\IntentDetectionServiceInterface;
use App\Contracts\Services\AI\ProductRecommendationServiceInterface;
use App\Contracts\Services\AI\PromptBuilderServiceInterface;
use App\Contracts\Services\AI\SafetyServiceInterface;
use App\Contracts\Services\AI\ShopifyContextServiceInterface;
use App\Contracts\Services\AI\StreamingServiceInterface;
use App\Contracts\Services\AuthServiceInterface;
use App\Contracts\Services\CartServiceInterface;
use App\Contracts\Services\ContactServiceInterface;
use App\Contracts\Services\ContentServiceInterface;
use App\Contracts\Services\CurrencyFlagServiceInterface;
use App\Contracts\Services\CustomerServiceInterface;
use App\Contracts\Services\HomeServiceInterface;
use App\Contracts\Services\NavigationServiceInterface;
use App\Contracts\Services\OrderServiceInterface;
use App\Contracts\Services\ProductServiceInterface;
use App\Contracts\Services\ProfileServiceInterface;
use App\Contracts\Services\Sales\LeadCaptureServiceInterface;
use App\Contracts\Services\Sales\ProactiveTriggerServiceInterface;
use App\Contracts\Services\Sales\StoreKnowledgeServiceInterface;
use App\Contracts\Services\Sales\UpsellServiceInterface;
use App\Contracts\Services\ShopServiceInterface;
use App\Contracts\Services\ThemeServiceInterface;
use App\Contracts\Services\WishlistServiceInterface;
use App\Contracts\Shopify\AdminApiClientInterface;
use App\Contracts\Shopify\StorefrontApiClientInterface;
use App\Models\Configuration;
use App\Services\AI\AIResponseService;
use App\Services\AI\AnalyticsService;
use App\Services\AI\ChatbotService;
use App\Services\AI\ConversationService;
use App\Services\AI\EscalationService;
use App\Services\AI\IntentDetectionService;
use App\Services\AI\ProductRecommendationService;
use App\Services\AI\PromptBuilderService;
use App\Services\AI\SafetyService;
use App\Services\AI\ShopifyContextService;
use App\Services\AI\StreamingService;
use App\Services\Cache\ShopifyCacheStrategy;
use App\Services\Sales\LeadCaptureService;
use App\Services\Sales\ProactiveTriggerService;
use App\Services\Sales\StoreKnowledgeService;
use App\Services\Sales\UpsellService;
use App\Services\Shopify\AuthService;
use App\Services\Shopify\CartService;
use App\Services\Shopify\ContactService;
use App\Services\Shopify\ContentService;
use App\Services\Shopify\CurrencyFlagService;
use App\Services\Shopify\CustomerService;
use App\Services\Shopify\HomeService;
use App\Services\Shopify\NavigationService;
use App\Services\Shopify\OrderService;
use App\Services\Shopify\ProductService;
use App\Services\Shopify\ProfileService;
use App\Services\Shopify\ShopService;
use App\Services\Shopify\ThemeTemplateService;
use App\Services\Shopify\WishlistService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register Shopify Client interfaces
        $this->app->bind(
            AdminApiClientInterface::class,
            AdminApiClient::class
        );

        $this->app->bind(
            StorefrontApiClientInterface::class,
            StorefrontApiClient::class
        );

        // Register Service interfaces
        $this->app->bind(
            ProductServiceInterface::class,
            ProductService::class
        );

        $this->app->bind(
            CartServiceInterface::class,
            CartService::class
        );

        $this->app->bind(
            OrderServiceInterface::class,
            OrderService::class
        );

        $this->app->bind(
            CustomerServiceInterface::class,
            CustomerService::class
        );

        $this->app->bind(
            AuthServiceInterface::class,
            AuthService::class
        );

        $this->app->bind(
            HomeServiceInterface::class,
            HomeService::class
        );

        $this->app->bind(
            WishlistServiceInterface::class,
            WishlistService::class
        );

        $this->app->bind(
            ContentServiceInterface::class,
            ContentService::class
        );

        $this->app->bind(
            ContactServiceInterface::class,
            ContactService::class
        );

        $this->app->bind(
            ProfileServiceInterface::class,
            ProfileService::class
        );

        $this->app->bind(
            ShopServiceInterface::class,
            ShopService::class
        );

        $this->app->bind(
            ThemeServiceInterface::class,
            ThemeTemplateService::class
        );

        $this->app->bind(
            NavigationServiceInterface::class,
            NavigationService::class
        );

        $this->app->bind(
            CurrencyFlagServiceInterface::class,
            CurrencyFlagService::class
        );

        // Register Cache Strategy interface
        $this->app->bind(
            CacheStrategyInterface::class,
            ShopifyCacheStrategy::class
        );

        // Register AI chatbot service interfaces
        $this->app->bind(
            SafetyServiceInterface::class,
            SafetyService::class
        );

        $this->app->bind(
            IntentDetectionServiceInterface::class,
            IntentDetectionService::class
        );

        $this->app->bind(
            ShopifyContextServiceInterface::class,
            ShopifyContextService::class
        );

        $this->app->bind(
            ProductRecommendationServiceInterface::class,
            ProductRecommendationService::class
        );

        $this->app->bind(
            ConversationServiceInterface::class,
            ConversationService::class
        );

        $this->app->bind(
            PromptBuilderServiceInterface::class,
            PromptBuilderService::class
        );

        $this->app->bind(
            AIResponseServiceInterface::class,
            AIResponseService::class
        );

        $this->app->bind(
            StreamingServiceInterface::class,
            StreamingService::class
        );

        $this->app->bind(
            AnalyticsServiceInterface::class,
            AnalyticsService::class
        );

        $this->app->bind(
            EscalationServiceInterface::class,
            EscalationService::class
        );

        $this->app->bind(
            ChatbotServiceInterface::class,
            ChatbotService::class
        );

        // -------------------------------------------------------------
        // Phase 2 (AI Sales Agent) service bindings.
        // -------------------------------------------------------------

        $this->app->bind(
            ProactiveTriggerServiceInterface::class,
            ProactiveTriggerService::class
        );

        $this->app->bind(
            LeadCaptureServiceInterface::class,
            LeadCaptureService::class
        );

        $this->app->bind(
            UpsellServiceInterface::class,
            UpsellService::class
        );

        $this->app->bind(
            StoreKnowledgeServiceInterface::class,
            StoreKnowledgeService::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (app()->environment('staging', 'production')) {
            URL::forceScheme('https');
        }
        Paginator::useBootstrapFive(); // Use Bootstrap 5

        $this->configHandler();
        $this->validateShopifyConfiguration();
        $this->configureRateLimiters();

        Blade::component('components.admin.card', 'admin.card');
        Blade::component('components.admin.chart', 'admin.chart');
    }

    /**
     * Register named rate limiters used across the API. Add new buckets here
     * instead of inlining limits inside route definitions so they remain
     * tweakable from a single place.
     */
    private function configureRateLimiters(): void
    {
        $perSession = (int) config('chatbot.rate_limits.per_session_per_minute', 20);
        $perIp = (int) config('chatbot.rate_limits.per_ip_per_minute', 60);

        RateLimiter::for('ai-chat-message', function (Request $request) use ($perSession, $perIp) {
            $sessionKey = (string) ($request->input('session_id') ?? $request->route('session') ?? '');

            return [
                Limit::perMinute($perSession)->by($sessionKey !== '' ? 'session:'.$sessionKey : 'ip:'.$request->ip()),
                Limit::perMinute($perIp)->by('ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('ai-chat-stream', function (Request $request) use ($perIp) {
            $session = (string) ($request->route('session') ?? $request->input('session_id') ?? '');

            return [
                Limit::perMinute(30)->by($session !== '' ? 'stream:'.$session : 'stream-ip:'.$request->ip()),
                Limit::perMinute($perIp)->by('ip:'.$request->ip()),
            ];
        });

        // ------------------------------------------------------------------
        // Phase 2 (AI Sales Agent) limiters. Per-endpoint values are tuned
        // for the expected client behaviour from the storefront chat widget.
        // ------------------------------------------------------------------

        // Proactive trigger GET — high-frequency polling from page visits.
        RateLimiter::for('ai-triggers', fn (Request $request) => [
            Limit::perMinute(30)->by('triggers-ip:'.$request->ip()),
        ]);

        // Trigger open/dismiss event — keyed by session so abusers can't drown
        // out a noisy IP shared across legitimate sessions.
        RateLimiter::for('ai-triggers-event', function (Request $request) {
            $session = (string) ($request->input('session_id') ?? '');

            return [
                Limit::perMinute(60)->by($session !== '' ? 'trig-evt:'.$session : 'trig-evt-ip:'.$request->ip()),
            ];
        });

        // Lead capture — intentionally strict to deter form abuse.
        RateLimiter::for('ai-lead-capture', function (Request $request) {
            $session = (string) ($request->input('session_id') ?? '');

            return [
                Limit::perMinute(5)->by($session !== '' ? 'lead:'.$session : 'lead-ip:'.$request->ip()),
            ];
        });

        // Upsell suggestions — same order-of-magnitude as message limiter.
        RateLimiter::for('ai-upsell', function (Request $request) {
            $session = (string) ($request->input('session_id') ?? '');

            return [
                Limit::perMinute(20)->by($session !== '' ? 'upsell:'.$session : 'upsell-ip:'.$request->ip()),
            ];
        });

        // Conversion event ingestion — high volume because every funnel step
        // emits one (chat opened, message sent, click, etc.).
        RateLimiter::for('ai-analytics-event', function (Request $request) {
            $session = (string) ($request->input('session_id') ?? '');

            return [
                Limit::perMinute(120)->by($session !== '' ? 'conv:'.$session : 'conv-ip:'.$request->ip()),
            ];
        });

        // Knowledge FAQ upsert — internal/admin endpoint, IP-bound.
        RateLimiter::for('ai-knowledge', fn (Request $request) => [
            Limit::perMinute(30)->by('know-ip:'.$request->ip()),
        ]);
    }

    /*
    * Load all configuration data
    */
    private function configHandler()
    {
        try {
            \DB::connection()->getPdo();

            if (\Schema::hasTable('configurations')) {
                $configuration = new Configuration;
                $configuration->init();
            }
        } catch (\Exception $e) {
            \Log::info('Configuration is not loaded.');
        }
    }

    /**
     * Validate required Shopify configuration on application boot.
     *
     * @throws \RuntimeException if required configuration is missing
     */
    private function validateShopifyConfiguration(): void
    {
        // Skip validation in console commands that don't need Shopify
        if ($this->app->runningInConsole() && ! $this->app->runningUnitTests()) {
            return;
        }

        $requiredKeys = [
            'store_domain' => 'SHOPIFY_STORE_DOMAIN',
            'api_version' => 'SHOPIFY_API_VERSION',
            'storefront_access_token' => 'SHOPIFY_STOREFRONT_ACCESS_TOKEN',
        ];

        $missingKeys = [];

        foreach ($requiredKeys as $configKey => $envKey) {
            $value = config("shopify.{$configKey}");

            if (empty($value)) {
                $missingKeys[] = $envKey;
            }
        }

        if (! empty($missingKeys)) {
            $message = sprintf(
                'Missing required Shopify configuration: %s. Please set these environment variables.',
                implode(', ', $missingKeys)
            );

            \Log::error('Shopify configuration validation failed', [
                'missing_keys' => $missingKeys,
            ]);

            // Only throw exception in production/staging to prevent app from starting with invalid config
            if ($this->app->environment('production', 'staging')) {
                throw new \RuntimeException($message);
            }
        }

        // Validate numeric configuration values have sensible defaults
        $this->validateNumericConfig('cache.ttl.product', 60, 86400);
        $this->validateNumericConfig('cache.ttl.collection', 60, 86400);
        $this->validateNumericConfig('cache.ttl.currency', 3600, 604800);
        $this->validateNumericConfig('cache.ttl.cart', 60, 86400);
        $this->validateNumericConfig('http.timeout', 5, 120);
        $this->validateNumericConfig('http.connect_timeout', 1, 60);
        $this->validateNumericConfig('retry.max_attempts', 1, 10);
        $this->validateNumericConfig('retry.initial_delay_ms', 10, 5000);
        $this->validateNumericConfig('retry.max_delay_ms', 100, 30000);
        $this->validateNumericConfig('circuit_breaker.failure_threshold', 1, 100);
        $this->validateNumericConfig('circuit_breaker.success_threshold', 1, 10);
        $this->validateNumericConfig('circuit_breaker.timeout_seconds', 10, 600);
        $this->validateNumericConfig('circuit_breaker.window_seconds', 10, 3600);
        $this->validateNumericConfig('rate_limit.max_attempts', 1, 1000);
        $this->validateNumericConfig('rate_limit.decay_minutes', 1, 60);
    }

    /**
     * Validate that a numeric configuration value is within acceptable range.
     *
     * @param  string  $key  Configuration key (dot notation)
     * @param  int  $min  Minimum acceptable value
     * @param  int  $max  Maximum acceptable value
     */
    private function validateNumericConfig(string $key, int $min, int $max): void
    {
        $value = config("shopify.{$key}");

        if (! is_numeric($value)) {
            \Log::warning("Shopify configuration '{$key}' is not numeric, using default", [
                'key' => $key,
                'value' => $value,
            ]);

            return;
        }

        if ($value < $min || $value > $max) {
            \Log::warning("Shopify configuration '{$key}' is out of acceptable range [{$min}, {$max}]", [
                'key' => $key,
                'value' => $value,
                'min' => $min,
                'max' => $max,
            ]);
        }
    }
}
