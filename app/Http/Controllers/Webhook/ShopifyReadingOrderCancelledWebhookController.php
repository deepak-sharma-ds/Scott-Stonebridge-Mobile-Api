<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Models\EmailReadingDelivery;
use App\Models\ShopifyWebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class ShopifyReadingOrderCancelledWebhookController extends Controller
{
    /**
     * Handle Shopify orders/cancelled and refunds/create webhooks.
     *
     * When a reading order is cancelled (orders/cancelled) or a reading line
     * item is refunded (refunds/create), the matching unsent deliveries are
     * marked `cancelled`. The original SendEmailReadingJob / generation job
     * already guard on exact status, so a cancelled delivery simply no-ops when
     * its queued job eventually runs — no email is sent. Already-sent readings
     * cannot be unsent and are left untouched.
     *
     * - orders/cancelled → cancel ALL unsent deliveries for the order.
     * - refunds/create   → cancel only the deliveries whose line item was
     *                       refunded (so a partial refund of just the reading
     *                       stops that reading; a refund of other items doesn't).
     */
    public function handle(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();
        $payload = json_decode($rawBody, true) ?: [];

        $webhookId = $request->header('X-Shopify-Webhook-Id');
        $topic = $request->header('X-Shopify-Topic', 'orders/cancelled');
        $hmacValid = (bool) $request->attributes->get('shopify_hmac_valid', false);

        $isRefund = str_contains(strtolower($topic), 'refund');
        $orderId = $this->resolveOrderId($payload, $isRefund);

        if ($orderId === 0) {
            Log::channel('shopify_webhooks')->warning('Reading cancel webhook received without order id', [
                'topic' => $topic,
                'webhook_id' => $webhookId,
            ]);

            return response()->json(['message' => 'No order id'], 200);
        }

        if ($webhookId) {
            $existing = ShopifyWebhookEvent::where('shopify_webhook_id', $webhookId)->first();
            if ($existing) {
                Log::channel('shopify_webhooks')->info('Duplicate reading cancel webhook ignored', [
                    'webhook_id' => $webhookId,
                    'order_id' => $orderId,
                ]);

                return response()->json(['message' => 'Already processed'], 200);
            }
        }

        try {
            $event = ShopifyWebhookEvent::create([
                'topic' => $topic,
                'shopify_order_id' => $orderId,
                'shopify_webhook_id' => $webhookId,
                'payload' => $payload,
                'hmac_valid' => $hmacValid,
                'received_at' => Carbon::now(),
            ]);
        } catch (Throwable $e) {
            Log::channel('shopify_webhooks')->error('Failed to persist reading cancel webhook event', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Logged but not stored'], 200);
        }

        if (! (bool) config('email_reading.cancel.enabled', true)) {
            Log::channel('shopify_webhooks')->info('Reading cancel disabled; webhook ignored', [
                'event_id' => $event->id,
                'order_id' => $orderId,
            ]);

            return response()->json(['message' => 'Cancel disabled'], 200);
        }

        // Only unsent readings can be stopped. Sent/failed/already-cancelled are
        // left as-is (a sent email cannot be unsent).
        $query = EmailReadingDelivery::where('shopify_order_id', $orderId)
            ->whereIn('status', [
                EmailReadingDelivery::STATUS_PENDING,
                EmailReadingDelivery::STATUS_GENERATED,
            ]);

        if ($isRefund) {
            $lineItemIds = $this->refundedLineItemIds($payload);

            if (empty($lineItemIds)) {
                Log::channel('shopify_webhooks')->info('Refund had no reading line items to cancel', [
                    'event_id' => $event->id,
                    'order_id' => $orderId,
                ]);

                return response()->json(['message' => 'No refunded line items'], 200);
            }

            $query->whereIn('shopify_line_item_id', $lineItemIds);
        }

        $cancelled = $query->get()->each(function (EmailReadingDelivery $delivery) {
            $delivery->forceFill(['status' => EmailReadingDelivery::STATUS_CANCELLED])->save();
        })->count();

        Log::channel('shopify_webhooks')->info('Reading deliveries cancelled', [
            'event_id' => $event->id,
            'order_id' => $orderId,
            'topic' => $topic,
            'cancelled' => $cancelled,
        ]);

        return response()->json([
            'message' => 'OK',
            'cancelled' => $cancelled,
        ], 200);
    }

    /**
     * Resolve the numeric Shopify order id from either an order payload
     * (orders/cancelled) or a refund payload (refunds/create).
     */
    private function resolveOrderId(array $payload, bool $isRefund): int
    {
        if ($isRefund) {
            $refund = $payload['refund'] ?? $payload;

            return (int) ($refund['order_id'] ?? $payload['order_id'] ?? 0);
        }

        $order = $payload['order'] ?? $payload;

        return (int) ($order['id'] ?? 0);
    }

    /**
     * Collect the refunded order line-item ids from a refunds/create payload.
     *
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
