<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\ScheduledMeeting;
use Spatie\Permission\Models\Role;
use DB;
use Hash;
use Illuminate\Support\Arr;
use Illuminate\View\View;
use App\Mail\SendMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Session;
use Google\Client;
use Google\Service\Calendar;
use Google_Client;
use Google_Service_Calendar;
use Google_Service_Calendar_Event;
use Google_Service_Calendar_EventDateTime;
use App\Models\AvailabilityDate;
use App\Models\GoogleToken;
use App\Models\TimeSlot;
use \Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;

class BookingController extends Controller
{


    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            if (!auth()->check() || !auth()->user()->hasRole('Admin')) {
                abort(403, 'Access denied');
            }
            return $next($request);
        });
    }

    public function index(Request $request)
    {
        try {
            // Start with base query using scopes
            $query = ScheduledMeeting::query();

            // Search by name, email using scope
            if ($request->filled('search')) {
                $query->search($request->search);
            }

            // Filter by date range
            if ($request->filled('date_range')) {
                $dates = explode(' to ', $request->date_range);
                if (count($dates) == 2) {
                    $query->whereDate('datetime', '>=', $dates[0])
                          ->whereDate('datetime', '<=', $dates[1]);
                }
            }

            // Optimized: Eager load relationships to prevent N+1
            $booking_inquiries = $query->withRelations()
                ->latest()
                ->paginate(config('Reading.nodes_per_page'));

            return view('admin.booking_inquiries.index', compact('booking_inquiries'));
        } catch (\Exception $e) {
            report($e);
            return redirect()->route('admin.scheduled-meetings')
                ->with('error', 'Something went wrong while fetching bookings.');
        }
    }

    public function view($id)
    {
        $booking = ScheduledMeeting::findOrFail($id);
        return view('admin.booking_inquiries.view', compact('booking'));
    }

    public function reschedule(Request $request)
    {
        $log = Log::channel('appointment_slots');
        $log->info('================== START: BookingController::reschedule (Admin) ==================');
        $log->info('Rescheduling meeting', ['request_data' => $request->all()]);
        // Validate input
        $data = $request->validate([
            'booking_id' => 'required|exists:scheduled_meetings,id',
            'availability_date' => 'required|date',
            'time_slot_id' => 'required|exists:time_slots,id',
        ]);

        $booking = ScheduledMeeting::findOrFail($data['booking_id']);

        $availability = AvailabilityDate::where('date', $data['availability_date'])->first();
        if (!$availability) {
            return back()->withErrors(['availability_date' => 'Selected date is not available.'])->withInput();
        }

        // Prevent double booking
        $exists = ScheduledMeeting::where('availability_date_id', $availability->id)
            ->where('time_slot_id', $data['time_slot_id'])
            ->where('id', '!=', $booking->id)
            ->where('status', '!=', 'closed')
            ->exists();

        if ($exists) {
            return back()->withErrors(['time_slot_id' => 'This time slot is already taken.'])->withInput();
        }

        try {
            $log->info('Rescheduling booking', ['booking_id' => $booking->id]);
            $calendarService = $this->getGoogleCalendarService();

            $timeSlot = TimeSlot::findOrFail($data['time_slot_id']);
            $date = Carbon::parse($availability->date)->format('Y-m-d');

            $startDateTime = Carbon::createFromFormat('Y-m-d H:i:s', "$date {$timeSlot->start_time}");
            $endDateTime   = Carbon::createFromFormat('Y-m-d H:i:s', "$date {$timeSlot->end_time}");


            if ($booking->status === 'needs_reschedule') {
                // Cancel old event
                if (!empty($booking->event_id)) {
                    try {
                        $log->info('Cancelling old calendar event for rescheduled booking', ['booking_id' => $booking->id, 'event_id' => $booking->event_id]);
                        $calendarService->events->delete('primary', $booking->event_id, ['sendUpdates' => 'all']);
                    } catch (\Throwable $th) {
                        $log->warning('Failed to delete old calendar event', ['booking_id' => $booking->id, 'event_id' => $booking->event_id, 'error' => $th->getMessage()]);
                    }
                }

                // Create new event
                $log->info('Creating new calendar event for rescheduled booking', ['booking_id' => $booking->id]);
                $event = $this->buildCalendarEvent($booking, $startDateTime, $endDateTime);
                $createdEvent = $calendarService->events->insert(
                    'primary',
                    $event,
                    ['conferenceDataVersion' => 1, 'sendUpdates' => 'all']
                );

                $booking->event_id = $createdEvent->getId();
                $booking->meeting_link = $createdEvent->getHangoutLink();
            }
            // else {
            //     // Update existing event
            //     $log->info('Updating existing calendar event for rescheduled booking', ['booking_id' => $booking->id]);
            //     $event = $calendarService->events->get('primary', $booking->event_id);
            //     $event->setStart(new Google_Service_Calendar_EventDateTime([
            //         'dateTime' => $startDateTime->toRfc3339String(),
            //         'timeZone' => 'Asia/Kolkata'
            //     ]));
            //     $event->setEnd(new Google_Service_Calendar_EventDateTime([
            //         'dateTime' => $endDateTime->toRfc3339String(),
            //         'timeZone' => 'Asia/Kolkata'
            //     ]));

            //     $calendarService->events->update('primary', $booking->event_id, $event, ['sendUpdates' => 'all']);
            //     $log->info('Updated Google Calendar event', [
            //         'booking_id' => $booking->id,
            //         'event_id' => $booking->event_id
            //     ]);
            // }
            else {
                $log->info('Rescheduling existing calendar event', [
                    'booking_id' => $booking->id,
                    'event_id'   => $booking->event_id
                ]);

                try {
                    // Try to fetch the existing event
                    $event = $calendarService->events->get('primary', $booking->event_id);

                    // Update existing event
                    $event->setStart(new Google_Service_Calendar_EventDateTime([
                        'dateTime' => $startDateTime->toRfc3339String(),
                        'timeZone' => 'Asia/Kolkata'
                    ]));
                    $event->setEnd(new Google_Service_Calendar_EventDateTime([
                        'dateTime' => $endDateTime->toRfc3339String(),
                        'timeZone' => 'Asia/Kolkata'
                    ]));

                    $calendarService->events->update('primary', $booking->event_id, $event, ['sendUpdates' => 'all']);
                    $log->info('Updated existing Google event', ['event_id' => $booking->event_id]);
                } catch (\Google_Service_Exception $e) {
                    // If event is deleted or missing → Google returns 404
                    if ($e->getCode() == 404) {
                        $log->warning('Event missing — creating new', [
                            'booking_id' => $booking->id,
                            'old_event_id' => $booking->event_id
                        ]);

                        // Create new event
                        $newEvent = $calendarService->events->insert(
                            'primary',
                            $this->buildCalendarEvent($booking, $startDateTime, $endDateTime),
                            ['conferenceDataVersion' => 1, 'sendUpdates' => 'all']
                        );

                        // Update DB with new event id/link
                        $booking->event_id = $newEvent->getId();
                        $booking->meeting_link = $newEvent->getHangoutLink();

                        $log->info('Created new Google event', ['event_id' => $booking->event_id]);
                    } else {
                        throw $e; // unexpected error → rethrow
                    }
                }
            }


            // Update booking record
            $booking->availability_date_id = $availability->id;
            $booking->time_slot_id = $timeSlot->id;
            $booking->datetime = $startDateTime;
            $booking->status = 'rescheduled';
            $booking->save();
            $log->info('Booking rescheduled successfully', ['booking_id' => $booking->id, 'new_datetime' => $booking->datetime]);

            $log->info('================== END: BookingController::reschedule (Admin) ==================');

            return redirect()->route('admin.scheduled-meetings')
                ->with('success', 'Meeting rescheduled successfully!');
        } catch (\Exception $e) {
            $log->error('Error rescheduling event: ' . $e->getMessage());
            return back()->withErrors(['api_error' => 'Error rescheduling event: ' . $e->getMessage()]);
        }
    }

    /**
     * Initialize Google Calendar service
     */
    private function getGoogleCalendarService(): Google_Service_Calendar
    {
        $log = Log::channel('appointment_slots');

        /**
         * ======================================================
         * 1. Load encrypted Google token from DB
         * ======================================================
         */
        $tokenRecord = GoogleToken::first();
        if (!$tokenRecord) {
            $log->warning('Google token not found in database');
            throw new \Exception('Google token not found');
        }

        $accessToken = Crypt::decryptString($tokenRecord->access_token);
        $refreshToken = $tokenRecord->refresh_token
            ? Crypt::decryptString($tokenRecord->refresh_token)
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

        // Build token array for Google client
        $tokenArray = [
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'created'       => $tokenRecord->created_at_timestamp,
            'expires_in'    => null,
        ];

        $client->setAccessToken($tokenArray);

        $log->info('Google token loaded from DB');

        /**
         * ======================================================
         * 3. Refresh if needed
         * ======================================================
         */
        if ($client->isAccessTokenExpired()) {

            if (!$refreshToken) {
                $log->error('Refresh token missing — admin must reauthenticate.');
                throw new \Exception('Google Calendar authentication expired');
            }

            $log->warning('Google token expired — refreshing…');

            $newToken = $client->fetchAccessTokenWithRefreshToken($refreshToken);
            $client->setAccessToken($newToken);

            // Compute new expiry
            $created = $newToken['created'] ?? time();
            $expires = ($newToken['expires_in'] ?? 3600) + $created;

            // Update database record
            $tokenRecord->update([
                'access_token'          => Crypt::encryptString($newToken['access_token']),
                'refresh_token'         => isset($newToken['refresh_token'])
                    ? Crypt::encryptString($newToken['refresh_token'])
                    : $tokenRecord->refresh_token,
                'expires_at'            => \Carbon\Carbon::createFromTimestamp($expires),
                'token_type'            => $newToken['token_type'] ?? $tokenRecord->token_type,
                'scope'                 => $newToken['scope'] ?? $tokenRecord->scope,
                'created_at_timestamp'  => $created,
            ]);

            $log->info('Google token refreshed and updated in DB');
        }

        /**
         * ======================================================
         * 4. Return Calendar Service
         * ======================================================
         */
        return new Google_Service_Calendar($client);
    }


    /**
     * Build Google Calendar Event
     */
    private function buildCalendarEvent(ScheduledMeeting $booking, Carbon $start, Carbon $end): Google_Service_Calendar_Event
    {
        Log::channel('appointment_slots')->info('Building calendar event for booking', ['booking_id' => $booking->id]);
        return new Google_Service_Calendar_Event([
            'summary' => 'Meeting with ' . $booking->name,
            'description' => "Booking rescheduled.\nPhone: " . $booking->phone,
            'start' => ['dateTime' => $start->toRfc3339String(), 'timeZone' => 'Asia/Kolkata'],
            'end' => ['dateTime' => $end->toRfc3339String(), 'timeZone' => 'Asia/Kolkata'],
            'attendees' => [['email' => $booking->email]],
            'conferenceData' => [
                'createRequest' => ['conferenceSolutionKey' => ['type' => 'hangoutsMeet'], 'requestId' => uniqid()]
            ],
        ]);
    }



    public function cancel(Request $request)
    {
        try {
            $data = $request->validate([
                'booking_id' => 'required|exists:scheduled_meetings,id',
            ]);
            $booking = ScheduledMeeting::where('id', $data['booking_id'])->delete();
            return redirect()->route('admin.scheduled-meetings')->with('success', 'Meeting closed successfully!');
        } catch (\Exception $e) {
            return back()->withErrors(['api_error' => 'Error canceling event: ' . $e->getMessage()]);
        }
    }

    public function getTimeSlots(Request $request)
    {
        $date = $request->get('date');
        $availability = AvailabilityDate::where('date', $date)->first();
        
        if (!$availability) {
            return response()->json(['success' => true, 'time_slots' => []]);
        }

        // Optimized: Get all data in one query
        $timeSlots = TimeSlot::where('availability_date_id', $availability->id)->get();

        // Get booked slot IDs efficiently
        $bookedSlotIds = ScheduledMeeting::active()
            ->where('availability_date_id', $availability->id)
            ->pluck('time_slot_id')
            ->toArray();

        // Format slots
        $slotsFormatted = $timeSlots->map(function ($slot) use ($bookedSlotIds) {
            return [
                'id' => $slot->id,
                'start_time' => Carbon::parse($slot->start_time)->format('H:i'),
                'end_time' => Carbon::parse($slot->end_time)->format('H:i'),
                'booked' => in_array($slot->id, $bookedSlotIds),
            ];
        });

        return response()->json([
            'success' => true,
            'time_slots' => $slotsFormatted
        ]);
    }

    public function adminGoogleAuth(Request $request)
    {
        try {

            // Extract code and state parameters sent by Google
            $code = $request->query('code');
            $state = $request->query('state');

            if (!$code) {
                return response()->json(['error' => 'Authorization code missing'], 400);
            }

            // Initialize Google Client
            $client = new \Google_Client();
            $client->setClientId(config('google.client_id') ?: env('GOOGLE_CLIENT_ID'));
            $client->setClientSecret(config('google.client_secret') ?: env('GOOGLE_CLIENT_SECRET'));
            $client->setRedirectUri(config('google.redirect_uri'));
            $client->addScope(config('google.scopes'));
            $client->setAccessType('offline');
            $client->setPrompt('consent');

            try {
                $token = $client->fetchAccessTokenWithAuthCode($code);
            } catch (\Exception $e) {
                Log::error('Admin OAuth Token Fetch Failed', ['exception' => $e->getMessage()]);
                return response()->json(['error' => 'Failed to fetch admin token'], 500);
            }

            if (isset($token['error'])) {
                return response()->json(['error' => $token['error_description'] ?? 'Unknown error'], 400);
            }

            // Store token inside DB securely
            $created  = $token['created'] ?? time();
            $expiresIn = $token['expires_in'] ?? 3600;
            $expiresAt = \Carbon\Carbon::createFromTimestamp($created + $expiresIn);

            \App\Models\GoogleToken::updateOrCreate(
                ['id' => 1],
                [
                    'access_token'          => Crypt::encryptString($token['access_token']),
                    'refresh_token'         => isset($token['refresh_token'])
                        ? Crypt::encryptString($token['refresh_token'])
                        : null,
                    'expires_at'            => $expiresAt,
                    'token_type'            => $token['token_type'] ?? null,
                    'scope'                 => $token['scope'] ?? null,
                    'created_at_timestamp'  => $created,
                ]
            );

            return response()->json([
                'message' => 'Admin Google Calendar token stored successfully',
                'token_info' => $token
            ]);
        } catch (\Throwable $th) {
            //throw $th;
            dd($th);
        }
    }
}
