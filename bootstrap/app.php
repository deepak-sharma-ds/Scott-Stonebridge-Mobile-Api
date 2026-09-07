<?php

use App\Http\Middleware\ApiLoggingMiddleware;
use App\Http\Middleware\CorrelationIdMiddleware;
use App\Http\Middleware\CurrencyMiddleware;
use App\Http\Middleware\CustomCors;
use App\Http\Middleware\DisableSessionMiddleware;
use App\Http\Middleware\RateLimitMiddleware;
use App\Http\Middleware\ResponseCacheMiddleware;
use App\Http\Middleware\ShopifyAuthMiddleware;
use App\Http\Middleware\ShopifyCustomerAuth;
use App\Http\Middleware\VerifyKlaviyoWebhookSecret;
use App\Http\Middleware\VerifyShopifyWebhookHmac;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {

        $middleware->validateCsrfTokens(except: [
            'webhook/*',
            'shopify/*',
            'shopify/loyalty',
            'bookings/store',
        ]);

        // Add your custom middleware
        $middleware->alias([
            'custom.cors' => CustomCors::class,
            'disable.session' => DisableSessionMiddleware::class,
            // 'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            // 'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            // 'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,

            // Legacy middleware (kept for backward compatibility)
            'shopify.customer.auth' => ShopifyCustomerAuth::class,

            // New refactored middleware
            'correlation.id' => CorrelationIdMiddleware::class,
            'currency' => CurrencyMiddleware::class,
            'rate.limit' => RateLimitMiddleware::class,
            'shopify.auth' => ShopifyAuthMiddleware::class,
            'api.logging' => ApiLoggingMiddleware::class,
            'response.cache' => ResponseCacheMiddleware::class,
            'shopify.hmac' => VerifyShopifyWebhookHmac::class,
            'klaviyo.secret' => VerifyKlaviyoWebhookSecret::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule) {
        // $schedule->command('app:generate-weekly-appoinment-slots')->weeklyOn(0, '00:00'); // Every Sunday midnight
        $schedule->command('app:generate-availability')->dailyAt('01:00');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
