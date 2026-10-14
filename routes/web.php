<?php

use App\Http\Controllers\EmailTrackingController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * All Webhooks
 */
require __DIR__.'/webhook.php';

/**
 * Authentication Routes
 */
require __DIR__.'/auth.php';

/**
 * Admin Routes
 */
require __DIR__.'/admin.php';

/**
 * Developer Routes (For Development purpose only)
 */
require __DIR__.'/developer.php';

/**
 * Email open-tracking pixel (public, session-less, throttled)
 */
Route::get('/track/email/{token}', [EmailTrackingController::class, 'track'])
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, ValidateCsrfToken::class, VerifyCsrfToken::class])
    ->middleware('throttle:60,1')
    ->name('email.track');
