<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\ShopifyController;
use App\Http\Controllers\GoogleOAuthController;
use App\Http\Controllers\Apis\ProductController;
use App\Http\Controllers\Apis\HomeController;
use App\Http\Controllers\Apis\AuthController;


// ||||||||||||||||||||||||||||||||||||||||||||||||||||||||

// Google Booking apis
Route::post('/shopify/send-html', [ShopifyController::class, 'createPage']);
Route::post('/shopify/receive-form', [BookingController::class, 'store'])->middleware('throttle:3,1');

Route::get('/google/auth-url', [GoogleOAuthController::class, 'getAuthUrl']);
Route::get('/google/callback', [GoogleOAuthController::class, 'handleCallback']);
Route::get('/shopify/get-time-slots', [BookingController::class, 'getTimeSlots']);

// |||||||||||||||||||||||||||||||||||||||||||||||||||||||//
//                                                        //
//                   SHOPIFY APP APIS                     //
//                                                        //
// |||||||||||||||||||||||||||||||||||||||||||||||||||||||//

Route::middleware(['disable.session'])->group(function () {
    // Products APIS
    Route::get('/products', [ProductController::class, 'getAllProducts']);
    Route::get('/products/search', [ProductController::class, 'searchProducts']);
    Route::get('/products/{productId}', [ProductController::class, 'getProductDetail']);

    Route::prefix('auth')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);
        Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('reset-password', [AuthController::class, 'resetPassword']);
    });

    Route::middleware(['shopify.customer.auth'])->prefix('home')->group(function () {
        Route::get('/', [HomeController::class, 'home']);
        Route::post('/subscribe', [HomeController::class, 'subscribe']);

    });
});