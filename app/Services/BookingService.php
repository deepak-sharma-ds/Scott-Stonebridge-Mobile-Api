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
use Illuminate\Support\Facades\Storage;

class BookingService
{
    public function bookMeeting(array $data)
    {
        $log = Log::channel('appointment_slots');
        $log->info('================== START: BookingService::bookMeeting ==================');
        $log->info('BookingService::bookMeeting called', ['data' => $data]);

        // 1. Validate availability date
        $availability = AvailabilityDate::where('date', $data['availability_date'])->first();
        if (!$availability) {
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

            /**
             * ======================================================
             *  3. LOAD GOOGLE TOKEN FROM DB (NOT from file)
             * ======================================================
             */
            $googleToken = GoogleToken::first();
            if (!$googleToken) {
                $log->error('Google token missing');
                return ['error' => 'Calendar service not configured.'];
            }

            // Decrypt stored tokens
            $accessToken  = Crypt::decryptString($googleToken->access_token);
            $refreshToken = $googleToken->refresh_token
                ? Crypt::decryptString($googleToken->refresh_token)
                : null;

            /**
             * ======================================================
             *  4. Initialize Google Client
             * ======================================================
             */
            $client = new Google_Client();
            $client->setClientId(config('google.client_id') ?: env('GOOGLE_CLIENT_ID'));
            $client->setClientSecret(config('google.client_secret') ?: env('GOOGLE_CLIENT_SECRET'));
            $client->setRedirectUri(config('google.redirect_uri'));
            $client->addScope(Google_Service_Calendar::CALENDAR);
            $client->setAccessType('offline');

            // Rebuild token array to set on the client
            $tokenArray = [
                'access_token'  => $accessToken,
                'refresh_token' => $refreshToken,
                'created'       => $googleToken->created_at_timestamp,
                'expires_in'    => null,  // google client will check using access token's internal expiry
            ];

            // Set token to client
            $client->setAccessToken($tokenArray);

            /**
             * ======================================================
             *  5. Refresh token if needed
             * ======================================================
             */
            if ($client->isAccessTokenExpired()) {

                if (!$refreshToken) {
                    $log->error('Refresh token missing — admin must reauthenticate.');
                    return ['error' => 'Google Calendar authentication expired.'];
                }

                $newToken = $client->fetchAccessTokenWithRefreshToken($refreshToken);
                $client->setAccessToken($newToken);

                $created  = $newToken['created'] ?? time();
                $expires  = ($newToken['expires_in'] ?? 3600) + $created;

                // Save updated token to DB
                $googleToken->update([
                    'access_token'          => Crypt::encryptString($newToken['access_token']),
                    'refresh_token'         => isset($newToken['refresh_token'])
                        ? Crypt::encryptString($newToken['refresh_token'])
                        : $googleToken->refresh_token, // keep old if not returned
                    'expires_at'            => Carbon::createFromTimestamp($expires),
                    'token_type'            => $newToken['token_type'] ?? $googleToken->token_type,
                    'scope'                 => $newToken['scope'] ?? $googleToken->scope,
                    'created_at_timestamp'  => $created,
                ]);

                $log->info("🔄 Google access token refreshed");
            }

            /**
             * ======================================================
             *  6. Create Google Calendar event
             * ======================================================
             */
            $calendarService = new Google_Service_Calendar($client);

            $timeSlot = TimeSlot::find($data['time_slot_id']);
            if (!$timeSlot) {
                $log->error('Time slot not found', ['time_slot_id' => $data['time_slot_id']]);
                return ['error' => 'Selected time slot not found'];
            }

            // Prepare dates
            $date = Carbon::parse($availability->date)->format('Y-m-d');
            $startDateTime = Carbon::parse("{$date} {$timeSlot->start_time}");
            $endDateTime   = Carbon::parse("{$date} {$timeSlot->end_time}");

            // Build event
            $event = new Google_Service_Calendar_Event([
                'summary'     => 'Meeting with ' . $data['name'],
                'description' => "Booking confirmed.\nPhone: " . $data['phone'],
                'start'       => [
                    'dateTime' => $startDateTime->toRfc3339String(),
                    'timeZone' => 'Asia/Kolkata',
                ],
                'end'         => [
                    'dateTime' => $endDateTime->toRfc3339String(),
                    'timeZone' => 'Asia/Kolkata',
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
            $eventId  = $createdEvent->getId();

            // 7. Save meeting record
            $scheduledMeeting = ScheduledMeeting::create([
                'user_id'              => null,
                'name'                 => $data['name'],
                'email'                => $data['email'],
                'phone'                => $data['phone'],
                'datetime'             => $startDateTime,
                'meeting_link'         => $meetLink,
                'event_id'             => $eventId,
                'status'               => 'confirmed',
                'availability_date_id' => $availability_id,
                'time_slot_id'         => $data['time_slot_id'],
                'order_id'             => $data['order_id'] ?? null,
            ]);

            return [
                'success'        => true,
                'booking'        => $scheduledMeeting,
                'meeting_link'   => $meetLink,
                'event_id'       => $eventId,
                'formatted_time' => $startDateTime->format('F j, Y g:i A'),
            ];
        } catch (\Exception $e) {
            $log->error('Failed to create event or save booking', ['exception' => $e->getMessage()]);
            return [
                'error'   => 'Failed to create event.',
                'message' => $e->getMessage()
            ];
        }
    }

    public function handleSlotBookingDeletion(int $timeSlotId, ?int $availabilityDateId = null): array
    {
        $log = Log::channel('appointment_slots');
        $log->info('================== START: BookingService::handleSlotBookingDeletion ==================');
        $log->info('Checking for booked meetings before deleting slot', [
            'time_slot_id' => $timeSlotId,
            'availability_date_id' => $availabilityDateId
        ]);

        // Find any active meeting for this slot
        $meeting = ScheduledMeeting::where('time_slot_id', $timeSlotId)
            ->when($availabilityDateId, fn($q) => $q->where('availability_date_id', $availabilityDateId))
            ->first();

        if (!$meeting) {
            $log->info('No meeting found for slot', ['time_slot_id' => $timeSlotId]);
            return ['status' => 'no_booking'];
        }

        try {
            /**
             * ======================================================
             * 1. Load token from DATABASE (NOT from JSON file)
             * ======================================================
             */
            $googleToken = GoogleToken::first();
            if (!$googleToken) {
                $log->error('Google token missing');
                return ['status' => 'error', 'message' => 'Google token missing'];
            }

            $accessToken = Crypt::decryptString($googleToken->access_token);
            $refreshToken = $googleToken->refresh_token
                ? Crypt::decryptString($googleToken->refresh_token)
                : null;

            /**
             * ======================================================
             * 2. Initialize Google Client
             * ======================================================
             */
            $client = new Google_Client();
            $client->setClientId(config('google.client_id') ?: env('GOOGLE_CLIENT_ID'));
            $client->setClientSecret(config('google.client_secret') ?: env('GOOGLE_CLIENT_SECRET'));
            $client->setRedirectUri(config('google.redirect_uri'));
            $client->addScope(Google_Service_Calendar::CALENDAR);
            $client->setAccessType('offline');

            // Rebuild token
            $tokenArray = [
                'access_token'  => $accessToken,
                'refresh_token' => $refreshToken,
                'created'       => $googleToken->created_at_timestamp,
                'expires_in'    => null,
            ];

            $client->setAccessToken($tokenArray);

            /**
             * ======================================================
             * 3. Refresh token if expired
             * ======================================================
             */
            if ($client->isAccessTokenExpired()) {

                if (!$refreshToken) {
                    $log->error('Refresh token missing — admin must reauthenticate.');
                    return ['status' => 'error', 'message' => 'Google token expired'];
                }

                $newToken = $client->fetchAccessTokenWithRefreshToken($refreshToken);
                $client->setAccessToken($newToken);

                $created = $newToken['created'] ?? time();
                $expires = ($newToken['expires_in'] ?? 3600) + $created;

                // Save new token to DB
                $googleToken->update([
                    'access_token'          => Crypt::encryptString($newToken['access_token']),
                    'refresh_token'         => isset($newToken['refresh_token'])
                        ? Crypt::encryptString($newToken['refresh_token'])
                        : $googleToken->refresh_token,
                    'expires_at'            => Carbon::createFromTimestamp($expires),
                    'token_type'            => $newToken['token_type'] ?? $googleToken->token_type,
                    'scope'                 => $newToken['scope'] ?? $googleToken->scope,
                    'created_at_timestamp'  => $created,
                ]);

                $log->info('🔄 Admin Google token refreshed');
            }

            /**
             * ======================================================
             * 4. Delete event from Google Calendar
             * ======================================================
             */
            $calendarService = new Google_Service_Calendar($client);

            if (!empty($meeting->event_id)) {
                try {
                    $calendarService->events->delete('primary', $meeting->event_id, ['sendUpdates' => 'all']);

                    $log->info('Deleted Google Calendar event', [
                        'meeting_id' => $meeting->id,
                        'event_id'   => $meeting->event_id
                    ]);
                } catch (\Throwable $th) {
                    $log->warning('Failed to delete event (maybe already deleted)', [
                        'meeting_id' => $meeting->id,
                        'event_id'   => $meeting->event_id,
                        'error'      => $th->getMessage()
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
                'status'     => 'reschedule_needed',
                'meeting_id' => $meeting->id,
            ];
        } catch (\Exception $e) {

            $log->error('Error during booked slot deletion', [
                'time_slot_id' => $timeSlotId,
                'exception'    => $e->getMessage()
            ]);

            return [
                'status'  => 'error',
                'message' => $e->getMessage(),
            ];
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

            $log->info("Reschedule reminder sent to admin for cancelled meeting", [
                'meeting_id' => $meeting->id,
                'admin_email' => $adminEmail
            ]);
        } catch (\Exception $e) {
            $log->error("Failed to send reschedule reminder", [
                'error' => $e->getMessage()
            ]);
        }
    }
}
