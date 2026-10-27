<?php

namespace App\Services;

use App\Models\AvailabilityDate;
use App\Models\GoogleToken;
use App\Models\ScheduledMeeting;
use App\Models\TimeSlot;
use Carbon\Carbon;
use Google_Client;
use Google_Service_Calendar;
use Google_Service_Calendar_Event;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class BookingService
{
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

            $event->setStart(new \Google_Service_Calendar_EventDateTime([
                'dateTime' => $startDateTime->setTimezone(config('app.timezone', 'Europe/London'))->toRfc3339String(),
                'timeZone' => config('app.timezone', 'Europe/London'),
            ]));
            $event->setEnd(new \Google_Service_Calendar_EventDateTime([
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

    protected static function notifyAdminForReschedule($meeting)
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
}
