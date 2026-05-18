<?php

declare(strict_types=1);

namespace App\Contracts\Services\Sales;

use App\DTOs\Sales\UpsellSuggestionDTO;

/**
 * Fetches upsell / cross-sell candidates from Shopify Storefront API and
 * computes the free-shipping gap for the current cart. No AI prompt logic
 * lives here — the prompt builder (Phase 6) consumes the DTOs returned by
 * this service.
 */
interface UpsellServiceInterface
{
    /**
     * Top-N upsell candidates for the given cart. Calls Shopify
     * productRecommendations per cart product, dedupes against cart
     * product IDs, then caps to config('sales.upsell.max_results').
     *
     * @param  list<array{product_id?: string, id?: string, quantity?: int}>  $cartItems
     * @return list<UpsellSuggestionDTO>
     */
    public function getUpsells(array $cartItems, string $shopDomain, ?string $currency = null): array;

    /**
     * Cross-sells anchored on a single product. Reuses the same
     * productRecommendations endpoint via the COMPLEMENTARY intent.
     *
     * @return list<UpsellSuggestionDTO>
     */
    public function getCrossSells(string $productId, string $shopDomain, ?string $currency = null): array;

    /**
     * Difference between the merchant's free-shipping threshold and the
     * current cart total. Returns null when the threshold is not set or
     * already met.
     */
    public function getFreeShippingGap(float $cartTotal, string $shopDomain): ?float;
}
