<?php

namespace App\DTOs\Customer;

use App\DTOs\Base\BaseDTO;
use InvalidArgumentException;

/**
 * Address Data Transfer Object
 * 
 * Represents a customer address with typed properties and validation.
 * Used for shipping and billing addresses in customer profiles.
 * 
 * Requirements: 16.4, 16.6, 16.7
 */
class AddressDTO extends BaseDTO
{
    public function __construct(
        public readonly ?string $id,
        public readonly ?string $address1,
        public readonly ?string $address2,
        public readonly ?string $city,
        public readonly ?string $province,
        public readonly ?string $country,
        public readonly ?string $zip,
        public readonly ?string $phone,
        public readonly ?string $firstName,
        public readonly ?string $lastName,
        public readonly ?string $company,
        public readonly bool $isDefault,
    ) {
        $this->validate();
    }

    /**
     * Validate the address data.
     * 
     * @throws InvalidArgumentException
     */
    protected function validate(): void
    {
        // Address fields are optional, but if provided should be valid
        // No strict validation required as addresses can be partial
    }

    /**
     * Create an AddressDTO from Shopify API response data.
     * 
     * Transforms raw Shopify GraphQL address response into a typed DTO instance.
     * Handles both edge/node structure and flat array structure.
     * 
     * @param array $data Raw address data from Shopify GraphQL response
     * @return self
     */
    public static function fromShopifyResponse(array $data): self
    {
        // Handle edge/node structure if present
        $addressData = $data['node'] ?? $data;
        
        return new self(
            id: $addressData['id'] ?? null,
            address1: $addressData['address1'] ?? null,
            address2: $addressData['address2'] ?? null,
            city: $addressData['city'] ?? null,
            province: $addressData['province'] ?? null,
            country: $addressData['country'] ?? null,
            zip: $addressData['zip'] ?? null,
            phone: $addressData['phone'] ?? null,
            firstName: $addressData['firstName'] ?? null,
            lastName: $addressData['lastName'] ?? null,
            company: $addressData['company'] ?? null,
            isDefault: (bool) ($addressData['isDefault'] ?? false),
        );
    }
}
