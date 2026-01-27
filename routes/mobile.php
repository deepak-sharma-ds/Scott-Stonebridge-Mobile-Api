<?php

use App\Http\Controllers\Apis\AboutPageController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Apis\ProductController;
use App\Http\Controllers\Apis\HomeController;
use App\Http\Controllers\Apis\AuthController;
use App\Http\Controllers\Apis\CartController;
use App\Http\Controllers\Apis\OrdertController;
use App\Http\Controllers\Apis\ProfileController;
use App\Http\Controllers\Apis\WishlistController;
use App\Http\Controllers\Apis\ContactUsController;
use App\Http\Controllers\Apis\PageController;
use App\Http\Controllers\Apis\BlogController;



/**
 * Shopify APIs for Mobile App Development
 */
Route::middleware(['disable.session'])->group(function () {
    // Products APIS
    Route::get('/products', [ProductController::class, 'getAllProducts']);
    Route::get('/products/search', [ProductController::class, 'searchProducts']);
    Route::get('/products/{productId}', [ProductController::class, 'getProductDetail']);

    // Pages (PUBLIC)
    Route::prefix('page')->group(function () {
        Route::post('/details', [AboutPageController::class, 'getPageDetails']);
    });

    Route::prefix('blog')->group(function () {
        Route::post('details', [BlogController::class, 'getBlogDetails']);      // list w/ pagination
        Route::post('article', [BlogController::class, 'getArticleDetails']);  // single article
        Route::post('resolve', [BlogController::class, 'resolveUrl']);         // dynamic internal redirect handler
    });


    Route::prefix('auth')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);
        Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('reset-password', [AuthController::class, 'resetPassword']);
        Route::post('logout', [AuthController::class, 'logout']);
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
            // Shows all orders
            Route::post('/', [OrdertController::class, 'index'])->name('order.index');
            // Shows all details of selected order
            Route::post('/details', [OrdertController::class, 'getOrderDetails'])->name('order.details');
        });

        Route::prefix('profile',)->group(function () {
            // Get customer profile + addresses
            Route::get('/', [ProfileController::class, 'index'])->name('profile.index');

            // Update profile details
            Route::post('/update', [ProfileController::class, 'updateProfile'])->name('profile.update');

            // Add an address
            Route::post('/address/add', [ProfileController::class, 'addAddress'])->name('profile.address.add');

            // Update address
            Route::post('/address/update', [ProfileController::class, 'updateAddress'])->name('profile.address.update');

            // Delete address
            Route::post('/address/delete', [ProfileController::class, 'deleteAddress'])->name('profile.address.delete');
        });

        Route::prefix('wishlist')->group(function () {

            // Get Wishlist (Storefront)
            Route::post('/', [WishlistController::class, 'index'])->name('wishlist.index');

            // Add to wishlist (Admin)
            Route::post('/add', [WishlistController::class, 'add'])->name('wishlist.add');

            // Remove from wishlist (Admin)
            Route::post('/remove', [WishlistController::class, 'remove'])->name('wishlist.remove');
        });
    });
});

Route::post('/contact-us', [ContactUsController::class, 'store']);

// Route::post('/page/details', [PageController::class, 'getPageDetails']);
