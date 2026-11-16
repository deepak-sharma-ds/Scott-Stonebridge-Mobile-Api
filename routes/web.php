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

/**
 * Customer-uploaded reading image (public link by unguessable token). The file itself lives on the
 * private disk; this is the URL saved as the answer so Shopify admin and the checkout can show it.
 */
Route::get('/reading-uploads/{token}', [\App\Http\Controllers\Admin\ReadingImageController::class, 'publicShow'])
    ->where('token', '[A-Za-z0-9]{40}')
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, ValidateCsrfToken::class, VerifyCsrfToken::class])
    ->middleware('throttle:120,1')
    ->name('reading_uploads.public');
