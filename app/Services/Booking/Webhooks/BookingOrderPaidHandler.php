<?php

declare(strict_types=1);

namespace App\Services\Booking\Webhooks;

use App\Contracts\Shopify\ShopifyWebhookHandlerInterface;
use App\Models\ScheduledMeeting;
use App\Models\ShopifyWebhookEvent;
use App\Models\TimeSlot;
use App\Services\Booking\BookingLineItemResolver;
use App\Services\BookingService;
use App\Services\Shopify\Webhooks\WebhookOutcome;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Handles Shopify orders/paid webhooks for Appointment Booking.
 * Atomically locks TimeSlots, prevents race condition double-bookings,
 * records ScheduledMeetings in Europe/London timezone, and dispatches Google Calendar synchronization.
 */
class BookingOrderPaidHandler implements ShopifyWebhookHandlerInterface
{
    public function __construct(
        protected readonly BookingLineItemResolver $resolver,
        protected readonly BookingService $bookingService,
    ) {}

    public function handle(ShopifyWebhookEvent $event): WebhookOutcome
    {
        $order = (array) $event->payload;
        $resolved = $this->resolver->resolve($order);

        if (! $resolved) {
            return WebhookOutcome::ignored('no_booking_line_item');
        }

        $orderId = (int) $resolved['order_id'];

        // Idempotency check: if already confirmed for this order, do not duplicate
        $existing = ScheduledMeeting::where('order_id', $orderId)
            ->where('status', 'confirmed')
            ->first();

        if ($existing) {
            return WebhookOutcome::processed('already_booked');
        }

        DB::transaction(function () use ($resolved, $orderId) {
            $slotId = (int) $resolved['time_slot_id'];
            $slot = TimeSlot::lockForUpdate()->find($slotId);

            if (! $slot) {
                $this->recordConflict($resolved, $orderId, 'Time slot not found');

                return;
            }

            // Check if slot is occupied
            $isOccupied = ScheduledMeeting::where('time_slot_id', $slot->id)
                ->whereNotIn('status', ['closed', 'cancelled'])
                ->exists();

            if ($isOccupied) {
                $this->recordConflict($resolved, $orderId, 'Time slot already booked by another customer');

                return;
            }

            $date = $resolved['availability_date'];
            $tz = (string) config('booking.timezone', config('app.timezone', 'Europe/London'));
            $start = Carbon::parse("{$date} {$slot->start_time}", $tz);
            $end = Carbon::parse("{$date} {$slot->end_time}", $tz);

            $meeting = ScheduledMeeting::create([
                'order_id' => $orderId,
                'availability_date_id' => $slot->availability_date_id,
                'time_slot_id' => $slot->id,
                'name' => $resolved['name'],
                'email' => $resolved['email'],
                'phone' => $resolved['phone'] ?? '',
                'meeting_link' => 'pending',
                'event_id' => 'pending',
                'datetime' => $start,
                'status' => 'confirmed',
            ]);

            // Multi-slot blocking if duration exceeds 30 minutes
            $durationMinutes = (int) ($resolved['properties']['duration'] ?? 30);
            if ($durationMinutes > 30) {
                $extendedEnd = $start->copy()->addMinutes($durationMinutes);
                $subsequentSlots = TimeSlot::where('availability_date_id', $slot->availability_date_id)
                    ->where('start_time', '>=', $slot->end_time)
                    ->where('end_time', '<=', $extendedEnd->format('H:i:s'))
                    ->orderBy('start_time')
                    ->get();

                foreach ($subsequentSlots as $nextSlot) {
                    ScheduledMeeting::create([
                        'order_id' => $orderId,
                        'availability_date_id' => $nextSlot->availability_date_id,
                        'time_slot_id' => $nextSlot->id,
                        'name' => $resolved['name'].' (Extended Block)',
                        'email' => $resolved['email'],
                        'phone' => $resolved['phone'] ?? '',
                        'meeting_link' => 'pending',
                        'event_id' => 'pending',
                        'datetime' => Carbon::parse("{$date} {$nextSlot->start_time}", $tz),
                        'status' => 'blocked_by_calendar',
                    ]);
                }
            }

            // Sync to Google Calendar if configured
            try {
                $resyncResult = $this->bookingService->syncConfirmedMeetingToGoogle($meeting, $start, $end);
                if (! empty($resyncResult['event_id'])) {
                    $meeting->update([
                        'event_id' => $resyncResult['event_id'],
                        'meeting_link' => $resyncResult['meeting_link'] ?? $meeting->meeting_link,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::channel('shopify_webhooks')->warning('Google Calendar sync deferred or failed', [
                    'meeting_id' => $meeting->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        return WebhookOutcome::processed();
    }

    /**
     * Record a booking collision safely without double-booking.
     * Transitions status to 'needs_reschedule' and notifies admin.
     *
     * @param  array<string, mixed>  $resolved
     */
    protected function recordConflict(array $resolved, int $orderId, string $reason): void
    {
        Log::channel('shopify_webhooks')->warning('Booking slot collision detected', [
            'order_id' => $orderId,
            'time_slot_id' => $resolved['time_slot_id'] ?? null,
            'reason' => $reason,
        ]);

        $tz = (string) config('booking.timezone', config('app.timezone', 'Europe/London'));
        $datetime = Carbon::parse($resolved['availability_date'], $tz);

        $meeting = ScheduledMeeting::create([
            'order_id' => $orderId,
            'availability_date_id' => null,
            'time_slot_id' => null,
            'name' => $resolved['name'],
            'email' => $resolved['email'],
            'phone' => $resolved['phone'] ?? '',
            'meeting_link' => 'pending',
            'event_id' => 'pending',
            'datetime' => $datetime,
            'status' => 'needs_reschedule',
        ]);

        try {
            BookingService::notifyAdminForReschedule($meeting);
        } catch (\Throwable $e) {
            Log::channel('shopify_webhooks')->error('Failed to notify admin of reschedule conflict', [
                'meeting_id' => $meeting->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
