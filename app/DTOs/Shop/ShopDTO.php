<?php

namespace App\DTOs\Shop;

use App\DTOs\Base\BaseDTO;

/**
 * Shop DTO
 * 
 * Represents shop information including supported currencies and markets
 */
class ShopDTO extends BaseDTO
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $domain,
        public readonly string $primaryCurrency,
        public readonly array $enabledCurrencies,
        public readonly array $markets,
        public readonly string $countryCode
    ) {
        $this->validate();
    }

    /**
     * Validate the DTO data
     * 
     * @return void
     */
    protected function validate(): void
    {
        // Shop data from Shopify API is generally valid
        // Basic validation for required fields
        if (empty($this->primaryCurrency)) {
            throw new \InvalidArgumentException('Primary currency is required');
        }
    }

    /**
     * Create from Shopify API response
     * 
     * @param array $data
     * @return self
     */
    public static function fromShopifyResponse(array $data): self
    {
        $shop = $data['shop'] ?? [];
        $localization = $data['localization'] ?? [];
        $paymentSettings = $shop['paymentSettings'] ?? [];

        // Extract enabled currencies
        $enabledCurrencies = $paymentSettings['enabledPresentmentCurrencies'] ?? [];
        
        // Extract markets from available countries
        $markets = [];
        foreach ($localization['availableCountries'] ?? [] as $country) {
            $markets[] = MarketDTO::fromShopifyResponse($country);
        }

        return new self(
            id: $shop['id'] ?? '',
            name: $shop['name'] ?? 'Unknown Shop',
            domain: $shop['primaryDomain']['host'] ?? '',
            primaryCurrency: $paymentSettings['currencyCode'] ?? 'GBP',
            enabledCurrencies: $enabledCurrencies,
            markets: $markets,
            countryCode: $paymentSettings['countryCode'] ?? 'GB'
        );
    }

    /**
     * Convert to array
     * 
     * @return array
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'domain' => $this->domain,
            'primary_currency' => $this->primaryCurrency,
            'enabled_currencies' => $this->enabledCurrencies,
            'markets' => array_map(fn($market) => $market->toArray(), $this->markets),
            'country_code' => $this->countryCode,
        ];
    }

    /**
     * Get unique list of supported currency codes
     * 
     * @return array
     */
    public function getSupportedCurrencies(): array
    {
        $currencies = [];
        
        // Add enabled presentment currencies
        foreach ($this->enabledCurrencies as $currency) {
            $currencies[$currency] = $currency;
        }
        
        // Add currencies from markets
        foreach ($this->markets as $market) {
            $currencies[$market->currencyCode] = $market->currencyCode;
        }
        
        return array_values($currencies);
    }
}
