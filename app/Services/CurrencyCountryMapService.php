<?php

namespace App\Services;

use App\Contracts\Services\ShopServiceInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Currency Country Map Service
 * 
 * Provides dynamic mapping between currency codes and country codes
 * based on Shopify markets data with fallback to static mapping.
 */
class CurrencyCountryMapService
{
    /**
     * Fallback static mapping for common currencies
     * Used when Shopify markets data is unavailable
     * 
     * @var array<string, string>
     */
    protected static array $fallbackMapping = [
        'GBP' => 'GB',
        'USD' => 'US',
        'EUR' => 'DE',
        'CAD' => 'CA',
        'AUD' => 'AU',
        'JPY' => 'JP',
        'CHF' => 'CH',
        'NZD' => 'NZ',
        'SEK' => 'SE',
        'DKK' => 'DK',
        'NOK' => 'NO',
        'INR' => 'IN',
        'BRL' => 'BR',
        'MXN' => 'MX',
        'SGD' => 'SG',
        'HKD' => 'HK',
        'CNY' => 'CN',
        'KRW' => 'KR',
        'ZAR' => 'ZA',
        'AED' => 'AE',
        'PLN' => 'PL',
        'THB' => 'TH',
        'MYR' => 'MY',
        'IDR' => 'ID',
        'PHP' => 'PH',
        'CZK' => 'CZ',
        'ILS' => 'IL',
        'CLP' => 'CL',
        'TWD' => 'TW',
        'RUB' => 'RU',
        'TRY' => 'TR',
    ];

    /**
     * Get country code for a given currency
     * 
     * @param string $currencyCode Currency code (e.g., 'GBP', 'USD')
     * @return string Country code (e.g., 'GB', 'US')
     */
    public static function getCountryCode(string $currencyCode): string
    {
        $currencyCode = strtoupper(trim($currencyCode));

        // Try to get from dynamic Shopify markets
        $countryCode = static::getCountryCodeFromMarkets($currencyCode);
        
        if ($countryCode) {
            return $countryCode;
        }

        // Fallback to static mapping
        return static::$fallbackMapping[$currencyCode] ?? 'GB';
    }

    /**
     * Get country code from Shopify markets for a given currency
     * 
     * @param string $currencyCode Currency code
     * @return string|null Country code or null if not found
     */
    protected static function getCountryCodeFromMarkets(string $currencyCode): ?string
    {
        try {
            // Get currency-to-country mapping from cache (cached for 24 hours)
            $mapping = Cache::remember(
                'currency_to_country_map',
                86400, // 24 hours
                function () {
                    return static::buildCurrencyToCountryMap();
                }
            );

            return $mapping[$currencyCode] ?? null;
        } catch (\Exception $e) {
            Log::warning('Failed to get country code from markets', [
                'currency' => $currencyCode,
                'error' => $e->getMessage(),
            ]);
            
            return null;
        }
    }

    /**
     * Build currency-to-country mapping from Shopify markets
     * 
     * @return array<string, string>
     */
    protected static function buildCurrencyToCountryMap(): array
    {
        try {
            $shopService = app(ShopServiceInterface::class);
            $shopData = $shopService->getMarkets();
            
            $mapping = [];
            
            // Build mapping from markets
            // Use the first country for each currency
            foreach ($shopData->markets as $market) {
                if (!isset($mapping[$market->currencyCode])) {
                    $mapping[$market->currencyCode] = $market->countryCode;
                }
            }
            
            // Also add primary currency mapping
            if (!isset($mapping[$shopData->primaryCurrency])) {
                $mapping[$shopData->primaryCurrency] = $shopData->countryCode;
            }
            
            return $mapping;
        } catch (\Exception $e) {
            Log::error('Failed to build currency-to-country map from Shopify', [
                'error' => $e->getMessage(),
            ]);
            
            return [];
        }
    }

    /**
     * Get all currency-to-country mappings
     * 
     * @return array<string, string>
     */
    public static function getAllMappings(): array
    {
        $dynamicMapping = static::getCountryCodeFromMarkets('') ? 
            Cache::get('currency_to_country_map', []) : 
            [];

        // Merge dynamic with fallback (dynamic takes precedence)
        return array_merge(static::$fallbackMapping, $dynamicMapping);
    }

    /**
     * Clear the cached mapping
     * 
     * @return void
     */
    public static function clearCache(): void
    {
        Cache::forget('currency_to_country_map');
    }

    /**
     * Get fallback mapping
     * 
     * @return array<string, string>
     */
    public static function getFallbackMapping(): array
    {
        return static::$fallbackMapping;
    }
}
