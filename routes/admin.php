<?php

use App\Http\Controllers\Admin\AudioController;
use App\Http\Controllers\Admin\AudioStreamController;
use App\Http\Controllers\Admin\ConfigurationsController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\BookingController;
use App\Http\Controllers\Admin\AvailableSlotController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\AvailabilitySlotController;
use App\Http\Controllers\Admin\PackageController;
use App\Http\Controllers\ProfileController;

Route::post('/configurations/make-slug', [ConfigurationsController::class, 'make_slug'])->name('configurations.make_slug');
Route::post('/configurations/upload-files', [ConfigurationsController::class, 'upload_files'])->name('configurations.upload_files');
Route::post('/configurations/remove-file', [ConfigurationsController::class, 'remove_file'])->name('configurations.remove_files');

Route::middleware(['auth'])->prefix('admin')->group(function () {


	/*Route for configurations*/
	Route::match(['get'], '/configurations', [ConfigurationsController::class, 'admin_prefix'])->name('admin.configurations');
	Route::match(['get'], '/configurations/index', [ConfigurationsController::class, 'admin_index'])->name('admin.configurations.admin_index');
	Route::match(['get', 'post'], '/configurations/add', [ConfigurationsController::class, 'admin_add'])->name('admin.configurations.admin_add');
	Route::match(['get', 'post'], '/configurations/edit/{id}', [ConfigurationsController::class, 'admin_edit'])->name('admin.configurations.admin_edit');
	Route::match(['get'], '/configurations/delete/{id}', [ConfigurationsController::class, 'admin_delete'])->name('admin.configurations.admin_delete');
	Route::match(['get'], '/configurations/view/{id?}', [ConfigurationsController::class, 'admin_view'])->name('admin.configurations.admin_view');
	Route::match(['get', 'post'], '/configurations/prefix/{prefix?}', [ConfigurationsController::class, 'admin_prefix'])->name('admin.configurations.admin_prefix');
	Route::match(['post'], '/configurations/save_config/{prefix}', [ConfigurationsController::class, 'save_config'])->name('admin.configurations.save_config');
	Route::match(['get'], '/configurations/change/{id}', [ConfigurationsController::class, 'admin_change'])->name('admin.configurations.admin_change');
	Route::match(['get'], '/configurations/moveup/{id}', [ConfigurationsController::class, 'admin_moveup'])->name('admin.configurations.admin_moveup');
	Route::match(['get'], '/configurations/movedown/{id}', [ConfigurationsController::class, 'admin_movedown'])->name('admin.configurations.admin_movedown');


	/* User Logs  */

	Route::resource('roles', RoleController::class);
	Route::resource('users', UserController::class);

	/* Store */
	Route::name('admin.')->group(function () {
		// Time Availability
		Route::resource('availability', AvailabilitySlotController::class);
		Route::post('availability/delete-date/{id}', [AvailabilitySlotController::class, 'deleteDate'])->name('availability.delete-date');
		Route::delete('/availability/time-slot/{id}', [AvailabilitySlotController::class, 'deleteTimeSlot']);
	});

	// Booking Inquiries
	Route::get('booking-inquiries', [BookingController::class, 'index'])->name('admin.scheduled-meetings');
	Route::get('booking/{id}/view', [BookingController::class, 'view'])->name('admin.booking.view');
	Route::put('booking/reschedule', [BookingController::class, 'reschedule'])->name('admin.booking.reschedule');
	Route::get('google-calendar/auth', [BookingController::class, 'adminGoogleAuth'])->name('admin.google.auth');
	Route::put('booking/cancel', [BookingController::class, 'cancel'])->name('admin.booking.cancel');
	Route::get('get-time-slots', [BookingController::class, 'getTimeSlots'])->name('admin.get.time-slots');

	Route::resource('packages', PackageController::class);
	Route::resource('audios', AudioController::class);

	Route::get('/stream/audio/{id}', [AudioStreamController::class, 'stream'])
		->name('audio.stream');
	// ->middleware('auth'); // or your custom auth for Shopify tag


});

Route::prefix('admin')->name('admin.')->group(function () {
	Route::get('/dashboard', [DashboardController::class, 'index'])->middleware(['auth', 'verified'])->name('dashboard');

	Route::middleware('auth')->group(function () {
		Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
		Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
		Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
	});
});
