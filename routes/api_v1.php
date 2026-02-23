<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\ProductController;
use Illuminate\Support\Facades\Route;

/**
 * API Version 1 Routes
 * 
 * This file contains all v1 API routes for the mobile application.
 * All routes are prefixed with /api/v1/
 * 
 * Middleware Stack:
 * - correlation.id: Adds correlation ID for request tracking
 * - currency: Handles currency context from request
 * - api.logging: Logs all API requests and responses
 * - rate.limit: Rate limiting per IP/user
 * - shopify.auth: Authentication for protected routes (applied per route group)
 */

Route::prefix('v1')->middleware([
    'correlation.id',
    'currency',
    'api.logging',
    'rate.limit',
])->group(function () {
    
    // ============================================
    // Public Routes (No Authentication Required)
    // ============================================
    
    /**
     * Product Routes
     * 
     * GET /api/v1/products - List all products with pagination
     * GET /api/v1/products/search - Search products
     * GET /api/v1/products/{handle} - Get product details by handle
     */
    Route::prefix('products')->group(function () {
        Route::get('/', [ProductController::class, 'index'])->name('api.v1.products.index');
        Route::get('/search', [ProductController::class, 'search'])->name('api.v1.products.search');
        Route::get('/{handle}', [ProductController::class, 'show'])->name('api.v1.products.show');
    });
    
    /**
     * Authentication Routes
     * 
     * POST /api/v1/auth/login - Customer login
     * POST /api/v1/auth/register - Customer registration
     */
    Route::prefix('auth')->group(function () {
        Route::post('/login', [AuthController::class, 'login'])->name('api.v1.auth.login');
        Route::post('/register', [AuthController::class, 'register'])->name('api.v1.auth.register');
        
        // Protected auth routes
        Route::middleware(['shopify.auth'])->group(function () {
            Route::get('/me', [AuthController::class, 'me'])->name('api.v1.auth.me');
        });
    });
    
    /**
     * Cart Routes (Guest and Authenticated)
     * 
     * POST /api/v1/cart - Create a new cart
     * GET /api/v1/cart/{cartId} - Get cart details
     * POST /api/v1/cart/{cartId}/items - Add item to cart
     * PUT /api/v1/cart/{cartId}/items/{lineId} - Update cart item
     * DELETE /api/v1/cart/{cartId}/items/{lineId} - Remove cart item
     * 
     * Note: Shopify IDs contain special characters (gid://shopify/Cart/...) 
     * The routes accept URL-encoded IDs automatically.
     * Example: gid://shopify/Cart/abc123 should be sent as gid%3A%2F%2Fshopify%2FCart%2Fabc123
     */
    Route::prefix('cart')->group(function () {
        Route::post('/', [CartController::class, 'store'])->name('api.v1.cart.store');
        Route::get('/{cartId}', [CartController::class, 'show'])
            ->where('cartId', '.*')
            ->name('api.v1.cart.show');
        Route::post('/{cartId}/items', [CartController::class, 'addItem'])
            ->where('cartId', '.*')
            ->name('api.v1.cart.addItem');
        Route::put('/{cartId}/items/{lineId}', [CartController::class, 'updateItem'])
            ->where(['cartId' => '.*', 'lineId' => '.*'])
            ->name('api.v1.cart.updateItem');
        Route::delete('/{cartId}/items/{lineId}', [CartController::class, 'removeItem'])
            ->where(['cartId' => '.*', 'lineId' => '.*'])
            ->name('api.v1.cart.removeItem');
    });
    
    // ============================================
    // Protected Routes (Authentication Required)
    // ============================================
    
    Route::middleware(['shopify.auth'])->group(function () {
        
        /**
         * Order Routes
         * 
         * GET /api/v1/orders - List customer orders
         * GET /api/v1/orders/{orderId} - Get order details
         * 
         * Note: Shopify IDs contain special characters and are URL-encoded automatically
         */
        Route::prefix('orders')->group(function () {
            Route::get('/', [OrderController::class, 'index'])->name('api.v1.orders.index');
            Route::get('/{orderId}', [OrderController::class, 'show'])
                ->where('orderId', '.*')
                ->name('api.v1.orders.show');
        });
    });
});
