<?php

declare(strict_types=1);

namespace App\Jobs\Booking;

use App\Mail\CalendarCollisionAlertMail;
use App\Models\AvailabilityDate;
use App\Models\Configuration;
use App\Models\ScheduledMeeting;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\BookingService;
use Carbon\Carbon;
use Google\Service\Exception;
use Google_Service_Calendar;
use Google_Service_Calendar_Event;
use Google_Service_Calendar_EventDateTime;
use Google_Service_Calendar_EventExtendedProperties;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ProcessGoogleCalendarSyncJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public ?string $channelId = null,
        public ?string $resourceId = null,
        public ?string $targetCalendarId = null,
    ) {}

    public function handle(BookingService $bookingService): void
    {
        $log = Log::channel('appointment_slots');
        $log->info('================== START: ProcessGoogleCalendarSyncJob ==================', [
            'channel_id' => $this->channelId,
            'resource_id' => $this->resourceId,
            'target_calendar_id' => $this->targetCalendarId,
        ]);

        $calendarService = $bookingService->getGoogleCalendarService();
        if (! $calendarService) {
            $log->error('ProcessGoogleCalendarSyncJob aborted: Google calendar service unavailable');

            return;
        }

        $slotsCalendarId = $bookingService->getSlotsCalendarId();

        $calendarsToSync = $this->targetCalendarId
            ? [$this->targetCalendarId]
            : array_values(array_filter(['primary', $slotsCalendarId]));

        foreach ($calendarsToSync as $calId) {
            $this->syncCalendarChanges($calId, $bookingService, $calendarService, $slotsCalendarId);
        }

        $log->info('================== END: ProcessGoogleCalendarSyncJob ==================');
    }

    protected function syncCalendarChanges(
        string $calId,
        BookingService $bookingService,
        Google_Service_Calendar $calendarService,
        ?string $slotsCalendarId
    ): void {
        $log = Log::channel('appointment_slots');
        $isPrimary = ($calId === 'primary');
        $configKey = $isPrimary ? 'GoogleCalendar.primary_sync_token' : 'GoogleCalendar.slots_sync_token';

        $syncToken = Configuration::where('name', $configKey)->value('value');
        $tz = config('app.timezone', 'Europe/London');

        $optParams = [
            'singleEvents' => true,
        ];

        if (! empty($syncToken)) {
            $optParams['syncToken'] = $syncToken;
        } else {
            $optParams['timeMin'] = Carbon::today($tz)->subDays(1)->toRfc3339String();
        }

        try {
            $events = $calendarService->events->listEvents($calId, $optParams);
        } catch (Exception $e) {
            if ($e->getCode() === 410) {
                $log->warning("Sync token expired for calendar {$calId}, falling back to full delta sync");
                Configuration::updateOrCreate(['name' => $configKey], ['value' => null]);
                unset($optParams['syncToken']);
                $optParams['timeMin'] = Carbon::today($tz)->subDays(1)->toRfc3339String();
                $events = $calendarService->events->listEvents($calId, $optParams);
            } else {
                $log->error("Failed listing calendar events for {$calId}: ".$e->getMessage());

                return;
            }
        } catch (\Throwable $e) {
            $log->error("Error querying calendar {$calId}: ".$e->getMessage());

            return;
        }

        $items = $events->getItems() ?: [];
        $log->info('Received '.count($items)." event(s) from calendar {$calId}");

        foreach ($items as $event) {
            if ($isPrimary) {
                $this->handlePrimaryCalendarEvent($event, $bookingService, $calendarService, $slotsCalendarId);
            } else {
                $this->handleSubCalendarEvent($event, $bookingService, $calendarService, $slotsCalendarId);
            }
        }

        $nextSyncToken = $events->getNextSyncToken();
        if ($nextSyncToken) {
            Configuration::updateOrCreate(['name' => $configKey], ['value' => $nextSyncToken]);
        }
    }

    /**
     * Handle events on Scott's Primary Calendar (direct booking, moves, cancellations).
     */
    protected function handlePrimaryCalendarEvent(
        Google_Service_Calendar_Event $event,
        BookingService $bookingService,
        Google_Service_Calendar $calendarService,
        ?string $slotsCalendarId
    ): void {
        $log = Log::channel('appointment_slots');
        $tz = config('app.timezone', 'Europe/London');
        $eventId = $event->getId();

        // 1. Check for Cancellation (Scott deleted event in Google Calendar)
        if ($event->getStatus() === 'cancelled') {
            $meeting = ScheduledMeeting::where('event_id', $eventId)
                ->where('status', '!=', 'cancelled')
                ->first();

            if ($meeting) {
                $log->info("Soft-cancelling meeting #{$meeting->id} ({$meeting->name}) from Google Calendar deletion");
                $meeting->update(['status' => 'cancelled']);

                // Restore green placeholder on sub-calendar
                if ($slotsCalendarId && $meeting->timeSlot && $meeting->availabilityDate) {
                    $bookingService->projectSlotPlaceholder($meeting->timeSlot, $meeting->availabilityDate, $slotsCalendarId);
                }
            }

            return;
        }

        // Only handle timed appointments (ignore all-day events)
        if (! $event->getStart() || ! $event->getStart()->getDateTime()) {
            return;
        }

        $eventStart = Carbon::parse($event->getStart()->getDateTime(), $tz);
        $eventEnd = Carbon::parse($event->getEnd()->getDateTime(), $tz);

        // 2. Check if meeting already exists locally
        $meeting = ScheduledMeeting::where('event_id', $eventId)->first();

        if ($meeting) {
            $oldStart = Carbon::parse($meeting->datetime, $tz);
            $extProps = $event->getExtendedProperties()?->getPrivate() ?? [];
            $managedBy = $extProps['managed_by'] ?? null;

            // Echo prevention: checks managed_by and timestamps to prevent loop
            if ($eventStart->equalTo($oldStart) && $meeting->status === 'confirmed') {
                $log->info("Echo breaker: meeting #{$meeting->id} already in sync at {$oldStart->toRfc3339String()}", [
                    'managed_by' => $managedBy,
                ]);

                return;
            }

            // Scott moved/dragged the event to a new time!
            $targetDateStr = $eventStart->format('Y-m-d');
            $targetTimeStr = $eventStart->format('H:i:s');

            $targetDate = AvailabilityDate::whereDate('date', $targetDateStr)->first()
                ?: AvailabilityDate::where('date', $targetDateStr)->first();
            $targetSlot = $targetDate
                ? TimeSlot::where('availability_date_id', $targetDate->id)
                    ->where(function ($q) use ($targetTimeStr) {
                        $q->where('start_time', $targetTimeStr)
                            ->orWhere('start_time', substr($targetTimeStr, 0, 5));
                    })
                    ->first()
                : null;

            $isOccupied = false;
            if (! $targetSlot) {
                $isOccupied = true;
            } else {
                $isOccupied = ScheduledMeeting::where('time_slot_id', $targetSlot->id)
                    ->where('id', '!=', $meeting->id)
                    ->whereIn('status', ['confirmed', 'blocked_by_calendar'])
                    ->exists();
            }

            if ($isOccupied) {
                // Strict Collision Revert (snap-back)
                $log->warning("Collision prevented: slot {$targetDateStr} {$targetTimeStr} occupied. Snapping back event #{$eventId} to {$oldStart->toRfc3339String()}");

                $oldEnd = (clone $oldStart)->addMinutes(30);

                $event->setStart(new Google_Service_Calendar_EventDateTime([
                    'dateTime' => $oldStart->toRfc3339String(),
                    'timeZone' => $tz,
                ]));
                $event->setEnd(new Google_Service_Calendar_EventDateTime([
                    'dateTime' => $oldEnd->toRfc3339String(),
                    'timeZone' => $tz,
                ]));

                try {
                    $calendarService->events->patch('primary', $eventId, $event);
                } catch (\Throwable $e) {
                    $log->error("Failed to patch revert on collision for event {$eventId}: ".$e->getMessage());
                }

                $adminEmail = config('mail.admin_email', config('env.ADMIN_EMAIL'));
                if ($adminEmail) {
                    try {
                        Mail::to($adminEmail)->send(new CalendarCollisionAlertMail(
                            $meeting,
                            $targetDateStr,
                            $targetTimeStr,
                            $oldStart->format('Y-m-d H:i')
                        ));
                    } catch (\Throwable $e) {
                        $log->error('Failed to send collision alert email: '.$e->getMessage());
                    }
                }

                return;
            }

            // Target slot is free: complete reschedule
            $log->info("Moving meeting #{$meeting->id} from {$oldStart->toRfc3339String()} to {$eventStart->toRfc3339String()}");

            // Restore green placeholder for OLD slot on sub-calendar
            if ($slotsCalendarId && $meeting->timeSlot && $meeting->availabilityDate) {
                $bookingService->projectSlotPlaceholder($meeting->timeSlot, $meeting->availabilityDate, $slotsCalendarId);
            }

            // Remove green placeholder for NEW slot on sub-calendar
            if ($slotsCalendarId) {
                $bookingService->removeSlotPlaceholder($targetSlot->id, $slotsCalendarId);
            }

            // Update meeting
            $meeting->update([
                'availability_date_id' => $targetDate->id,
                'time_slot_id' => $targetSlot->id,
                'datetime' => $eventStart,
            ]);

            return;
        }

        // 3. Meeting does not exist: Direct booking on Primary Calendar by Scott
        $targetDateStr = $eventStart->format('Y-m-d');
        $targetTimeStr = $eventStart->format('H:i:s');

        $targetDate = AvailabilityDate::whereDate('date', $targetDateStr)->first()
            ?: AvailabilityDate::where('date', $targetDateStr)->first();
        $targetSlot = $targetDate
            ? TimeSlot::where('availability_date_id', $targetDate->id)
                ->where(function ($q) use ($targetTimeStr) {
                    $q->where('start_time', $targetTimeStr)
                        ->orWhere('start_time', substr($targetTimeStr, 0, 5));
                })
                ->first()
            : null;

        $log->info('Direct primary booking slot search', [
            'date_str' => $targetDateStr,
            'time_str' => $targetTimeStr,
            'found_date_id' => $targetDate?->id,
            'found_slot_id' => $targetSlot?->id,
        ]);

        if (! $targetSlot) {
            $log->info("Direct event on primary at {$targetDateStr} {$targetTimeStr} does not correspond to an open booking slot. Ignoring.");

            return;
        }

        $isOccupied = ScheduledMeeting::where('time_slot_id', $targetSlot->id)
            ->whereIn('status', ['confirmed', 'blocked_by_calendar'])
            ->exists();

        if ($isOccupied) {
            $log->warning("Direct booking collision: slot {$targetDateStr} {$targetTimeStr} is already booked.");

            return;
        }

        // Extract customer name/email/phone from attendees, description, or title
        $customerEmail = null;
        $guestDisplayName = null;
        foreach ($event->getAttendees() ?: [] as $att) {
            if (! empty($att->getEmail())) {
                $customerEmail = $att->getEmail();
                $guestDisplayName = $att->getDisplayName();
                break;
            }
        }

        $customerEmail = $customerEmail ?: config('mail.admin_email', 'admin@example.com');
        $details = $this->extractCustomerDetails($event, $guestDisplayName, 'Scott Direct Booking');
        $customerName = $details['name'];
        $customerPhone = $details['phone'];

        $createdMeeting = ScheduledMeeting::create([
            'user_id' => $targetDate->user_id,
            'name' => $customerName,
            'email' => $customerEmail,
            'phone' => $customerPhone,
            'datetime' => $eventStart,
            'meeting_link' => $event->getHangoutLink() ?: 'pending',
            'event_id' => $eventId,
            'status' => 'confirmed',
            'availability_date_id' => $targetDate->id,
            'time_slot_id' => $targetSlot->id,
        ]);

        $log->info("Recorded direct primary booking meeting #{$createdMeeting->id} for slot {$targetSlot->id}");

        // Stamp managed_by extendedProperties on Google event
        try {
            $ext = $event->getExtendedProperties() ?: new Google_Service_Calendar_EventExtendedProperties;
            $private = $ext->getPrivate() ?: [];
            $private['managed_by'] = 'laravel_booking_system';
            $private['slot_id'] = (string) $targetSlot->id;
            $private['date_id'] = (string) $targetDate->id;
            $ext->setPrivate($private);
            $event->setExtendedProperties($ext);

            $calendarService->events->patch('primary', $eventId, $event);
        } catch (\Throwable $e) {
            $log->warning("Could not patch managed_by on direct primary event {$eventId}: ".$e->getMessage());
        }

        // Remove placeholder from sub-calendar
        if ($slotsCalendarId) {
            $bookingService->removeSlotPlaceholder($targetSlot->id, $slotsCalendarId);
        }

        // Multi-slot blocking if direct meeting duration > 30 mins
        $durationMinutes = $eventStart->diffInMinutes($eventEnd);
        if ($durationMinutes > 30) {
            $extraSlotsCount = (int) ceil(($durationMinutes - 30) / 30);
            $subsequentSlots = TimeSlot::where('availability_date_id', $targetDate->id)
                ->where('start_time', '>', $targetSlot->start_time)
                ->orderBy('start_time')
                ->take($extraSlotsCount)
                ->get();

            foreach ($subsequentSlots as $nextSlot) {
                ScheduledMeeting::create([
                    'user_id' => $targetDate->user_id,
                    'availability_date_id' => $nextSlot->availability_date_id,
                    'time_slot_id' => $nextSlot->id,
                    'name' => $customerName.' (Extended Block)',
                    'email' => $customerEmail,
                    'phone' => '+447000000000',
                    'meeting_link' => 'pending',
                    'event_id' => $eventId.'_block_'.$nextSlot->id,
                    'datetime' => Carbon::parse("{$targetDateStr} {$nextSlot->start_time}", $tz),
                    'status' => 'blocked_by_calendar',
                ]);

                if ($slotsCalendarId) {
                    $bookingService->removeSlotPlaceholder($nextSlot->id, $slotsCalendarId);
                }
            }
        }
    }

    /**
     * Handle events on the Available Slots Sub-Calendar (placeholder conversion).
     */
    protected function handleSubCalendarEvent(
        Google_Service_Calendar_Event $event,
        BookingService $bookingService,
        Google_Service_Calendar $calendarService,
        ?string $slotsCalendarId
    ): void {
        $log = Log::channel('appointment_slots');
        $tz = config('app.timezone', 'Europe/London');

        if ($event->getStatus() === 'cancelled') {
            return;
        }

        if (! $event->getStart() || ! $event->getStart()->getDateTime()) {
            return;
        }

        $attendees = $event->getAttendees() ?: [];
        $guestAttendee = null;
        $adminEmail = config('mail.admin_email');

        foreach ($attendees as $att) {
            if (! empty($att->getEmail()) && $att->getEmail() !== $adminEmail) {
                $guestAttendee = $att;
                break;
            }
        }

        $summary = $event->getSummary() ?: '';
        $isConverted = ($guestAttendee !== null) || (! str_contains($summary, '🟢 Available'));

        if (! $isConverted) {
            // Echo / unchanged placeholder
            return;
        }

        $log->info("Sub-calendar placeholder conversion detected for event {$event->getId()}");

        $eventStart = Carbon::parse($event->getStart()->getDateTime(), $tz);
        $eventEnd = Carbon::parse($event->getEnd()->getDateTime(), $tz);

        $privateProps = $event->getExtendedProperties()?->getPrivate() ?: [];
        $slotId = $privateProps['slot_id'] ?? null;
        $dateId = $privateProps['date_id'] ?? null;

        $timeSlot = $slotId ? TimeSlot::find((int) $slotId) : null;
        $availabilityDate = $dateId ? AvailabilityDate::find((int) $dateId) : null;

        if (! $availabilityDate) {
            $availabilityDate = AvailabilityDate::firstOrCreate(
                ['date' => $eventStart->format('Y-m-d')],
                ['user_id' => User::first()?->id]
            );
        }

        if (! $timeSlot && $availabilityDate) {
            $timeSlot = TimeSlot::where('availability_date_id', $availabilityDate->id)
                ->where('start_time', $eventStart->format('H:i:s'))
                ->first();
        }

        if ($timeSlot) {
            $isOccupied = ScheduledMeeting::where('time_slot_id', $timeSlot->id)
                ->whereIn('status', ['confirmed', 'blocked_by_calendar'])
                ->exists();

            if ($isOccupied) {
                $log->warning("Sub-calendar conversion collision: slot {$timeSlot->id} already booked.");

                return;
            }
        }

        $details = $this->extractCustomerDetails($event, $guestAttendee?->getDisplayName(), 'Customer Appointment');
        $customerName = $details['name'];
        $customerPhone = $details['phone'];
        $customerEmail = $guestAttendee?->getEmail() ?: 'customer@example.com';

        $meeting = ScheduledMeeting::create([
            'user_id' => $availabilityDate?->user_id,
            'name' => $customerName,
            'email' => $customerEmail,
            'phone' => $customerPhone,
            'datetime' => $eventStart,
            'meeting_link' => 'pending',
            'event_id' => 'pending',
            'status' => 'confirmed',
            'availability_date_id' => $availabilityDate?->id,
            'time_slot_id' => $timeSlot?->id,
        ]);

        $log->info("Created ScheduledMeeting #{$meeting->id} from sub-calendar conversion");

        // Promote meeting to Primary Calendar with Google Meet link
        $synced = $bookingService->syncConfirmedMeetingToGoogle($meeting, $eventStart, $eventEnd);
        if ($synced) {
            $meeting->update([
                'event_id' => $synced['event_id'],
                'meeting_link' => $synced['meeting_link'],
            ]);
        }

        // Delete the placeholder event from the sub-calendar
        if ($slotsCalendarId) {
            try {
                $calendarService->events->delete($slotsCalendarId, $event->getId(), ['sendUpdates' => 'none']);
                $log->info("Deleted sub-calendar placeholder {$event->getId()}");
            } catch (\Throwable $e) {
                $log->warning("Failed deleting sub-calendar placeholder {$event->getId()}: ".$e->getMessage());
            }
        }
    }

    /**
     * Extract customer name and phone from description (Name: ... / Phone: ...),
     * Google guest displayName, or clean event title.
     *
     * @return array{name: string, phone: string}
     */
    protected function extractCustomerDetails(
        Google_Service_Calendar_Event $event,
        ?string $guestDisplayName,
        string $defaultName
    ): array {
        $description = (string) ($event->getDescription() ?? '');
        $summary = (string) ($event->getSummary() ?? '');

        $name = null;
        $phone = null;

        // 1. Check description for Name: or Customer:
        if (preg_match('/(?:Name|Customer):\s*([^\r\n]+)/i', $description, $matches)) {
            $name = trim($matches[1]);
        }

        // 2. Check description for Phone: or Tel:
        if (preg_match('/(?:Phone|Tel|Mobile):\s*([^\r\n]+)/i', $description, $matches)) {
            $phone = trim($matches[1]);
        }

        // 3. Fall back to Google Guest displayName
        if (empty($name) && ! empty($guestDisplayName)) {
            $name = trim($guestDisplayName);
        }

        // 4. Fall back to parsing the Event Title (Summary)
        if (empty($name) && ! empty($summary)) {
            $clean = preg_replace('/^[🟢🔴]\s*/u', '', $summary);
            $clean = preg_replace('/^Meeting with\s*/i', '', (string) $clean);
            $clean = preg_replace('/\s*\(\d{1,2}:\d{2}\s*-\s*\d{1,2}:\d{2}\)/', '', (string) $clean);
            $clean = trim((string) $clean);

            if ($clean !== '' && ! str_starts_with(strtolower($clean), 'available')) {
                $name = $clean;
            }
        }

        return [
            'name' => $name ?: $defaultName,
            'phone' => $phone ?: '+447000000000',
        ];
    }
}
