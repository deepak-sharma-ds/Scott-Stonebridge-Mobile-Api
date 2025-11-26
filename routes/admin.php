<?php

use App\Http\Controllers\Admin\AudioController;
use App\Http\Controllers\Admin\AudioStreamController;
use App\Http\Controllers\Admin\AvailabilityCalendarController;
use App\Http\Controllers\Admin\AvailabilityGenerationController;
use App\Http\Controllers\Admin\ConfigurationsController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\BookingController;
use App\Http\Controllers\Admin\AvailableSlotController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\AvailabilitySlotController;
use App\Http\Controllers\Admin\AvailabilityTemplateController;
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
		// Route::resource('availability', AvailabilitySlotController::class);
		// Route::post('availability/delete-date/{id}', [AvailabilitySlotController::class, 'deleteDate'])->name('availability.delete-date');
		// Route::delete('/availability/time-slot/{id}', [AvailabilitySlotController::class, 'deleteTimeSlot']);


		// Availability Templates (New Flow)
		Route::get('availability-templates', [AvailabilityTemplateController::class, 'index'])->name('availability_templates.index');
		Route::get('availability-templates/create', [AvailabilityTemplateController::class, 'create'])->name('availability_templates.create');
		Route::post('availability-templates', [AvailabilityTemplateController::class, 'store'])->name('availability_templates.store');
		Route::delete('availability-templates/{id}', [AvailabilityTemplateController::class, 'destroy'])->name('availability_templates.destroy');

		// Generate Availability
		Route::get('availability/generate', [AvailabilityGenerationController::class, 'showForm'])->name('availability_templates.generate.form');
		Route::post('availability/generate', [AvailabilityGenerationController::class, 'generate'])->name('availability_templates.generate');


		// Calendar UI
		Route::get('availability/calendar', [AvailabilityCalendarController::class, 'index'])->name('availability.calendar');
		Route::get('availability/calendar/events', [AvailabilityCalendarController::class, 'events'])->name('availability.calendar.events');

		// CRUD for date
		Route::get('availability/calendar/day/{date}', [AvailabilityCalendarController::class, 'day'])->where('date', '\d{4}-\d{2}-\d{2}')->name('availability.calendar.day'); // date: YYYY-mm-dd
		Route::post('availability/calendar/day/{date}', [AvailabilityCalendarController::class, 'storeDay'])->where('date', '\d{4}-\d{2}-\d{2}')->name('availability.calendar.day.store');
		Route::delete('availability/calendar/day/{date}', [AvailabilityCalendarController::class, 'deleteDay'])->where('date', '\d{4}-\d{2}-\d{2}')->name('availability.calendar.day.delete');

		// Delete slot
		Route::delete('availability/calendar/slot/{id}', [AvailabilityCalendarController::class, 'deleteSlot'])->where('id', '[0-9]+')->name('availability.calendar.slot.delete');
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

	// Route::get('/stream/audio/{id}', [AudioStreamController::class, 'stream'])
	// 	->name('audio.stream');
	// ->middleware('auth'); // or your custom auth for Shopify tag
	Route::get('/media/hls/{audio}/{file?}', [AudioStreamController::class, 'stream'])
		->where('file', '.*')
		->name('audio.stream');
});

Route::prefix('admin')->name('admin.')->group(function () {
	Route::get('/dashboard', [DashboardController::class, 'index'])->middleware(['auth', 'verified'])->name('dashboard');

	Route::middleware('auth')->group(function () {
		Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
		Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
		Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
	});
});
