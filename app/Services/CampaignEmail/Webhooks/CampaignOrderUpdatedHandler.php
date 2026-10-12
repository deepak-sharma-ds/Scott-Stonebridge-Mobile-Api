<?php

declare(strict_types=1);

namespace App\Services\CampaignEmail\Webhooks;

use App\Contracts\Shopify\ShopifyWebhookHandlerInterface;
use App\Models\CampaignDelivery;
use App\Models\CampaignProduct;
use App\Models\ShopifyWebhookEvent;
use App\Services\CampaignEmail\CampaignDeliveryLifecycle;
use App\Services\Shopify\ShippingScheduleResolver;
use App\Services\Shopify\Webhooks\WebhookOutcome;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Handles a Shopify orders/updated event for Campaign Email Automation.
 *
 * When the customer buys the same-day upgrade on an existing campaign order,
 * the order's unsent campaign deliveries are pulled forward to a random time
 * within the configured hours window. The delivery row alone decides when it
 * sends, so no queued job is touched. orders/updated arriving before
 * orders/paid was processed throws so the inbox retries with backoff.
 */
class CampaignOrderUpdatedHandler implements ShopifyWebhookHandlerInterface
{
    public function __construct(
        private readonly ShippingScheduleResolver $scheduleResolver,
        private readonly CampaignDeliveryLifecycle $lifecycle,
    ) {}

    public function handle(ShopifyWebhookEvent $event): WebhookOutcome
    {
        $payload = (array) $event->payload;
        $order = $payload['order'] ?? $payload;
        $orderId = (int) $event->shopify_order_id;

        $allowlist = (array) config('campaign_email.test_emails', []);
        if (! empty($allowlist)) {
            $incoming = strtolower(trim((string) (
                $order['email']
                ?? $order['contact_email']
                ?? ($order['customer']['email'] ?? '')
            )));

            if ($incoming === '' || ! in_array($incoming, $allowlist, true)) {
                return WebhookOutcome::ignored('not_in_test_allowlist');
            }
        }

        if (! (bool) config('campaign_email.expedite.enabled', true)) {
            return WebhookOutcome::ignored('expedite_disabled');
        }

        if (! $this->scheduleResolver->hasSameDayUpgrade($order, 'campaign_email')) {
            return WebhookOutcome::ignored('no_same_day_upgrade');
        }

        $productIds = collect((array) ($order['line_items'] ?? []))
            ->pluck('product_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();

        $hasCampaignProducts = $productIds !== []
            && CampaignProduct::whereIn('shopify_product_id', $productIds)->exists();

        if (! $hasCampaignProducts) {
            return WebhookOutcome::ignored('no_campaign_products');
        }

        if (! CampaignDelivery::where('shopify_order_id', $orderId)->exists()) {
            throw new RuntimeException("Order {$orderId} has campaign products but its orders/paid event has not been processed yet; retrying.");
        }

        $existingExpeditedAt = CampaignDelivery::where('shopify_order_id', $orderId)
            ->whereNotNull('expedited_at')
            ->value('scheduled_at');

        $expeditedAt = $this->scheduleResolver->resolveExpeditedAt(
            $existingExpeditedAt ? Carbon::parse($existingExpeditedAt) : null,
            'campaign_email'
        );

        $expedited = $this->lifecycle->expediteOrder($orderId, $expeditedAt);

        if ($expedited === 0) {
            return WebhookOutcome::ignored('nothing_to_expedite');
        }

        Log::channel('shopify_webhooks')->info('Campaign send expedited', [
            'event_id' => $event->id,
            'order_id' => $orderId,
            'expedited' => $expedited,
            'expedited_at' => $expeditedAt->toIso8601String(),
        ]);

        return WebhookOutcome::processed();
    }
}
