<?php

declare(strict_types=1);

namespace App\Services\AI\MCP\Mappers;

use App\DTOs\Chat\CartStateDTO;

final class CartMapper
{
    /**
     * @param  array<string, mixed>  $mcpResult  `result` payload from `get_cart` / `update_cart`.
     */
    public static function fromCart(array $mcpResult): ?CartStateDTO
    {
        $unwrapped = McpEnvelope::unwrap($mcpResult);
        $cart = $unwrapped['cart'] ?? $unwrapped;
        if (! is_array($cart)) {
            return null;
        }

        $id = (string) ($cart['id'] ?? '');
        if ($id === '') {
            return null;
        }

        $lines = self::extractLines($cart);
        $itemCount = (int) ($cart['item_count'] ?? $cart['total_quantity'] ?? array_sum(array_map(
            static fn (array $line): int => (int) ($line['quantity'] ?? 0),
            $lines,
        )));

        return new CartStateDTO(
            id: $id,
            itemCount: $itemCount,
            subtotalMinorUnits: self::priceMinor(
                $cart['subtotal']
                ?? $cart['subtotal_amount']
                ?? $cart['cost']['subtotal_amount']
                ?? $cart['cost']['total_amount']
                ?? $cart['cost']['subtotal']
                ?? null,
            ),
            currency: self::currency($cart),
            items: $lines,
            checkoutUrl: self::stringOrNull($cart['checkout_url'] ?? $cart['checkoutUrl'] ?? null),
        );
    }

    /**
     * @param  array<string, mixed>  $cart
     * @return list<array{variant_id:string,product_id:?string,title:string,image:?string,quantity:int,line_price_minor_units:?int}>
     */
    private static function extractLines(array $cart): array
    {
        $raw = $cart['lines'] ?? $cart['items'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $line) {
            if (! is_array($line)) {
                continue;
            }

            $variantId = self::stringOrNull(
                $line['variant_id']
                ?? $line['merchandise_id']
                ?? $line['merchandise']['id']
                ?? null,
            );
            if ($variantId === null) {
                continue;
            }

            $out[] = [
                'variant_id' => $variantId,
                'product_id' => self::stringOrNull(
                    $line['product_id']
                    ?? $line['merchandise']['product']['id']
                    ?? null,
                ),
                'title' => (string) (
                    $line['title']
                    ?? $line['merchandise']['title']
                    ?? $line['merchandise']['product']['title']
                    ?? ''
                ),
                'image' => self::stringOrNull(
                    $line['image']['url']
                    ?? $line['image']
                    ?? $line['merchandise']['image']['url']
                    ?? $line['merchandise']['image_url']
                    ?? $line['merchandise']['product']['image_url']
                    ?? null,
                ),
                'quantity' => (int) ($line['quantity'] ?? 0),
                'line_price_minor_units' => self::priceMinor(
                    $line['line_price']
                    ?? $line['line_total']
                    ?? $line['cost']['total_amount']
                    ?? $line['cost']['subtotal_amount']
                    ?? $line['cost']['total']
                    ?? null,
                ),
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $cart
     */
    private static function currency(array $cart): ?string
    {
        $candidates = [
            $cart['currency'] ?? null,
            $cart['currency_code'] ?? null,
            $cart['subtotal']['currency'] ?? null,
            $cart['subtotal']['currency_code'] ?? null,
            $cart['cost']['total_amount']['currency'] ?? null,
            $cart['cost']['subtotal_amount']['currency'] ?? null,
            $cart['cost']['subtotal']['currency'] ?? null,
            $cart['cost']['subtotal']['currency_code'] ?? null,
        ];
        foreach ($candidates as $c) {
            if (is_string($c) && $c !== '') {
                return $c;
            }
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

        if (is_int($value)) {
            return $value;
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
