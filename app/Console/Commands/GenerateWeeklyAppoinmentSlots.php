<?php

namespace App\Console\Commands;

use App\Models\AvailabilityDate;
use App\Models\TimeSlot;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GenerateWeeklyAppoinmentSlots extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:generate-weekly-appoinment-slots';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This command will generate weekly appoinment slots based on the configuration in config/weekly_appoinment_availability.php';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $log = Log::channel('appointment_slots');
        $log->info('================== START: Generating weekly appointment slots ==================');

        try {
            $availability = config('weekly_appoinment_availability');
            $USER_ID = config('env.ADMIN_USER_ID'); // Admin user ID

            // $startOfWeek = Carbon::now()->startOfWeek(); // Monday
            // $endOfWeek   = Carbon::now()->endOfWeek();   // Sunday
            $startOfWeek = Carbon::now()->startOfMonth();
            $endOfWeek   = Carbon::now()->endOfMonth();

            // Fetch UK bank holidays
            $year = $startOfWeek->year;
            $response = Http::get('https://www.gov.uk/bank-holidays.json');
            $holidays = [];
            if ($response->ok()) {
                $data = $response->json();
                $events = $data['england-and-wales']['events'] ?? [];
                $holidays = collect($events)
                    ->filter(fn($event) => Carbon::parse($event['date'])->year === $year)
                    ->map(fn($event) => $event['date'])
                    ->toArray();
            }
            $log->info('UK Bank Holidays for ' . $year . ': ' . implode(', ', $holidays));

            // Loop through each day of the week
            for ($date = $startOfWeek; $date->lte($endOfWeek); $date->addDay()) {
                $dayName = $date->format('l');
                $dateString = $date->toDateString();

                // Skip if day is a holiday
                if (in_array($dateString, $holidays)) {
                    $log->info("Skipping {$dayName} ({$dateString}) due to UK bank holiday");
                    continue;
                }

                // Skip if no availability configured for the day
                if (empty($availability[$dayName])) {
                    continue;
                }

                // Create or get availability date
                $availabilityDate = AvailabilityDate::firstOrCreate([
                    'date' => $dateString,
                    'user_id' => $USER_ID,
                ]);
                $log->info("Processing {$dayName}, {$dateString}");

                // Create time slots for the day
                foreach ($availability[$dayName] as $slot) {
                    [$start, $end] = $slot;

                    TimeSlot::firstOrCreate([
                        'availability_date_id' => $availabilityDate->id,
                        'start_time' => $start,
                        'end_time' => $end,
                    ]);
                }
                $log->info("Slots created for {$dayName}, {$dateString}");
            }

            $log->info('✅ Weekly availability slots created successfully!');
            $this->info('✅ Weekly availability slots created successfully!');
        } catch (\Throwable $th) {
            $log->error('Error generating weekly appointment slots: ' . $th->getMessage());
            $this->error('❌ Error: ' . $th->getMessage());
        }
    }
}
