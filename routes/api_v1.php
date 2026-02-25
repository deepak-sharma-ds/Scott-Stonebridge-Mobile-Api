<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\ContentController;
use App\Http\Controllers\Api\V1\HomeController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\WishlistController;
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
     * GET /api/v1/products/featured - Get featured products
     * POST /api/v1/products/featured - Get featured products (backward compatibility)
     */
    Route::prefix('products')->group(function () {
        Route::get('/', [ProductController::class, 'index'])->name('api.v1.products.index');
        Route::get('/search', [ProductController::class, 'search'])->name('api.v1.products.search');
        Route::get('/featured', [ProductController::class, 'indexFeatured'])->name('api.v1.products.featured');
        Route::post('/featured', [ProductController::class, 'indexFeatured'])->name('api.v1.products.featured.post');
        Route::get('/{handle}', [ProductController::class, 'show'])->name('api.v1.products.show');
    });
    
    /**
     * Collection Routes
     * 
     * GET /api/v1/collections - List all collections
     * POST /api/v1/collections - List all collections (backward compatibility)
     * GET /api/v1/collections/{handle}/products - Get products by collection
     * POST /api/v1/collections/products - Get products by collection (backward compatibility)
     */
    Route::prefix('collections')->group(function () {
        Route::get('/', [ProductController::class, 'indexCollections'])->name('api.v1.collections.index');
        Route::post('/', [ProductController::class, 'indexCollections'])->name('api.v1.collections.index.post');
        Route::get('/{handle}/products', [ProductController::class, 'showCollectionProducts'])->name('api.v1.collections.products');
        Route::post('/products', [ProductController::class, 'showCollectionProducts'])->name('api.v1.collections.products.post');
    });
    
    /**
     * Authentication Routes
     * 
     * POST /api/v1/auth/login - Customer login
     * POST /api/v1/auth/register - Customer registration
     * POST /api/v1/auth/forgot-password - Request password reset
     * POST /api/v1/auth/reset-password - Confirm password reset
     */
    Route::prefix('auth')->group(function () {
        Route::post('/login', [AuthController::class, 'login'])->name('api.v1.auth.login');
        Route::post('/register', [AuthController::class, 'register'])->name('api.v1.auth.register');
        Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->name('api.v1.auth.forgot-password');
        Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('api.v1.auth.reset-password');
        
        // Protected auth routes
        Route::middleware(['shopify.auth'])->group(function () {
            Route::get('/me', [AuthController::class, 'me'])->name('api.v1.auth.me');
            Route::post('/logout', [AuthController::class, 'logout'])->name('api.v1.auth.logout');
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
     * PUT /api/v1/cart/{cartId}/buyer - Update buyer identity (authenticated)
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
        
        // Protected cart routes
        Route::middleware(['shopify.auth'])->group(function () {
            Route::put('/{cartId}/buyer', [CartController::class, 'updateBuyerIdentity'])
                ->where('cartId', '.*')
                ->name('api.v1.cart.updateBuyer');
        });
    });
    
    /**
     * Content/CMS Routes (Public)
     * 
     * GET /api/v1/pages/{handle} - Get page by handle
     * POST /api/v1/pages/details - Get page details (backward compatibility)
     * GET /api/v1/policies/{type} - Get policy page by type
     * POST /api/v1/policies/details - Get policy details (backward compatibility)
     * GET /api/v1/blogs - List all blogs
     * POST /api/v1/blogs/details - List blogs (backward compatibility)
     * GET /api/v1/blogs/{blogHandle}/articles - List articles in a blog
     * GET /api/v1/blogs/{blogHandle}/articles/{articleHandle} - Get article details
     * POST /api/v1/blogs/article - Get article details (backward compatibility)
     * POST /api/v1/resolve - Resolve URL to resource type
     */
    Route::prefix('pages')->group(function () {
        Route::get('/{handle}', [ContentController::class, 'showPage'])->name('api.v1.pages.show');
        Route::post('/details', [ContentController::class, 'showPage'])->name('api.v1.pages.details');
    });
    
    Route::prefix('policies')->group(function () {
        Route::get('/{type}', [ContentController::class, 'showPolicy'])->name('api.v1.policies.show');
        Route::post('/details', [ContentController::class, 'showPolicy'])->name('api.v1.policies.details');
    });
    
    Route::prefix('blogs')->group(function () {
        Route::get('/', [ContentController::class, 'indexBlogs'])->name('api.v1.blogs.index');
        Route::post('/details', [ContentController::class, 'indexBlogs'])->name('api.v1.blogs.details');
        Route::get('/{blogHandle}/articles', [ContentController::class, 'indexArticles'])->name('api.v1.blogs.articles.index');
        Route::get('/{blogHandle}/articles/{articleHandle}', [ContentController::class, 'showArticle'])->name('api.v1.blogs.articles.show');
        Route::post('/article', [ContentController::class, 'showArticle'])->name('api.v1.blogs.article');
    });
    
    Route::post('/resolve', [ContentController::class, 'resolve'])->name('api.v1.resolve');
    
    /**
     * Contact Route (Public with stricter rate limiting)
     * 
     * POST /api/v1/contact - Submit contact form
     */
    Route::post('/contact', [ContactController::class, 'store'])->name('api.v1.contact.store');
    
    /**
     * Home Routes (Public - Guest Friendly)
     * 
     * GET /api/v1/home - Get home page data (no auth required)
     * POST /api/v1/home/subscribe - Subscribe to newsletter (optional auth)
     */
    Route::prefix('home')->group(function () {
        Route::get('/', [HomeController::class, 'index'])->name('api.v1.home.index');
        Route::post('/subscribe', [HomeController::class, 'subscribe'])->name('api.v1.home.subscribe');
    });
    
    // ============================================
    // Protected Routes (Authentication Required)
    // ============================================
    
    Route::middleware(['shopify.auth'])->group(function () {
        
        /**
         * Profile Routes
         * 
         * GET /api/v1/profile - Get customer profile and addresses
         * PUT /api/v1/profile - Update customer profile
         * POST /api/v1/profile/addresses - Add new address
         * PUT /api/v1/profile/addresses/{id} - Update address
         * DELETE /api/v1/profile/addresses/{id} - Delete address
         * 
         * Note: Address IDs are Shopify IDs and may contain special characters
         */
        Route::prefix('profile')->group(function () {
            Route::get('/', [ProfileController::class, 'index'])->name('api.v1.profile.index');
            Route::put('/', [ProfileController::class, 'update'])->name('api.v1.profile.update');
            Route::post('/addresses', [ProfileController::class, 'storeAddress'])->name('api.v1.profile.addresses.store');
            Route::put('/addresses/{id}', [ProfileController::class, 'updateAddress'])
                ->where('id', '.*')
                ->name('api.v1.profile.addresses.update');
            Route::delete('/addresses/{id}', [ProfileController::class, 'destroyAddress'])
                ->where('id', '.*')
                ->name('api.v1.profile.addresses.destroy');
        });
        
        /**
         * Wishlist Routes
         * 
         * GET /api/v1/wishlist - Get customer wishlist
         * POST /api/v1/wishlist/items - Add product to wishlist
         * DELETE /api/v1/wishlist/items/{productId} - Remove product from wishlist
         * 
         * Note: Product IDs are Shopify IDs and may contain special characters
         */
        Route::prefix('wishlist')->group(function () {
            Route::get('/', [WishlistController::class, 'index'])->name('api.v1.wishlist.index');
            Route::post('/items', [WishlistController::class, 'store'])->name('api.v1.wishlist.items.store');
            Route::delete('/items/{productId}', [WishlistController::class, 'destroy'])
                ->where('productId', '.*')
                ->name('api.v1.wishlist.items.destroy');
        });
        
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
