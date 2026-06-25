<?php

namespace App\Jobs\EmailReading;

use App\Contracts\Services\OrderFulfillmentServiceInterface;
use App\Models\EmailReadingDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Fulfill a reading's Shopify order line item after its email is sent.
 *
 * Dispatched from SendEmailReadingJob once the delivery is marked `sent`, so a
 * Shopify failure can never block or re-trigger the email. Only the reading
 * line item is fulfilled — orders with other (physical) items are left
 * partially fulfilled by Shopify. A per-delivery `fulfilled_at` flag plus the
 * Shopify-side "no remaining quantity" check keep this idempotent across retries.
 */
class MarkReadingOrderFulfilledJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public function __construct(public int $deliveryId) {}

    public function backoff(): array
    {
        return [30, 120, 300, 900, 1800];
    }

    public function handle(OrderFulfillmentServiceInterface $fulfillmentService): void
    {
        if (! (bool) config('email_reading.fulfillment.enabled', true)) {
            return;
        }

        /** @var EmailReadingDelivery|null $delivery */
        $delivery = EmailReadingDelivery::find($this->deliveryId);
        if (! $delivery) {
            return;
        }

        // Already fulfilled (idempotency) or a manual delivery with no Shopify
        // order/line item to act on.
        if ($delivery->fulfilled_at !== null) {
            return;
        }

        $orderId = (int) $delivery->shopify_order_id;
        $lineItemId = (int) $delivery->shopify_line_item_id;
        if ($orderId === 0 || $lineItemId === 0) {
            return;
        }

        $notifyCustomer = (bool) config('email_reading.fulfillment.notify_customer', false);

        $result = $fulfillmentService->fulfillLineItems($orderId, [$lineItemId], $notifyCustomer);

        // Stamp whether a fulfillment was created or there was simply nothing
        // open to fulfill — both mean "no further action for this delivery".
        $delivery->forceFill(['fulfilled_at' => Carbon::now()])->save();

        Log::channel('shopify_webhooks')->info('Reading order line item fulfillment processed', [
            'delivery_id' => $delivery->id,
            'order_id' => $orderId,
            'line_item_id' => $lineItemId,
            'fulfilled' => $result['fulfilled'],
            'status' => $result['status'],
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::channel('shopify_webhooks')->error('Reading order fulfillment failed', [
            'delivery_id' => $this->deliveryId,
            'error' => $e->getMessage(),
        ]);
    }
}
