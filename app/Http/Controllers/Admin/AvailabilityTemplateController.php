<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AvailabilityTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class AvailabilityTemplateController extends Controller
{
    public function index()
    {
        $templates = AvailabilityTemplate::where('user_id', Auth::id())
            ->orderByRaw("FIELD(day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday')")
            ->get()
            ->groupBy('day_of_week');

        return view('admin.availability_templates.index', compact('templates'));
    }

    public function create()
    {
        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        return view('admin.availability_templates.create', compact('days'));
    }

    public function store(Request $request)
    {
        $data = $request->all();

        // Expecting 'templates' => [ 'Monday' => [['start'=>'09:00','end'=>'12:00'], ...], ... ]
        $validator = Validator::make($data, [
            'templates' => 'required|array',
        ]);

        $validator->validate();

        // Remove existing templates for user then re-create for simplicity
        AvailabilityTemplate::where('user_id', Auth::id())->delete();

        foreach ($data['templates'] as $day => $slots) {
            foreach ($slots as $slot) {
                if (empty($slot['start']) || empty($slot['end'])) continue;
                if (strtotime($slot['end']) <= strtotime($slot['start'])) continue; // skip invalid
                AvailabilityTemplate::create([
                    'user_id' => Auth::id(),
                    'day_of_week' => $day,
                    'start_time' => $slot['start'],
                    'end_time' => $slot['end'],
                ]);
            }
        }

        return redirect()->route('admin.availability_templates.index')->with('success', 'Templates saved.');
    }

    // optional edit/show not strictly necessary due to create/save all pattern
    public function destroy($id)
    {
        $tpl = AvailabilityTemplate::findOrFail($id);
        $tpl->delete();
        return back()->with('success', 'Template deleted.');
    }
}
