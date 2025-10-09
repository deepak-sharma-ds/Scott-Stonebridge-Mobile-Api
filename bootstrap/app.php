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
            'shopify.customer.auth' => \App\Http\Middleware\ShopifyCustomerAuth::class,
        ]);
    })
    ->withSchedule(function (Illuminate\Console\Scheduling\Schedule $schedule) {
        $schedule->command('availability:generate-weekly')->weeklyOn(0, '00:00'); // Every Sunday midnight
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
