<?php

namespace Tests\Feature\Booking;

use App\Models\AvailabilityDate;
use App\Models\Configuration;
use App\Models\ScheduledMeeting;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\BookingService;
use Carbon\Carbon;
use Google_Client;
use Google_Service_Calendar;
use Google_Service_Calendar_Calendar;
use Google_Service_Calendar_CalendarList;
use Google_Service_Calendar_CalendarListEntry;
use Google_Service_Calendar_Event;
use Google_Service_Calendar_Events;
use Google_Service_Calendar_Resource_CalendarList;
use Google_Service_Calendar_Resource_Calendars;
use Google_Service_Calendar_Resource_Events;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CalendarSetupFeatureTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private AvailabilityDate $date;

    private TimeSlot $openSlot;

    private TimeSlot $bookedSlot;

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

        config(['app.timezone' => 'Europe/London']);

        $role = Role::findOrCreate('Admin', 'web');
        $this->user = User::factory()->create();
        $this->user->assignRole($role);

        $tomorrow = Carbon::tomorrow('Europe/London')->format('Y-m-d');
        $this->date = AvailabilityDate::create([
            'user_id' => $this->user->id,
            'date' => $tomorrow,
        ]);

        $this->openSlot = TimeSlot::create([
            'availability_date_id' => $this->date->id,
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
        ]);

        $this->bookedSlot = TimeSlot::create([
            'availability_date_id' => $this->date->id,
            'start_time' => '11:00:00',
            'end_time' => '11:30:00',
        ]);

        ScheduledMeeting::create([
            'user_id' => $this->user->id,
            'name' => 'Alice Customer',
            'email' => 'alice@example.com',
            'phone' => '+447123456789',
            'datetime' => Carbon::parse("{$tomorrow} 11:00:00", 'Europe/London'),
            'meeting_link' => 'https://meet.google.com/abc-defg-hij',
            'event_id' => 'evt_booked_123',
            'status' => 'confirmed',
            'availability_date_id' => $this->date->id,
            'time_slot_id' => $this->bookedSlot->id,
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_calendar_setup_command_fails_gracefully_when_not_authenticated(): void
    {
        $bookingService = app(BookingService::class);
        $this->app->instance(BookingService::class, $bookingService);

        // When no Google token is stored, command exits with failure code
        $this->artisan('calendar:setup')
            ->expectsOutputToContain('Google Calendar credentials missing or expired')
            ->assertFailed();
    }

    public function test_discovers_existing_sub_calendar_by_name(): void
    {
        $mockClient = Mockery::mock(Google_Client::class);
        $mockCalendarService = Mockery::mock(Google_Service_Calendar::class);
        $mockCalendarListResource = Mockery::mock(Google_Service_Calendar_Resource_CalendarList::class);
        $mockEventsResource = Mockery::mock(Google_Service_Calendar_Resource_Events::class);

        $mockCalendarService->calendarList = $mockCalendarListResource;
        $mockCalendarService->events = $mockEventsResource;

        // Simulate listing existing secondary calendars
        $entry1 = new Google_Service_Calendar_CalendarListEntry;
        $entry1->setId('primary_cal_id');
        $entry1->setSummary('Scott Primary');

        $entry2 = new Google_Service_Calendar_CalendarListEntry;
        $entry2->setId('existing_slots_cal_id');
        $entry2->setSummary('Stonebridge - Available Slots');

        $calendarList = new Google_Service_Calendar_CalendarList;
        $calendarList->setItems([$entry1, $entry2]);

        $mockCalendarListResource->shouldReceive('listCalendarList')
            ->andReturn($calendarList);

        $bookingService = app(BookingService::class);
        $bookingService->setGoogleClient($mockClient);
        $bookingService->setCalendarService($mockCalendarService);

        $calendarId = $bookingService->getOrCreateSubCalendar('Stonebridge - Available Slots');

        $this->assertEquals('existing_slots_cal_id', $calendarId);
        $this->assertEquals('existing_slots_cal_id', Configuration::where('name', 'GoogleCalendar.slots_calendar_id')->value('value'));
    }

    public function test_creates_new_sub_calendar_when_not_found(): void
    {
        $mockClient = Mockery::mock(Google_Client::class);
        $mockCalendarService = Mockery::mock(Google_Service_Calendar::class);
        $mockCalendarListResource = Mockery::mock(Google_Service_Calendar_Resource_CalendarList::class);
        $mockCalendarsResource = Mockery::mock(Google_Service_Calendar_Resource_Calendars::class);

        $mockCalendarService->calendarList = $mockCalendarListResource;
        $mockCalendarService->calendars = $mockCalendarsResource;

        // No matching sub-calendar exists yet
        $calendarList = new Google_Service_Calendar_CalendarList;
        $calendarList->setItems([]);

        $mockCalendarListResource->shouldReceive('listCalendarList')
            ->andReturn($calendarList);

        // Expect secondary calendar insertion
        $createdCalendar = new Google_Service_Calendar_Calendar;
        $createdCalendar->setId('newly_created_subcal_id');
        $createdCalendar->setSummary('Stonebridge - Available Slots');
        $createdCalendar->setTimeZone('Europe/London');

        $mockCalendarsResource->shouldReceive('insert')
            ->once()
            ->with(Mockery::on(function ($cal) {
                return $cal instanceof Google_Service_Calendar_Calendar
                    && $cal->getSummary() === 'Stonebridge - Available Slots'
                    && $cal->getTimeZone() === 'Europe/London';
            }))
            ->andReturn($createdCalendar);

        $bookingService = app(BookingService::class);
        $bookingService->setGoogleClient($mockClient);
        $bookingService->setCalendarService($mockCalendarService);

        $calendarId = $bookingService->getOrCreateSubCalendar('Stonebridge - Available Slots');

        $this->assertEquals('newly_created_subcal_id', $calendarId);
        $this->assertEquals('newly_created_subcal_id', Configuration::where('name', 'GoogleCalendar.slots_calendar_id')->value('value'));
    }

    public function test_calendar_setup_command_backfills_unbooked_slots_as_placeholders(): void
    {
        $mockClient = Mockery::mock(Google_Client::class);
        $mockCalendarService = Mockery::mock(Google_Service_Calendar::class);
        $mockCalendarListResource = Mockery::mock(Google_Service_Calendar_Resource_CalendarList::class);
        $mockEventsResource = Mockery::mock(Google_Service_Calendar_Resource_Events::class);

        $mockCalendarService->calendarList = $mockCalendarListResource;
        $mockCalendarService->events = $mockEventsResource;

        // Existing calendar
        $entry = new Google_Service_Calendar_CalendarListEntry;
        $entry->setId('sub_cal_123');
        $entry->setSummary('Stonebridge - Available Slots');

        $calendarList = new Google_Service_Calendar_CalendarList;
        $calendarList->setItems([$entry]);

        $mockCalendarListResource->shouldReceive('listCalendarList')
            ->andReturn($calendarList);

        // When searching for existing placeholder for openSlot
        $emptyEvents = new Google_Service_Calendar_Events;
        $emptyEvents->setItems([]);
        $mockEventsResource->shouldReceive('listEvents')
            ->with('sub_cal_123', Mockery::subset(['privateExtendedProperty' => 'slot_id='.$this->openSlot->id]))
            ->andReturn($emptyEvents);

        // Should insert 1 placeholder for openSlot (bookedSlot is ignored!)
        $mockEventsResource->shouldReceive('insert')
            ->once()
            ->with('sub_cal_123', Mockery::on(function ($event) {
                $ext = $event->getExtendedProperties();
                $private = $ext ? $ext->getPrivate() : [];

                return $event instanceof Google_Service_Calendar_Event
                    && str_contains($event->getSummary(), '🟢 Available')
                    && ($private['slot_id'] ?? null) == (string) $this->openSlot->id
                    && ($private['managed_by'] ?? null) === 'laravel_booking_system'
                    && ($private['is_placeholder'] ?? null) === 'true';
            }))
            ->andReturnUsing(function ($calId, $evt) {
                $evt->setId('evt_placeholder_open_slot');

                return $evt;
            });

        $bookingService = app(BookingService::class);
        $bookingService->setGoogleClient($mockClient);
        $bookingService->setCalendarService($mockCalendarService);
        $this->app->instance(BookingService::class, $bookingService);

        $this->artisan('calendar:setup')
            ->expectsOutputToContain('Sub-calendar connected: sub_cal_123')
            ->expectsOutputToContain('Successfully backfilled 1 slot placeholder(s)')
            ->assertSuccessful();

        $this->assertEquals('sub_cal_123', Configuration::where('name', 'GoogleCalendar.slots_calendar_id')->value('value'));
    }

    public function test_placeholder_has_correct_extended_properties_and_timezone(): void
    {
        $mockClient = Mockery::mock(Google_Client::class);
        $mockCalendarService = Mockery::mock(Google_Service_Calendar::class);
        $mockEventsResource = Mockery::mock(Google_Service_Calendar_Resource_Events::class);

        $mockCalendarService->events = $mockEventsResource;

        $bookingService = app(BookingService::class);
        $bookingService->setGoogleClient($mockClient);
        $bookingService->setCalendarService($mockCalendarService);

        // Check search
        $emptyEvents = new Google_Service_Calendar_Events;
        $emptyEvents->setItems([]);
        $mockEventsResource->shouldReceive('listEvents')
            ->with('target_subcal', Mockery::subset(['privateExtendedProperty' => 'slot_id='.$this->openSlot->id]))
            ->andReturn($emptyEvents);

        $capturedEvent = null;
        $mockEventsResource->shouldReceive('insert')
            ->once()
            ->with('target_subcal', Mockery::on(function ($event) use (&$capturedEvent) {
                $capturedEvent = $event;

                return true;
            }))
            ->andReturnUsing(function ($calId, $evt) {
                $evt->setId('gen_evt_123');

                return $evt;
            });

        $eventId = $bookingService->projectSlotPlaceholder($this->openSlot, $this->date, 'target_subcal');

        $this->assertEquals('gen_evt_123', $eventId);
        $this->assertNotNull($capturedEvent);
        $this->assertEquals('🟢 Available (10:00 - 10:30)', $capturedEvent->getSummary());

        $ext = $capturedEvent->getExtendedProperties();
        $this->assertNotNull($ext);
        $private = $ext->getPrivate();
        $this->assertEquals((string) $this->openSlot->id, $private['slot_id']);
        $this->assertEquals((string) $this->date->id, $private['date_id']);
        $this->assertEquals('laravel_booking_system', $private['managed_by']);
        $this->assertEquals('true', $private['is_placeholder']);

        // Check start & end timezone
        $start = $capturedEvent->getStart();
        $this->assertEquals('Europe/London', $start->getTimeZone());
        $this->assertStringContainsString('10:00:00', $start->getDateTime());
    }

    public function test_calendar_setup_is_idempotent_and_updates_existing_placeholders(): void
    {
        $mockClient = Mockery::mock(Google_Client::class);
        $mockCalendarService = Mockery::mock(Google_Service_Calendar::class);
        $mockEventsResource = Mockery::mock(Google_Service_Calendar_Resource_Events::class);

        $mockCalendarService->events = $mockEventsResource;

        $bookingService = app(BookingService::class);
        $bookingService->setGoogleClient($mockClient);
        $bookingService->setCalendarService($mockCalendarService);

        // Existing placeholder event found
        $existingPlaceholder = new Google_Service_Calendar_Event;
        $existingPlaceholder->setId('existing_evt_999');
        $existingPlaceholder->setStatus('confirmed');

        $eventsList = new Google_Service_Calendar_Events;
        $eventsList->setItems([$existingPlaceholder]);

        $mockEventsResource->shouldReceive('listEvents')
            ->with('target_subcal', Mockery::subset(['privateExtendedProperty' => 'slot_id='.$this->openSlot->id]))
            ->andReturn($eventsList);

        // Must call update, NOT insert!
        $mockEventsResource->shouldReceive('update')
            ->once()
            ->with('target_subcal', 'existing_evt_999', Mockery::on(function ($evt) {
                return $evt->getId() === 'existing_evt_999'
                    && str_contains($evt->getSummary(), '🟢 Available');
            }))
            ->andReturn($existingPlaceholder);

        $mockEventsResource->shouldNotReceive('insert');

        $eventId = $bookingService->projectSlotPlaceholder($this->openSlot, $this->date, 'target_subcal');

        $this->assertEquals('existing_evt_999', $eventId);
    }

    public function test_admin_google_calendar_status_and_setup_endpoints(): void
    {
        $mockClient = Mockery::mock(Google_Client::class);
        $mockCalendarService = Mockery::mock(Google_Service_Calendar::class);
        $mockCalendarListResource = Mockery::mock(Google_Service_Calendar_Resource_CalendarList::class);
        $mockEventsResource = Mockery::mock(Google_Service_Calendar_Resource_Events::class);

        $mockCalendarService->calendarList = $mockCalendarListResource;
        $mockCalendarService->events = $mockEventsResource;

        $entry = new Google_Service_Calendar_CalendarListEntry;
        $entry->setId('endpoint_subcal_id');
        $entry->setSummary('Stonebridge - Available Slots');

        $calendarList = new Google_Service_Calendar_CalendarList;
        $calendarList->setItems([$entry]);

        $mockCalendarListResource->shouldReceive('listCalendarList')
            ->andReturn($calendarList);

        $emptyEvents = new Google_Service_Calendar_Events;
        $emptyEvents->setItems([]);
        $mockEventsResource->shouldReceive('listEvents')
            ->andReturn($emptyEvents);

        $mockEventsResource->shouldReceive('insert')
            ->andReturnUsing(function ($calId, $evt) {
                $evt->setId('evt_ep');

                return $evt;
            });

        $bookingService = app(BookingService::class);
        $bookingService->setGoogleClient($mockClient);
        $bookingService->setCalendarService($mockCalendarService);
        $this->app->instance(BookingService::class, $bookingService);

        // Test as authenticated admin user
        $response = $this->actingAs($this->user)->getJson(route('admin.google.status'));
        $response->assertOk();
        $response->assertJsonStructure([
            'is_configured',
            'has_token',
            'slots_calendar_id',
        ]);

        $postResponse = $this->actingAs($this->user)->postJson(route('admin.google.setup'));
        $postResponse->assertOk();
        $postResponse->assertJson([
            'success' => true,
            'calendar_id' => 'endpoint_subcal_id',
        ]);
    }
}
