<?php

namespace App\Providers;

use App\Services\GraphQL\GraphQLLoaderService;
use App\Services\Shopify\AdminService;
use App\Services\Shopify\ShopifyManager;
use App\Services\Shopify\StorefrontService;
use Illuminate\Support\ServiceProvider;

class ShopifyServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Storefront + Admin services
        $this->app->singleton(StorefrontService::class, fn() => new StorefrontService());
        $this->app->singleton(AdminService::class, fn() => new AdminService());

        // Shopify Manager
        $this->app->singleton('shopify', function ($app) {
            return new ShopifyManager(
                $app->make(StorefrontService::class),
                $app->make(AdminService::class)
            );
        });

        // 💡 Register your new GraphQLLoaderService here
        $this->app->singleton('graphql.loader', function () {
            return new GraphQLLoaderService();
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
