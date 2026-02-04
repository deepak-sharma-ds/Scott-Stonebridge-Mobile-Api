<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Contracts\Shopify\StorefrontServiceInterface;
use App\Contracts\Shopify\ShopifyAdapterInterface;
use App\Contracts\Shopify\CartServiceInterface;
use App\Contracts\PackageServiceInterface;
use App\Services\Shopify\StorefrontService;
use App\Services\Shopify\ShopifyAdapter;
use App\Services\Shopify\CartService;
use App\Services\PackageService;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind Shopify services
        $this->app->singleton(ShopifyAdapterInterface::class, ShopifyAdapter::class);
        $this->app->singleton(StorefrontServiceInterface::class, StorefrontService::class);
        $this->app->singleton(CartServiceInterface::class, CartService::class);
        
        // Bind application services
        $this->app->singleton(PackageServiceInterface::class, PackageService::class);
    }

    /**
     * Bootstrap any application services.
     */
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Illuminate\Support\Facades\RateLimiter::for('api', function (\Illuminate\Http\Request $request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
    }
}
