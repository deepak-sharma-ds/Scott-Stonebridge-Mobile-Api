<?php

declare(strict_types=1);

namespace App\Services\Shopify\Webhooks;

use App\Jobs\Shopify\ProcessShopifyWebhookEventJob;
use App\Models\ShopifyWebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Store-first webhook receiver. The HTTP request only (1) persists the raw
 * event, (2) queues its processing and (3) acknowledges — all real work runs
 * in ProcessShopifyWebhookEventJob. A failed store answers 500 so Shopify
 * retries; a duplicate (same endpoint + webhook id) answers 200 without
 * reprocessing.
 */
class WebhookInbox
{
    public function receive(Request $request, string $endpoint, string $defaultTopic): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        $payload = is_array($payload) ? $payload : [];

        $webhookId = $request->header('X-Shopify-Webhook-Id');
        $topic = (string) $request->header('X-Shopify-Topic', $defaultTopic);
        $orderId = $this->resolveOrderId($payload, $topic);

        try {
            $event = ShopifyWebhookEvent::create([
                'endpoint' => $endpoint,
                'topic' => $topic,
                'shopify_order_id' => $orderId,
                'shopify_webhook_id' => $webhookId,
                'payload' => $payload,
                'hmac_valid' => (bool) $request->attributes->get('shopify_hmac_valid', false),
                'status' => $orderId === 0
                    ? ShopifyWebhookEvent::STATUS_IGNORED
                    : ShopifyWebhookEvent::STATUS_RECEIVED,
                'reason' => $orderId === 0 ? 'no_order_id' : null,
                'received_at' => Carbon::now(),
                'processed_at' => $orderId === 0 ? Carbon::now() : null,
            ]);
        } catch (UniqueConstraintViolationException) {
            Log::channel('shopify_webhooks')->info('Duplicate webhook ignored', [
                'endpoint' => $endpoint,
                'webhook_id' => $webhookId,
            ]);

            return response()->json(['message' => 'Already received'], 200);
        } catch (Throwable $e) {
            // Nothing was stored: answer 5xx so Shopify retries the delivery.
            Log::channel('shopify_webhooks')->error('Failed to store webhook event', [
                'endpoint' => $endpoint,
                'webhook_id' => $webhookId,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Could not store event'], 500);
        }

        if ($event->status === ShopifyWebhookEvent::STATUS_RECEIVED) {
            $this->dispatch($event);
        }

        return response()->json(['message' => 'Received'], 200);
    }

    /**
     * Queue processing for an event. A dispatch failure is not fatal: the
     * event is already stored and `webhooks:retry` re-queues stale ones.
     */
    public function dispatch(ShopifyWebhookEvent $event): void
    {
        try {
            ProcessShopifyWebhookEventJob::dispatch($event->id)
                ->onConnection(config('shopify.webhook_inbox.connection'))
                ->onQueue(config('shopify.webhook_inbox.queue'));
        } catch (Throwable $e) {
            Log::channel('shopify_webhooks')->error('Failed to queue webhook event processing', [
                'event_id' => $event->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Orders carry `id`; refund payloads carry `order_id`.
     *
     * @param  array<string,mixed>  $payload
     */
    private function resolveOrderId(array $payload, string $topic): int
    {
        if (str_contains(strtolower($topic), 'refund')) {
            $refund = $payload['refund'] ?? $payload;

            return (int) ($refund['order_id'] ?? $payload['order_id'] ?? 0);
        }

        $order = $payload['order'] ?? $payload;

        return (int) ($order['id'] ?? 0);
    }
}
