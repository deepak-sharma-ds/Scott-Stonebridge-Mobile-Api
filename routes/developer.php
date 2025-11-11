<?php

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
