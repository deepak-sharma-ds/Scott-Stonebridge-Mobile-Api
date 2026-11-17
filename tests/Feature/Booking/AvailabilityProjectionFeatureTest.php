<?php

namespace Tests\Feature\Booking;

use App\Jobs\Booking\SyncSlotsToGoogleSubCalendarJob;
use App\Models\AvailabilityDate;
use App\Models\AvailabilityTemplate;
use App\Models\Configuration;
use App\Models\GoogleToken;
use App\Models\ScheduledMeeting;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\BookingService;
use Carbon\Carbon;
use Google_Client;
use Google_Service_Calendar;
use Google_Service_Calendar_Resource_Events;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AvailabilityProjectionFeatureTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;

    private AvailabilityDate $date;

    private TimeSlot $openSlot;

    private TimeSlot $bookedSlot;

    private string $tomorrowStr;

    protected function refreshTestDatabase()
    {
        if (! RefreshDatabaseState::$migrated) {
            $this->artisan('migrate:fresh', $this->migrateFreshUsing());
            $this->createConfigurationsTable();
            $this->app[Kernel::class]->setArtisan(null);
            RefreshDatabaseState::$migrated = true;
        } else {
            $this->createConfigurationsTable();
        }

        $this->beginDatabaseTransaction();
    }

    private function createConfigurationsTable(): void
    {
        if (! Schema::hasTable('configurations')) {
            Schema::create('configurations', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->text('value')->nullable();
                $table->string('title')->nullable();
                $table->text('description')->nullable();
                $table->string('input_type')->nullable();
                $table->boolean('editable')->default(1);
                $table->integer('weight')->nullable();
                $table->text('params')->nullable();
                $table->integer('order')->nullable();
            });
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.timezone' => 'Europe/London',
            'booking.timezone' => 'Europe/London',
        ]);

        $role = Role::findOrCreate('Admin', 'web');
        $this->adminUser = User::factory()->create();
        $this->adminUser->assignRole($role);

        $this->tomorrowStr = Carbon::tomorrow('Europe/London')->format('Y-m-d');

        $this->date = AvailabilityDate::create([
            'user_id' => $this->adminUser->id,
            'date' => $this->tomorrowStr,
        ]);

        $this->openSlot = TimeSlot::create([
            'availability_date_id' => $this->date->id,
            'start_time' => '10:00',
            'end_time' => '10:30',
        ]);

        $this->bookedSlot = TimeSlot::create([
            'availability_date_id' => $this->date->id,
            'start_time' => '11:00',
            'end_time' => '11:30',
        ]);

        ScheduledMeeting::create([
            'user_id' => $this->adminUser->id,
            'name' => 'Existing Customer',
            'email' => 'customer@example.com',
            'phone' => '+447123456789',
            'datetime' => Carbon::parse("{$this->tomorrowStr} 11:00:00", 'Europe/London'),
            'meeting_link' => 'https://meet.google.com/meet-test',
            'event_id' => 'evt_google_123',
            'status' => 'confirmed',
            'availability_date_id' => $this->date->id,
            'time_slot_id' => $this->bookedSlot->id,
        ]);

        GoogleToken::create([
            'id' => 1,
            'access_token' => Crypt::encryptString('fake_access_token'),
            'refresh_token' => Crypt::encryptString('fake_refresh_token'),
            'expires_at' => Carbon::now()->addHour(),
            'token_type' => 'Bearer',
            'scope' => 'https://www.googleapis.com/auth/calendar',
            'created_at_timestamp' => time(),
        ]);

        Configuration::updateOrCreate(
            ['name' => 'GoogleCalendar.slots_calendar_id'],
            ['value' => 'sub_calendar_123']
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_admin_bulk_slot_generation_dispatches_projection_job(): void
    {
        Queue::fake();

        // Create weekly template so generator has slots to create
        AvailabilityTemplate::create([
            'user_id' => $this->adminUser->id,
            'day_of_week' => 1, // Monday
            'start_time' => '09:00',
            'end_time' => '17:00',
            'is_active' => true,
        ]);

        $startStr = Carbon::now('Europe/London')->addDays(3)->format('Y-m-d');
        $endStr = Carbon::now('Europe/London')->addDays(7)->format('Y-m-d');

        $response = $this->actingAs($this->adminUser)
            ->post(route('admin.availability_templates.generate'), [
                'mode' => 'custom_range',
                'custom_start' => $startStr,
                'custom_end' => $endStr,
            ]);

        $response->assertRedirect(route('admin.availability_templates.index'));

        Queue::assertPushed(SyncSlotsToGoogleSubCalendarJob::class, function ($job) use ($startStr, $endStr) {
            return $job->startDate === $startStr && $job->endDate === $endStr;
        });
    }

    public function test_admin_store_day_dispatches_projection_job(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->adminUser)
            ->postJson(route('admin.availability.calendar.day.store', ['date' => $this->tomorrowStr]), [
                'time_slots' => [
                    ['start_time' => '10:00', 'end_time' => '10:30'],
                    ['start_time' => '11:00', 'end_time' => '11:30'], // booked slot kept
                    ['start_time' => '14:00', 'end_time' => '14:30'], // new slot added
                ],
            ]);

        $response->assertOk();

        Queue::assertPushed(SyncSlotsToGoogleSubCalendarJob::class, function ($job) {
            return $job->startDate === $this->tomorrowStr && $job->endDate === $this->tomorrowStr;
        });
    }

    public function test_admin_delete_slot_dispatches_cleanup_job(): void
    {
        Queue::fake();

        $slotId = $this->openSlot->id;

        $response = $this->actingAs($this->adminUser)
            ->deleteJson(route('admin.availability.calendar.slot.delete', ['id' => $slotId]));

        $response->assertOk();
        $this->assertDatabaseMissing('time_slots', ['id' => $slotId]);

        Queue::assertPushed(SyncSlotsToGoogleSubCalendarJob::class, function ($job) use ($slotId) {
            return in_array($slotId, $job->slotIdsToDelete, true);
        });
    }

    public function test_admin_delete_day_dispatches_cleanup_job_for_unbooked_slots(): void
    {
        Queue::fake();

        $openSlotId = $this->openSlot->id;

        $response = $this->actingAs($this->adminUser)
            ->deleteJson(route('admin.availability.calendar.day.delete', ['date' => $this->tomorrowStr]));

        $response->assertOk();

        // Open slot was deleted, booked slot was preserved
        $this->assertDatabaseMissing('time_slots', ['id' => $openSlotId]);
        $this->assertDatabaseHas('time_slots', ['id' => $this->bookedSlot->id]);

        Queue::assertPushed(SyncSlotsToGoogleSubCalendarJob::class, function ($job) use ($openSlotId) {
            return in_array($openSlotId, $job->slotIdsToDelete, true);
        });
    }

    public function test_admin_booking_cancel_soft_cancels_row_and_calls_google_delete_and_dispatches_restore(): void
    {
        Queue::fake();

        $meeting = ScheduledMeeting::where('time_slot_id', $this->bookedSlot->id)->first();
        $meetingId = $meeting->id;
        $slotId = $meeting->time_slot_id;

        $mockBookingService = Mockery::mock(BookingService::class)->makePartial();
        $mockBookingService->shouldReceive('deleteGoogleCalendarEvent')
            ->once()
            ->with('evt_google_123', 'primary')
            ->andReturn(true);

        $this->app->instance(BookingService::class, $mockBookingService);

        $response = $this->actingAs($this->adminUser)
            ->put(route('admin.booking.cancel'), [
                'booking_id' => $meetingId,
            ]);

        $response->assertRedirect(route('admin.scheduled-meetings'));

        // Database row was preserved, status soft-cancelled
        $this->assertDatabaseHas('scheduled_meetings', [
            'id' => $meetingId,
            'status' => 'cancelled',
        ]);

        Queue::assertPushed(SyncSlotsToGoogleSubCalendarJob::class, function ($job) use ($slotId) {
            return in_array($slotId, $job->slotIdsToProject, true);
        });
    }

    public function test_sync_slots_job_projects_unbooked_slots_and_prunes_deleted_slots(): void
    {
        $mockClient = Mockery::mock(Google_Client::class);
        $mockCalendarService = Mockery::mock(Google_Service_Calendar::class);
        $mockEvents = Mockery::mock(Google_Service_Calendar_Resource_Events::class);
        $mockCalendarService->events = $mockEvents;

        $mockBookingService = Mockery::mock(BookingService::class)->makePartial();
        $mockBookingService->setGoogleClient($mockClient);
        $mockBookingService->setCalendarService($mockCalendarService);

        $mockBookingService->shouldReceive('getSlotsCalendarId')
            ->andReturn('sub_calendar_123');

        // Expect projectSlotPlaceholder called ONLY for openSlot (bookedSlot ignored)
        $mockBookingService->shouldReceive('projectSlotPlaceholder')
            ->once()
            ->with(Mockery::on(fn ($slot) => $slot->id === $this->openSlot->id), Mockery::any(), 'sub_calendar_123')
            ->andReturn('new_placeholder_evt');

        // Expect removeSlotPlaceholder called for pruned ID 999
        $mockBookingService->shouldReceive('removeSlotPlaceholder')
            ->once()
            ->with(999, 'sub_calendar_123')
            ->andReturn(true);

        $job = new SyncSlotsToGoogleSubCalendarJob(
            startDate: $this->tomorrowStr,
            endDate: $this->tomorrowStr,
            slotIdsToDelete: [999]
        );

        $job->handle($mockBookingService);
        $this->assertTrue(true);
    }
}
