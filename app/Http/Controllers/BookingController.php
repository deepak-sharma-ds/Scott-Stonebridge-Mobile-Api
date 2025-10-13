<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Session;
use Google\Client;
use App\Models\ScheduledMeeting;
use Google\Service\Calendar;
use App\Services\BookingService;
use App\Models\AvailabilityDate;
use App\Models\TimeSlot;
use \Carbon\Carbon;
use App\Mail\BookingConfirmationMail;


class BookingController extends Controller
{

    public function store(Request $request, BookingService $bookingService)
    {
        if ($request->header('X-App-Secret') !== config('shopify.api_secret')) {
            return response()->json(['error' => 'Unauthorized access'], 403);
        }

        $data = $request->validate([
            'name'            => 'required|string|max:255',
            'email'           => 'required|email',
            'phone'           => 'required|string',
            'availability_date' => 'required|date',
            'time_slot_id'    => 'required|exists:time_slots,id',
            // no google_token required
        ]);

        $result = $bookingService->bookMeeting($data);

        if (!empty($result['success'])) {
            return response()->json($result);
            // $booking = $result['booking'];  // Now this is the ScheduledMeeting model
            // Mail::to($booking->email)->send(new BookingConfirmationMail($booking));
        }

        return response()->json([
            'error'   => $result['error'] ?? 'Booking failed',
            'message' => $result['message'] ?? null,
        ], 500);
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
