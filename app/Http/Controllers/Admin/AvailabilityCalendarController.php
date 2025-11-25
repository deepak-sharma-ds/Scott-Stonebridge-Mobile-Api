<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AvailabilityDate;
use App\Models\TimeSlot;
use App\Models\ScheduledMeeting;
use App\Services\BookingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class AvailabilityCalendarController extends Controller
{
    protected BookingService $bookingService;

    public function __construct(BookingService $bookingService)
    {
        $this->bookingService = $bookingService;
    }

    /**
     * Show calendar blade
     */
    public function index()
    {
        return view('admin.availability.calendar');
    }

    /**
     * Return events for FullCalendar (days that have availability)
     */
    public function events(Request $request)
    {
        $userId = Auth::id();

        $dates = AvailabilityDate::withCount('timeSlots')
            ->where('user_id', $userId)
            ->get()
            ->map(fn($d) => [
                'title' => $d->time_slots_count . ' slot' . ($d->time_slots_count > 1 ? 's' : ''),
                'start' => $d->date->toDateString(),
                'allDay' => true,
                'extendedProps' => [
                    'date' => $d->date->toDateString(),
                    'slots_count' => $d->time_slots_count,
                ]
            ]);

        return response()->json($dates);
    }

    /**
     * Get detailed slots for a date
     */
    public function day($date)
    {
        $userId = Auth::id();

        // Validate date
        try {
            $d = Carbon::parse($date)->toDateString();
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Invalid date'], 422);
        }

        $availability = AvailabilityDate::with('timeSlots')
            ->where('user_id', $userId)
            ->whereDate('date', $d)
            ->first();

        if (! $availability) {
            return response()->json([
                'success' => true,
                'exists' => false,
                'date' => $d,
                'slots' => []
            ]);
        }

        $slots = $availability->timeSlots->map(fn($s) => [
            'id' => $s->id,
            'start_time' => substr($s->start_time, 0, 5),
            'end_time' => substr($s->end_time, 0, 5),
        ])->values();

        return response()->json([
            'success' => true,
            'exists' => true,
            'date' => $d,
            'availability_date_id' => $availability->id,
            'slots' => $slots
        ]);
    }

    /**
     * Create or update slots for a date.
     * We respect "do nothing until admin clicks add" by simply creating the date only when this endpoint is called.
     *
     * Payload:
     *  time_slots => [ ['start_time'=>'09:00','end_time'=>'10:00'], ... ]
     */
    public function storeDay(Request $request, $date)
    {
        $userId = Auth::id();

        try {
            $date = Carbon::parse($date)->toDateString();
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Invalid date'], 422);
        }

        $validator = Validator::make($request->all(), [
            'time_slots' => 'required|array|min:1',
            'time_slots.*.start_time' => 'required|date_format:H:i',
            'time_slots.*.end_time' => 'required|date_format:H:i|after:time_slots.*.start_time',
        ]);

        // Additional validation: ensure end > start per slot
        $validator->after(function ($v) use ($request) {
            foreach ($request->input('time_slots', []) as $index => $slot) {
                if (isset($slot['start_time'], $slot['end_time']) && strtotime($slot['end_time']) <= strtotime($slot['start_time'])) {
                    $v->errors()->add("time_slots.{$index}.end_time", 'End time must be after start time.');
                }
            }
        });

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        // Find or create availability_date for this user and date
        $availabilityDate = AvailabilityDate::firstOrCreate([
            'user_id' => $userId,
            'date' => $date,
        ]);

        // IMPORTANT: We will replace existing timeslots with the posted list.
        // But first, prevent deleting slots that have booked meetings by handling them separately.
        $existingSlots = $availabilityDate->timeSlots()->get();

        // We'll compute which existing slot IDs are being removed
        $postedSlotsNormalized = [];
        foreach ($request->input('time_slots') as $slot) {
            $postedSlotsNormalized[] = sprintf('%s-%s', $slot['start_time'], $slot['end_time']);
        }

        $toDelete = [];
        foreach ($existingSlots as $es) {
            $key = sprintf('%s-%s', substr($es->start_time, 0, 5), substr($es->end_time, 0, 5));
            if (! in_array($key, $postedSlotsNormalized)) {
                $toDelete[] = $es;
            }
        }

        // For each slot to delete, ensure no booked meeting exists (time_slot_id usage)
        foreach ($toDelete as $es) {
            $meetingExists = ScheduledMeeting::where('time_slot_id', $es->id)
                ->when($es->availability_date_id, fn($q) => $q->where('availability_date_id', $es->availability_date_id))
                ->exists();

            if ($meetingExists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot remove one or more existing slots because they have booked meetings. Please reschedule bookings first.',
                ], 409);
            }
        }

        // Now safe to delete removed slots
        foreach ($toDelete as $es) {
            // call bookingService just in case (preserve existing logic)
            $this->bookingService->handleSlotBookingDeletion($es->id, $es->availability_date_id);
            $es->delete();
        }

        // Create any new slots that don't already exist
        $existingKeys = $availabilityDate->timeSlots()->get()->map(fn($s) => sprintf('%s-%s', substr($s->start_time, 0, 5), substr($s->end_time, 0, 5)))->toArray();

        $created = 0;
        foreach ($request->input('time_slots') as $slot) {
            $key = sprintf('%s-%s', $slot['start_time'], $slot['end_time']);
            if (in_array($key, $existingKeys)) continue;

            TimeSlot::create([
                'availability_date_id' => $availabilityDate->id,
                'start_time' => $slot['start_time'],
                'end_time' => $slot['end_time'],
            ]);
            $created++;
        }

        return response()->json([
            'success' => true,
            'message' => "Saved. Slots created: {$created}",
            'created' => $created,
        ]);
    }

    /**
     * Delete a timeslot (keeps booking checks / reschedule)
     */
    public function deleteSlot($id)
    {
        $timeSlot = TimeSlot::findOrFail($id);

        // Ensure the slot belongs to the logged-in user
        if ($timeSlot->availabilityDate->user_id !== Auth::id()) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        // Check booked meetings
        $meeting = ScheduledMeeting::where('time_slot_id', $timeSlot->id)
            ->when($timeSlot->availability_date_id, fn($q) => $q->where('availability_date_id', $timeSlot->availability_date_id))
            ->exists();

        if ($meeting) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete this slot as it has booked meetings. Please reschedule or cancel the bookings first.'
            ], 409);
        }

        $result = $this->bookingService->handleSlotBookingDeletion($timeSlot->id, $timeSlot->availability_date_id);

        $timeSlot->delete();

        return response()->json([
            'success' => true,
            'message' => match ($result['status'] ?? 'deleted') {
                'reschedule_needed' => 'Slot deleted. Existing booking marked for reschedule.',
                'no_booking' => 'Slot deleted successfully.',
                'error' => 'Slot deleted, but there was an issue handling booked meetings.',
                default => 'Slot deleted.',
            },
            'result' => $result,
        ]);
    }

    /**
     * Delete entire date (only if no scheduled meetings)
     */
    public function deleteDay($date)
    {
        try {
            $date = Carbon::parse($date)->toDateString();
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Invalid date'], 422);
        }

        $userId = Auth::id();

        $availability = AvailabilityDate::where('user_id', $userId)
            ->whereDate('date', $date)
            ->first();

        if (! $availability) {
            return response()->json(['success' => false, 'message' => 'Date not found'], 404);
        }

        $hasMeetings = ScheduledMeeting::where('availability_date_id', $availability->id)->exists();

        if ($hasMeetings) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete this date as it has booked meetings. Please reschedule or cancel the bookings first.'
            ], 409);
        }

        // safe delete
        $availability->timeSlots()->delete();
        $availability->delete();

        return response()->json(['success' => true, 'message' => 'Date deleted']);
    }
}
