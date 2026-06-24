<?php

use App\Http\Controllers\ShopifyController;
use App\Http\Controllers\Webhook\ShopifyReadingOrderUpdatedWebhookController;
use App\Http\Controllers\Webhook\ShopifyReadingWebhookController;
use Illuminate\Support\Facades\Route;

/**
 * SHOPIFY Webhooks
 */
Route::prefix('webhook')->group(function () {
    Route::post('/shopify', [ShopifyController::class, 'handleAppointmentBookingWebhook']);

    Route::post('/order-paid', [ShopifyController::class, 'orderPaid']);

    Route::post('/order-paid-reading', [ShopifyReadingWebhookController::class, 'handle'])
        ->middleware('shopify.hmac')
        ->name('webhook.shopify.reading');

    Route::post('/order-updated-reading', [ShopifyReadingOrderUpdatedWebhookController::class, 'handle'])
        ->middleware('shopify.hmac')
        ->name('webhook.shopify.reading.updated');
});
