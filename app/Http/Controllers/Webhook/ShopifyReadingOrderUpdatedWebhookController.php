<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\EmailReading\SendEmailReadingJob;
use App\Models\EmailReadingDelivery;
use App\Models\ShopifyWebhookEvent;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class ShopifyReadingOrderUpdatedWebhookController extends Controller
{
    /**
     * Handle a Shopify orders/updated webhook.
     *
     * When the customer buys the "same day" upgrade on an existing reading
     * order, Shopify edits the order and fires this webhook with the upgrade
     * present as a shipping line. We pull the order's reading send forward to
     * a random time within the configured hours window. The original 3-7 day
     * SendEmailReadingJob is left in the queue: when it eventually runs it sees
     * the delivery already `sent` and no-ops, so no second email goes out.
     */
    public function handle(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();
        $payload = json_decode($rawBody, true) ?: [];
        $order = $payload['order'] ?? $payload;

        $orderId = isset($order['id']) ? (int) $order['id'] : 0;
        $webhookId = $request->header('X-Shopify-Webhook-Id');
        $topic = $request->header('X-Shopify-Topic', 'orders/updated');
        $hmacValid = (bool) $request->attributes->get('shopify_hmac_valid', false);

        if ($orderId === 0) {
            Log::channel('shopify_webhooks')->warning('Reading update webhook received without order id', [
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
                Log::channel('shopify_webhooks')->info('Reading update webhook skipped: email not in test allowlist', [
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
                Log::channel('shopify_webhooks')->info('Duplicate reading update webhook ignored', [
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
            Log::channel('shopify_webhooks')->error('Failed to persist reading update webhook event', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Logged but not stored'], 200);
        }

        if (! (bool) config('email_reading.expedite.enabled', true)) {
            Log::channel('shopify_webhooks')->info('Reading expedite disabled; update webhook ignored', [
                'event_id' => $event->id,
                'order_id' => $orderId,
            ]);

            return response()->json(['message' => 'Expedite disabled'], 200);
        }

        if (! $this->hasSameDayUpgrade($order)) {
            return response()->json(['message' => 'No same-day upgrade'], 200);
        }

        /** @var Collection<int,EmailReadingDelivery> $deliveries */
        $deliveries = EmailReadingDelivery::where('shopify_order_id', $orderId)
            ->whereIn('status', [
                EmailReadingDelivery::STATUS_PENDING,
                EmailReadingDelivery::STATUS_GENERATED,
            ])
            ->whereNull('expedited_at')
            ->get();

        if ($deliveries->isEmpty()) {
            Log::channel('shopify_webhooks')->info('Same-day upgrade detected but no expeditable deliveries', [
                'event_id' => $event->id,
                'order_id' => $orderId,
            ]);

            return response()->json(['message' => 'Nothing to expedite'], 200);
        }

        $expeditedAt = $this->resolveExpeditedAtForOrder($orderId);
        $now = Carbon::now();
        $expedited = 0;

        foreach ($deliveries as $delivery) {
            $delivery->forceFill([
                'scheduled_at' => $expeditedAt,
                'expedited_at' => $now,
            ])->save();

            $send = SendEmailReadingJob::dispatch($delivery->id)
                ->onConnection(config('email_reading.queue.connection'))
                ->onQueue(config('email_reading.queue.mail'));

            if ($expeditedAt->isFuture()) {
                $send->delay($expeditedAt);
            }

            $expedited++;
        }

        Log::channel('shopify_webhooks')->info('Reading send expedited', [
            'event_id' => $event->id,
            'order_id' => $orderId,
            'expedited' => $expedited,
            'expedited_at' => $expeditedAt->toIso8601String(),
        ]);

        return response()->json([
            'message' => 'OK',
            'expedited' => $expedited,
        ], 200);
    }

    /**
     * True when the order carries a non-removed shipping line whose title or
     * code matches one of the configured same-day upgrade labels.
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
     * Resolve the pulled-forward send time for an order's reading emails.
     *
     * One random timestamp within the configured hours window is computed per
     * order: an existing `expedited_at` already stored for the same order means
     * we reuse that delivery's `scheduled_at` so a replayed/duplicate webhook
     * cannot re-randomize the time and every reading in the order sends
     * together.
     */
    private function resolveExpeditedAtForOrder(int $orderId): Carbon
    {
        $existing = EmailReadingDelivery::where('shopify_order_id', $orderId)
            ->whereNotNull('expedited_at')
            ->value('scheduled_at');

        if ($existing) {
            return Carbon::parse($existing);
        }

        $min = max(0, (int) config('email_reading.expedite.min_hours', 1));
        $max = max($min, (int) config('email_reading.expedite.max_hours', 24));

        $offsetSeconds = random_int($min * 3600, $max * 3600);

        return Carbon::now()->addSeconds($offsetSeconds);
    }
}
