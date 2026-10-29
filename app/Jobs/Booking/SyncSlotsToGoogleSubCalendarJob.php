<?php

namespace App\Jobs\Booking;

use App\Models\AvailabilityDate;
use App\Models\TimeSlot;
use App\Services\BookingService;
use Carbon\Carbon;
use Google\Service\Exception as GoogleServiceException;
use Google_Service_Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncSlotsToGoogleSubCalendarJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 5;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public array $backoff = [30, 60, 120, 300];

    /**
     * Create a new job instance.
     */
    public function __construct(
        public ?string $startDate = null,
        public ?string $endDate = null,
        public array $slotIdsToProject = [],
        public array $slotIdsToDelete = [],
    ) {}

    /**
     * Execute the job.
     */
    public function handle(BookingService $bookingService): void
    {
        $log = Log::channel('appointment_slots');
        $log->info('SyncSlotsToGoogleSubCalendarJob: Starting sync', [
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'slotIdsToProject' => $this->slotIdsToProject,
            'slotIdsToDelete' => $this->slotIdsToDelete,
        ]);

        $slotsCalendarId = $bookingService->getSlotsCalendarId();
        if (! $slotsCalendarId) {
            $log->warning('SyncSlotsToGoogleSubCalendarJob: No secondary slots calendar configured. Aborting sync.');

            return;
        }

        try {
            // 1. Prune / delete placeholders
            if (! empty($this->slotIdsToDelete)) {
                foreach (array_chunk($this->slotIdsToDelete, 50) as $chunk) {
                    foreach ($chunk as $slotId) {
                        $bookingService->removeSlotPlaceholder((int) $slotId, $slotsCalendarId);
                    }
                }
            }

            // 2. Project specific slot IDs
            if (! empty($this->slotIdsToProject)) {
                foreach (array_chunk($this->slotIdsToProject, 50) as $chunk) {
                    $slots = TimeSlot::with('availabilityDate')
                        ->whereIn('id', $chunk)
                        ->whereDoesntHave('scheduledMeetings', function ($query) {
                            $query->whereIn('status', ['confirmed', 'blocked_by_calendar']);
                        })
                        ->get();

                    foreach ($slots as $slot) {
                        if ($slot->availabilityDate) {
                            $bookingService->projectSlotPlaceholder($slot, $slot->availabilityDate, $slotsCalendarId);
                        }
                    }
                }
            }

            // 3. Project date range if provided
            if ($this->startDate && $this->endDate) {
                $today = Carbon::today(config('app.timezone', 'Europe/London'))->toDateString();
                $effectiveStart = max($this->startDate, $today);

                if ($effectiveStart <= $this->endDate) {
                    $dates = AvailabilityDate::whereDate('date', '>=', $effectiveStart)
                        ->whereDate('date', '<=', $this->endDate)
                        ->with(['timeSlots' => function ($query) {
                            $query->whereDoesntHave('scheduledMeetings', function ($q) {
                                $q->whereIn('status', ['confirmed', 'blocked_by_calendar']);
                            });
                        }])
                        ->get();

                    $allSlots = [];
                    foreach ($dates as $date) {
                        foreach ($date->timeSlots as $slot) {
                            $allSlots[] = ['slot' => $slot, 'date' => $date];
                        }
                    }

                    foreach (array_chunk($allSlots, 50) as $chunk) {
                        foreach ($chunk as $item) {
                            $bookingService->projectSlotPlaceholder($item['slot'], $item['date'], $slotsCalendarId);
                        }
                    }
                }
            }

            $log->info('SyncSlotsToGoogleSubCalendarJob: Finished sync successfully');
        } catch (\Throwable $e) {
            $log->error('SyncSlotsToGoogleSubCalendarJob error: '.$e->getMessage());

            if ($this->isRateLimited($e)) {
                $log->warning('SyncSlotsToGoogleSubCalendarJob: Rate limited by Google. Releasing back to queue for 30s.');
                $this->release(30);

                return;
            }

            throw $e;
        }
    }

    /**
     * Check if exception is a rate limit or quota exceeded error.
     */
    protected function isRateLimited(\Throwable $e): bool
    {
        if ($e->getCode() === 429) {
            return true;
        }

        if ($e instanceof Google_Service_Exception || $e instanceof GoogleServiceException) {
            if ($e->getCode() === 403 && str_contains(strtolower($e->getMessage()), 'ratelimitexceeded')) {
                return true;
            }
        }

        return false;
    }
}
