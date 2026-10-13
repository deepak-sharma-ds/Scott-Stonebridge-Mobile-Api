<?php

declare(strict_types=1);

namespace App\Services\EmailReading\Webhooks;

use App\Contracts\Shopify\ShopifyWebhookHandlerInterface;
use App\Jobs\EmailReading\NotifyReadingFailureJob;
use App\Jobs\EmailReading\ProcessEmailReadingOrderJob;
use App\Models\CampaignProduct;
use App\Models\EmailReadingDelivery;
use App\Models\EmailReadingProduct;
use App\Models\ShopifyWebhookEvent;
use App\Models\UnlistedProduct;
use App\Services\Shopify\ShippingScheduleResolver;
use App\Services\Shopify\Webhooks\WebhookOutcome;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Handles a Shopify orders/paid event for Email Reading Automation: creates
 * one Delivery per automation-enabled Reading Product line item and queues
 * its generation.
 *
 * Reading and Campaign flows must never both handle a line item. A product
 * that is vip-tagged, or also registered as a Campaign Product, belongs to the
 * Campaign flow; if it is ALSO a Reading Product that is an ambiguous
 * registration, so the line becomes a failed Delivery for an admin to resolve
 * instead of being guessed at.
 */
class ReadingOrderPaidHandler implements ShopifyWebhookHandlerInterface
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

        $allowlist = (array) config('email_reading.test_emails', []);
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

        $matched = EmailReadingProduct::active()
            ->automationEnabled()
            ->whereIn('shopify_product_id', $productIds)
            ->get()
            ->keyBy('shopify_product_id');

        if ($matched->isEmpty()) {
            return WebhookOutcome::ignored('no_matching_product');
        }

        $conflictingIds = $this->campaignOwnedProductIds($matched->keys()->all());

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

        // When the customer already bought the same-day upgrade at purchase
        // time, deliver within 24h instead of the standard 3-7 day window.
        $sameDay = $this->scheduleResolver->hasSameDayUpgrade($order, 'email_reading')
            && (bool) config('email_reading.expedite.enabled', true);

        $existingScheduledAt = EmailReadingDelivery::where('shopify_order_id', $orderId)
            ->whereNotNull('scheduled_at')
            ->value('scheduled_at');

        $scheduledAt = $this->scheduleResolver->resolveScheduledAt(
            $existingScheduledAt ? Carbon::parse($existingScheduledAt) : null,
            $sameDay,
            'email_reading'
        );
        $expeditedAt = ($sameDay && $scheduledAt) ? Carbon::now() : null;

        $dispatched = 0;
        $conflicts = 0;

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

            if (in_array($productId, $conflictingIds, true)) {
                $delivery = EmailReadingDelivery::firstOrCreate(
                    ['shopify_line_item_id' => $lineItemId],
                    [
                        'shopify_order_id' => $orderId,
                        'email_reading_product_id' => $product->id,
                        'customer_email' => $customerEmail,
                        'customer_name' => $customerName ?: null,
                        'questions' => $this->mapProperties($line['properties'] ?? []),
                        'status' => EmailReadingDelivery::STATUS_FAILED,
                        'error_message' => 'Product is registered as both a Reading Product and a vip/Campaign product; not processed automatically. Resolve the registration, then reactivate.',
                    ]
                );

                if ($delivery->wasRecentlyCreated) {
                    NotifyReadingFailureJob::dispatch($delivery->id, (string) $delivery->error_message)
                        ->onConnection(config('email_reading.queue.connection'))
                        ->onQueue(config('email_reading.queue.mail'));
                    $conflicts++;
                }

                continue;
            }

            $delivery = EmailReadingDelivery::firstOrCreate(
                ['shopify_line_item_id' => $lineItemId],
                [
                    'shopify_order_id' => $orderId,
                    'email_reading_product_id' => $product->id,
                    'customer_email' => $customerEmail,
                    'customer_name' => $customerName ?: null,
                    'questions' => $this->mapProperties($line['properties'] ?? []),
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
            'conflicts' => $conflicts,
            'scheduled_at' => $scheduledAt?->toIso8601String(),
            'same_day' => $sameDay,
        ]);

        return WebhookOutcome::processed();
    }

    /**
     * Of the given Reading Product ids, those that the Campaign flow also
     * claims: vip-tagged in the unlisted catalog, or a Campaign Product.
     *
     * @param  array<int,int>  $productIds
     * @return array<int,int>
     */
    private function campaignOwnedProductIds(array $productIds): array
    {
        $vip = UnlistedProduct::whereIn('shopify_product_id', $productIds)
            ->where('is_vip', true)
            ->pluck('shopify_product_id');

        $campaign = CampaignProduct::whereIn('shopify_product_id', $productIds)
            ->pluck('shopify_product_id');

        return $vip->merge($campaign)->map(fn ($id) => (int) $id)->unique()->values()->all();
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
     *
     * @param  array<int,array<string,mixed>>  $properties
     * @return array<string,mixed>
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
