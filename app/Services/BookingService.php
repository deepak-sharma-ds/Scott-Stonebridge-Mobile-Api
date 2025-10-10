<?php

namespace App\Services;

use App\Models\AvailabilityDate;
use App\Models\ScheduledMeeting;
use App\Models\TimeSlot;
use Carbon\Carbon;
use Google_Client;
use Google_Service_Calendar;
use Google_Service_Calendar_Event;
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

        // find availability date id if you have such table
        $availability = AvailabilityDate::where('date', $data['availability_date'])->first();
        if (!$availability) {
            $log->error('Availability date not found', ['date' => $data['availability_date']]);
            return ['error' => 'Availability date not found.'];
        }
        $availability_id = $availability->id;

        // check if already booked
        $already = ScheduledMeeting::where('availability_date_id', $availability_id)
            ->where('time_slot_id', $data['time_slot_id'])
            ->exists();
        if ($already) {
            $log->warning('Time slot already booked', [
                'availability_date_id' => $availability_id,
                'time_slot_id'          => $data['time_slot_id']
            ]);
            return ['error' => 'This time slot is already booked.'];
        }

        try {
            // Initialize Google client with stored admin credentials/token
            $client = new Google_Client();
            $client->setAuthConfig(storage_path('app/credentials.json'));
            $client->addScope(Google_Service_Calendar::CALENDAR);
            $client->setAccessType('offline');
            $client->setPrompt('consent');

            // Load stored admin token
            if (!Storage::disk('local')->exists('admin_google_token.json')) {
                $log->error('Admin Google token file not found');
                return ['error' => 'Calendar service not configured.'];
            }
            $tokenJson = Storage::disk('local')->get('admin_google_token.json');
            $token = json_decode($tokenJson, true);
            $client->setAccessToken($token);

            // Refresh token if expired
            if ($client->isAccessTokenExpired()) {
                if ($client->getRefreshToken()) {
                    $newToken = $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
                    $client->setAccessToken($newToken);
                    // Save updated token back
                    Storage::disk('local')->put('admin_google_token.json', json_encode($newToken));
                    $log->info('Admin access token refreshed');
                } else {
                    $log->error('Admin refresh token missing');
                    return ['error' => 'Calendar service requires re-authentication by admin.'];
                }
            }

            // Create calendar service
            $calendarService = new Google_Service_Calendar($client);

            // Get the time slot
            $timeSlot = TimeSlot::find($data['time_slot_id']);
            if (!$timeSlot) {
                $log->error('Time slot not found', ['time_slot_id' => $data['time_slot_id']]);
                return ['error' => 'Selected time slot not found'];
            }

            // Prepare date-times
            $date = Carbon::parse($availability->date)->format('Y-m-d');
            $startTime = Carbon::parse($timeSlot->start_time)->format('H:i:s');
            $endTime   = Carbon::parse($timeSlot->end_time)->format('H:i:s');

            $startDateTime = Carbon::parse("{$date} {$startTime}");
            $endDateTime   = Carbon::parse("{$date} {$endTime}");

            $log->info('Creating Google Calendar event', ['start' => $startDateTime, 'end' => $endDateTime]);

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
                'attendees'    => [
                    ['email' => $data['email']],
                ],
                'conferenceData' => [
                    'createRequest' => [
                        'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
                        'requestId' => uniqid(),
                    ],
                ],
            ]);

            // Insert event into admin's primary calendar
            $createdEvent = $calendarService->events->insert('primary', $event, ['conferenceDataVersion' => 1]);

            $meetLink = $createdEvent->getHangoutLink();
            $eventId  = $createdEvent->getId();

            $log->info('Google Calendar event created', ['event_id' => $eventId, 'meet_link' => $meetLink]);

            // Save meeting record
            $scheduledMeeting  = ScheduledMeeting::create([
                'user_id'              => null,  // since not tied to a logged-in user
                'name'                 => $data['name'],
                'email'                => $data['email'],
                'phone'                => $data['phone'],
                'datetime'             => $startDateTime,
                'meeting_link'         => $meetLink,
                'event_id'             => $eventId,
                'status'               => 'confirmed',
                'availability_date_id' => $availability_id,
                'time_slot_id'         => $data['time_slot_id'],
            ]);

            $log->info('ScheduledMeeting record created in DB');

            return [
                'success'     => true,
                'booking'     => $scheduledMeeting,
                'meeting_link' => $meetLink,
                'event_id'    => $eventId,
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

    public function handleSlotBookingDeletionOld(int $timeSlotId, ?int $availabilityDateId = null): array
    {
        $log = Log::channel('appointment_slots');
        $log->info('================== START: BookingService::handleSlotBookingDeletion ==================');
        $log->info('Checking for booked meetings before deleting slot', [
            'time_slot_id' => $timeSlotId,
            'availability_date_id' => $availabilityDateId
        ]);

        $meeting = ScheduledMeeting::where('time_slot_id', $timeSlotId)
            ->when($availabilityDateId, fn($q) => $q->where('availability_date_id', $availabilityDateId))
            ->first();

        if (!$meeting) {
            $log->info('No meeting found for slot', ['time_slot_id' => $timeSlotId]);
            return ['status' => 'no_booking'];
        }

        try {
            // Initialize Google Client
            $client = new Google_Client();
            $client->setAuthConfig(storage_path('app/credentials.json'));
            $client->addScope(Google_Service_Calendar::CALENDAR);
            $client->setAccessType('offline');

            // Load token
            if (!Storage::disk('local')->exists('admin_google_token.json')) {
                $log->error('Admin Google token not found');
                return ['status' => 'error', 'message' => 'Google token missing'];
            }

            $token = json_decode(Storage::disk('local')->get('admin_google_token.json'), true);
            $client->setAccessToken($token);

            // Refresh token if expired
            if ($client->isAccessTokenExpired() && $client->getRefreshToken()) {
                $newToken = $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
                Storage::disk('local')->put('admin_google_token.json', json_encode($newToken));
                $client->setAccessToken($newToken);
                $log->info('Admin Google token refreshed');
            }

            $calendarService = new Google_Service_Calendar($client);

            // Cancel event if exists
            if (!empty($meeting->event_id)) {
                $calendarService->events->delete('primary', $meeting->event_id, ['sendUpdates' => 'all']);
                $log->info('Deleted Google Calendar event', [
                    'meeting_id' => $meeting->id,
                    'event_id' => $meeting->event_id
                ]);
            }

            // Mark meeting as "needs_reschedule"
            $meeting->status = 'needs_reschedule';
            $meeting->save();

            $log->info('Meeting marked for reschedule', ['meeting_id' => $meeting->id]);

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

    public function handleSlotBookingDeletion(int $timeSlotId, ?int $availabilityDateId = null): array
    {
        $log = Log::channel('appointment_slots');
        $log->info('================== START: BookingService::handleSlotBookingDeletion ==================');
        $log->info('Checking for booked meetings before deleting slot', [
            'time_slot_id' => $timeSlotId,
            'availability_date_id' => $availabilityDateId
        ]);

        $meeting = ScheduledMeeting::where('time_slot_id', $timeSlotId)
            ->when($availabilityDateId, fn($q) => $q->where('availability_date_id', $availabilityDateId))
            ->first();

        if (!$meeting) {
            $log->info('No meeting found for slot', ['time_slot_id' => $timeSlotId]);
            return ['status' => 'no_booking'];
        }

        try {
            // Initialize Google Client
            $client = new Google_Client();
            $client->setAuthConfig(storage_path('app/credentials.json'));
            $client->addScope(Google_Service_Calendar::CALENDAR);
            $client->setAccessType('offline');

            // Load token
            if (!Storage::disk('local')->exists('admin_google_token.json')) {
                $log->error('Admin Google token not found');
                return ['status' => 'error', 'message' => 'Google token missing'];
            }

            $token = json_decode(Storage::disk('local')->get('admin_google_token.json'), true);
            $client->setAccessToken($token);

            // Refresh token if expired
            if ($client->isAccessTokenExpired() && $client->getRefreshToken()) {
                $newToken = $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
                Storage::disk('local')->put('admin_google_token.json', json_encode($newToken));
                $client->setAccessToken($newToken);
                $log->info('Admin Google token refreshed');
            }

            $calendarService = new Google_Service_Calendar($client);

            // Cancel event if exists
            if (!empty($meeting->event_id)) {
                try {
                    $calendarService->events->delete('primary', $meeting->event_id, ['sendUpdates' => 'all']);
                    $log->info('Deleted Google Calendar event', [
                        'meeting_id' => $meeting->id,
                        'event_id' => $meeting->event_id
                    ]);
                } catch (\Throwable $th) {
                    $log->warning('Failed to delete Google Calendar event, might be already deleted', [
                        'meeting_id' => $meeting->id,
                        'event_id' => $meeting->event_id,
                        'error' => $th->getMessage()
                    ]);
                }
                // Clear event_id to avoid confusion
                $meeting->event_id = null;
            }

            // Mark meeting as "needs_reschedule"
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

    protected static function notifyAdminForReschedule($meeting)
    {
        $log = Log::channel('appointment_slots');
        try {
            $adminEmail = env('ADMIN_EMAIL', 'admin@yourdomain.com');

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
