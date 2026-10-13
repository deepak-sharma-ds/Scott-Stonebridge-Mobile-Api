<?php

declare(strict_types=1);

namespace App\Services\EmailReading\Webhooks;

use App\Contracts\Shopify\ShopifyWebhookHandlerInterface;
use App\Models\EmailReadingDelivery;
use App\Models\EmailReadingProduct;
use App\Models\ShopifyWebhookEvent;
use App\Services\EmailReading\EmailReadingDeliveryLifecycle;
use App\Services\Shopify\Webhooks\WebhookOutcome;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Handles Shopify orders/cancelled and refunds/create events for Email
 * Reading Automation.
 *
 * - orders/cancelled -> cancel ALL unsent deliveries for the order.
 * - refunds/create   -> cancel only the deliveries whose line item was
 *                       refunded.
 *
 * Only pending/generated deliveries are cancelled: a delivery already
 * `sending` can no longer be stopped and a sent reading cannot be unsent.
 */
class ReadingOrderCancelledHandler implements ShopifyWebhookHandlerInterface
{
    public function __construct(private readonly EmailReadingDeliveryLifecycle $lifecycle) {}

    public function handle(ShopifyWebhookEvent $event): WebhookOutcome
    {
        $payload = (array) $event->payload;
        $orderId = (int) $event->shopify_order_id;
        $isRefund = str_contains(strtolower((string) $event->topic), 'refund');

        if (! (bool) config('email_reading.cancel.enabled', true)) {
            return WebhookOutcome::ignored('cancel_disabled');
        }

        $lineItemIds = null;

        if ($isRefund) {
            $lineItemIds = $this->refundedLineItemIds($payload);

            if (empty($lineItemIds)) {
                return WebhookOutcome::ignored('no_refunded_line_items');
            }
        }

        $cancelled = $this->lifecycle->cancelForOrder($orderId, $lineItemIds);

        if ($cancelled === 0 && ! $isRefund) {
            $this->assertDeliveriesNotPending($payload, $orderId);
        }

        Log::channel('shopify_webhooks')->info('Reading deliveries cancelled', [
            'event_id' => $event->id,
            'order_id' => $orderId,
            'topic' => $event->topic,
            'cancelled' => $cancelled,
        ]);

        return WebhookOutcome::processed();
    }

    /**
     * A cancellation that arrives before orders/paid was processed would
     * otherwise be lost (the deliveries it should cancel do not exist yet).
     * Throw so the inbox retries until they do.
     *
     * @param  array<string,mixed>  $payload
     */
    private function assertDeliveriesNotPending(array $payload, int $orderId): void
    {
        $order = $payload['order'] ?? $payload;

        $productIds = collect((array) ($order['line_items'] ?? []))
            ->pluck('product_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($productIds === []
            || ! EmailReadingProduct::active()->automationEnabled()->whereIn('shopify_product_id', $productIds)->exists()) {
            return;
        }

        if (! EmailReadingDelivery::where('shopify_order_id', $orderId)->exists()) {
            throw new RuntimeException("Order {$orderId} was cancelled before its orders/paid event was processed; retrying.");
        }
    }

    /**
     * Collect the refunded order line-item ids from a refunds/create payload.
     *
     * @param  array<string,mixed>  $payload
     * @return array<int,int>
     */
    private function refundedLineItemIds(array $payload): array
    {
        $refund = $payload['refund'] ?? $payload;
        $lines = $refund['refund_line_items'] ?? $payload['refund_line_items'] ?? [];

        return collect($lines)
            ->map(fn ($line) => (int) ($line['line_item_id'] ?? ($line['line_item']['id'] ?? 0)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
