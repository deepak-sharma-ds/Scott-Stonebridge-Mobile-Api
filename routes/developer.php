<?php

use App\Http\Controllers\AudioAccessController;
use App\Http\Controllers\HlsController;
use Illuminate\Support\Facades\Route;

Route::get('clear-all', function () {
	\Artisan::call('config:clear');
	\Artisan::call('route:clear');
	\Artisan::call('view:clear');
	\Artisan::call('cache:clear');
	\Artisan::call('optimize:clear');
	\Artisan::call('config:cache');
	\Artisan::call('route:cache');
	echo 'success';
});

Route::get('route-cache', function () {
	\Artisan::call('route:cache');
	echo 'success';
});

Route::get('route-clear', function () {
	\Artisan::call('route:clear');
	echo 'success';
});

Route::get('config-cache', function () {
	\Artisan::call('config:cache');
	echo 'success';
});

Route::get('config-clear', function () {
	\Artisan::call('config:clear');
	echo 'success';
});

Route::get('/phpinfo', function () {
	return phpinfo();
});


Route::get('/secure/hls/{audio}/{token}/playlist.m3u8', [HlsController::class, 'playlist'])->name('hls.playlist');
Route::get('/secure/hls/{audio}/{token}/segments/{segment}', [HlsController::class, 'segment'])->name('hls.segment');
Route::get('/secure/hls/{audio}/{token}/key', [HlsController::class, 'key'])->name('hls.key');
Route::get('/download/audio/{audioId}/{customerId}/{signature}', [HlsController::class, 'download'])->name('audio.download');


Route::get('/access/{shopifyCustomerId}/{packageTag}', [AudioAccessController::class, 'show'])->name('access.show');

Route::get('/test/test/test/test', [AudioAccessController::class, 'test'])->name('test.test');