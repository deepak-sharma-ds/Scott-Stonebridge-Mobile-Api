<?php

/*
|--------------------------------------------------------------------------
| Predefined Product Bundles — admin routes
|--------------------------------------------------------------------------
| Loaded by App\Providers\BundleServiceProvider (web middleware group).
*/

use App\Http\Controllers\Admin\Bundles\BundleController;
use App\Http\Controllers\Admin\Bundles\BundleMapPriceController;
use App\Http\Controllers\Admin\Bundles\BundleSetupController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'admin', 'permission:view bundle'])
    ->prefix('admin/bundles')
    ->name('admin.bundles.')
    ->group(function () {

        // Static paths first so they are not captured by {bundle}.
        Route::get('/', [BundleController::class, 'index'])->name('index');
        Route::get('/map-prices', [BundleMapPriceController::class, 'index'])->name('map-prices.index');
        Route::get('/setup', [BundleSetupController::class, 'index'])->name('setup');
        Route::get('/logs', [BundleSetupController::class, 'logs'])->name('logs');

        Route::middleware('permission:manage bundle')->group(function () {
            Route::get('/create', [BundleController::class, 'create'])->name('create');
            Route::post('/', [BundleController::class, 'store'])->name('store');
            Route::get('/search-variants', [BundleController::class, 'searchVariants'])->name('search-variants');
            Route::get('/search-products', [BundleController::class, 'searchProducts'])->name('search-products');
            Route::post('/preview', [BundleController::class, 'preview'])->name('preview');

            Route::post('/map-prices', [BundleMapPriceController::class, 'store'])->name('map-prices.store');
            Route::post('/map-prices/import', [BundleMapPriceController::class, 'import'])->name('map-prices.import');
            Route::put('/map-prices/{mapPrice}', [BundleMapPriceController::class, 'update'])->name('map-prices.update');
            Route::delete('/map-prices/{mapPrice}', [BundleMapPriceController::class, 'destroy'])->name('map-prices.destroy');

            Route::post('/setup/cart-transform', [BundleSetupController::class, 'registerCartTransform'])->name('setup.cart-transform');

            Route::get('/{bundle}/edit', [BundleController::class, 'edit'])->name('edit');
            Route::put('/{bundle}', [BundleController::class, 'update'])->name('update');
            Route::delete('/{bundle}', [BundleController::class, 'destroy'])->name('destroy');
            Route::post('/{bundle}/activate', [BundleController::class, 'activate'])->name('activate');
            Route::post('/{bundle}/pause', [BundleController::class, 'pause'])->name('pause');
            Route::post('/{bundle}/resync', [BundleController::class, 'resync'])->name('resync');
            Route::post('/{bundle}/reviewed', [BundleController::class, 'markReviewed'])->name('reviewed');
        });

        Route::get('/{bundle}', [BundleController::class, 'show'])->name('show');
    });
