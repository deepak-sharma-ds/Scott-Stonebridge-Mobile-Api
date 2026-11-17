<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Booking\ProcessGoogleCalendarSyncJob;
use App\Services\BookingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class CalendarSyncDeltaCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'calendar:sync-delta {--now : Force synchronous inline execution via dispatchSync}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run incremental delta sync against Google Calendar to reconcile any missed webhook events';

    /**
     * Execute the console command.
     */
    public function handle(BookingService $bookingService): int
    {
        $log = Log::channel('appointment_slots');
        $this->info('Starting Google Calendar 30-minute incremental delta sync...');

        $client = $bookingService->getGoogleClient();
        if (! $client) {
            $this->warn('Google Calendar credentials missing or expired. Skipping delta sync.');
            $log->warning('CalendarSyncDeltaCommand: Google client unavailable');

            return self::SUCCESS;
        }

        try {
            if ($this->option('now')) {
                ProcessGoogleCalendarSyncJob::dispatchSync();
                $this->info('Delta sync executed synchronously inline.');
            } else {
                ProcessGoogleCalendarSyncJob::dispatch();
                $this->info('Delta sync job dispatched to background queue.');
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Failed executing delta sync: '.$e->getMessage());
            $log->error('CalendarSyncDeltaCommand failed: '.$e->getMessage(), ['exception' => $e]);

            return self::FAILURE;
        }
    }
}
