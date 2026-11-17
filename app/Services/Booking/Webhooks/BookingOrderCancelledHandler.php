<?php

declare(strict_types=1);

namespace App\Services\Booking\Webhooks;

use App\Contracts\Shopify\ShopifyWebhookHandlerInterface;
use App\Jobs\Booking\SyncSlotsToGoogleSubCalendarJob;
use App\Models\ScheduledMeeting;
use App\Models\ShopifyWebhookEvent;
use App\Services\Booking\BookingLineItemResolver;
use App\Services\BookingService;
use App\Services\Shopify\Webhooks\WebhookOutcome;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Handles Shopify orders/cancelled webhooks for Appointment Booking.
 * Implements soft-cancellation, frees the TimeSlot for immediate storefront re-booking,
 * and purges the Google Calendar event with 'sendUpdates' => 'all'.
 */
class BookingOrderCancelledHandler implements ShopifyWebhookHandlerInterface
{
    public function __construct(
        protected readonly BookingLineItemResolver $resolver,
        protected readonly BookingService $bookingService,
    ) {}

    public function handle(ShopifyWebhookEvent $event): WebhookOutcome
    {
        $payload = (array) $event->payload;
        $orderId = (int) $event->shopify_order_id;

        $meetings = ScheduledMeeting::where('order_id', $orderId)
            ->where('status', '!=', 'cancelled')
            ->get();

        if ($meetings->isEmpty()) {
            $isBookingOrder = (bool) $this->resolver->resolve($payload);

            if ($isBookingOrder) {
                // Out-of-order delivery: orders/cancelled arrived before orders/paid.
                // Throw so WebhookInbox retries until the booking is processed.
                throw new RuntimeException("Booking for order #{$orderId} not yet recorded; retrying cancellation.");
            }

            return WebhookOutcome::ignored('no_booking_for_order');
        }

        $cancelledCount = 0;

        foreach ($meetings as $meeting) {
            // Purge Google Calendar event if present
            if (! empty($meeting->event_id)) {
                try {
                    $this->bookingService->deleteGoogleCalendarEvent($meeting->event_id);
                } catch (\Throwable $e) {
                    Log::channel('shopify_webhooks')->warning('Failed to delete Google Calendar event for cancelled booking', [
                        'meeting_id' => $meeting->id,
                        'event_id' => $meeting->event_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $slotId = $meeting->time_slot_id;

            // Soft-cancel and release TimeSlot
            $meeting->update([
                'status' => 'cancelled',
                'time_slot_id' => null,
            ]);

            // Restore green placeholder on secondary Google Calendar
            if ($slotId) {
                SyncSlotsToGoogleSubCalendarJob::dispatch(
                    slotIdsToProject: [$slotId]
                );
            }

            $cancelledCount++;
        }

        Log::channel('shopify_webhooks')->info('Booking(s) successfully cancelled', [
            'order_id' => $orderId,
            'count' => $cancelledCount,
        ]);

        return WebhookOutcome::processed();
    }
}
