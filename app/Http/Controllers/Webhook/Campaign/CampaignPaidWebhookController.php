<?php

namespace App\Http\Controllers\Webhook\Campaign;

use App\Http\Controllers\Controller;
use App\Jobs\Campaign\NotifyCampaignFailureJob;
use App\Models\CampaignDelivery;
use App\Models\CampaignProduct;
use App\Models\MarketingCampaign;
use App\Models\ShopifyWebhookEvent;
use App\Services\Shopify\ShippingScheduleResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class CampaignPaidWebhookController extends Controller
{
    public function __construct(private readonly ShippingScheduleResolver $scheduleResolver) {}

    /**
     * Handle a Shopify orders/paid webhook for Campaign Email Automation.
     *
     * Fully isolated from the reading flow's own orders/paid webhook (separate
     * route, separate Shopify subscription, separate tables). For each line
     * item whose product is registered against any campaign, resolves the
     * hidden `_campaign_key` line-item property to exactly one active
     * campaign + product + pre-generated response. Any resolution failure is
     * recorded as a failed delivery and flagged for a human — never guessed,
     * never retried, never auto-fulfilled.
     */
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
            Log::channel('shopify_webhooks')->warning('Campaign webhook received without order id', [
                'topic' => $topic,
                'webhook_id' => $webhookId,
            ]);

            return response()->json(['message' => 'No order id'], 200);
        }

        if ($webhookId) {
            $existing = ShopifyWebhookEvent::where('shopify_webhook_id', $webhookId)->first();
            if ($existing) {
                Log::channel('shopify_webhooks')->info('Duplicate campaign webhook ignored', [
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
            Log::channel('shopify_webhooks')->error('Failed to persist campaign webhook event', [
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
            Log::channel('shopify_webhooks')->warning('Campaign order has no customer email', [
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

        $campaignProductsByProductId = CampaignProduct::whereIn('shopify_product_id', $productIds)
            ->get()
            ->groupBy('shopify_product_id');

        if ($campaignProductsByProductId->isEmpty()) {
            return response()->json(['message' => 'No campaign products in order'], 200);
        }

        $sameDay = $this->scheduleResolver->hasSameDayUpgrade($order, 'campaign_email')
            && (bool) config('campaign_email.expedite.enabled', true);

        $existingScheduledAt = CampaignDelivery::where('shopify_order_id', $orderId)
            ->whereNotNull('scheduled_at')
            ->value('scheduled_at');

        $scheduledAt = $this->scheduleResolver->resolveScheduledAt(
            $existingScheduledAt ? Carbon::parse($existingScheduledAt) : null,
            $sameDay,
            'campaign_email'
        );
        $expeditedAt = ($sameDay && $scheduledAt) ? Carbon::now() : null;

        $created = 0;
        $failed = 0;

        foreach ($lineItems as $line) {
            $productId = isset($line['product_id']) ? (int) $line['product_id'] : 0;
            $lineItemId = isset($line['id']) ? (int) $line['id'] : 0;

            if ($productId === 0 || $lineItemId === 0) {
                continue;
            }

            /** @var Collection<int,CampaignProduct>|null $candidates */
            $candidates = $campaignProductsByProductId->get($productId);
            if (! $candidates) {
                continue;
            }

            [$campaignProduct, $failureReason] = $this->resolvePairing($line, $candidates);

            if ($failureReason !== null) {
                $delivery = CampaignDelivery::firstOrCreate(
                    ['shopify_line_item_id' => $lineItemId],
                    [
                        'shopify_order_id' => $orderId,
                        'campaign_product_id' => $campaignProduct?->id,
                        'customer_email' => $customerEmail,
                        'customer_name' => $customerName ?: null,
                        'status' => CampaignDelivery::STATUS_FAILED,
                        'error_message' => $failureReason,
                    ]
                );

                if ($delivery->wasRecentlyCreated) {
                    NotifyCampaignFailureJob::dispatch($delivery->id, $failureReason)
                        ->onConnection(config('campaign_email.queue.connection'))
                        ->onQueue(config('campaign_email.queue.mail'));
                    $failed++;
                }

                continue;
            }

            $delivery = CampaignDelivery::firstOrCreate(
                ['shopify_line_item_id' => $lineItemId],
                [
                    'shopify_order_id' => $orderId,
                    'campaign_product_id' => $campaignProduct->id,
                    'customer_email' => $customerEmail,
                    'customer_name' => $customerName ?: null,
                    'status' => CampaignDelivery::STATUS_PENDING,
                    'scheduled_at' => $scheduledAt,
                    'expedited_at' => $expeditedAt,
                ]
            );

            if ($delivery->wasRecentlyCreated) {
                $created++;
            }
        }

        Log::channel('shopify_webhooks')->info('Campaign webhook processed', [
            'event_id' => $event->id,
            'order_id' => $orderId,
            'created' => $created,
            'failed' => $failed,
            'scheduled_at' => $scheduledAt?->toIso8601String(),
            'same_day' => $sameDay,
        ]);

        return response()->json([
            'message' => 'OK',
            'created' => $created,
            'failed' => $failed,
        ], 200);
    }

    /**
     * Resolve a line item to its (campaign, product) pairing, or a specific
     * fail-safe reason. Never guesses: a campaign-linked product with no
     * resolvable `_campaign_key` fails rather than picking one of its
     * candidate campaigns.
     *
     * @param  Collection<int,CampaignProduct>  $candidates  every CampaignProduct row for this Shopify product, across all campaigns
     * @return array{0: ?CampaignProduct, 1: ?string}
     */
    private function resolvePairing(array $line, Collection $candidates): array
    {
        $campaignKey = null;
        foreach ((array) ($line['properties'] ?? []) as $prop) {
            if ((string) ($prop['name'] ?? '') === '_campaign_key') {
                $campaignKey = trim((string) ($prop['value'] ?? ''));
                break;
            }
        }

        if (! $campaignKey) {
            return [null, 'missing _campaign_key'];
        }

        $campaign = MarketingCampaign::where('campaign_key', $campaignKey)->first();
        if (! $campaign) {
            return [null, "unknown campaign_key: {$campaignKey}"];
        }

        if ($campaign->status !== MarketingCampaign::STATUS_ACTIVE) {
            return [null, "campaign not active: {$campaignKey}"];
        }

        $campaignProduct = $candidates->firstWhere('marketing_campaign_id', $campaign->id);
        if (! $campaignProduct) {
            return [null, "no campaign product for pairing: {$campaignKey}"];
        }

        if (! $campaignProduct->response) {
            return [$campaignProduct, 'no response generated for pairing'];
        }

        return [$campaignProduct, null];
    }
}
