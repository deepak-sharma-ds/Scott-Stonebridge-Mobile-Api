<?php

use App\Http\Controllers\AudioAccessController;
use App\Http\Controllers\HlsController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

Route::get('/media/{path}', function (string $path) {

	// 1 Normalize & block traversal
	$path = ltrim($path, '/');

	if (Str::contains($path, ['..', './', '\\'])) {
		abort(403);
	}

	// 2 Force public disk namespace
	$diskPath = 'public/' . $path;

	abort_unless(Storage::exists($diskPath), 404);

	// 3 Allow ONLY safe image types
	$allowedMime = [
		'image/png',
		'image/jpeg',
		'image/webp',
		'image/avif',
		'image/svg+xml',
	];

	$mime = Storage::mimeType($diskPath);

	abort_unless(in_array($mime, $allowedMime), 403);

	// 4 Stream file with security headers
	return response()->file(
		storage_path('app/' . $diskPath),
		[
			'Content-Type'        => $mime,
			'Cache-Control'       => 'public, max-age=31536000, immutable',
			'X-Content-Type-Options' => 'nosniff',
			'Content-Disposition' => 'inline',
		]
	);
})->where('path', '.*');

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
