<?php

namespace App\Contracts\Services;

use App\DTOs\Shop\ShopDTO;

/**
 * Shop Service Interface
 *
 * Defines the contract for shop-level operations including
 * markets and currency information.
 */
interface ShopServiceInterface
{
    /**
     * Get shop markets and supported currencies
     */
    public function getMarkets(): ShopDTO;

    /**
     * Get supported currencies only
     */
    public function getSupportedCurrencies(): array;

    /**
     * Check if currency is supported
     */
    public function isCurrencySupported(string $currencyCode): bool;

    /**
     * Clear markets cache
     */
    public function clearMarketsCache(): void;
}
