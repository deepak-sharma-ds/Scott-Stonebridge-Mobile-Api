<?php

namespace App\Console\Commands;

use App\Models\AvailabilityDate;
use App\Models\TimeSlot;
use Carbon\Carbon;
use Illuminate\Console\Command;
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
        $log->info('================ START: Generating weekly appointment slots ==================');

        try {
            $availability = config('weekly_appoinment_availability');
            $USER_ID = env('ADMIN_USER_ID'); // Set admin user ID here or load from env if needed

            $startOfWeek = Carbon::now()->startOfWeek(); // Monday
            $endOfWeek   = Carbon::now()->endOfWeek();   // Sunday

            for ($date = $startOfWeek; $date->lte($endOfWeek); $date->addDay()) {
                $dayName = $date->format('l');

                if (empty($availability[$dayName])) {
                    continue;
                }

                // Create or get availability date for user
                $availabilityDate = AvailabilityDate::firstOrCreate([
                    'date' => $date->toDateString(),
                    'user_id' => $USER_ID,
                ]);
                $log->info("Processing {$dayName}, {$date->toDateString()}");
                
                // Create time slots for the day
                foreach ($availability[$dayName] as $slot) {
                    [$start, $end] = $slot;

                    TimeSlot::firstOrCreate([
                        'availability_date_id' => $availabilityDate->id,
                        'start_time' => $start,
                        'end_time' => $end,
                    ]);
                }
                $log->info("Slots created for {$dayName}, {$date->toDateString()}");
            }

            $log->info('✅ Weekly availability slots created successfully!');

            $this->info('✅ Weekly availability slots created successfully!');
        } catch (\Throwable $th) {
            //throw $th;
            $log->error('Error generating weekly appointment slots: ' . $th->getMessage());
            $this->error('❌ Error: ' . $th->getMessage());
        }
    }
}
