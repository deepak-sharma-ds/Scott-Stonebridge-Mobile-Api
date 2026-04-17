<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {

        $middleware->validateCsrfTokens(except: [
            'webhook/*',
            'shopify/*',
            'shopify/loyalty',
            'bookings/store'
        ]);

        // Add your custom middleware
        $middleware->alias([
            'custom.cors' => \App\Http\Middleware\CustomCors::class,
            'disable.session' => \App\Http\Middleware\DisableSessionMiddleware::class,
            // 'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            // 'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            // 'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            
            // Legacy middleware (kept for backward compatibility)
            'shopify.customer.auth' => \App\Http\Middleware\ShopifyCustomerAuth::class,
            
            // New refactored middleware
            'correlation.id' => \App\Http\Middleware\CorrelationIdMiddleware::class,
            'currency' => \App\Http\Middleware\CurrencyMiddleware::class,
            'rate.limit' => \App\Http\Middleware\RateLimitMiddleware::class,
            'shopify.auth' => \App\Http\Middleware\ShopifyAuthMiddleware::class,
            'api.logging' => \App\Http\Middleware\ApiLoggingMiddleware::class,
            'response.cache' => \App\Http\Middleware\ResponseCacheMiddleware::class,
        ]);
    })
    ->withSchedule(function (Illuminate\Console\Scheduling\Schedule $schedule) {
        // $schedule->command('app:generate-weekly-appoinment-slots')->weeklyOn(0, '00:00'); // Every Sunday midnight
        $schedule->command('app:generate-availability')->dailyAt('01:00');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
