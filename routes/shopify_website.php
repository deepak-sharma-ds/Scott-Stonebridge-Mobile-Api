<?php

use App\Http\Controllers\BookingController;
use App\Http\Controllers\ShopifyController;
use App\Http\Controllers\ShopifyPackageController;
use App\Http\Controllers\GoogleOAuthController;
use App\Http\Controllers\PlaySessionController;
use Illuminate\Support\Facades\Route;


// Google Booking apis
Route::post('/shopify/send-html', [ShopifyController::class, 'createPage']);
Route::post('/shopify/receive-form', [BookingController::class, 'store'])->middleware('throttle:3,1');

Route::get('/google/auth-url', [GoogleOAuthController::class, 'getAuthUrl']);
Route::get('/google/callback', [GoogleOAuthController::class, 'handleCallback']);
Route::get('/shopify/get-time-slots', [BookingController::class, 'getTimeSlots']);



Route::prefix('shopify')->group(function () {
    Route::middleware(['disable.session'])->group(function () {

        // Audio subscription Module APIs
        Route::post('/packages', [ShopifyPackageController::class, 'index']);
        Route::post('/packages/{id}', [ShopifyPackageController::class, 'show']);


        // Route::prefix('webhook')->group(function () {
        //     Route::post('/order-paid', [ShopifyController::class, 'orderPaid']); // this route moved to webhook.php
        // });
        Route::post('/play-session', [PlaySessionController::class, 'store']);
        Route::post('/audio-save-progress', [PlaySessionController::class, 'save']);
    });
});
