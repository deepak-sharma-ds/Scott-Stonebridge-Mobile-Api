<?php

use App\Http\Controllers\ShopifyController;
use App\Http\Controllers\Webhook\Campaign\CampaignPaidWebhookController;
use App\Http\Controllers\Webhook\KlaviyoFlowWebhookController;
use App\Http\Controllers\Webhook\ShopifyReadingOrderCancelledWebhookController;
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

    Route::post('/order-cancelled-reading', [ShopifyReadingOrderCancelledWebhookController::class, 'handle'])
        ->middleware('shopify.hmac')
        ->name('webhook.shopify.reading.cancelled');

    Route::post('/order-paid-campaign', [CampaignPaidWebhookController::class, 'handle'])
        ->middleware('shopify.hmac')
        ->name('webhook.shopify.campaign');

    /**
     * KLAVIYO Webhooks (marketing push notifications)
     */
    Route::post('/klaviyo-flow-email', [KlaviyoFlowWebhookController::class, 'handle'])
        ->middleware('klaviyo.secret')
        ->name('webhook.klaviyo.flow');
});
