<?php

declare(strict_types=1);

namespace App\Contracts\Services\Sales;

use App\DTOs\Sales\UpsellSuggestionDTO;

/**
 * Discovers Delivery Upgrade Products (Shopify products tagged
 * `delivery-upgrade`) and decides whether a cart is eligible to be offered
 * one. Both calls are failure-safe: any Shopify error, a disabled kill
 * switch or an empty result yields an empty list / false, never an exception.
 * See ADR 0022.
 */
interface DeliveryUpgradeServiceInterface
{
    /**
     * Available products carrying the Delivery Upgrade Tag.
     *
     * @return list<UpsellSuggestionDTO>
     */
    public function getUpgradeProducts(string $shopDomain, ?string $currency = null): array;

    /**
     * True when at least one cart product belongs to an Upgrade-Eligible
     * Collection. A product that itself carries the Delivery Upgrade Tag
     * never counts as an eligible reading.
     *
     * @param  list<array{product_id?: string, id?: string, quantity?: int}>  $cartItems
     */
    public function cartHasEligibleReading(array $cartItems, string $shopDomain): bool;
}
