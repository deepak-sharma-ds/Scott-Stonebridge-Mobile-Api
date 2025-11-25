<?php

namespace App\Services;

use App\Models\AvailabilityDate;
use App\Models\AvailabilityTemplate;
use App\Models\ScheduledMeeting;
use App\Models\TimeSlot;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

class AvailabilityGenerator
{
    protected $log;

    public function __construct()
    {
        $this->log = Log::channel('appointment_slots');
    }

    /**
     * Generate slots for given date range (inclusive) for a given user id
     *
     * @param Carbon $start
     * @param Carbon $end
     * @param int $userId
     * @return array ['created' => int, 'skipped_holiday' => int, 'skipped_existing' => int, 'skipped_booked' => int]
     */
    public function generateForRange(Carbon $start, Carbon $end, int $userId): array
    {
        $this->log->info("Generating availability for user={$userId} from {$start->toDateString()} to {$end->toDateString()}");

        $year = $start->year;
        $holidays = $this->fetchUKBankHolidays($year);

        $templates = AvailabilityTemplate::where('user_id', $userId)
            ->get()
            ->groupBy('day_of_week'); // Monday => collection

        $stats = [
            'created' => 0,
            'skipped_holiday' => 0,
            'skipped_no_template' => 0,
            'skipped_existing_date' => 0,
            'skipped_existing_slot' => 0,
            'skipped_booked' => 0,
        ];

        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $dateStr = $date->toDateString();
            $dayName = $date->format('l');

            // Holidays (skip)
            if (in_array($dateStr, $holidays)) {
                $this->log->info("Skipping {$dateStr} ({$dayName}) - Bank Holiday");
                $stats['skipped_holiday']++;
                continue;
            }

            // No template for the weekday
            if (empty($templates[$dayName]) || $templates[$dayName]->isEmpty()) {
                $stats['skipped_no_template']++;
                continue;
            }

            // Create or get AvailabilityDate
            $availabilityDate = AvailabilityDate::firstOrCreate([
                'date' => $dateStr,
                'user_id' => $userId,
            ]);

            // If there's already a scheduled meeting for this date and template wants to modify slots we'll still attempt only to add missing slots but not delete existing ones.
            $existingSlots = $availabilityDate->timeSlots()->get()->map(fn($s) => "{$s->start_time}-{$s->end_time}")->toArray();

            // For each template slot for this weekday
            foreach ($templates[$dayName] as $template) {
                $slotKey = "{$template->start_time}-{$template->end_time}";

                // Avoid duplicate (exact start+end)
                if (in_array($slotKey, $existingSlots)) {
                    $this->log->info("Skipping creation for {$dateStr} {$slotKey} - already exists");
                    $stats['skipped_existing_slot']++;
                    continue;
                }

                // If any scheduled meeting exists on this date/time (conflict) — skip creating that slot
                $hasBooking = ScheduledMeeting::where('availability_date_id', $availabilityDate->id)
                    ->where('time_slot_id', null) // meeting booked for date but maybe not slot; we check time overlap
                    ->exists();

                // Better check for overlapping bookings on that date (some meetings might store time_slot_id; some may not)
                $overlapBooking = ScheduledMeeting::where('availability_date_id', $availabilityDate->id)
                    ->where(function ($q) use ($template) {
                        // If meeting has time_slot_id then we cannot easily compare; but check by datetime if possible (best-effort)
                        // We assume ScheduledMeeting->datetime column exists and is a timestamp in the same timezone.
                        $q->whereNotNull('datetime')
                            ->whereRaw("TIME(datetime) BETWEEN ? AND ?", [$template->start_time, $template->end_time]);
                    })
                    ->exists();

                if ($hasBooking || $overlapBooking) {
                    $this->log->warning("Skipping {$dateStr} {$slotKey} — existing booking present");
                    $stats['skipped_booked']++;
                    continue;
                }

                // Create new timeslot
                TimeSlot::create([
                    'availability_date_id' => $availabilityDate->id,
                    'start_time' => $template->start_time,
                    'end_time' => $template->end_time,
                ]);

                $this->log->info("Created slot for {$dateStr} {$slotKey}");
                $stats['created']++;
            }
        }

        return $stats;
    }

    /**
     * Fetch UK bank holidays for a given year (england-and-wales)
     * returns array of 'YYYY-MM-DD'
     */
    protected function fetchUKBankHolidays(int $year): array
    {
        try {
            $resp = Http::get('https://www.gov.uk/bank-holidays.json');
            if ($resp->ok()) {
                $data = $resp->json();
                $events = data_get($data, 'england-and-wales.events', []);
                return collect($events)
                    ->filter(fn($e) => Carbon::parse($e['date'])->year === $year)
                    ->map(fn($e) => $e['date'])
                    ->values()
                    ->toArray();
            }
        } catch (\Throwable $e) {
            $this->log->error("Failed to fetch bank holidays: " . $e->getMessage());
        }
        return [];
    }
}
