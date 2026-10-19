<?php

/*
|--------------------------------------------------------------------------
| Predefined Product Bundles — Shopify webhooks
|--------------------------------------------------------------------------
| Loaded by App\Providers\BundleServiceProvider (api middleware group, /api prefix).
| Verified by HMAC inside the controller.
*/

use App\Http\Controllers\Bundles\BundleWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/bundle-app/webhooks', BundleWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('bundles.webhooks');
