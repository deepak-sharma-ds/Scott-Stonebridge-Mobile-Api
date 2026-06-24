<?php

namespace App\Http\Controllers;

use App\Models\AvailabilityDate;
use App\Models\ScheduledMeeting;
use App\Models\TimeSlot;
use App\Services\BookingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BookingController extends Controller
{
    public function store(Request $request, BookingService $bookingService)
    {
        if ($request->header('X-App-Secret') !== config('shopify.api_secret')) {
            return response()->json(['error' => 'Unauthorized access'], 403);
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email',
            'phone' => 'required|string',
            'availability_date' => 'required|date',
            'time_slot_id' => 'required|exists:time_slots,id',
            // no google_token required
        ]);

        $result = $bookingService->bookMeeting($data);

        if (! empty($result['success'])) {
            return response()->json($result);
            // $booking = $result['booking'];  // Now this is the ScheduledMeeting model
            // Mail::to($booking->email)->send(new BookingConfirmationMail($booking));
        }

        return response()->json([
            'error' => $result['error'] ?? 'Booking failed',
            'message' => $result['message'] ?? null,
        ], 500);
    }

    public function getTimeSlots(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $date = $request->get('date');
        $availability = AvailabilityDate::where('date', $date)->first();
        if (! $availability) {
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
            'time_slots' => $slotsFormatted,
        ]);
    }

    public function checkAvailableDates(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'month' => ['required', 'date_format:Y-m'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $startDate = Carbon::createFromFormat('Y-m', $request->month)->startOfMonth();
        $endDate = $startDate->copy()->endOfMonth();

        // Get all availability dates with slot counts
        $availabilityDates = AvailabilityDate::withCount('timeSlots')
            ->whereBetween('date', [$startDate, $endDate])
            ->get()
            ->keyBy(fn ($item) => Carbon::parse($item->date)->format('Y-m-d'));

        $dates = [];

        for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {

            $formattedDate = $date->format('Y-m-d');

            $availability = $availabilityDates->get($formattedDate);

            $dates[] = [
                'date' => $formattedDate,
                'available' => $availability && $availability->time_slots_count > 0,
            ];
        }

        return response()->json([
            'success' => true,
            'dates' => $dates,
        ]);
    }
}
