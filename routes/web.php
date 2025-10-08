<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BookingController;
use App\Http\Middleware\CustomCors;
use App\Http\Controllers\Admin\DashboardController; 
use Milon\Barcode\Facades\DNS1D;
use App\Http\Controllers\ShopifyController;


require __DIR__.'/auth.php';
require __DIR__.'/admin.php';


Route::get('/', function () {
	return redirect()->route('admin.dashboard');
});


Route::get('clear-all', function() {
	\Artisan::call('config:clear');
	\Artisan::call('route:clear');
	\Artisan::call('view:clear');
	\Artisan::call('cache:clear');
	\Artisan::call('optimize:clear');
	\Artisan::call('config:cache');
    \Artisan::call('route:cache');
    echo 'success';
});

Route::get('route-cache', function() {
	\Artisan::call('route:cache');
	echo 'success';
});

Route::get('route-clear', function() {
	\Artisan::call('route:clear');
	echo 'success';
});

Route::get('config-cache', function() {
	\Artisan::call('config:cache');
	echo 'success';
});

Route::get('config-clear', function() {
	\Artisan::call('config:clear');
	echo 'success';
});

Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->middleware(['auth', 'verified'])->name('dashboard');
	
    Route::middleware('auth')->group(function () {
        Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    });
});


Route::get('/phpinfo',function(){
	return phpinfo();
});