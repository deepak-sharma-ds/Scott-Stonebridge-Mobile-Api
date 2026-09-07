<?php

namespace App\Jobs\Campaign;

use App\Contracts\Services\OrderFulfillmentServiceInterface;
use App\Models\CampaignDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fulfill a campaign delivery's Shopify order line item after its email is sent.
 *
 * Dispatched from SendCampaignEmailJob once the delivery is marked `sent`, so a
 * Shopify failure can never block or re-trigger the email. Only the campaign
 * line item is fulfilled — orders with other (physical) items are left
 * partially fulfilled by Shopify. A per-delivery `fulfilled_at` flag plus the
 * Shopify-side "no remaining quantity" check keep this idempotent across retries.
 */
class MarkCampaignOrderFulfilledJob implements ShouldQueue
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
        if (! (bool) config('campaign_email.fulfillment.enabled', true)) {
            return;
        }

        /** @var CampaignDelivery|null $delivery */
        $delivery = CampaignDelivery::find($this->deliveryId);
        if (! $delivery) {
            return;
        }

        // Already fulfilled (idempotency).
        if ($delivery->fulfilled_at !== null) {
            return;
        }

        $orderId = (int) $delivery->shopify_order_id;
        $lineItemId = (int) $delivery->shopify_line_item_id;
        if ($orderId === 0 || $lineItemId === 0) {
            return;
        }

        $notifyCustomer = (bool) config('campaign_email.fulfillment.notify_customer', false);

        $result = $fulfillmentService->fulfillLineItems($orderId, [$lineItemId], $notifyCustomer);

        // Stamp whether a fulfillment was created or there was simply nothing
        // open to fulfill — both mean "no further action for this delivery".
        $delivery->forceFill(['fulfilled_at' => Carbon::now()])->save();

        Log::channel('shopify_webhooks')->info('Campaign order line item fulfillment processed', [
            'delivery_id' => $delivery->id,
            'order_id' => $orderId,
            'line_item_id' => $lineItemId,
            'fulfilled' => $result['fulfilled'],
            'status' => $result['status'],
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::channel('shopify_webhooks')->error('Campaign order fulfillment failed', [
            'delivery_id' => $this->deliveryId,
            'error' => $e->getMessage(),
        ]);
    }
}
