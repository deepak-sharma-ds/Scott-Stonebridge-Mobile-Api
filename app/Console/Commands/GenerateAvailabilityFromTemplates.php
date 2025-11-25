<?php

namespace App\Console\Commands;

use App\Models\AvailabilityTemplate;
use App\Services\AvailabilityGenerator;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateAvailabilityFromTemplates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:generate-availability {--user_id= : User ID (optional)} {--month= : month (1-12)} {--year= : year}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate availability using templates for admin(s)';

    protected $generator;

    public function __construct(AvailabilityGenerator $generator)
    {
        parent::__construct();
        $this->generator = $generator;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $userId = $this->option('user_id'); // if null run for all users with templates

        $month = $this->option('month') ?? now()->month;
        $year = $this->option('year') ?? now()->year;

        $start = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        if ($userId) {
            $this->info("Generating availability for user: {$userId} for {$start->toDateString()} - {$end->toDateString()}");
            $stats = $this->generator->generateForRange($start, $end, (int)$userId);
            $this->info("Created: {$stats['created']}");
            return 0;
        }

        // Determine all users who have templates
        $users = AvailabilityTemplate::select('user_id')->distinct()->pluck('user_id');
        foreach ($users as $u) {
            $this->info("User {$u}:");
            $stats = $this->generator->generateForRange($start, $end, (int)$u);
            $this->info("  Created: {$stats['created']}, Skipped holidays: {$stats['skipped_holiday']}");
        }

        return 0;
    }
}
