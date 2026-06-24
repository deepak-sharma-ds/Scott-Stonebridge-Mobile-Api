<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\EmailReading\ProcessEmailReadingOrderJob;
use App\Models\EmailReadingDelivery;
use App\Models\EmailReadingProduct;
use App\Models\ShopifyWebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class ShopifyReadingWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();
        $payload = json_decode($rawBody, true) ?: [];
        $order = $payload['order'] ?? $payload;

        $orderId = isset($order['id']) ? (int) $order['id'] : 0;
        $webhookId = $request->header('X-Shopify-Webhook-Id');
        $topic = $request->header('X-Shopify-Topic', 'orders/paid');
        $hmacValid = (bool) $request->attributes->get('shopify_hmac_valid', false);

        if ($orderId === 0) {
            Log::channel('shopify_webhooks')->warning('Reading webhook received without order id', [
                'topic' => $topic,
                'webhook_id' => $webhookId,
            ]);

            return response()->json(['message' => 'No order id'], 200);
        }

        $allowlist = (array) config('email_reading.test_emails', []);
        if (! empty($allowlist)) {
            $incomingEmail = strtolower(trim((string) (
                $order['email']
                ?? $order['contact_email']
                ?? ($order['customer']['email'] ?? '')
            )));

            if ($incomingEmail === '' || ! in_array($incomingEmail, $allowlist, true)) {
                Log::channel('shopify_webhooks')->info('Reading webhook skipped: email not in test allowlist', [
                    'order_id' => $orderId,
                    'email' => $incomingEmail,
                ]);

                return response()->json([
                    'message' => 'Skipped: email not in test allowlist',
                ], 200);
            }
        }

        if ($webhookId) {
            $existing = ShopifyWebhookEvent::where('shopify_webhook_id', $webhookId)->first();
            if ($existing) {
                Log::channel('shopify_webhooks')->info('Duplicate reading webhook ignored', [
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
            Log::channel('shopify_webhooks')->error('Failed to persist webhook event', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Logged but not stored'], 200);
        }

        $lineItems = $order['line_items'] ?? [];
        $customerEmail = $order['email']
            ?? $order['contact_email']
            ?? ($order['customer']['email'] ?? null);
        $customerName = trim(
            (string) ($order['customer']['first_name'] ?? '').' '
            .(string) ($order['customer']['last_name'] ?? '')
        );
        if ($customerName === '') {
            $customerName = trim(
                (string) ($order['billing_address']['first_name'] ?? '').' '
                .(string) ($order['billing_address']['last_name'] ?? '')
            );
        }

        if (! $customerEmail) {
            Log::channel('shopify_webhooks')->warning('Reading order has no customer email', [
                'order_id' => $orderId,
                'event_id' => $event->id,
            ]);

            return response()->json(['message' => 'No customer email; event stored'], 200);
        }

        $productIds = collect($lineItems)
            ->pluck('product_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();

        if (empty($productIds)) {
            return response()->json(['message' => 'No line items'], 200);
        }

        $matched = EmailReadingProduct::active()
            ->whereIn('shopify_product_id', $productIds)
            ->get()
            ->keyBy('shopify_product_id');

        if ($matched->isEmpty()) {
            return response()->json(['message' => 'No reading products in order'], 200);
        }

        // When the customer already bought the same-day upgrade at purchase
        // time, deliver within 24h instead of the standard 3-7 day window.
        $sameDay = $this->hasSameDayUpgrade($order)
            && (bool) config('email_reading.expedite.enabled', true);

        $scheduledAt = $this->resolveScheduledAtForOrder($orderId, $sameDay);
        $expeditedAt = ($sameDay && $scheduledAt) ? Carbon::now() : null;

        $dispatched = 0;

        foreach ($lineItems as $line) {
            $productId = isset($line['product_id']) ? (int) $line['product_id'] : 0;
            $lineItemId = isset($line['id']) ? (int) $line['id'] : 0;

            if ($productId === 0 || $lineItemId === 0) {
                continue;
            }

            /** @var EmailReadingProduct|null $product */
            $product = $matched->get($productId);
            if (! $product) {
                continue;
            }

            $questions = $this->mapProperties($line['properties'] ?? []);

            $delivery = EmailReadingDelivery::firstOrCreate(
                ['shopify_line_item_id' => $lineItemId],
                [
                    'shopify_order_id' => $orderId,
                    'email_reading_product_id' => $product->id,
                    'customer_email' => $customerEmail,
                    'customer_name' => $customerName ?: null,
                    'questions' => $questions,
                    'status' => EmailReadingDelivery::STATUS_PENDING,
                    'scheduled_at' => $scheduledAt,
                    'expedited_at' => $expeditedAt,
                ]
            );

            if ($delivery->wasRecentlyCreated) {
                ProcessEmailReadingOrderJob::dispatch($delivery->id)
                    ->onConnection(config('email_reading.queue.connection'))
                    ->onQueue(config('email_reading.queue.process'));
                $dispatched++;
            }
        }

        Log::channel('shopify_webhooks')->info('Reading webhook processed', [
            'event_id' => $event->id,
            'order_id' => $orderId,
            'matched_products' => $matched->count(),
            'dispatched' => $dispatched,
            'scheduled_at' => $scheduledAt?->toIso8601String(),
            'same_day' => $sameDay,
        ]);

        return response()->json([
            'message' => 'OK',
            'dispatched' => $dispatched,
        ], 200);
    }

    /**
     * Resolve the send time for an order's reading emails.
     *
     * Returns null when scheduling is disabled (callers then dispatch the
     * send immediately, preserving the pre-scheduling behavior). Otherwise a
     * single random timestamp is returned per order: an existing `scheduled_at`
     * already stored for the same order is reused so a replayed/duplicate
     * webhook cannot re-randomize the time, and every reading line item in the
     * order sends together.
     *
     * When `$sameDay` is true (customer bought the same-day upgrade at purchase
     * time), the timestamp falls in the expedite window (`min_hours`-`max_hours`)
     * instead of the standard `min_days`-`max_days` window.
     */
    private function resolveScheduledAtForOrder(int $orderId, bool $sameDay = false): ?Carbon
    {
        // Reuse any timestamp already stored for this order (idempotency on
        // replays), regardless of which window produced it.
        $existing = EmailReadingDelivery::where('shopify_order_id', $orderId)
            ->whereNotNull('scheduled_at')
            ->value('scheduled_at');

        if ($existing) {
            return Carbon::parse($existing);
        }

        if ($sameDay) {
            $min = max(0, (int) config('email_reading.expedite.min_hours', 1));
            $max = max($min, (int) config('email_reading.expedite.max_hours', 24));

            return Carbon::now()->addSeconds(random_int($min * 3600, $max * 3600));
        }

        if (! (bool) config('email_reading.schedule.enabled', true)) {
            return null;
        }

        $min = max(0, (int) config('email_reading.schedule.min_days', 3));
        $max = max($min, (int) config('email_reading.schedule.max_days', 7));

        return Carbon::now()->addSeconds(random_int($min * 86400, $max * 86400));
    }

    /**
     * True when the order carries a non-removed shipping line whose title or
     * code matches one of the configured same-day upgrade labels. Mirrors the
     * detection used by the orders/updated webhook so both entry points agree.
     */
    private function hasSameDayUpgrade(array $order): bool
    {
        $titles = (array) config('email_reading.expedite.shipping_titles', []);
        if (empty($titles)) {
            return false;
        }

        foreach ((array) ($order['shipping_lines'] ?? []) as $line) {
            if (($line['is_removed'] ?? false) === true) {
                continue;
            }

            $candidates = [
                strtolower(trim((string) ($line['title'] ?? ''))),
                strtolower(trim((string) ($line['code'] ?? ''))),
            ];

            foreach ($candidates as $candidate) {
                if ($candidate !== '' && in_array($candidate, $titles, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Convert Shopify line-item properties (array of {name,value}) into a
     * key/value snapshot. Each property contributes:
     *   - a normalised key derived from its name (e.g. "You & Me Details"
     *     becomes "you_me_details"),
     *   - a positional alias (`q1`, `q2`, ...) so prompt templates that
     *     prefer numeric placeholders still resolve.
     *
     * Also stamps the verbatim property name under `_raw` for audit/debug.
     */
    private function mapProperties(array $properties): array
    {
        $out = ['_raw' => []];
        $idx = 1;

        foreach ($properties as $prop) {
            $name = (string) ($prop['name'] ?? '');
            $value = (string) ($prop['value'] ?? '');
            if ($name === '') {
                continue;
            }

            $key = EmailReadingDelivery::normalizeKey($name);
            if ($key !== '') {
                $out[$key] = $value;
            }
            $out['q'.$idx] = $value;
            $out['_raw'][$name] = $value;
            $idx++;
        }

        return $out;
    }
}
