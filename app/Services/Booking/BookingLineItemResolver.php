<?php

declare(strict_types=1);

namespace App\Services\Booking;

use Carbon\Carbon;

/**
 * Resolves authentic appointment booking items from Shopify order payloads
 * using a 3-tier sequential matching hierarchy (product_id -> handle -> sku)
 * and isolates line-item properties to prevent property bleed across multi-product carts.
 */
class BookingLineItemResolver
{
    /**
     * Resolve the authentic booking item and its isolated parameters from an order payload.
     *
     * @param  array<string, mixed>  $order
     * @return array{
     *     order_id: int|string,
     *     line_item_id: int,
     *     product_id: int,
     *     name: string,
     *     email: string,
     *     phone: ?string,
     *     availability_date: string,
     *     time_slot_id: int,
     *     properties: array<string, mixed>
     * }|null
     */
    public function resolve(array $order): ?array
    {
        $lineItems = (array) ($order['line_items'] ?? []);
        if (empty($lineItems)) {
            return null;
        }

        $configuredProductIds = array_map(fn ($id) => (int) $id, (array) config('booking.product_ids', []));
        $configuredHandles = array_map(fn ($h) => strtolower(trim((string) $h)), (array) config('booking.product_handles', []));
        $configuredSkus = array_map(fn ($s) => strtolower(trim((string) $s)), (array) config('booking.product_skus', []));

        $hasTargetingConfig = ! empty($configuredProductIds) || ! empty($configuredHandles) || ! empty($configuredSkus);

        $candidates = [];

        foreach ($lineItems as $lineItem) {
            if (! is_array($lineItem)) {
                continue;
            }

            $tier = $this->determineMatchingTier($lineItem, $configuredProductIds, $configuredHandles, $configuredSkus);
            if ($hasTargetingConfig && $tier === null) {
                continue;
            }

            $properties = $this->extractProperties($lineItem);

            $timeSlotId = isset($properties['time_slot_id']) ? (int) $properties['time_slot_id'] : 0;
            $availabilityDate = isset($properties['availability_date']) ? trim((string) $properties['availability_date']) : '';

            if ($timeSlotId <= 0 || $availabilityDate === '') {
                continue;
            }

            // Customer Email
            $customerEmail = trim(
                (string) ($properties['email']
                    ?? $order['email']
                    ?? $order['contact_email']
                    ?? ($order['customer']['email'] ?? ''))
            );

            if ($customerEmail === '') {
                continue;
            }

            $candidates[] = [
                'tier' => $tier ?? 99,
                'lineItem' => $lineItem,
                'properties' => $properties,
                'timeSlotId' => $timeSlotId,
                'availabilityDate' => $availabilityDate,
                'customerEmail' => $customerEmail,
            ];
        }

        if (empty($candidates)) {
            return null;
        }

        // Sort candidates so lowest tier number (1 = product_id, 2 = handle, 3 = sku) wins
        usort($candidates, fn ($a, $b) => $a['tier'] <=> $b['tier']);

        $selected = $candidates[0];
        $lineItem = $selected['lineItem'];
        $properties = $selected['properties'];
        $timeSlotId = $selected['timeSlotId'];
        $availabilityDate = $selected['availabilityDate'];
        $customerEmail = $selected['customerEmail'];

        // Customer Name
        $customerName = trim(
            (string) ($properties['name']
                ?? trim(($order['customer']['first_name'] ?? '').' '.($order['customer']['last_name'] ?? ''))
                ?: ($order['billing_address']['name'] ?? ''))
        );

        // Customer Phone
        $phone = $properties['phone']
            ?? $order['phone']
            ?? ($order['customer']['phone'] ?? ($order['billing_address']['phone'] ?? null));

        $phone = is_string($phone) ? trim($phone) : null;

        return [
            'order_id' => $order['id'] ?? 0,
            'line_item_id' => (int) ($lineItem['id'] ?? 0),
            'product_id' => (int) ($lineItem['product_id'] ?? 0),
            'name' => $customerName !== '' ? $customerName : 'Valued Customer',
            'email' => strtolower($customerEmail),
            'phone' => $phone,
            'availability_date' => Carbon::parse($availabilityDate)->format('Y-m-d'),
            'time_slot_id' => $timeSlotId,
            'properties' => $properties,
        ];

        return null;
    }

    /**
     * Determine which tier of the targeting hierarchy matches:
     * 1. Primary: product_id
     * 2. Fallback 1: handle
     * 3. Fallback 2: sku
     * Returns null if unconfigured or unmatched.
     *
     * @param  array<string, mixed>  $lineItem
     * @param  array<int>  $configuredProductIds
     * @param  array<string>  $configuredHandles
     * @param  array<string>  $configuredSkus
     */
    protected function determineMatchingTier(
        array $lineItem,
        array $configuredProductIds,
        array $configuredHandles,
        array $configuredSkus
    ): ?int {
        $productId = isset($lineItem['product_id']) ? (int) $lineItem['product_id'] : 0;
        $handle = strtolower(trim((string) ($lineItem['handle'] ?? ($lineItem['product_handle'] ?? ''))));
        $sku = strtolower(trim((string) ($lineItem['sku'] ?? '')));

        // 1. Primary: Product ID
        if (! empty($configuredProductIds) && $productId > 0 && in_array($productId, $configuredProductIds, true)) {
            return 1;
        }

        // 2. Fallback 1: Handle
        if (! empty($configuredHandles) && $handle !== '' && in_array($handle, $configuredHandles, true)) {
            return 2;
        }

        // 3. Fallback 2: SKU
        if (! empty($configuredSkus) && $sku !== '' && in_array($sku, $configuredSkus, true)) {
            return 3;
        }

        return null;
    }

    /**
     * Extract line item properties into a normalized key-value map.
     * Handles both Shopify array-of-objects [{'name': ..., 'value': ...}] and associative maps.
     *
     * @param  array<string, mixed>  $lineItem
     * @return array<string, mixed>
     */
    protected function extractProperties(array $lineItem): array
    {
        $rawProperties = $lineItem['properties'] ?? [];
        if (! is_array($rawProperties)) {
            return [];
        }

        $normalized = [];

        foreach ($rawProperties as $key => $value) {
            if (is_array($value) && isset($value['name'])) {
                $propName = strtolower(trim((string) $value['name']));
                $normalized[$propName] = $value['value'] ?? null;
            } elseif (is_string($key)) {
                $normalized[strtolower(trim($key))] = $value;
            }
        }

        return $normalized;
    }
}
