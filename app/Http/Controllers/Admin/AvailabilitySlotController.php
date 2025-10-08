<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AvailabilityDate;
use App\Models\TimeSlot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Validator;



class AvailabilitySlotController extends Controller
{
    public function index()
    {
        $query = AvailabilityDate::with('timeSlots')->where('user_id', Auth::id())->orderBy('date', 'desc');
        $availability_dates = $query->latest()->paginate(config('Reading.nodes_per_page'));
        return view('admin.availability.index', compact('availability_dates'));
    }

    public function create()
    {
        return view('admin.availability.create');
    }

    public function store(Request $request)
    {

        $validator = Validator::make($request->all(), [
            'date' => 'required|date|unique:availability_dates,date,NULL,id,user_id,' . Auth::id(),
            'time_slots' => 'required|array',
            'time_slots.*.start_time' => 'required',
            'time_slots.*.end_time' => 'required',
        ]);

        $validator->after(function ($validator) use ($request) {
            foreach ($request->input('time_slots', []) as $index => $slot) {
                if (strtotime($slot['end_time']) <= strtotime($slot['start_time'])) {
                    $validator->errors()->add("time_slots.$index.end_time", 'The end time must be after the start time.');
                }
            }
        });

        $validator->validate();

        $request->validate([
            'date' => 'required|date|unique:availability_dates,date,NULL,id,user_id,' . Auth::id(),
            'time_slots' => 'required|array',
            'time_slots.*.start_time' => 'required|date_format:H:i',
            'time_slots.*.end_time' => 'required|date_format:H:i|after:start_time',
        ]);

        $availabilityDate = AvailabilityDate::create([
            'date' => $request->date,
            'user_id' => Auth::id(),
        ]);

        foreach ($request->time_slots as $timeSlot) {
            TimeSlot::create([
                'availability_date_id' => $availabilityDate->id,
                'start_time' => $timeSlot['start_time'],
                'end_time' => $timeSlot['end_time'],
            ]);
        }

        return redirect()->route('admin.availability.index');
    }

    public function edit($id)
    {
        $availabilityDate = AvailabilityDate::with('timeSlots')->findOrFail($id);
        return view('admin.availability.edit', compact('availabilityDate'));
    }

    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'date' => [
                'required',
                'date',
                Rule::unique('availability_dates')->ignore($id)->where(function ($query) {
                    return $query->where('user_id', Auth::id());
                }),
            ],
            'time_slots' => 'required|array',
            'time_slots.*.start_time' => 'required',
            'time_slots.*.end_time' => 'required',
        ]);

        $validator->after(function ($validator) use ($request) {
            foreach ($request->input('time_slots', []) as $index => $slot) {
                if (strtotime($slot['end_time']) <= strtotime($slot['start_time'])) {
                    $validator->errors()->add("time_slots.$index.end_time", 'The end time must be after the start time.');
                }
            }
        });

        $validator->validate();

        $availabilityDate = AvailabilityDate::findOrFail($id);
        $availabilityDate->update(['date' => $request->date]);
    
        // Remove existing time slots and add new ones
        $availabilityDate->timeSlots()->delete();

        foreach ($request->time_slots as $timeSlot) {
            TimeSlot::create([
                'availability_date_id' => $availabilityDate->id,
                'start_time' => $timeSlot['start_time'],
                'end_time' => $timeSlot['end_time'],
            ]);
        }

        return redirect()->route('admin.availability.index');
    }

    public function deleteDate($id)
    {
        $availabilityDate = AvailabilityDate::findOrFail($id);
        $availabilityDate->timeSlots()->delete();
        $availabilityDate->delete();
        return redirect()->route('admin.availability.index')->with('success', 'Availability slot deleted successfully.');
    }

    public function deleteTimeSlot(Request $request, $id)
    {
        $timeSlot = TimeSlot::findOrFail($id);
        $timeSlot->delete();

        return response()->json(['success' => true]);
    }
}
