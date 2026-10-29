<?php

namespace App\Services;

use App\Models\AvailabilityDate;
use App\Models\Configuration;
use App\Models\GoogleToken;
use App\Models\ScheduledMeeting;
use App\Models\TimeSlot;
use Carbon\Carbon;
use Google_Client;
use Google_Service_Calendar;
use Google_Service_Calendar_Calendar;
use Google_Service_Calendar_Event;
use Google_Service_Calendar_EventDateTime;
use Google_Service_Calendar_EventExtendedProperties;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class BookingService
{
    protected ?Google_Client $googleClient = null;

    protected ?Google_Service_Calendar $calendarService = null;

    public function setGoogleClient(?Google_Client $client): self
    {
        $this->googleClient = $client;

        return $this;
    }

    public function setCalendarService(?Google_Service_Calendar $service): self
    {
        $this->calendarService = $service;

        return $this;
    }

    public function getGoogleCalendarService(): ?Google_Service_Calendar
    {
        if ($this->calendarService !== null) {
            return $this->calendarService;
        }

        $client = $this->getGoogleClient();
        if (! $client) {
            return null;
        }

        $this->calendarService = new Google_Service_Calendar($client);

        return $this->calendarService;
    }

    public function getSlotsCalendarId(): ?string
    {
        $val = Configuration::where('name', 'GoogleCalendar.slots_calendar_id')->value('value');

        return ! empty($val) ? (string) $val : null;
    }

    public function setSlotsCalendarId(string $calendarId): void
    {
        Configuration::updateOrCreate(
            ['name' => 'GoogleCalendar.slots_calendar_id'],
            [
                'value' => $calendarId,
                'title' => 'Google Calendar Available Slots Secondary Calendar ID',
                'description' => 'Secondary Google Calendar ID holding green available slot placeholders',
                'editable' => 1,
            ]
        );
    }

    public function bookMeeting(array $data)
    {
        $log = Log::channel('appointment_slots');
        $log->info('================== START: BookingService::bookMeeting ==================');
        $log->info('BookingService::bookMeeting called', ['data' => $data]);

        // 1. Validate availability date
        $availability = AvailabilityDate::where('date', $data['availability_date'])->first();
        if (! $availability) {
            $log->error('Availability date not found', ['date' => $data['availability_date']]);

            return ['error' => 'Availability date not found.'];
        }
        $availability_id = $availability->id;

        // 2. Prevent double booking
        $already = ScheduledMeeting::where('availability_date_id', $availability_id)
            ->where('time_slot_id', $data['time_slot_id'])
            ->exists();
        if ($already) {
            $log->warning('Time slot already booked');

            return ['error' => 'This time slot is already booked.'];
        }

        try {

            $client = $this->getGoogleClient();
            if (! $client) {
                return ['error' => 'Calendar service not configured or authentication expired.'];
            }

            $calendarService = new Google_Service_Calendar($client);

            $timeSlot = TimeSlot::find($data['time_slot_id']);
            if (! $timeSlot) {
                $log->error('Time slot not found', ['time_slot_id' => $data['time_slot_id']]);

                return ['error' => 'Selected time slot not found'];
            }

            // Prepare dates explicitly in Europe/London timezone
            $date = Carbon::parse($availability->date)->format('Y-m-d');
            $startDateTime = Carbon::parse("{$date} {$timeSlot->start_time}", config('app.timezone', 'Europe/London'));
            $endDateTime = Carbon::parse("{$date} {$timeSlot->end_time}", config('app.timezone', 'Europe/London'));

            // Build event
            $event = new Google_Service_Calendar_Event([
                'summary' => 'Meeting with '.$data['name'],
                'description' => "Booking confirmed.\nPhone: ".$data['phone'],
                'start' => [
                    'dateTime' => $startDateTime->setTimezone(config('app.timezone', 'Europe/London'))->toRfc3339String(),
                    'timeZone' => config('app.timezone', 'Europe/London'),
                ],
                'end' => [
                    'dateTime' => $endDateTime->setTimezone(config('app.timezone', 'Europe/London'))->toRfc3339String(),
                    'timeZone' => config('app.timezone', 'Europe/London'),
                ],
                'attendees' => [
                    ['email' => $data['email']],
                ],
                'conferenceData' => [
                    'createRequest' => [
                        'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
                        'requestId' => uniqid(),
                    ],
                ],
            ]);

            // Insert event
            $createdEvent = $calendarService->events->insert(
                'primary',
                $event,
                ['conferenceDataVersion' => 1]
            );

            $meetLink = $createdEvent->getHangoutLink();
            $eventId = $createdEvent->getId();

            // 7. Save meeting record
            $scheduledMeeting = ScheduledMeeting::create([
                'user_id' => null,
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'datetime' => $startDateTime,
                'meeting_link' => $meetLink,
                'event_id' => $eventId,
                'status' => 'confirmed',
                'availability_date_id' => $availability_id,
                'time_slot_id' => $data['time_slot_id'],
                'order_id' => $data['order_id'] ?? null,
            ]);

            return [
                'success' => true,
                'booking' => $scheduledMeeting,
                'meeting_link' => $meetLink,
                'event_id' => $eventId,
                'formatted_time' => $startDateTime->format('F j, Y g:i A'),
            ];
        } catch (\Exception $e) {
            $log->error('Failed to create event or save booking', ['exception' => $e->getMessage()]);

            return [
                'error' => 'Failed to create event.',
                'message' => $e->getMessage(),
            ];
        }
    }

    public function handleSlotBookingDeletion(int $timeSlotId, ?int $availabilityDateId = null): array
    {
        $log = Log::channel('appointment_slots');
        $log->info('================== START: BookingService::handleSlotBookingDeletion ==================');
        $log->info('Checking for booked meetings before deleting slot', [
            'time_slot_id' => $timeSlotId,
            'availability_date_id' => $availabilityDateId,
        ]);

        // Find any active meeting for this slot
        $meeting = ScheduledMeeting::where('time_slot_id', $timeSlotId)
            ->when($availabilityDateId, fn ($q) => $q->where('availability_date_id', $availabilityDateId))
            ->first();

        if (! $meeting) {
            $log->info('No meeting found for slot', ['time_slot_id' => $timeSlotId]);

            return ['status' => 'no_booking'];
        }

        try {
            $client = $this->getGoogleClient();
            if (! $client) {
                return ['status' => 'error', 'message' => 'Google token missing or expired'];
            }

            $calendarService = new Google_Service_Calendar($client);

            if (! empty($meeting->event_id)) {
                try {
                    $calendarService->events->delete('primary', $meeting->event_id, ['sendUpdates' => 'all']);

                    $log->info('Deleted Google Calendar event', [
                        'meeting_id' => $meeting->id,
                        'event_id' => $meeting->event_id,
                    ]);
                } catch (\Throwable $th) {
                    $log->warning('Failed to delete event (maybe already deleted)', [
                        'meeting_id' => $meeting->id,
                        'event_id' => $meeting->event_id,
                        'error' => $th->getMessage(),
                    ]);
                }

                // Clear event_id so it's not reused
                $meeting->event_id = null;
            }

            /**
             * ======================================================
             * 5. Mark meeting as "needs_reschedule"
             * ======================================================
             */
            $meeting->status = 'needs_reschedule';
            $meeting->save();

            $log->info('Meeting marked for reschedule', ['meeting_id' => $meeting->id]);

            // Send reschedule reminder to admin
            // $adminEmail = config('mail.admin_email', 'admin@yourdomain.com');
            // \Mail::send('emails.reschedule_reminder', ['meeting' => $meeting], function ($message) use ($adminEmail) {
            //     $message->to($adminEmail)
            //         ->subject('Meeting Slot Cancelled – Reschedule Required');
            // });

            // Send reschedule reminder to admin
            // self::notifyAdminForReschedule($meeting);

            return [
                'status' => 'reschedule_needed',
                'meeting_id' => $meeting->id,
            ];
        } catch (\Exception $e) {

            $log->error('Error during booked slot deletion', [
                'time_slot_id' => $timeSlotId,
                'exception' => $e->getMessage(),
            ]);

            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Initialize and refresh Google Client using database-stored OAuth credentials
     */
    public function getGoogleClient(): ?Google_Client
    {
        if ($this->googleClient !== null) {
            return $this->googleClient;
        }

        $log = Log::channel('appointment_slots');
        $googleToken = GoogleToken::first();
        if (! $googleToken) {
            $log->error('Google token missing');

            return null;
        }

        $accessToken = Crypt::decryptString($googleToken->access_token);
        $refreshToken = $googleToken->refresh_token
            ? Crypt::decryptString($googleToken->refresh_token)
            : null;

        $client = new Google_Client;
        $client->setClientId(config('google.client_id') ?: env('GOOGLE_CLIENT_ID'));
        $client->setClientSecret(config('google.client_secret') ?: env('GOOGLE_CLIENT_SECRET'));
        $client->setRedirectUri(config('google.redirect_uri'));
        $client->addScope(Google_Service_Calendar::CALENDAR);
        $client->setAccessType('offline');

        $tokenArray = [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'created' => $googleToken->created_at_timestamp,
            'expires_in' => null,
        ];

        $client->setAccessToken($tokenArray);

        if ($client->isAccessTokenExpired()) {
            if (! $refreshToken) {
                $log->error('Refresh token missing — admin must reauthenticate.');

                return null;
            }

            $newToken = $client->fetchAccessTokenWithRefreshToken($refreshToken);
            $client->setAccessToken($newToken);

            $created = $newToken['created'] ?? time();
            $expires = ($newToken['expires_in'] ?? 3600) + $created;

            $googleToken->update([
                'access_token' => Crypt::encryptString($newToken['access_token']),
                'refresh_token' => isset($newToken['refresh_token'])
                    ? Crypt::encryptString($newToken['refresh_token'])
                    : $googleToken->refresh_token,
                'expires_at' => Carbon::createFromTimestamp($expires),
                'token_type' => $newToken['token_type'] ?? $googleToken->token_type,
                'scope' => $newToken['scope'] ?? $googleToken->scope,
                'created_at_timestamp' => $created,
            ]);

            $log->info('🔄 Google access token refreshed');
        }

        return $client;
    }

    /**
     * Resync a meeting event in Google Calendar so its timezone/offset matches Europe/London
     */
    public function resyncMeetingToGoogleCalendar(ScheduledMeeting $meeting): array
    {
        $log = Log::channel('appointment_slots');
        if (empty($meeting->event_id)) {
            return ['status' => 'skipped', 'message' => 'No event_id'];
        }

        try {
            $client = $this->getGoogleClient();
            if (! $client) {
                return ['status' => 'error', 'message' => 'Google Calendar not configured'];
            }

            $calendarService = new Google_Service_Calendar($client);
            $event = $calendarService->events->get('primary', $meeting->event_id);

            $date = Carbon::parse($meeting->availabilityDate?->date ?? $meeting->datetime)->format('Y-m-d');
            $startTime = $meeting->timeSlot?->start_time ?? Carbon::parse($meeting->datetime)->format('H:i:s');
            $endTime = $meeting->timeSlot?->end_time ?? Carbon::parse($meeting->datetime)->addMinutes(30)->format('H:i:s');

            $startDateTime = Carbon::parse("{$date} {$startTime}", config('app.timezone', 'Europe/London'));
            $endDateTime = Carbon::parse("{$date} {$endTime}", config('app.timezone', 'Europe/London'));

            $event->setStart(new Google_Service_Calendar_EventDateTime([
                'dateTime' => $startDateTime->setTimezone(config('app.timezone', 'Europe/London'))->toRfc3339String(),
                'timeZone' => config('app.timezone', 'Europe/London'),
            ]));
            $event->setEnd(new Google_Service_Calendar_EventDateTime([
                'dateTime' => $endDateTime->setTimezone(config('app.timezone', 'Europe/London'))->toRfc3339String(),
                'timeZone' => config('app.timezone', 'Europe/London'),
            ]));

            $calendarService->events->update('primary', $meeting->event_id, $event, ['sendUpdates' => 'all']);

            $meeting->update(['datetime' => $startDateTime]);

            $log->info("Resynced meeting #{$meeting->id} ({$meeting->name}) to Google Calendar: {$startDateTime->toRfc3339String()}");

            return ['status' => 'success', 'dateTime' => $startDateTime->toRfc3339String()];
        } catch (\Throwable $e) {
            $log->error("Failed to resync meeting #{$meeting->id}: ".$e->getMessage());

            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Create or sync a confirmed meeting event directly to Scott's Primary Google Calendar.
     */
    public function syncConfirmedMeetingToGoogle(ScheduledMeeting $meeting, Carbon $start, Carbon $end): ?array
    {
        $calendarService = $this->getGoogleCalendarService();
        if (! $calendarService) {
            return null;
        }

        try {
            $event = new Google_Service_Calendar_Event([
                'summary' => 'Meeting with '.$meeting->name,
                'description' => "Booking confirmed.\nPhone: ".$meeting->phone,
                'start' => [
                    'dateTime' => $start->setTimezone(config('app.timezone', 'Europe/London'))->toRfc3339String(),
                    'timeZone' => config('app.timezone', 'Europe/London'),
                ],
                'end' => [
                    'dateTime' => $end->setTimezone(config('app.timezone', 'Europe/London'))->toRfc3339String(),
                    'timeZone' => config('app.timezone', 'Europe/London'),
                ],
                'attendees' => [
                    ['email' => $meeting->email],
                ],
                'conferenceData' => [
                    'createRequest' => [
                        'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
                        'requestId' => uniqid(),
                    ],
                ],
                'extendedProperties' => [
                    'private' => [
                        'slot_id' => (string) $meeting->time_slot_id,
                        'date_id' => (string) $meeting->availability_date_id,
                        'order_id' => (string) $meeting->order_id,
                        'customer_name' => (string) $meeting->name,
                        'managed_by' => 'laravel_booking_system',
                    ],
                ],
            ]);

            $created = $calendarService->events->insert('primary', $event, ['conferenceDataVersion' => 1]);

            return [
                'event_id' => $created->getId(),
                'meeting_link' => $created->getHangoutLink(),
            ];
        } catch (\Throwable $e) {
            Log::channel('appointment_slots')->error('Google Calendar insert failed: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Delete an event from Google Calendar and notify attendees.
     */
    public function deleteGoogleCalendarEvent(string $eventId, string $calendarId = 'primary'): bool
    {
        $calendarService = $this->getGoogleCalendarService();
        if (! $calendarService) {
            return false;
        }

        try {
            $calendarService->events->delete($calendarId, $eventId, ['sendUpdates' => 'all']);

            return true;
        } catch (\Throwable $e) {
            Log::channel('appointment_slots')->warning("Failed to delete Google event {$eventId}: ".$e->getMessage());

            return false;
        }
    }

    public static function notifyAdminForReschedule(ScheduledMeeting $meeting): void
    {
        $log = Log::channel('appointment_slots');
        try {
            $adminEmail = config('env.ADMIN_EMAIL');

            Mail::send('emails.reschedule_reminder', ['meeting' => $meeting], function ($message) use ($adminEmail) {
                $message->to($adminEmail)
                    ->subject('Meeting Slot Cancelled – Reschedule Required');
            });

            $log->info('Reschedule reminder sent to admin for cancelled meeting', [
                'meeting_id' => $meeting->id,
                'admin_email' => $adminEmail,
            ]);
        } catch (\Exception $e) {
            $log->error('Failed to send reschedule reminder', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Discover existing sub-calendar by name or provision a new one.
     */
    public function getOrCreateSubCalendar(string $calendarName = 'Stonebridge - Available Slots', string $timezone = 'Europe/London'): ?string
    {
        $calendarService = $this->getGoogleCalendarService();
        if (! $calendarService) {
            return null;
        }

        // 1. Check if configured sub-calendar ID is already valid
        $existingId = $this->getSlotsCalendarId();
        if ($existingId) {
            try {
                $cal = $calendarService->calendars->get($existingId);
                if ($cal && $cal->getSummary() === $calendarName) {
                    return $existingId;
                }
            } catch (\Throwable $e) {
                Log::channel('appointment_slots')->warning("Configured sub-calendar {$existingId} not accessible, rediscovering...");
            }
        }

        // 2. Discover existing secondary calendar by summary
        try {
            $calendarList = $calendarService->calendarList->listCalendarList();
            foreach ($calendarList->getItems() as $item) {
                if ($item->getSummary() === $calendarName && ! $item->getDeleted()) {
                    $calendarId = $item->getId();
                    $this->setSlotsCalendarId($calendarId);

                    return $calendarId;
                }
            }
        } catch (\Throwable $e) {
            Log::channel('appointment_slots')->warning('Failed listing calendars: '.$e->getMessage());
        }

        // 3. Create secondary calendar if not found
        try {
            $newCal = new Google_Service_Calendar_Calendar;
            $newCal->setSummary($calendarName);
            $newCal->setTimeZone($timezone);
            $newCal->setDescription('Secondary calendar for Stonebridge appointment booking open slots');

            $created = $calendarService->calendars->insert($newCal);
            $calendarId = $created->getId();
            $this->setSlotsCalendarId($calendarId);

            return $calendarId;
        } catch (\Throwable $e) {
            Log::channel('appointment_slots')->error('Failed creating sub-calendar: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Project an open TimeSlot as a visual 🟢 Available placeholder on the sub-calendar.
     */
    public function projectSlotPlaceholder(TimeSlot $slot, AvailabilityDate $date, ?string $calendarId = null): ?string
    {
        $calendarId = $calendarId ?: ($this->getSlotsCalendarId() ?? $this->getOrCreateSubCalendar());
        if (! $calendarId) {
            return null;
        }

        $calendarService = $this->getGoogleCalendarService();
        if (! $calendarService) {
            return null;
        }

        $tz = config('app.timezone', 'Europe/London');
        $dateStr = Carbon::parse($date->date)->format('Y-m-d');
        $startDateTime = Carbon::parse("{$dateStr} {$slot->start_time}", $tz);
        $endDateTime = Carbon::parse("{$dateStr} {$slot->end_time}", $tz);

        $summary = '🟢 Available ('.$startDateTime->format('H:i').' - '.$endDateTime->format('H:i').')';
        $description = "Open booking slot\nSlot ID: {$slot->id}\nDate ID: {$date->id}";

        $extendedProperties = [
            'private' => [
                'slot_id' => (string) $slot->id,
                'date_id' => (string) $date->id,
                'managed_by' => 'laravel_booking_system',
                'is_placeholder' => 'true',
            ],
        ];

        try {
            // Check for existing placeholder for idempotency
            $existing = $this->findPlaceholderBySlotId($slot->id, $calendarId);

            if ($existing) {
                $existing->setSummary($summary);
                $existing->setDescription($description);
                $existing->setStart(new Google_Service_Calendar_EventDateTime([
                    'dateTime' => $startDateTime->toRfc3339String(),
                    'timeZone' => $tz,
                ]));
                $existing->setEnd(new Google_Service_Calendar_EventDateTime([
                    'dateTime' => $endDateTime->toRfc3339String(),
                    'timeZone' => $tz,
                ]));
                $existing->setExtendedProperties(new Google_Service_Calendar_EventExtendedProperties($extendedProperties));

                $updated = $calendarService->events->update($calendarId, $existing->getId(), $existing);

                return $updated->getId();
            }

            $event = new Google_Service_Calendar_Event([
                'summary' => $summary,
                'description' => $description,
                'start' => [
                    'dateTime' => $startDateTime->toRfc3339String(),
                    'timeZone' => $tz,
                ],
                'end' => [
                    'dateTime' => $endDateTime->toRfc3339String(),
                    'timeZone' => $tz,
                ],
                'extendedProperties' => $extendedProperties,
                'transparency' => 'transparent',
            ]);

            $created = $calendarService->events->insert($calendarId, $event);

            return $created->getId();
        } catch (\Throwable $e) {
            Log::channel('appointment_slots')->error("Failed projecting placeholder for slot {$slot->id}: ".$e->getMessage());

            return null;
        }
    }

    /**
     * Find a placeholder event on the sub-calendar by TimeSlot ID.
     */
    public function findPlaceholderBySlotId(int $slotId, ?string $calendarId = null): ?Google_Service_Calendar_Event
    {
        $calendarId = $calendarId ?: $this->getSlotsCalendarId();
        if (! $calendarId) {
            return null;
        }

        $calendarService = $this->getGoogleCalendarService();
        if (! $calendarService) {
            return null;
        }

        try {
            $events = $calendarService->events->listEvents($calendarId, [
                'privateExtendedProperty' => 'slot_id='.$slotId,
            ]);

            foreach ($events->getItems() as $item) {
                if ($item->getStatus() !== 'cancelled') {
                    return $item;
                }
            }
        } catch (\Throwable $e) {
            Log::channel('appointment_slots')->warning("Error searching placeholder for slot {$slotId}: ".$e->getMessage());
        }

        return null;
    }

    /**
     * Remove placeholder event from the sub-calendar when booked or deleted.
     */
    public function removeSlotPlaceholder(int $slotId, ?string $calendarId = null): bool
    {
        $calendarId = $calendarId ?: $this->getSlotsCalendarId();
        if (! $calendarId) {
            return false;
        }

        $calendarService = $this->getGoogleCalendarService();
        if (! $calendarService) {
            return false;
        }

        try {
            $events = $calendarService->events->listEvents($calendarId, [
                'privateExtendedProperty' => 'slot_id='.$slotId,
            ]);

            $deletedAny = false;
            foreach ($events->getItems() as $item) {
                if ($item->getStatus() !== 'cancelled') {
                    $calendarService->events->delete($calendarId, $item->getId(), ['sendUpdates' => 'none']);
                    $deletedAny = true;
                }
            }

            return $deletedAny;
        } catch (\Throwable $e) {
            Log::channel('appointment_slots')->warning("Error deleting placeholder for slot {$slotId}: ".$e->getMessage());

            return false;
        }
    }
}
