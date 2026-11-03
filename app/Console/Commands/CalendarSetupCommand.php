<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AvailabilityDate;
use App\Services\BookingService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CalendarSetupCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'calendar:setup {--force : Force rediscovery or recreation of sub-calendar}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Provision Google Available Slots sub-calendar and backfill unbooked slot placeholders';

    /**
     * Execute the console command.
     */
    public function handle(BookingService $bookingService): int
    {
        $this->info('Validating Google Calendar credentials...');

        $client = $bookingService->getGoogleClient();
        if (! $client) {
            $this->error('Google Calendar credentials missing or expired. Please re-authenticate at /admin/google-calendar/auth or run google:admin-login.');

            return self::FAILURE;
        }

        $this->info("Locating or provisioning 'Stonebridge - Available Slots' sub-calendar...");

        $calendarId = $bookingService->getOrCreateSubCalendar(
            'Stonebridge - Available Slots',
            config('app.timezone', 'Europe/London')
        );

        if (! $calendarId) {
            $this->error('Failed to locate or provision Google Sub-Calendar.');

            return self::FAILURE;
        }

        $this->info("Sub-calendar connected: {$calendarId}");

        $today = Carbon::today(config('app.timezone', 'Europe/London'))->format('Y-m-d');

        $dates = AvailabilityDate::where('date', '>=', $today)
            ->with(['timeSlots' => function ($query) {
                $query->whereDoesntHave('scheduledMeetings', function ($q) {
                    $q->whereIn('status', ['confirmed', 'blocked_by_calendar']);
                });
            }])
            ->orderBy('date')
            ->get();

        $count = 0;
        foreach ($dates as $date) {
            foreach ($date->timeSlots as $slot) {
                $eventId = $bookingService->projectSlotPlaceholder($slot, $date, $calendarId);
                if ($eventId) {
                    $count++;
                }
            }
        }

        $this->info("Successfully backfilled {$count} slot placeholder(s) to 'Stonebridge - Available Slots'.");

        return self::SUCCESS;
    }
}
