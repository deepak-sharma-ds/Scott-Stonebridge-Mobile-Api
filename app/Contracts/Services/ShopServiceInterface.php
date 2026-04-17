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
     * 
     * @return ShopDTO
     */
    public function getMarkets(): ShopDTO;

    /**
     * Get supported currencies only
     * 
     * @return array
     */
    public function getSupportedCurrencies(): array;

    /**
     * Check if currency is supported
     * 
     * @param string $currencyCode
     * @return bool
     */
    public function isCurrencySupported(string $currencyCode): bool;

    /**
     * Clear markets cache
     * 
     * @return void
     */
    public function clearMarketsCache(): void;
}
