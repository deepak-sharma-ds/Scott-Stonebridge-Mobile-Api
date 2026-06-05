<?php

declare(strict_types=1);

namespace App\Services\AI\MCP\Mappers;

use App\DTOs\AI\OrderTrackingDTO;

/**
 * Maps the Customer Account GraphQL `customer.orders` payload onto the same
 * `OrderTrackingDTO` shape produced by the MCP `OrderMapper`. Keeping the
 * DTO identical means downstream chunk renderers (`order_tracking`) don't
 * branch by source.
 */
final class CustomerGraphOrderMapper
{
    /**
     * @param  array<string, mixed>  $data  GraphQL `data` payload (already unwrapped).
     */
    public static function fromMostRecent(array $data): ?OrderTrackingDTO
    {
        $node = $data['customer']['orders']['edges'][0]['node'] ?? null;
        if (! is_array($node)) {
            return null;
        }

        return self::fromOrderNode($node);
    }

    /**
     * @param  array<string, mixed>  $node
     */
    public static function fromOrderNode(array $node): ?OrderTrackingDTO
    {
        $name = (string) ($node['name'] ?? $node['number'] ?? '');
        if ($name === '') {
            return null;
        }
        $orderNumber = ltrim($name, '#');

        $status = OrderTrackingDTO::statusFromShopifyEnums(
            (string) ($node['fulfillmentStatus'] ?? $node['displayFulfillmentStatus'] ?? ''),
            (string) ($node['financialStatus'] ?? $node['displayFinancialStatus'] ?? ''),
        );

        $fulfillment = $node['fulfillments']['edges'][0]['node']
            ?? $node['fulfillments'][0]
            ?? null;
        if (! is_array($fulfillment)) {
            $fulfillment = [];
        }

        $tracking = $fulfillment['trackingInformation'][0]
            ?? $fulfillment['trackingInfo'][0]
            ?? [];
        if (! is_array($tracking)) {
            $tracking = [];
        }

        return new OrderTrackingDTO(
            orderNumber: $orderNumber,
            status: $status,
            trackingNumber: self::stringOrNull($tracking['number'] ?? null),
            trackingUrl: self::stringOrNull($tracking['url'] ?? null),
            carrier: self::stringOrNull($tracking['company'] ?? null),
            estimatedDelivery: self::stringOrNull(
                $fulfillment['estimatedDeliveryAt']
                ?? $fulfillment['estimated_delivery_at']
                ?? null,
            ),
            shipToCity: self::stringOrNull($node['shippingAddress']['city'] ?? null),
        );
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_string($value) ? $value : (string) $value;
    }
}
