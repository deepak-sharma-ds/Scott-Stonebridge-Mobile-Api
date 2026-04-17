<?php

namespace App\DTOs\Shop;

use App\DTOs\Base\BaseDTO;

/**
 * Market DTO
 * 
 * Represents a market/country with its currency information
 */
class MarketDTO extends BaseDTO
{
    public function __construct(
        public readonly string $countryCode,
        public readonly string $countryName,
        public readonly string $currencyCode,
        public readonly string $currencyName,
        public readonly string $currencySymbol,
        public readonly string $unitSystem,
        public readonly ?string $currencyFlag = null
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
        // Market data from Shopify API is generally valid
        // Basic validation for required fields
        if (empty($this->countryCode)) {
            throw new \InvalidArgumentException('Country code is required');
        }
        
        if (empty($this->currencyCode)) {
            throw new \InvalidArgumentException('Currency code is required');
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
        return new self(
            countryCode: $data['isoCode'] ?? '',
            countryName: $data['name'] ?? '',
            currencyCode: $data['currency']['isoCode'] ?? '',
            currencyName: $data['currency']['name'] ?? '',
            currencySymbol: $data['currency']['symbol'] ?? '',
            unitSystem: $data['unitSystem'] ?? 'METRIC'
        );
    }

    /**
     * Convert to array
     * 
     * @return array
     */
    public function toArray(): array
    {
        $data = [
            'country_code' => $this->countryCode,
            'country_name' => $this->countryName,
            'currency_code' => $this->currencyCode,
            'currency_name' => $this->currencyName,
            'currency_symbol' => $this->currencySymbol,
            'unit_system' => $this->unitSystem,
        ];

        if ($this->currencyFlag !== null) {
            $data['currency_flag'] = $this->currencyFlag;
        }

        return $data;
    }

    /**
     * Create a new instance with currency flag
     * 
     * @param string $flagUrl
     * @return self
     */
    public function withCurrencyFlag(string $flagUrl): self
    {
        return new self(
            countryCode: $this->countryCode,
            countryName: $this->countryName,
            currencyCode: $this->currencyCode,
            currencyName: $this->currencyName,
            currencySymbol: $this->currencySymbol,
            unitSystem: $this->unitSystem,
            currencyFlag: $flagUrl
        );
    }
}
