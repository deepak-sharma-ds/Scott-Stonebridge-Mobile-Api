<?php

namespace Tests\Feature\Booking;

use App\Jobs\Booking\ProcessGoogleCalendarSyncJob;
use App\Mail\CalendarCollisionAlertMail;
use App\Models\AvailabilityDate;
use App\Models\Configuration;
use App\Models\GoogleToken;
use App\Models\ScheduledMeeting;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\BookingService;
use Carbon\Carbon;
use Google_Client;
use Google_Service_Calendar;
use Google_Service_Calendar_Event;
use Google_Service_Calendar_EventDateTime;
use Google_Service_Calendar_EventExtendedProperties;
use Google_Service_Calendar_Events;
use Google_Service_Calendar_Resource_Events;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class GoogleCalendarSyncFeatureTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private AvailabilityDate $date;

    private TimeSlot $slot1;

    private TimeSlot $slot2;

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
            'booking.google_channel_token' => null,
            'mail.admin_email' => 'admin@example.com',
        ]);

        $this->user = User::factory()->create();

        $this->tomorrowStr = Carbon::tomorrow('Europe/London')->format('Y-m-d');
        $this->date = AvailabilityDate::create([
            'user_id' => $this->user->id,
            'date' => $this->tomorrowStr,
        ]);

        $this->slot1 = TimeSlot::create([
            'availability_date_id' => $this->date->id,
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
        ]);

        $this->slot2 = TimeSlot::create([
            'availability_date_id' => $this->date->id,
            'start_time' => '10:30:00',
            'end_time' => '11:00:00',
        ]);

        // Fake GoogleToken record in DB
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

    public function test_webhook_handles_sync_handshake_without_dispatching_job(): void
    {
        Queue::fake();

        $response = $this->postJson(route('webhook.google.calendar'), [], [
            'X-Goog-Resource-State' => 'sync',
            'X-Goog-Channel-ID' => 'test-channel-id-123',
            'X-Goog-Resource-ID' => 'test-resource-id-456',
        ]);

        $response->assertOk();
        Queue::assertNothingPushed();
    }

    public function test_webhook_dispatches_sync_job_on_exists_state(): void
    {
        Queue::fake();

        $response = $this->postJson(route('webhook.google.calendar'), [], [
            'X-Goog-Resource-State' => 'exists',
            'X-Goog-Channel-ID' => 'test-channel-id-123',
            'X-Goog-Resource-ID' => 'test-resource-id-456',
        ]);

        $response->assertOk();
        Queue::assertPushed(ProcessGoogleCalendarSyncJob::class, function ($job) {
            return $job->channelId === 'test-channel-id-123';
        });
    }

    public function test_sub_calendar_placeholder_conversion_to_primary_booking(): void
    {
        $mockClient = Mockery::mock(Google_Client::class);
        $mockCalendarService = Mockery::mock(Google_Service_Calendar::class);
        $mockEvents = Mockery::mock(Google_Service_Calendar_Resource_Events::class);
        $mockCalendarService->events = $mockEvents;

        $bookingService = app(BookingService::class);
        $bookingService->setGoogleClient($mockClient);
        $bookingService->setCalendarService($mockCalendarService);
        $this->app->instance(BookingService::class, $bookingService);

        // Build Google event representing converted placeholder on sub_calendar_123
        $convertedEvent = new Google_Service_Calendar_Event;
        $convertedEvent->setId('placeholder_evt_001');
        $convertedEvent->setStatus('confirmed');
        $convertedEvent->setSummary('Appointment with Jane Doe');

        $start = Carbon::parse("{$this->tomorrowStr} 10:00:00", 'Europe/London');
        $end = Carbon::parse("{$this->tomorrowStr} 10:30:00", 'Europe/London');
        $convertedEvent->setStart(new Google_Service_Calendar_EventDateTime([
            'dateTime' => $start->toRfc3339String(),
            'timeZone' => 'Europe/London',
        ]));
        $convertedEvent->setEnd(new Google_Service_Calendar_EventDateTime([
            'dateTime' => $end->toRfc3339String(),
            'timeZone' => 'Europe/London',
        ]));

        $ext = new Google_Service_Calendar_EventExtendedProperties;
        $ext->setPrivate([
            'slot_id' => (string) $this->slot1->id,
            'date_id' => (string) $this->date->id,
            'is_placeholder' => 'true',
            'managed_by' => 'laravel_booking_system',
        ]);
        $convertedEvent->setExtendedProperties($ext);

        // Guest attendee added
        $attendee = new \Google_Service_Calendar_EventAttendee;
        $attendee->setEmail('jane@example.com');
        $attendee->setDisplayName('Jane Doe');
        $convertedEvent->setAttendees([$attendee]);

        $eventsListSub = new Google_Service_Calendar_Events;
        $eventsListSub->setItems([$convertedEvent]);
        $eventsListSub->setNextSyncToken('new_sub_sync_token');

        $eventsListPrimary = new Google_Service_Calendar_Events;
        $eventsListPrimary->setItems([]);
        $eventsListPrimary->setNextSyncToken('new_pri_sync_token');

        // Sub calendar query returns modified placeholder
        $mockEvents->shouldReceive('listEvents')
            ->with('sub_calendar_123', Mockery::any())
            ->andReturn($eventsListSub);

        // Primary calendar query returns empty
        $mockEvents->shouldReceive('listEvents')
            ->with('primary', Mockery::any())
            ->andReturn($eventsListPrimary);

        // Expect insert into primary calendar with Google Meet
        $mockEvents->shouldReceive('insert')
            ->once()
            ->with('primary', Mockery::on(function ($evt) {
                return $evt instanceof Google_Service_Calendar_Event
                    && str_contains($evt->getSummary(), 'Jane Doe');
            }), Mockery::any())
            ->andReturnUsing(function ($cal, $evt) {
                $evt->setId('promoted_pri_evt_123');
                $evt->setHangoutLink('https://meet.google.com/promoted-meet');

                return $evt;
            });

        // Expect deletion of converted placeholder from sub_calendar_123
        $mockEvents->shouldReceive('delete')
            ->once()
            ->with('sub_calendar_123', 'placeholder_evt_001', Mockery::any())
            ->andReturn(null);

        // Execute sync job
        $job = new ProcessGoogleCalendarSyncJob;
        $job->handle($bookingService);

        // Verify ScheduledMeeting created in DB
        $this->assertDatabaseHas('scheduled_meetings', [
            'time_slot_id' => $this->slot1->id,
            'email' => 'jane@example.com',
            'status' => 'confirmed',
            'event_id' => 'promoted_pri_evt_123',
        ]);
    }

    public function test_direct_booking_on_primary_calendar_claims_slot_and_removes_placeholder(): void
    {
        $mockClient = Mockery::mock(Google_Client::class);
        $mockCalendarService = Mockery::mock(Google_Service_Calendar::class);
        $mockEvents = Mockery::mock(Google_Service_Calendar_Resource_Events::class);
        $mockCalendarService->events = $mockEvents;

        $bookingService = app(BookingService::class);
        $bookingService->setGoogleClient($mockClient);
        $bookingService->setCalendarService($mockCalendarService);
        $this->app->instance(BookingService::class, $bookingService);

        // Direct event created on Primary Calendar by Scott
        $directEvent = new Google_Service_Calendar_Event;
        $directEvent->setId('scott_direct_evt_555');
        $directEvent->setStatus('confirmed');
        $directEvent->setSummary('Direct VIP Consultation with Bob');

        $start = Carbon::parse("{$this->tomorrowStr} 10:00:00", 'Europe/London');
        $end = Carbon::parse("{$this->tomorrowStr} 10:30:00", 'Europe/London');
        $directEvent->setStart(new Google_Service_Calendar_EventDateTime([
            'dateTime' => $start->toRfc3339String(),
            'timeZone' => 'Europe/London',
        ]));
        $directEvent->setEnd(new Google_Service_Calendar_EventDateTime([
            'dateTime' => $end->toRfc3339String(),
            'timeZone' => 'Europe/London',
        ]));

        $attendee = new \Google_Service_Calendar_EventAttendee;
        $attendee->setEmail('bob@example.com');
        $attendee->setDisplayName('Bob Smith');
        $directEvent->setAttendees([$attendee]);

        $eventsListPrimary = new Google_Service_Calendar_Events;
        $eventsListPrimary->setItems([$directEvent]);
        $eventsListPrimary->setNextSyncToken('pri_sync_2');

        $eventsListSub = new Google_Service_Calendar_Events;
        $eventsListSub->setItems([]);
        $eventsListSub->setNextSyncToken('sub_sync_2');

        $mockEvents->shouldReceive('listEvents')
            ->with('primary', Mockery::any())
            ->andReturn($eventsListPrimary);

        $mockEvents->shouldReceive('listEvents')
            ->with('sub_calendar_123', Mockery::any())
            ->andReturnUsing(function ($calId, $optParams) use ($eventsListSub) {
                if (isset($optParams['privateExtendedProperty']) && $optParams['privateExtendedProperty'] === 'slot_id='.$this->slot1->id) {
                    $item = new Google_Service_Calendar_Event;
                    $item->setId('sub_placeholder_to_delete');
                    $item->setStatus('confirmed');
                    $list = new Google_Service_Calendar_Events;
                    $list->setItems([$item]);

                    return $list;
                }

                return $eventsListSub;
            });

        // Expect patch on primary event to stamp managed_by
        $mockEvents->shouldReceive('patch')
            ->once()
            ->with('primary', 'scott_direct_evt_555', Mockery::any())
            ->andReturn($directEvent);

        $mockEvents->shouldReceive('delete')
            ->once()
            ->with('sub_calendar_123', 'sub_placeholder_to_delete', Mockery::any())
            ->andReturn(null);

        // Execute sync job
        $job = new ProcessGoogleCalendarSyncJob;
        $job->handle($bookingService);

        // Verify ScheduledMeeting created in DB
        $this->assertDatabaseHas('scheduled_meetings', [
            'time_slot_id' => $this->slot1->id,
            'email' => 'bob@example.com',
            'status' => 'confirmed',
            'event_id' => 'scott_direct_evt_555',
        ]);
    }

    public function test_cancellation_in_google_calendar_soft_cancels_and_restores_placeholder(): void
    {
        $mockClient = Mockery::mock(Google_Client::class);
        $mockCalendarService = Mockery::mock(Google_Service_Calendar::class);
        $mockEvents = Mockery::mock(Google_Service_Calendar_Resource_Events::class);
        $mockCalendarService->events = $mockEvents;

        $bookingService = app(BookingService::class);
        $bookingService->setGoogleClient($mockClient);
        $bookingService->setCalendarService($mockCalendarService);
        $this->app->instance(BookingService::class, $bookingService);

        // Create an existing meeting in DB
        $meeting = ScheduledMeeting::create([
            'user_id' => $this->user->id,
            'name' => 'Charlie Customer',
            'email' => 'charlie@example.com',
            'phone' => '+447123456789',
            'datetime' => Carbon::parse("{$this->tomorrowStr} 10:00:00", 'Europe/London'),
            'meeting_link' => 'https://meet.google.com/meet-charlie',
            'event_id' => 'pri_charlie_evt',
            'status' => 'confirmed',
            'availability_date_id' => $this->date->id,
            'time_slot_id' => $this->slot1->id,
        ]);

        // Google returns cancelled status for this event
        $cancelledEvent = new Google_Service_Calendar_Event;
        $cancelledEvent->setId('pri_charlie_evt');
        $cancelledEvent->setStatus('cancelled');

        $eventsListPrimary = new Google_Service_Calendar_Events;
        $eventsListPrimary->setItems([$cancelledEvent]);
        $eventsListPrimary->setNextSyncToken('pri_sync_3');

        $eventsListSub = new Google_Service_Calendar_Events;
        $eventsListSub->setItems([]);
        $eventsListSub->setNextSyncToken('sub_sync_3');

        $mockEvents->shouldReceive('listEvents')
            ->with('primary', Mockery::any())
            ->andReturn($eventsListPrimary);

        $mockEvents->shouldReceive('listEvents')
            ->with('sub_calendar_123', Mockery::any())
            ->andReturnUsing(function () use ($eventsListSub) {
                return $eventsListSub;
            });

        $mockEvents->shouldReceive('insert')
            ->once()
            ->with('sub_calendar_123', Mockery::on(function ($evt) {
                return $evt instanceof Google_Service_Calendar_Event
                    && str_contains($evt->getSummary(), '🟢 Available');
            }))
            ->andReturnUsing(function ($cal, $evt) {
                $evt->setId('restored_placeholder_evt');

                return $evt;
            });

        // Execute sync job
        $job = new ProcessGoogleCalendarSyncJob;
        $job->handle($bookingService);

        // Verify meeting soft-cancelled
        $meeting->refresh();
        $this->assertEquals('cancelled', $meeting->status);
    }

    public function test_reschedule_to_valid_slot_moves_booking_and_updates_placeholders(): void
    {
        $mockClient = Mockery::mock(Google_Client::class);
        $mockCalendarService = Mockery::mock(Google_Service_Calendar::class);
        $mockEvents = Mockery::mock(Google_Service_Calendar_Resource_Events::class);
        $mockCalendarService->events = $mockEvents;

        $bookingService = app(BookingService::class);
        $bookingService->setGoogleClient($mockClient);
        $bookingService->setCalendarService($mockCalendarService);
        $this->app->instance(BookingService::class, $bookingService);

        // Existing meeting in slot 1 (10:00 - 10:30)
        $meeting = ScheduledMeeting::create([
            'user_id' => $this->user->id,
            'name' => 'Dan Customer',
            'email' => 'dan@example.com',
            'phone' => '+447123456789',
            'datetime' => Carbon::parse("{$this->tomorrowStr} 10:00:00", 'Europe/London'),
            'meeting_link' => 'https://meet.google.com/meet-dan',
            'event_id' => 'pri_dan_evt',
            'status' => 'confirmed',
            'availability_date_id' => $this->date->id,
            'time_slot_id' => $this->slot1->id,
        ]);

        // Event moved to slot 2 (10:30 - 11:00)
        $movedEvent = new Google_Service_Calendar_Event;
        $movedEvent->setId('pri_dan_evt');
        $movedEvent->setStatus('confirmed');
        $movedEvent->setSummary('Appointment with Dan');

        $newStart = Carbon::parse("{$this->tomorrowStr} 10:30:00", 'Europe/London');
        $newEnd = Carbon::parse("{$this->tomorrowStr} 11:00:00", 'Europe/London');
        $movedEvent->setStart(new Google_Service_Calendar_EventDateTime([
            'dateTime' => $newStart->toRfc3339String(),
            'timeZone' => 'Europe/London',
        ]));
        $movedEvent->setEnd(new Google_Service_Calendar_EventDateTime([
            'dateTime' => $newEnd->toRfc3339String(),
            'timeZone' => 'Europe/London',
        ]));

        $eventsListPrimary = new Google_Service_Calendar_Events;
        $eventsListPrimary->setItems([$movedEvent]);
        $eventsListPrimary->setNextSyncToken('pri_sync_4');

        $eventsListSub = new Google_Service_Calendar_Events;
        $eventsListSub->setItems([]);
        $eventsListSub->setNextSyncToken('sub_sync_4');

        $mockEvents->shouldReceive('listEvents')
            ->with('primary', Mockery::any())
            ->andReturn($eventsListPrimary);

        $mockEvents->shouldReceive('listEvents')
            ->with('sub_calendar_123', Mockery::any())
            ->andReturnUsing(function ($calId, $optParams) use ($eventsListSub) {
                if (isset($optParams['privateExtendedProperty']) && $optParams['privateExtendedProperty'] === 'slot_id='.$this->slot2->id) {
                    $slot2Item = new Google_Service_Calendar_Event;
                    $slot2Item->setId('slot2_placeholder_id');
                    $slot2Item->setStatus('confirmed');
                    $list2 = new Google_Service_Calendar_Events;
                    $list2->setItems([$slot2Item]);

                    return $list2;
                }

                return $eventsListSub;
            });

        $mockEvents->shouldReceive('insert')
            ->once()
            ->with('sub_calendar_123', Mockery::on(function ($evt) {
                return str_contains($evt->getSummary(), '🟢 Available');
            }))
            ->andReturnUsing(function ($cal, $evt) {
                $evt->setId('restored_slot1');

                return $evt;
            });

        $mockEvents->shouldReceive('delete')
            ->once()
            ->with('sub_calendar_123', 'slot2_placeholder_id', Mockery::any())
            ->andReturn(null);

        // Execute sync job
        $job = new ProcessGoogleCalendarSyncJob;
        $job->handle($bookingService);

        // Verify meeting moved in DB
        $meeting->refresh();
        $this->assertEquals($this->slot2->id, $meeting->time_slot_id);
        $this->assertEquals($newStart->toDateTimeString(), $meeting->datetime->toDateTimeString());
    }

    public function test_collision_strict_revert_snaps_event_back_and_alerts_admin(): void
    {
        Mail::fake();

        $mockClient = Mockery::mock(Google_Client::class);
        $mockCalendarService = Mockery::mock(Google_Service_Calendar::class);
        $mockEvents = Mockery::mock(Google_Service_Calendar_Resource_Events::class);
        $mockCalendarService->events = $mockEvents;

        $bookingService = app(BookingService::class);
        $bookingService->setGoogleClient($mockClient);
        $bookingService->setCalendarService($mockCalendarService);
        $this->app->instance(BookingService::class, $bookingService);

        // Meeting A in slot 1 (10:00 - 10:30)
        $meetingA = ScheduledMeeting::create([
            'user_id' => $this->user->id,
            'name' => 'Meeting A Customer',
            'email' => 'a@example.com',
            'phone' => '+447123456789',
            'datetime' => Carbon::parse("{$this->tomorrowStr} 10:00:00", 'Europe/London'),
            'meeting_link' => 'https://meet.google.com/meet-a',
            'event_id' => 'pri_a_evt',
            'status' => 'confirmed',
            'availability_date_id' => $this->date->id,
            'time_slot_id' => $this->slot1->id,
        ]);

        // Meeting B in slot 2 (10:30 - 11:00)
        $meetingB = ScheduledMeeting::create([
            'user_id' => $this->user->id,
            'name' => 'Meeting B Customer',
            'email' => 'b@example.com',
            'phone' => '+447987654321',
            'datetime' => Carbon::parse("{$this->tomorrowStr} 10:30:00", 'Europe/London'),
            'meeting_link' => 'https://meet.google.com/meet-b',
            'event_id' => 'pri_b_evt',
            'status' => 'confirmed',
            'availability_date_id' => $this->date->id,
            'time_slot_id' => $this->slot2->id,
        ]);

        // Scott drags Meeting B onto Slot 1 (10:00:00) which is already taken by Meeting A!
        $collidingEvent = new Google_Service_Calendar_Event;
        $collidingEvent->setId('pri_b_evt');
        $collidingEvent->setStatus('confirmed');
        $collidingEvent->setSummary('Meeting B Customer');

        $collidingStart = Carbon::parse("{$this->tomorrowStr} 10:00:00", 'Europe/London');
        $collidingEnd = Carbon::parse("{$this->tomorrowStr} 10:30:00", 'Europe/London');
        $collidingEvent->setStart(new Google_Service_Calendar_EventDateTime([
            'dateTime' => $collidingStart->toRfc3339String(),
            'timeZone' => 'Europe/London',
        ]));
        $collidingEvent->setEnd(new Google_Service_Calendar_EventDateTime([
            'dateTime' => $collidingEnd->toRfc3339String(),
            'timeZone' => 'Europe/London',
        ]));

        $eventsListPrimary = new Google_Service_Calendar_Events;
        $eventsListPrimary->setItems([$collidingEvent]);
        $eventsListPrimary->setNextSyncToken('pri_sync_5');

        $eventsListSub = new Google_Service_Calendar_Events;
        $eventsListSub->setItems([]);
        $eventsListSub->setNextSyncToken('sub_sync_5');

        $mockEvents->shouldReceive('listEvents')
            ->with('primary', Mockery::any())
            ->andReturn($eventsListPrimary);

        $mockEvents->shouldReceive('listEvents')
            ->with('sub_calendar_123', Mockery::any())
            ->andReturn($eventsListSub);

        // Expect patch on Google Calendar snapping Meeting B back to original slot 2 (10:30:00)
        $mockEvents->shouldReceive('patch')
            ->once()
            ->with('primary', 'pri_b_evt', Mockery::on(function ($evt) {
                return str_contains($evt->getStart()->getDateTime(), '10:30:00');
            }))
            ->andReturn($collidingEvent);

        // Execute sync job
        $job = new ProcessGoogleCalendarSyncJob;
        $job->handle($bookingService);

        // Verify Meeting B stayed on slot 2 in DB
        $meetingB->refresh();
        $this->assertEquals($this->slot2->id, $meetingB->time_slot_id);

        // Verify alert email dispatched
        Mail::assertSent(CalendarCollisionAlertMail::class, function ($mail) use ($meetingB) {
            return $mail->meeting->id === $meetingB->id;
        });
    }

    public function test_echo_prevention_skips_processing_when_meeting_already_synced(): void
    {
        $mockClient = Mockery::mock(Google_Client::class);
        $mockCalendarService = Mockery::mock(Google_Service_Calendar::class);
        $mockEvents = Mockery::mock(Google_Service_Calendar_Resource_Events::class);
        $mockCalendarService->events = $mockEvents;

        $bookingService = app(BookingService::class);
        $bookingService->setGoogleClient($mockClient);
        $bookingService->setCalendarService($mockCalendarService);
        $this->app->instance(BookingService::class, $bookingService);

        $start = Carbon::parse("{$this->tomorrowStr} 10:00:00", 'Europe/London');

        $meeting = ScheduledMeeting::create([
            'user_id' => $this->user->id,
            'name' => 'Echo Customer',
            'email' => 'echo@example.com',
            'phone' => '+447123456789',
            'datetime' => $start,
            'meeting_link' => 'https://meet.google.com/echo',
            'event_id' => 'pri_echo_123',
            'status' => 'confirmed',
            'availability_date_id' => $this->date->id,
            'time_slot_id' => $this->slot1->id,
        ]);

        $echoEvent = new Google_Service_Calendar_Event;
        $echoEvent->setId('pri_echo_123');
        $echoEvent->setStatus('confirmed');
        $echoEvent->setSummary('Meeting with Echo Customer');
        $echoEvent->setStart(new Google_Service_Calendar_EventDateTime([
            'dateTime' => $start->toRfc3339String(),
            'timeZone' => 'Europe/London',
        ]));
        $echoEvent->setEnd(new Google_Service_Calendar_EventDateTime([
            'dateTime' => (clone $start)->addMinutes(30)->toRfc3339String(),
            'timeZone' => 'Europe/London',
        ]));

        $eventsListPrimary = new Google_Service_Calendar_Events;
        $eventsListPrimary->setItems([$echoEvent]);

        $mockEvents->shouldReceive('listEvents')
            ->with('primary', Mockery::any())
            ->andReturn($eventsListPrimary);

        $mockEvents->shouldReceive('listEvents')
            ->with('sub_calendar_123', Mockery::any())
            ->andReturn(new Google_Service_Calendar_Events);

        // Should not patch, delete, or insert anything
        $mockEvents->shouldNotReceive('patch');
        $mockEvents->shouldNotReceive('insert');
        $mockEvents->shouldNotReceive('delete');

        $job = new ProcessGoogleCalendarSyncJob;
        $job->handle($bookingService);

        $meeting->refresh();
        $this->assertEquals('confirmed', $meeting->status);
    }
}
