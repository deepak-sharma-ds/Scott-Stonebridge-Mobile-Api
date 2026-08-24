<?php

declare(strict_types=1);

namespace App\Services\AI\MCP\Mappers;

use App\DTOs\AI\CustomerOrderSummaryDTO;
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

    /**
     * Map a Customer Account `customer.orders` connection onto a list of
     * order summaries plus the pagination cursor for the `order_list` chunk.
     *
     * @param  array<string, mixed>  $data  GraphQL `data` payload (already unwrapped).
     * @return array{orders: list<CustomerOrderSummaryDTO>, page_info: array{has_next_page: bool, cursor: string|null}}
     */
    public static function listFromConnection(array $data): array
    {
        $connection = $data['customer']['orders'] ?? [];
        $edges = is_array($connection) && is_array($connection['edges'] ?? null)
            ? $connection['edges']
            : [];

        $orders = [];
        foreach ($edges as $edge) {
            $node = is_array($edge) ? ($edge['node'] ?? null) : null;
            if (! is_array($node)) {
                continue;
            }
            $summary = self::summaryFromNode($node);
            if ($summary !== null) {
                $orders[] = $summary;
            }
        }

        $pageInfo = is_array($connection) && is_array($connection['pageInfo'] ?? null)
            ? $connection['pageInfo']
            : [];

        return [
            'orders' => $orders,
            'page_info' => [
                'has_next_page' => (bool) ($pageInfo['hasNextPage'] ?? false),
                'cursor' => self::stringOrNull($pageInfo['endCursor'] ?? null),
            ],
        ];
    }

    /**
     * Map a single `customer.orders` node onto a lightweight list-row DTO.
     *
     * @param  array<string, mixed>  $node
     */
    public static function summaryFromNode(array $node): ?CustomerOrderSummaryDTO
    {
        $name = (string) ($node['name'] ?? $node['number'] ?? '');
        if ($name === '') {
            return null;
        }

        $status = OrderTrackingDTO::statusFromShopifyEnums(
            (string) ($node['fulfillmentStatus'] ?? $node['displayFulfillmentStatus'] ?? ''),
            (string) ($node['financialStatus'] ?? $node['displayFinancialStatus'] ?? ''),
        );

        $total = $node['totalPrice'] ?? $node['totalPriceSet']['presentmentMoney'] ?? $node['totalPriceSet']['shopMoney'] ?? [];
        if (! is_array($total)) {
            $total = [];
        }

        return new CustomerOrderSummaryDTO(
            orderNumber: ltrim($name, '#'),
            name: str_starts_with($name, '#') ? $name : '#'.$name,
            status: $status,
            processedAt: self::stringOrNull($node['processedAt'] ?? null),
            totalAmount: self::stringOrNull($total['amount'] ?? null),
            currencyCode: self::stringOrNull($total['currencyCode'] ?? null),
            orderUrl: self::stringOrNull($node['statusPageUrl'] ?? $node['statusUrl'] ?? null),
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
