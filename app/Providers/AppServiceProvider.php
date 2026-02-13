<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Pagination\Paginator;
use App\Models\Configuration;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Blade;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register Shopify Client interfaces
        $this->app->bind(
            \App\Contracts\Shopify\AdminApiClientInterface::class,
            \App\Clients\Shopify\AdminApiClient::class
        );

        $this->app->bind(
            \App\Contracts\Shopify\StorefrontApiClientInterface::class,
            \App\Clients\Shopify\StorefrontApiClient::class
        );

        // Register Service interfaces
        $this->app->bind(
            \App\Contracts\Services\ProductServiceInterface::class,
            \App\Services\Shopify\ProductService::class
        );

        $this->app->bind(
            \App\Contracts\Services\CartServiceInterface::class,
            \App\Services\Shopify\CartService::class
        );

        $this->app->bind(
            \App\Contracts\Services\OrderServiceInterface::class,
            \App\Services\Shopify\OrderService::class
        );

        $this->app->bind(
            \App\Contracts\Services\CustomerServiceInterface::class,
            \App\Services\Shopify\CustomerService::class
        );

        $this->app->bind(
            \App\Contracts\Services\AuthServiceInterface::class,
            \App\Services\Shopify\AuthService::class
        );

        // Register Cache Strategy interface
        $this->app->bind(
            \App\Contracts\Cache\CacheStrategyInterface::class,
            \App\Services\Cache\ShopifyCacheStrategy::class
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

        Blade::component('components.admin.card', 'admin.card');
        Blade::component('components.admin.chart', 'admin.chart');
    }

    /*
    * Load all configuration data
    */
    private function configHandler()
    {
        try {
            \DB::connection()->getPdo();

            if (\Schema::hasTable('configurations')) {
                $configuration = new Configuration();
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
        if ($this->app->runningInConsole() && !$this->app->runningUnitTests()) {
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

        if (!empty($missingKeys)) {
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
     * @param string $key Configuration key (dot notation)
     * @param int $min Minimum acceptable value
     * @param int $max Maximum acceptable value
     */
    private function validateNumericConfig(string $key, int $min, int $max): void
    {
        $value = config("shopify.{$key}");
        
        if (!is_numeric($value)) {
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
