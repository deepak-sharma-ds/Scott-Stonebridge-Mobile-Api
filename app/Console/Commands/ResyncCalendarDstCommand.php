<?php

namespace App\Console\Commands;

use App\Models\ScheduledMeeting;
use App\Services\BookingService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ResyncCalendarDstCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:resync-calendar-dst 
                            {--id= : Specific meeting ID to resync} 
                            {--inserted-after=2026-10-25 : Only resync meetings inserted/created after this date (YYYY-MM-DD)}
                            {--dry-run : Preview matched meetings without making Google Calendar API updates}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Resync Google Calendar events to ensure local time alignment across DST clock changes for meetings inserted after 25th Oct 2026';

    /**
     * Execute the console command.
     */
    public function handle(BookingService $bookingService): int
    {
        $tz = config('app.timezone', 'Europe/London');
        $specificId = $this->option('id');
        $isDryRun = (bool) $this->option('dry-run');

        if ($specificId) {
            $meetings = ScheduledMeeting::where('id', $specificId)->get();
        } else {
            $insertedAfter = $this->option('inserted-after') ?: '2026-10-25';
            $cutoff = Carbon::parse($insertedAfter, $tz)->endOfDay();

            $meetings = ScheduledMeeting::where('status', '!=', 'closed')
                ->whereNotNull('event_id')
                ->where('created_at', '>', $cutoff)
                ->orderBy('datetime')
                ->get();
        }

        if ($meetings->isEmpty()) {
            $this->info('No eligible scheduled meetings found to resync.');

            return 0;
        }

        $dryRunPrefix = $isDryRun ? '[DRY RUN] ' : '';
        $this->info("{$dryRunPrefix}Found {$meetings->count()} meeting(s) to resync (timezone: {$tz}):");

        $successCount = 0;
        $failCount = 0;

        foreach ($meetings as $meeting) {
            $this->line("Processing #{$meeting->id} ({$meeting->name}) booked at {$meeting->created_at} for appointment {$meeting->datetime}...");

            if ($isDryRun) {
                $this->info("  [DRY RUN] Would update Google Calendar event '{$meeting->event_id}' to Europe/London local time");
                $successCount++;

                continue;
            }

            $res = $bookingService->resyncMeetingToGoogleCalendar($meeting);

            if (($res['status'] ?? '') === 'success') {
                $this->info("  ✓ Successfully updated to {$res['dateTime']}");
                $successCount++;
            } else {
                $this->warn('  ✗ Failed/Skipped: '.($res['message'] ?? 'Unknown error'));
                $failCount++;
            }
        }

        $this->newLine();
        $this->info("{$dryRunPrefix}Resync complete. Processed: {$successCount} | Failed/Skipped: {$failCount}");

        return 0;
    }
}
