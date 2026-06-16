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
        ]);

        return response()->json([
            'message' => 'OK',
            'dispatched' => $dispatched,
        ], 200);
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
