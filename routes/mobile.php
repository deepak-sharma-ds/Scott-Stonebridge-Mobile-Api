<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Apis\ProductController;
use App\Http\Controllers\Apis\HomeController;
use App\Http\Controllers\Apis\AuthController;
use App\Http\Controllers\Apis\CartController;
use App\Http\Controllers\Apis\OrdertController;

/**
 * Shopify APIs for Mobile App Development
 */
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

    Route::middleware(['shopify.customer.auth'])->group(function () {
        // Products
        Route::post('/categories', [ProductController::class, 'getCategories']);
        Route::post('/categories/products', [ProductController::class, 'getProducts']);
        Route::post('/products/details', [ProductController::class, 'getProductDetails']);
        Route::post('/featured/product', [ProductController::class, 'getFeaturedProducts']);

        Route::prefix('cart',)->group(function () {
            Route::post('/create', [CartController::class, 'createCart'])->name('cart.create');
            Route::post('/add', [CartController::class, 'addToCart'])->name('cart.add');
            Route::post('/update', [CartController::class, 'updateToCart'])->name('cart.update');
            Route::post('/remove', [CartController::class, 'removeToCart'])->name('cart.remove');
            Route::post('/details', [CartController::class, 'getCartDetails'])->name('cart.details');
            Route::post('/buyer/identify', [CartController::class, 'cartBuyerIdentityUpdate'])->name('cart.buyer.identify');
        });

        Route::prefix('orders',)->group(function () {
            Route::post('/', [OrdertController::class, 'index'])->name('order.index');
            Route::post('/details', [OrdertController::class, 'getOrderDetails'])->name('order.details');
        });
    });
});
