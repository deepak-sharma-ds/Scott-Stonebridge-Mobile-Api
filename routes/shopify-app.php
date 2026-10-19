<?php

/*
|--------------------------------------------------------------------------
| Embedded Shopify app (Polaris UI inside the Shopify admin)
|--------------------------------------------------------------------------
| Loaded by App\Providers\BundleServiceProvider.
|  - /shopify-app/*        the React + Polaris app shell (framed by the Shopify admin)
|  - /api/shopify-app/*    JSON API, authenticated with the App Bridge session token
*/

use App\Http\Controllers\ShopifyApp\AnalyticsApiController;
use App\Http\Controllers\ShopifyApp\AppApiController;
use App\Http\Controllers\ShopifyApp\FunnelApiController;
use App\Http\Controllers\ShopifyApp\ProxyController;
use App\Http\Middleware\VerifyAppProxySignature;
use App\Http\Controllers\ShopifyApp\BundleApiController;
use App\Http\Controllers\ShopifyApp\MapPriceApiController;
use App\Http\Controllers\ShopifyApp\ShellController;
use App\Http\Middleware\ShopifyEmbeddedHeaders;
use App\Http\Middleware\VerifyShopifySessionToken;
use Illuminate\Support\Facades\Route;

Route::middleware(ShopifyEmbeddedHeaders::class)
    ->get('/shopify-app/{path?}', ShellController::class)
    ->where('path', '.*')
    ->name('shopify-app');

Route::middleware(['api', VerifyShopifySessionToken::class, 'throttle:240,1'])
    ->prefix('api/shopify-app')
    ->name('shopify-app.api.')
    ->group(function () {
        Route::get('/bootstrap', [AppApiController::class, 'bootstrap'])->name('bootstrap');
        Route::get('/setup', [AppApiController::class, 'setup'])->name('setup');
        Route::post('/setup/cart-transform', [AppApiController::class, 'registerCartTransform'])->name('setup.cart-transform');
        Route::get('/logs', [AppApiController::class, 'logs'])->name('logs');

        Route::get('/bundles', [BundleApiController::class, 'index'])->name('bundles.index');
        Route::post('/bundles', [BundleApiController::class, 'store'])->name('bundles.store');
        Route::post('/bundles/preview', [BundleApiController::class, 'preview'])->name('bundles.preview');
        Route::post('/variants/lookup', [BundleApiController::class, 'lookupVariants'])->name('variants.lookup');
        Route::post('/products/lookup', [BundleApiController::class, 'lookupProducts'])->name('products.lookup');
        Route::get('/bundles/{bundle}', [BundleApiController::class, 'show'])->name('bundles.show');
        Route::put('/bundles/{bundle}', [BundleApiController::class, 'update'])->name('bundles.update');
        Route::delete('/bundles/{bundle}', [BundleApiController::class, 'destroy'])->name('bundles.destroy');
        Route::post('/bundles/{bundle}/activate', [BundleApiController::class, 'activate'])->name('bundles.activate');
        Route::post('/bundles/{bundle}/pause', [BundleApiController::class, 'pause'])->name('bundles.pause');
        Route::post('/bundles/{bundle}/resync', [BundleApiController::class, 'resync'])->name('bundles.resync');
        Route::post('/bundles/{bundle}/reviewed', [BundleApiController::class, 'markReviewed'])->name('bundles.reviewed');

        // Bundle funnels
        Route::get('/funnels', [FunnelApiController::class, 'index'])->name('funnels.index');
        Route::get('/funnels/defaults', [FunnelApiController::class, 'defaults'])->name('funnels.defaults');
        Route::post('/funnels', [FunnelApiController::class, 'store'])->name('funnels.store');
        Route::post('/funnels/preview', [FunnelApiController::class, 'preview'])->name('funnels.preview');
        Route::get('/funnels/{funnel}', [FunnelApiController::class, 'show'])->name('funnels.show');
        Route::put('/funnels/{funnel}', [FunnelApiController::class, 'update'])->name('funnels.update');
        Route::delete('/funnels/{funnel}', [FunnelApiController::class, 'destroy'])->name('funnels.destroy');
        Route::post('/funnels/{funnel}/status', [FunnelApiController::class, 'status'])->name('funnels.status');
        Route::post('/funnels/{funnel}/resync', [FunnelApiController::class, 'resync'])->name('funnels.resync');
        Route::post('/funnels/{funnel}/rename', [FunnelApiController::class, 'rename'])->name('funnels.rename');
        Route::post('/funnels/{funnel}/duplicate', [FunnelApiController::class, 'duplicate'])->name('funnels.duplicate');

        // Analytics
        Route::get('/analytics', [AnalyticsApiController::class, 'overview'])->name('analytics.overview');
        Route::get('/analytics/funnels', [AnalyticsApiController::class, 'funnels'])->name('analytics.funnels');
        Route::get('/analytics/orders', [AnalyticsApiController::class, 'orders'])->name('analytics.orders');

        Route::get('/map-prices', [MapPriceApiController::class, 'index'])->name('map-prices.index');
        Route::post('/map-prices', [MapPriceApiController::class, 'store'])->name('map-prices.store');
        Route::post('/map-prices/import', [MapPriceApiController::class, 'import'])->name('map-prices.import');
        Route::put('/map-prices/{mapPrice}', [MapPriceApiController::class, 'update'])->name('map-prices.update');
        Route::delete('/map-prices/{mapPrice}', [MapPriceApiController::class, 'destroy'])->name('map-prices.destroy');
    });

// Sample MAP prices CSV for the import (static example rows, no store data)
Route::get('/api/bundle-app/samples/map-prices.csv', [MapPriceApiController::class, 'sample'])
    ->middleware('throttle:60,1')
    ->name('shopify-app.samples.map-prices');

// Storefront events through the Shopify app proxy: /apps/zymox-bundles/events -> here
Route::middleware(['api', VerifyAppProxySignature::class, 'throttle:600,1'])
    ->prefix('api/bundle-app/proxy')
    ->name('shopify-app.proxy.')
    ->group(function () {
        Route::post('/events', [ProxyController::class, 'events'])->name('events');
    });
