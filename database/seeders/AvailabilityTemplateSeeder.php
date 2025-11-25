<?php

namespace Database\Seeders;

use App\Models\AvailabilityTemplate;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class AvailabilityTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Load your config file
        $weeklyConfig = config('weekly_appoinment_availability');

        $userId = config('env.ADMIN_USER_ID');

        foreach ($weeklyConfig as $day => $slots) {
            foreach ($slots as $slot) {

                // $slot = ['19:00', '19:30']
                AvailabilityTemplate::firstOrCreate([
                    'user_id'     => $userId,
                    'day_of_week' => $day,
                    'start_time'  => $slot[0],
                    'end_time'    => $slot[1],
                ]);
            }
        }
    }
}
