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
use App\Models\TimeSlot;
use \Carbon\Carbon;

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
            $query = ScheduledMeeting::query();

            // Search by name, email or order number
            if ($request->filled('search')) {
                $query->where(function ($q) use ($request) {
                    $q->where('name', 'like', '%' . $request->search . '%')
                        ->orWhere('email', 'like', '%' . $request->search . '%');
                });
            }

            // Filter by date range
            if ($request->filled('date_range')) {
                $dates = explode(' to ', $request->date_range);
                if (count($dates) == 2) {
                    $from = $dates[0];
                    $to = $dates[1];

                    $query->whereDate('datetime', '>=', $from)
                        ->whereDate('datetime', '<=', $to);
                }
            }
            // Get filtered results
            $booking_inquiries = $query->latest()->paginate(config('Reading.nodes_per_page'));
            // Get distinct statuses for the filter dropdown
            return view('admin.booking_inquiries.index', compact('booking_inquiries'));
        } catch (\Exception $e) {
            return redirect()->route('admin.scheduled-meetings')
                ->with('error', 'Something went wrong while fetching the order.');
        }
    }

    public function view($id)
    {
        $booking = ScheduledMeeting::findOrFail($id);
        return view('admin.booking_inquiries.view', compact('booking'));
    }

    public function rescheduleOld(Request $request)
    {
        // Validate new inputs
        $data = $request->validate([
            'booking_id' => 'required|exists:scheduled_meetings,id',
            'availability_date' => 'required|date',
            'time_slot_id' => 'required|exists:time_slots,id',
        ]);

        // Find booking
        $booking = ScheduledMeeting::findOrFail($data['booking_id']);

        // Find availability date record
        $availability = AvailabilityDate::where('date', $data['availability_date'])->first();
        if (!$availability) {
            return back()->withErrors(['availability_date' => 'Selected date is not available.'])->withInput();
        }

        $availability_id = $availability->id;
        $timeSlotId = $data['time_slot_id'];

        // Double booking prevention on admin side
        $exists = ScheduledMeeting::where('availability_date_id', $availability_id)
            ->where('time_slot_id', $timeSlotId)
            ->where('id', '!=', $booking->id)  // allow booking to change itself
            ->where('status', '!=', 'closed')
            ->exists();

        if ($exists) {
            return back()->withErrors(['time_slot_id' => 'This time slot is already taken. Please choose another.'])->withInput();
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
                return back()->withErrors(['api_error' => 'Calendar service not configured properly.']);
            }
            $tokenJson = Storage::disk('local')->get('admin_google_token.json');
            $token = json_decode($tokenJson, true);
            $client->setAccessToken($token);

            // Refresh token if expired
            if ($client->isAccessTokenExpired()) {
                if ($client->getRefreshToken()) {
                    $newToken = $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
                    $client->setAccessToken($newToken);
                    Storage::disk('local')->put('admin_google_token.json', json_encode($newToken));
                } else {
                    return back()->withErrors(['api_error' => 'Calendar service requires re-authentication by admin.']);
                }
            }

            // retrieve time slot and availability for new date/time
            $timeSlot = TimeSlot::findOrFail($timeSlotId);

            $date = Carbon::parse($availability->date)->format('Y-m-d');
            $startTime = Carbon::parse($timeSlot->start_time)->format('H:i:s');
            $endTime = Carbon::parse($timeSlot->end_time)->format('H:i:s');

            $startDateTime = Carbon::parse("$date $startTime");
            $endDateTime = Carbon::parse("$date $endTime");

            $calendarService = new Google_Service_Calendar($client);

            // fetch the event
            $event = $calendarService->events->get('primary', $booking->event_id);

            // update times
            $event->setStart(new Google_Service_Calendar_EventDateTime([
                'dateTime' => $startDateTime->toRfc3339String(),
                'timeZone' => 'Asia/Kolkata'
            ]));
            $event->setEnd(new Google_Service_Calendar_EventDateTime([
                'dateTime' => $endDateTime->toRfc3339String(),
                'timeZone' => 'Asia/Kolkata'
            ]));

            $updatedEvent = $calendarService->events->update('primary', $booking->event_id, $event);

            // update scheduled meeting record
            $booking->availability_date_id = $availability_id;
            $booking->time_slot_id = $timeSlotId;
            $booking->datetime = $startDateTime;
            $booking->status = 'rescheduled';
            $booking->save();

            return redirect()->route('admin.scheduled-meetings')
                ->with('success', 'Meeting rescheduled successfully!');
        } catch (\Exception $e) {
            return back()->withErrors(['api_error' => 'Error rescheduling event: ' . $e->getMessage()]);
        }
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
            } else {
                // Update existing event
                $log->info('Updating existing calendar event for rescheduled booking', ['booking_id' => $booking->id]);
                $event = $calendarService->events->get('primary', $booking->event_id);
                $event->setStart(new Google_Service_Calendar_EventDateTime([
                    'dateTime' => $startDateTime->toRfc3339String(),
                    'timeZone' => 'Asia/Kolkata'
                ]));
                $event->setEnd(new Google_Service_Calendar_EventDateTime([
                    'dateTime' => $endDateTime->toRfc3339String(),
                    'timeZone' => 'Asia/Kolkata'
                ]));

                $calendarService->events->update('primary', $booking->event_id, $event, ['sendUpdates' => 'all']);
                $log->info('Updated Google Calendar event', [
                    'booking_id' => $booking->id,
                    'event_id' => $booking->event_id
                ]);
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
        $client = new Google_Client();
        $client->setAuthConfig(storage_path('app/credentials.json'));
        $client->addScope(Google_Service_Calendar::CALENDAR);
        $client->setAccessType('offline');
        $client->setPrompt('consent');

        if (!Storage::disk('local')->exists('admin_google_token.json')) {
            Log::channel('appointment_slots')->warning('Google token not found');
            throw new \Exception('Google token not found');
        }

        $token = json_decode(Storage::disk('local')->get('admin_google_token.json'), true);
        $client->setAccessToken($token);
        Log::channel('appointment_slots')->info('Google token loaded');

        if ($client->isAccessTokenExpired() && $client->getRefreshToken()) {
            $newToken = $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
            $client->setAccessToken($newToken);
            Storage::disk('local')->put('admin_google_token.json', json_encode($newToken));
            Log::channel('appointment_slots')->info('Google token refreshed');
        }

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

        // get all slots for that date
        $timeSlots = TimeSlot::where('availability_date_id', $availability->id)->get();

        // get booked slot ids for that date
        $bookedSlotIds = ScheduledMeeting::where('availability_date_id', $availability->id)
            ->where('status', '!=', 'closed')
            ->pluck('time_slot_id')
            ->toArray();

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
}
