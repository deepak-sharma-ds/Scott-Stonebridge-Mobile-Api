<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AvailabilityGenerator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AvailabilityGenerationController extends Controller
{
    protected $generator;

    public function __construct(AvailabilityGenerator $generator)
    {
        $this->generator = $generator;
    }

    public function showForm()
    {
        return view('admin.availability_templates.generate'); // blade with dropdowns
    }

    public function generate(Request $request)
    {
        // $request->validate([
        //     'mode' => 'required|in:current_week,week_of_month,entire_month,custom_range',
        //     'month' => 'nullable|integer|min:1|max:12',
        //     'year' => 'nullable|integer|min:1970|max:3100',
        //     'week_number' => 'nullable|integer|min:1|max:5',
        //     'custom_start' => 'nullable|date',
        //     'custom_end' => 'nullable|date|after_or_equal:custom_start',
        // ]);

        $request->validate([
            'mode' => 'required|in:current_week,week_of_month,entire_month,custom_range',

            // Week of month
            'month'       => 'required_if:mode,week_of_month,entire_month|nullable|integer|between:1,12',
            'year'        => 'required_if:mode,week_of_month,entire_month|nullable|integer|min:1970|max:3100',
            'week_number' => 'required_if:mode,week_of_month|nullable|integer|between:1,5',

            // Custom range
            'custom_start' => 'required_if:mode,custom_range|nullable|date',
            'custom_end'   => 'required_if:mode,custom_range|nullable|date|after_or_equal:custom_start',
        ]);


        $mode = $request->mode;
        $userId = Auth::id();
        $now = Carbon::now();

        switch ($mode) {
            case 'current_week':
                $start = $now->copy()->startOfWeek();
                $end   = $now->copy()->endOfWeek();
                break;

            case 'week_of_month':
                $month = $request->month ?? $now->month;
                $year  = $request->year ?? $now->year;
                $weekNumber = (int)($request->week_number ?? 1);

                // find first day of month, then find first monday-of-week block
                $firstOfMonth = Carbon::createFromDate($year, $month, 1);
                // Start of the requested calendar week (weekNumber starts from 1)
                $start = $firstOfMonth->copy()->startOfWeek()->addWeeks($weekNumber - 1);
                $end = $start->copy()->endOfWeek();
                break;

            case 'entire_month':
                $month = $request->month ?? $now->month;
                $year  = $request->year ?? $now->year;
                $start = Carbon::createFromDate($year, $month, 1)->startOfMonth();
                $end = $start->copy()->endOfMonth();
                break;

            case 'custom_range':
                $start = Carbon::parse($request->custom_start);
                $end   = Carbon::parse($request->custom_end);
                break;

            default:
                return back()->with('error', 'Invalid mode');
        }

        $stats = $this->generator->generateForRange($start, $end, $userId);

        return redirect()->route('admin.availability_templates.index')
            ->with('success', "Slots generated for {$start->toDateString()} → {$end->toDateString()}. Created: {$stats['created']} | Skipped holidays: {$stats['skipped_holiday']} | Skipped booked: {$stats['skipped_booked']}");
    }
}
