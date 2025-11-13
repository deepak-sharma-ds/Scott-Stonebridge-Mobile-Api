<?php

use App\Http\Controllers\ShopifyController;
use Illuminate\Support\Facades\Route;

/**
 * SHOPIFY Webhooks
 */
Route::prefix('webhook')->group(function () {
    Route::post('/shopify', [ShopifyController::class, 'handleAppointmentBookingWebhook']);

    Route::post('/order-paid', [ShopifyController::class, 'orderPaid']);
});
