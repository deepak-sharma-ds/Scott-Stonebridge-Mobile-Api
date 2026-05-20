<?php

declare(strict_types=1);

namespace App\Services\AI\MCP\Mappers;

use App\DTOs\AI\OrderTrackingDTO;
use App\DTOs\Chat\OrderSummaryDTO;

final class OrderMapper
{
    /**
     * @param  array<string, mixed>  $mcpResult  `result` payload from `get_order_status` / `get_most_recent_order_status`.
     */
    public static function fromOrderStatus(array $mcpResult): ?OrderTrackingDTO
    {
        $order = $mcpResult['order'] ?? $mcpResult;
        if (! is_array($order)) {
            return null;
        }

        $orderNumber = self::extractOrderNumber($order);
        if ($orderNumber === null) {
            return null;
        }

        $status = OrderTrackingDTO::statusFromShopifyEnums(
            (string) ($order['fulfillment_status'] ?? $order['displayFulfillmentStatus'] ?? ''),
            (string) ($order['financial_status'] ?? $order['displayFinancialStatus'] ?? ''),
        );

        $tracking = $order['tracking'] ?? $order['fulfillments'][0]['tracking_info'][0] ?? $order['fulfillments'][0]['trackingInfo'][0] ?? [];
        if (! is_array($tracking)) {
            $tracking = [];
        }

        return new OrderTrackingDTO(
            orderNumber: $orderNumber,
            status: $status,
            trackingNumber: self::stringOrNull($tracking['number'] ?? $tracking['tracking_number'] ?? null),
            trackingUrl: self::stringOrNull($tracking['url'] ?? $tracking['tracking_url'] ?? null),
            carrier: self::stringOrNull($tracking['company'] ?? $tracking['carrier'] ?? null),
            estimatedDelivery: self::stringOrNull(
                $order['estimated_delivery']
                ?? $order['fulfillments'][0]['estimated_delivery_at']
                ?? $order['fulfillments'][0]['estimatedDeliveryAt']
                ?? null,
            ),
            shipToCity: self::stringOrNull(
                $order['ship_to']['city']
                ?? $order['shipping_address']['city']
                ?? $order['shippingAddress']['city']
                ?? null,
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $mcpResult
     * @return list<OrderSummaryDTO>
     */
    public static function fromOrderList(array $mcpResult): array
    {
        $orders = $mcpResult['orders'] ?? [];
        if (! is_array($orders)) {
            return [];
        }

        $out = [];
        foreach ($orders as $order) {
            if (! is_array($order)) {
                continue;
            }

            $number = self::extractOrderNumber($order);
            if ($number === null) {
                continue;
            }

            $status = OrderTrackingDTO::statusFromShopifyEnums(
                (string) ($order['fulfillment_status'] ?? $order['displayFulfillmentStatus'] ?? ''),
                (string) ($order['financial_status'] ?? $order['displayFinancialStatus'] ?? ''),
            );

            $out[] = new OrderSummaryDTO(
                orderNumber: $number,
                status: $status,
                financialStatus: self::stringOrNull($order['financial_status'] ?? $order['displayFinancialStatus'] ?? null),
                totalMinorUnits: self::priceMinor($order['total'] ?? $order['total_price'] ?? null),
                currency: self::stringOrNull($order['currency'] ?? $order['currency_code'] ?? null),
                createdAt: self::stringOrNull($order['created_at'] ?? $order['createdAt'] ?? null),
            );
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private static function extractOrderNumber(array $order): ?string
    {
        $candidates = [
            $order['order_number'] ?? null,
            $order['name'] ?? null,
            $order['number'] ?? null,
        ];

        foreach ($candidates as $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $string = is_string($value) ? $value : (string) $value;

            return ltrim($string, '#');
        }

        return null;
    }

    private static function priceMinor(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            if (isset($value['minor_units']) && is_numeric($value['minor_units'])) {
                return (int) $value['minor_units'];
            }
            $value = $value['amount'] ?? null;
            if ($value === null) {
                return null;
            }
        }

        if (is_numeric($value)) {
            return (int) round(((float) $value) * 100);
        }

        return null;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_string($value) ? $value : (string) $value;
    }
}
