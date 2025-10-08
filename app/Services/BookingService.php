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
use Illuminate\Support\Facades\Storage;

class BookingService
{
    public function bookMeeting(array $data)
    {
        Log::info('BookingService::bookMeeting called', ['data' => $data]);

        // find availability date id if you have such table
        $availability = AvailabilityDate::where('date', $data['availability_date'])->first();
        if (!$availability) {
            Log::error('Availability date not found', ['date' => $data['availability_date']]);
            return ['error' => 'Availability date not found.'];
        }
        $availability_id = $availability->id;

        // check if already booked
        $already = ScheduledMeeting::where('availability_date_id', $availability_id)
            ->where('time_slot_id', $data['time_slot_id'])
            ->exists();
        if ($already) {
            Log::warning('Time slot already booked', [
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
                Log::error('Admin Google token file not found');
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
                    Log::info('Admin access token refreshed');
                } else {
                    Log::error('Admin refresh token missing');
                    return ['error' => 'Calendar service requires re-authentication by admin.'];
                }
            }

            // Create calendar service
            $calendarService = new Google_Service_Calendar($client);

            // Get the time slot
            $timeSlot = TimeSlot::find($data['time_slot_id']);
            if (!$timeSlot) {
                Log::error('Time slot not found', ['time_slot_id' => $data['time_slot_id']]);
                return ['error' => 'Selected time slot not found'];
            }

            // Prepare date-times
            $date = Carbon::parse($availability->date)->format('Y-m-d');
            $startTime = Carbon::parse($timeSlot->start_time)->format('H:i:s');
            $endTime   = Carbon::parse($timeSlot->end_time)->format('H:i:s');

            $startDateTime = Carbon::parse("{$date} {$startTime}");
            $endDateTime   = Carbon::parse("{$date} {$endTime}");

            Log::info('Creating Google Calendar event', ['start' => $startDateTime, 'end' => $endDateTime]);

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

            Log::info('Google Calendar event created', ['event_id' => $eventId, 'meet_link' => $meetLink]);

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

            Log::info('ScheduledMeeting record created in DB');

            return [
                'success'     => true,
                'booking'     => $scheduledMeeting,
                'meeting_link'=> $meetLink,
                'event_id'    => $eventId,
                'formatted_time' => $startDateTime->format('F j, Y g:i A'),
            ];

        } catch (\Exception $e) {
            Log::error('Failed to create event or save booking', ['exception' => $e->getMessage()]);
            return [
                'error'   => 'Failed to create event.',
                'message' => $e->getMessage()
            ];
        }
    }
}
