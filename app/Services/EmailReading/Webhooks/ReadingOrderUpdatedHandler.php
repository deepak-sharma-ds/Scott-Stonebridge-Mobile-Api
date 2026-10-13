<?php

declare(strict_types=1);

namespace App\Services\EmailReading\Webhooks;

use App\Contracts\Shopify\ShopifyWebhookHandlerInterface;
use App\Models\EmailReadingDelivery;
use App\Models\EmailReadingProduct;
use App\Models\ShopifyWebhookEvent;
use App\Services\EmailReading\EmailReadingDeliveryLifecycle;
use App\Services\Shopify\ShippingScheduleResolver;
use App\Services\Shopify\Webhooks\WebhookOutcome;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Handles a Shopify orders/updated event for Email Reading Automation.
 *
 * When the customer buys the "same day" upgrade on an existing reading order,
 * Shopify edits the order and fires this event with the upgrade as a shipping
 * line. The order's unsent deliveries are pulled forward to a random time
 * within the configured hours window. The delivery row alone decides when it
 * sends (the dispatcher reads the live scheduled_at), so no queued job needs
 * to be touched.
 *
 * orders/updated can arrive before orders/paid has been processed; in that
 * case this throws so the inbox retries with backoff until the deliveries
 * exist, instead of silently losing the upgrade.
 */
class ReadingOrderUpdatedHandler implements ShopifyWebhookHandlerInterface
{
    public function __construct(
        private readonly ShippingScheduleResolver $scheduleResolver,
        private readonly EmailReadingDeliveryLifecycle $lifecycle,
    ) {}

    public function handle(ShopifyWebhookEvent $event): WebhookOutcome
    {
        $payload = (array) $event->payload;
        $order = $payload['order'] ?? $payload;
        $orderId = (int) $event->shopify_order_id;

        $allowlist = (array) config('email_reading.test_emails', []);
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

        if (! (bool) config('email_reading.expedite.enabled', true)) {
            return WebhookOutcome::ignored('expedite_disabled');
        }

        if (! $this->scheduleResolver->hasSameDayUpgrade($order, 'email_reading')) {
            return WebhookOutcome::ignored('no_same_day_upgrade');
        }

        $productIds = collect((array) ($order['line_items'] ?? []))
            ->pluck('product_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();

        $hasReadingProducts = $productIds !== []
            && EmailReadingProduct::active()->automationEnabled()->whereIn('shopify_product_id', $productIds)->exists();

        if (! $hasReadingProducts) {
            return WebhookOutcome::ignored('no_reading_products');
        }

        if (! EmailReadingDelivery::where('shopify_order_id', $orderId)->exists()) {
            throw new RuntimeException("Order {$orderId} has reading products but its orders/paid event has not been processed yet; retrying.");
        }

        $existingExpeditedAt = EmailReadingDelivery::where('shopify_order_id', $orderId)
            ->whereNotNull('expedited_at')
            ->value('scheduled_at');

        $expeditedAt = $this->scheduleResolver->resolveExpeditedAt(
            $existingExpeditedAt ? Carbon::parse($existingExpeditedAt) : null,
            'email_reading'
        );

        $expedited = $this->lifecycle->expediteOrder($orderId, $expeditedAt);

        if ($expedited === 0) {
            return WebhookOutcome::ignored('nothing_to_expedite');
        }

        Log::channel('shopify_webhooks')->info('Reading send expedited', [
            'event_id' => $event->id,
            'order_id' => $orderId,
            'expedited' => $expedited,
            'expedited_at' => $expeditedAt->toIso8601String(),
        ]);

        return WebhookOutcome::processed();
    }
}
