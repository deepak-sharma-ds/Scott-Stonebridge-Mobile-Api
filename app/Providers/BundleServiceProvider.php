<?php

namespace App\Providers;

use App\Services\Bundles\ShopifyBundleCredentials;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Predefined Product Bundles module (Scott Bundle App).
 * Self-contained: registers its own routes so existing route files stay untouched.
 */
class BundleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ShopifyBundleCredentials::class);
    }

    public function boot(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        Route::middleware('web')->group(base_path('routes/bundles.php'));

        Route::middleware('api')->prefix('api')->group(base_path('routes/bundles-webhooks.php'));

        Route::group([], base_path('routes/shopify-app.php'));
    }
}
