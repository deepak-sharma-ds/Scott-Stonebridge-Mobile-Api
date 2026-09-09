<?php

namespace App\Http\Controllers\Webhook\Campaign;

use App\Http\Controllers\Controller;
use App\Jobs\Campaign\SendCampaignEmailJob;
use App\Models\CampaignDelivery;
use App\Models\ShopifyWebhookEvent;
use App\Services\Shopify\ShippingScheduleResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class CampaignOrderUpdatedWebhookController extends Controller
{
    public function __construct(private readonly ShippingScheduleResolver $scheduleResolver) {}

    /**
     * Handle a Shopify orders/updated webhook for Campaign Email Automation.
     *
     * When the customer buys the same-day upgrade on an existing campaign
     * order, Shopify edits the order and fires this webhook with the upgrade
     * present as a shipping line. We pull the order's still-unsent campaign
     * deliveries forward to a random time within the configured hours window.
     * The original SendCampaignEmailJob dispatched at order-paid time is left
     * in the queue: when it eventually runs it sees the delivery already
     * `sent` and no-ops, so no second email goes out.
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
            Log::channel('shopify_webhooks')->warning('Campaign update webhook received without order id', [
                'topic' => $topic,
                'webhook_id' => $webhookId,
            ]);

            return response()->json(['message' => 'No order id'], 200);
        }

        if ($webhookId) {
            $existing = ShopifyWebhookEvent::where('shopify_webhook_id', $webhookId)->first();
            if ($existing) {
                Log::channel('shopify_webhooks')->info('Duplicate campaign update webhook ignored', [
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
            Log::channel('shopify_webhooks')->error('Failed to persist campaign update webhook event', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Logged but not stored'], 200);
        }

        if (! (bool) config('campaign_email.expedite.enabled', true)) {
            Log::channel('shopify_webhooks')->info('Campaign expedite disabled; update webhook ignored', [
                'event_id' => $event->id,
                'order_id' => $orderId,
            ]);

            return response()->json(['message' => 'Expedite disabled'], 200);
        }

        if (! $this->scheduleResolver->hasSameDayUpgrade($order, 'campaign_email')) {
            return response()->json(['message' => 'No same-day upgrade'], 200);
        }

        /** @var Collection<int,CampaignDelivery> $deliveries */
        $deliveries = CampaignDelivery::where('shopify_order_id', $orderId)
            ->whereIn('status', [
                CampaignDelivery::STATUS_PENDING,
                CampaignDelivery::STATUS_GENERATED,
            ])
            ->whereNull('expedited_at')
            ->get();

        if ($deliveries->isEmpty()) {
            Log::channel('shopify_webhooks')->info('Same-day upgrade detected but no expeditable campaign deliveries', [
                'event_id' => $event->id,
                'order_id' => $orderId,
            ]);

            return response()->json(['message' => 'Nothing to expedite'], 200);
        }

        $existingExpeditedAt = CampaignDelivery::where('shopify_order_id', $orderId)
            ->whereNotNull('expedited_at')
            ->value('scheduled_at');

        $expeditedAt = $this->scheduleResolver->resolveExpeditedAt(
            $existingExpeditedAt ? Carbon::parse($existingExpeditedAt) : null,
            'campaign_email'
        );
        $now = Carbon::now();
        $expedited = 0;

        foreach ($deliveries as $delivery) {
            $delivery->forceFill([
                'scheduled_at' => $expeditedAt,
                'expedited_at' => $now,
            ])->save();

            $send = SendCampaignEmailJob::dispatch($delivery->id)
                ->onConnection(config('campaign_email.queue.connection'))
                ->onQueue(config('campaign_email.queue.mail'));

            if ($expeditedAt->isFuture()) {
                $send->delay($expeditedAt);
            }

            $expedited++;
        }

        Log::channel('shopify_webhooks')->info('Campaign send expedited', [
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
}
