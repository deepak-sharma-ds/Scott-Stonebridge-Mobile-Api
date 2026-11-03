<?php

namespace Tests\Feature\Booking;

use App\Mail\CalendarWatchRenewalFailedMail;
use App\Models\Configuration;
use App\Models\GoogleToken;
use App\Models\User;
use App\Services\BookingService;
use Carbon\Carbon;
use Google_Client;
use Google_Service_Calendar;
use Google_Service_Calendar_Channel;
use Google_Service_Calendar_Events;
use Google_Service_Calendar_Resource_Channels;
use Google_Service_Calendar_Resource_Events;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CalendarWatchAndSyncFeatureTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;

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
            'env.ADMIN_EMAIL' => 'scott@example.com',
        ]);

        $role = Role::findOrCreate('Admin', 'web');
        $this->adminUser = User::factory()->create();
        $this->adminUser->assignRole($role);

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

    public function test_calendar_renew_watch_renews_expiring_channels_and_persists_configurations(): void
    {
        // 12 hours remaining -> within 48h window, must renew
        $expiringTimestamp = Carbon::now()->addHours(12)->getTimestampMs();
        Configuration::updateOrCreate(
            ['name' => 'GoogleCalendar.primary_channel_expiration'],
            ['value' => (string) $expiringTimestamp]
        );
        Configuration::updateOrCreate(
            ['name' => 'GoogleCalendar.slots_channel_expiration'],
            ['value' => (string) $expiringTimestamp]
        );

        $mockClient = Mockery::mock(Google_Client::class);
        $mockCalendarService = Mockery::mock(Google_Service_Calendar::class);
        $mockEvents = Mockery::mock(Google_Service_Calendar_Resource_Events::class);
        $mockChannels = Mockery::mock(Google_Service_Calendar_Resource_Channels::class);
        $mockCalendarService->events = $mockEvents;
        $mockCalendarService->channels = $mockChannels;

        $newExpiration = Carbon::now()->addDays(7)->getTimestampMs();

        $mockWatchResponsePrimary = Mockery::mock(Google_Service_Calendar_Channel::class);
        $mockWatchResponsePrimary->shouldReceive('getId')->andReturn('new_primary_channel_id');
        $mockWatchResponsePrimary->shouldReceive('getResourceId')->andReturn('new_primary_res_id');
        $mockWatchResponsePrimary->shouldReceive('getExpiration')->andReturn((string) $newExpiration);

        $mockWatchResponseSlots = Mockery::mock(Google_Service_Calendar_Channel::class);
        $mockWatchResponseSlots->shouldReceive('getId')->andReturn('new_slots_channel_id');
        $mockWatchResponseSlots->shouldReceive('getResourceId')->andReturn('new_slots_res_id');
        $mockWatchResponseSlots->shouldReceive('getExpiration')->andReturn((string) $newExpiration);

        $mockEvents->shouldReceive('watch')
            ->once()
            ->with('primary', Mockery::type(Google_Service_Calendar_Channel::class))
            ->andReturn($mockWatchResponsePrimary);

        $mockEvents->shouldReceive('watch')
            ->once()
            ->with('sub_calendar_123', Mockery::type(Google_Service_Calendar_Channel::class))
            ->andReturn($mockWatchResponseSlots);

        $mockBookingService = Mockery::mock(BookingService::class)->makePartial();
        $mockBookingService->setGoogleClient($mockClient);
        $mockBookingService->setCalendarService($mockCalendarService);
        $mockBookingService->shouldReceive('getSlotsCalendarId')->andReturn('sub_calendar_123');

        $this->app->instance(BookingService::class, $mockBookingService);

        $this->artisan('calendar:renew-watch')
            ->assertSuccessful();

        $this->assertEquals(
            'new_primary_channel_id',
            Configuration::where('name', 'GoogleCalendar.primary_channel_id')->value('value')
        );
        $this->assertEquals(
            'new_primary_res_id',
            Configuration::where('name', 'GoogleCalendar.primary_resource_id')->value('value')
        );
        $this->assertEquals(
            (string) $newExpiration,
            Configuration::where('name', 'GoogleCalendar.primary_channel_expiration')->value('value')
        );

        $this->assertEquals(
            'new_slots_channel_id',
            Configuration::where('name', 'GoogleCalendar.slots_channel_id')->value('value')
        );
        $this->assertEquals(
            'new_slots_res_id',
            Configuration::where('name', 'GoogleCalendar.slots_resource_id')->value('value')
        );
        $this->assertEquals(
            (string) $newExpiration,
            Configuration::where('name', 'GoogleCalendar.slots_channel_expiration')->value('value')
        );
    }

    public function test_calendar_renew_watch_skips_channels_with_ample_time_remaining(): void
    {
        // 5 days remaining -> outside 48h window, skip renewal
        $futureTimestamp = Carbon::now()->addDays(5)->getTimestampMs();
        Configuration::updateOrCreate(
            ['name' => 'GoogleCalendar.primary_channel_expiration'],
            ['value' => (string) $futureTimestamp]
        );
        Configuration::updateOrCreate(
            ['name' => 'GoogleCalendar.slots_channel_expiration'],
            ['value' => (string) $futureTimestamp]
        );

        $mockClient = Mockery::mock(Google_Client::class);
        $mockCalendarService = Mockery::mock(Google_Service_Calendar::class);
        $mockEvents = Mockery::mock(Google_Service_Calendar_Resource_Events::class);
        $mockCalendarService->events = $mockEvents;

        // watch should NOT be called on either calendar
        $mockEvents->shouldNotReceive('watch');

        $mockBookingService = Mockery::mock(BookingService::class)->makePartial();
        $mockBookingService->setGoogleClient($mockClient);
        $mockBookingService->setCalendarService($mockCalendarService);
        $mockBookingService->shouldReceive('getSlotsCalendarId')->andReturn('sub_calendar_123');

        $this->app->instance(BookingService::class, $mockBookingService);

        $this->artisan('calendar:renew-watch')
            ->assertSuccessful();
    }

    public function test_calendar_renew_watch_force_flag_renews_even_if_not_expiring(): void
    {
        $futureTimestamp = Carbon::now()->addDays(5)->getTimestampMs();
        Configuration::updateOrCreate(
            ['name' => 'GoogleCalendar.primary_channel_expiration'],
            ['value' => (string) $futureTimestamp]
        );
        Configuration::updateOrCreate(
            ['name' => 'GoogleCalendar.slots_channel_expiration'],
            ['value' => (string) $futureTimestamp]
        );

        $mockClient = Mockery::mock(Google_Client::class);
        $mockCalendarService = Mockery::mock(Google_Service_Calendar::class);
        $mockEvents = Mockery::mock(Google_Service_Calendar_Resource_Events::class);
        $mockChannels = Mockery::mock(Google_Service_Calendar_Resource_Channels::class);
        $mockCalendarService->events = $mockEvents;
        $mockCalendarService->channels = $mockChannels;

        $newExpiration = Carbon::now()->addDays(7)->getTimestampMs();

        $mockWatchResponse = Mockery::mock(Google_Service_Calendar_Channel::class);
        $mockWatchResponse->shouldReceive('getId')->andReturn('forced_channel_id');
        $mockWatchResponse->shouldReceive('getResourceId')->andReturn('forced_res_id');
        $mockWatchResponse->shouldReceive('getExpiration')->andReturn((string) $newExpiration);

        $mockEvents->shouldReceive('watch')
            ->twice()
            ->andReturn($mockWatchResponse);

        $mockBookingService = Mockery::mock(BookingService::class)->makePartial();
        $mockBookingService->setGoogleClient($mockClient);
        $mockBookingService->setCalendarService($mockCalendarService);
        $mockBookingService->shouldReceive('getSlotsCalendarId')->andReturn('sub_calendar_123');

        $this->app->instance(BookingService::class, $mockBookingService);

        $this->artisan('calendar:renew-watch', ['--force' => true])
            ->assertSuccessful();
    }

    public function test_calendar_renew_watch_sends_alert_email_when_renewal_fails(): void
    {
        Mail::fake();

        // Expired timestamp
        Configuration::updateOrCreate(
            ['name' => 'GoogleCalendar.primary_channel_expiration'],
            ['value' => (string) Carbon::now()->subHour()->getTimestampMs()]
        );

        $mockClient = Mockery::mock(Google_Client::class);
        $mockCalendarService = Mockery::mock(Google_Service_Calendar::class);
        $mockEvents = Mockery::mock(Google_Service_Calendar_Resource_Events::class);
        $mockCalendarService->events = $mockEvents;

        $mockEvents->shouldReceive('watch')
            ->andThrow(new \Exception('OAuth token revoked by Google'));

        $mockBookingService = Mockery::mock(BookingService::class)->makePartial();
        $mockBookingService->setGoogleClient($mockClient);
        $mockBookingService->setCalendarService($mockCalendarService);
        $mockBookingService->shouldReceive('getSlotsCalendarId')->andReturn('sub_calendar_123');

        $this->app->instance(BookingService::class, $mockBookingService);

        $this->artisan('calendar:renew-watch')
            ->assertFailed();

        Mail::assertSent(CalendarWatchRenewalFailedMail::class, function ($mail) {
            return $mail->hasTo('scott@example.com') && str_contains($mail->errorMessage, 'OAuth token revoked');
        });
    }

    public function test_calendar_sync_delta_runs_and_updates_sync_token(): void
    {
        $mockClient = Mockery::mock(Google_Client::class);
        $mockCalendarService = Mockery::mock(Google_Service_Calendar::class);
        $mockEvents = Mockery::mock(Google_Service_Calendar_Resource_Events::class);
        $mockCalendarService->events = $mockEvents;

        $mockEventsList = Mockery::mock(Google_Service_Calendar_Events::class);
        $mockEventsList->shouldReceive('getItems')->andReturn([]);
        $mockEventsList->shouldReceive('getNextSyncToken')->andReturn('new_delta_sync_token_456');

        $mockEvents->shouldReceive('listEvents')
            ->andReturn($mockEventsList);

        $mockBookingService = Mockery::mock(BookingService::class)->makePartial();
        $mockBookingService->setGoogleClient($mockClient);
        $mockBookingService->setCalendarService($mockCalendarService);
        $mockBookingService->shouldReceive('getSlotsCalendarId')->andReturn('sub_calendar_123');

        $this->app->instance(BookingService::class, $mockBookingService);

        $this->artisan('calendar:sync-delta', ['--now' => true])
            ->assertSuccessful();

        $this->assertEquals(
            'new_delta_sync_token_456',
            Configuration::where('name', 'GoogleCalendar.primary_sync_token')->value('value')
        );
    }

    public function test_console_schedule_registers_calendar_renew_watch_and_sync_delta(): void
    {
        $schedule = $this->app->make(Schedule::class);
        $events = collect($schedule->events());

        $renewEvent = $events->first(function ($event) {
            return str_contains($event->command ?? '', 'calendar:renew-watch');
        });

        $syncEvent = $events->first(function ($event) {
            return str_contains($event->command ?? '', 'calendar:sync-delta');
        });

        $this->assertNotNull($renewEvent, 'calendar:renew-watch should be registered in schedule');
        $this->assertNotNull($syncEvent, 'calendar:sync-delta should be registered in schedule');

        // Check cron expressions: daily = '0 0 * * *', everyThirtyMinutes = '0,30 * * * *' or '*/30 * * * *'
        $this->assertEquals('0 0 * * *', $renewEvent->expression);
        $this->assertTrue(in_array($syncEvent->expression, ['0,30 * * * *', '*/30 * * * *'], true));
    }
}
