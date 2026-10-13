<?php

declare(strict_types=1);

namespace App\Services\CampaignEmail\Webhooks;

use App\Contracts\Shopify\ShopifyWebhookHandlerInterface;
use App\Jobs\Campaign\NotifyCampaignFailureJob;
use App\Models\CampaignDelivery;
use App\Models\CampaignProduct;
use App\Models\EmailReadingProduct;
use App\Models\MarketingCampaign;
use App\Models\ShopifyWebhookEvent;
use App\Models\UnlistedProduct;
use App\Services\Shopify\ShippingScheduleResolver;
use App\Services\Shopify\Webhooks\WebhookOutcome;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Handles a Shopify orders/paid event for Campaign Email Automation.
 *
 * Fully isolated from the reading flow (separate endpoint, handler, tables).
 * For each line item whose product is registered against any campaign,
 * resolves the hidden `_campaign_key` line-item property to exactly one active
 * campaign + product + pre-generated response. Any resolution failure is
 * recorded as a failed delivery and flagged for a human — never guessed,
 * never retried, never auto-fulfilled.
 *
 * Campaign products must be vip-tagged (the `is_vip` snapshot synced from
 * Shopify). A product that is ALSO an automation-enabled Reading Product is
 * an ambiguous registration and is refused the same way.
 */
class CampaignOrderPaidHandler implements ShopifyWebhookHandlerInterface
{
    public function __construct(private readonly ShippingScheduleResolver $scheduleResolver) {}

    public function handle(ShopifyWebhookEvent $event): WebhookOutcome
    {
        $payload = (array) $event->payload;
        $order = $payload['order'] ?? $payload;
        $orderId = (int) $event->shopify_order_id;

        $customerEmail = $order['email']
            ?? $order['contact_email']
            ?? ($order['customer']['email'] ?? null);

        $allowlist = (array) config('campaign_email.test_emails', []);
        if (! empty($allowlist)) {
            $incoming = strtolower(trim((string) $customerEmail));

            if ($incoming === '' || ! in_array($incoming, $allowlist, true)) {
                return WebhookOutcome::ignored('not_in_test_allowlist');
            }
        }

        if (! $customerEmail) {
            return WebhookOutcome::ignored('no_customer_email');
        }

        $lineItems = (array) ($order['line_items'] ?? []);

        $productIds = collect($lineItems)
            ->pluck('product_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();

        if (empty($productIds)) {
            return WebhookOutcome::ignored('no_line_items');
        }

        $campaignProductsByProductId = CampaignProduct::whereIn('shopify_product_id', $productIds)
            ->get()
            ->groupBy('shopify_product_id');

        if ($campaignProductsByProductId->isEmpty()) {
            return WebhookOutcome::ignored('no_matching_product');
        }

        $registeredIds = $campaignProductsByProductId->keys()->map(fn ($id) => (int) $id)->all();

        $vipIds = UnlistedProduct::whereIn('shopify_product_id', $registeredIds)
            ->where('is_vip', true)
            ->pluck('shopify_product_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $readingIds = EmailReadingProduct::active()
            ->automationEnabled()
            ->whereIn('shopify_product_id', $registeredIds)
            ->pluck('shopify_product_id')
            ->map(fn ($id) => (int) $id)
            ->all();

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

            if (in_array($productId, $readingIds, true)) {
                [$campaignProduct, $failureReason] = [null, 'product is registered as both a Campaign Product and a Reading Product'];
            } elseif (! in_array($productId, $vipIds, true)) {
                [$campaignProduct, $failureReason] = [null, 'product is not vip-tagged in the synced catalog'];
            } else {
                [$campaignProduct, $failureReason] = $this->resolvePairing($line, $candidates);
            }

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

            // The delivery row alone decides when it sends: the dispatcher
            // claims it once scheduled_at is due (no delayed job is queued).
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

        return WebhookOutcome::processed();
    }

    /**
     * Resolve a line item to its (campaign, product) pairing, or a specific
     * fail-safe reason. Never guesses: a campaign-linked product with no
     * resolvable `_campaign_key` fails rather than picking one of its
     * candidate campaigns.
     *
     * @param  array<string,mixed>  $line
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
