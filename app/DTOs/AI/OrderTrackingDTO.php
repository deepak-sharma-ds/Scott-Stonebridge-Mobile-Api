<?php

declare(strict_types=1);

namespace App\DTOs\AI;

use App\DTOs\Base\BaseDTO;

/**
 * Trimmed order-status payload returned by OrderTrackingService. Maps the
 * Shopify Admin `displayFulfillmentStatus` enum into a frontend-friendly
 * lowercase shape and surfaces the first non-empty tracking row so the chat
 * widget can render a single "Track your order" link without juggling the
 * full Shopify fulfillment object.
 */
class OrderTrackingDTO extends BaseDTO
{
    /** Allowed values for $status — kept in sync with the frontend widget UI. */
    public const STATUSES = [
        'processing',
        'in_transit',
        'shipped',
        'delivered',
        'cancelled',
        'unfulfilled',
    ];

    public function __construct(
        public readonly string $orderNumber,        // "1234" — no leading '#'
        public readonly string $status,             // one of self::STATUSES
        public readonly ?string $trackingNumber,
        public readonly ?string $trackingUrl,
        public readonly ?string $carrier,
        public readonly ?string $estimatedDelivery, // ISO 8601 date or null
        public readonly ?string $shipToCity,
        public readonly array $lineItems = [],
        public readonly ?string $shippingTitle = null,
    ) {
        $this->validate();
    }

    protected function validate(): void
    {
        $this->validateRequired($this->orderNumber, 'orderNumber');
        $this->validateRequired($this->status, 'status');
        $this->validateInArray($this->status, self::STATUSES, 'status');
    }

    /**
     * Build from a Shopify Admin `orders.edges[0].node` payload.
     *
     * @param  array<string, mixed>  $node
     */
    public static function fromShopifyNode(array $node): self
    {
        // "Order #1234" comes through as `name = "#1234"` — strip the prefix.
        $name = (string) ($node['name'] ?? '');
        $orderNumber = ltrim($name, '#');

        $status = self::mapStatus(
            (string) ($node['displayFulfillmentStatus'] ?? ''),
            (string) ($node['displayFinancialStatus'] ?? ''),
        );

        $firstFulfilment = $node['fulfillments'][0] ?? null;
        $firstTracking = $firstFulfilment['trackingInfo'][0] ?? null;

        $lineItems = [];
        $rawLines = $node['lineItems']['edges'] ?? $node['lineItems'] ?? [];
        if (is_array($rawLines)) {
            foreach ($rawLines as $edge) {
                $itemNode = is_array($edge) && isset($edge['node']) ? $edge['node'] : $edge;
                if (! is_array($itemNode)) {
                    continue;
                }
                $price = $itemNode['originalUnitPriceSet']['presentmentMoney']['amount']
                    ?? $itemNode['originalUnitPriceSet']['shopMoney']['amount']
                    ?? $itemNode['discountedUnitPriceSet']['presentmentMoney']['amount']
                    ?? $itemNode['price']
                    ?? null;
                $currency = $itemNode['originalUnitPriceSet']['presentmentMoney']['currencyCode']
                    ?? $itemNode['originalUnitPriceSet']['shopMoney']['currencyCode']
                    ?? 'GBP';

                $customAttrs = [];
                $rawAttrs = $itemNode['customAttributes'] ?? [];
                if (is_array($rawAttrs)) {
                    foreach ($rawAttrs as $attr) {
                        if (is_array($attr) && isset($attr['key'])) {
                            $customAttrs[$attr['key']] = $attr['value'] ?? '';
                        }
                    }
                }

                $lineItems[] = [
                    'title' => (string) ($itemNode['title'] ?? ''),
                    'quantity' => (int) ($itemNode['quantity'] ?? 1),
                    'variant_title' => self::stringOrNull($itemNode['variantTitle'] ?? $itemNode['variant']['title'] ?? null),
                    'price' => self::stringOrNull($price),
                    'currency' => (string) $currency,
                    'custom_attributes' => $customAttrs,
                ];
            }
        }

        $shippingTitle = $node['shippingLine']['title']
            ?? $node['shippingLines']['edges'][0]['node']['title']
            ?? $node['shippingLines'][0]['title']
            ?? null;

        return new self(
            orderNumber: $orderNumber,
            status: $status,
            trackingNumber: self::stringOrNull($firstTracking['number'] ?? null),
            trackingUrl: self::stringOrNull($firstTracking['url'] ?? null),
            carrier: self::stringOrNull($firstTracking['company'] ?? null),
            estimatedDelivery: self::stringOrNull($firstFulfilment['estimatedDeliveryAt'] ?? null),
            shipToCity: self::stringOrNull($node['shippingAddress']['city'] ?? null),
            lineItems: $lineItems,
            shippingTitle: self::stringOrNull($shippingTitle),
        );
    }

    /**
     * Render snake_case JSON keys (BaseDTO::toArray uses property names which
     * are camelCase, so we override to keep the API envelope consistent with
     * the rest of the /api/v1/ai/* responses).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'order_number' => $this->orderNumber,
            'status' => $this->status,
            'tracking_number' => $this->trackingNumber,
            'tracking_url' => $this->trackingUrl,
            'carrier' => $this->carrier,
            'estimated_delivery' => $this->estimatedDelivery,
            'ship_to_city' => $this->shipToCity,
            'line_items' => $this->lineItems,
            'shipping_title' => $this->shippingTitle,
        ];
    }

    /**
     * Public helper used by MCP mappers and the legacy Admin GraphQL path.
     */
    public static function statusFromShopifyEnums(string $fulfilment, string $financial): string
    {
        return self::mapStatus($fulfilment, $financial);
    }

    /**
     * Map Shopify enums onto the widget UI's status vocabulary. Financial
     * status only sways the verdict toward "cancelled" — fulfillment status
     * otherwise wins.
     */
    private static function mapStatus(string $fulfilment, string $financial): string
    {
        $fulfilment = strtoupper($fulfilment);
        $financial = strtoupper($financial);

        if ($financial === 'VOIDED' || $financial === 'REFUNDED') {
            return 'cancelled';
        }

        return match ($fulfilment) {
            'FULFILLED', 'DELIVERED' => 'delivered',
            'IN_TRANSIT', 'OUT_FOR_DELIVERY' => 'in_transit',
            'PARTIALLY_FULFILLED' => 'shipped',
            'UNFULFILLED', '' => 'processing',
            default => 'processing',
        };
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_string($value) ? $value : (string) $value;
    }
}
