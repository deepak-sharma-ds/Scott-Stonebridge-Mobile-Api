<?php

namespace App\Services\Shopify;

use App\Contracts\Services\ShopServiceInterface;
use App\Contracts\Shopify\StorefrontApiClientInterface;
use App\DTOs\Shop\ShopDTO;
use App\Services\Base\BaseService;
use App\Traits\CacheWithFallback;
use Illuminate\Support\Facades\Cache;

/**
 * Shop Service
 * 
 * Handles shop-level operations including markets and currency information
 */
class ShopService extends BaseService implements ShopServiceInterface
{
    use CacheWithFallback;

    public function __construct(
        protected StorefrontApiClientInterface $storefrontClient
    ) {
        parent::__construct();
    }

    /**
     * Get shop markets and supported currencies
     * 
     * Fetches shop information including all supported markets and currencies.
     * Results are cached for 24 hours as this data rarely changes.
     * 
     * @return ShopDTO
     */
    public function getMarkets(): ShopDTO
    {
        try {
            $this->logPerformanceStart('getMarkets');

            $shop = $this->cacheWithFallback(
                'shop:markets',
                86400, // 24 hours
                fn() => $this->fetchMarkets(),
                ['shop', 'markets']
            );

            $this->logPerformanceEnd('getMarkets', [
                'markets_count' => count($shop->markets),
                'currencies_count' => count($shop->getSupportedCurrencies()),
            ]);

            return $shop;
        } catch (\Exception $e) {
            $this->logErrorWithException('Failed to fetch shop markets', $e);
            throw $e;
        }
    }

    /**
     * Fetch markets from Shopify API
     * 
     * @return ShopDTO
     */
    protected function fetchMarkets(): ShopDTO
    {
        $response = $this->storefrontClient->query('storefront/shop/markets');

        return ShopDTO::fromShopifyResponse($response['data']);
    }

    /**
     * Get supported currencies only
     * 
     * Returns a simple array of supported currency codes.
     * Cached for 24 hours.
     * 
     * @return array
     */
    public function getSupportedCurrencies(): array
    {
        try {
            $this->logPerformanceStart('getSupportedCurrencies');

            $currencies = $this->cacheWithFallback(
                'shop:currencies',
                86400, // 24 hours
                fn() => $this->getMarkets()->getSupportedCurrencies(),
                ['shop', 'currencies']
            );

            $this->logPerformanceEnd('getSupportedCurrencies', [
                'count' => count($currencies),
            ]);

            return $currencies;
        } catch (\Exception $e) {
            $this->logErrorWithException('Failed to fetch supported currencies', $e);
            
            // Return fallback currencies if API fails
            return ['GBP', 'USD', 'EUR', 'CAD', 'AUD'];
        }
    }

    /**
     * Check if currency is supported
     * 
     * @param string $currencyCode
     * @return bool
     */
    public function isCurrencySupported(string $currencyCode): bool
    {
        $supportedCurrencies = $this->getSupportedCurrencies();
        return in_array(strtoupper($currencyCode), $supportedCurrencies, true);
    }

    /**
     * Clear markets cache
     * 
     * @return void
     */
    public function clearMarketsCache(): void
    {
        $this->forgetCacheWithFallback(['shop', 'markets']);
        $this->forgetCacheWithFallback(['shop', 'currencies']);
        Cache::forget('shop:markets');
        Cache::forget('shop:currencies');
        
        // Also clear the currency-to-country mapping cache
        \App\Services\CurrencyCountryMapService::clearCache();
    }
}
