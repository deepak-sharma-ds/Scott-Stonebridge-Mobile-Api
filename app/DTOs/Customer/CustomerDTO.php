<?php

namespace App\DTOs\Customer;

use App\DTOs\Base\BaseDTO;
use InvalidArgumentException;

/**
 * Customer Data Transfer Object
 * 
 * Represents a Shopify customer with typed properties and validation.
 * Customers contain personal information, addresses, and marketing preferences.
 * 
 * Requirements: 16.4, 16.6, 16.7
 */
class CustomerDTO extends BaseDTO
{
    public function __construct(
        public readonly string $id,
        public readonly string $email,
        public readonly ?string $firstName,
        public readonly ?string $lastName,
        public readonly ?string $phone,
        public readonly array $addresses,
        public readonly array $tags,
        public readonly bool $acceptsMarketing,
        public readonly string $createdAt,
    ) {
        $this->validate();
    }

    /**
     * Validate the customer data.
     * 
     * @throws InvalidArgumentException
     */
    protected function validate(): void
    {
        $this->validateRequired($this->id, 'Customer ID');
        $this->validateRequired($this->email, 'Customer email');
        $this->validateEmail($this->email, 'Customer email');
    }

    /**
     * Create a CustomerDTO from Shopify API response data.
     * 
     * Transforms raw Shopify GraphQL customer response into a typed DTO instance.
     * Handles nested addresses and customer metadata.
     * 
     * @param array $data Raw customer data from Shopify GraphQL response
     * @return self
     */
    public static function fromShopifyResponse(array $data): self
    {
        // Handle both edge/node structure and flat array structure for addresses
        $addresses = $data['addresses']['edges'] ?? $data['addresses'] ?? [];
        
        return new self(
            id: $data['id'],
            email: $data['email'],
            firstName: $data['firstName'] ?? null,
            lastName: $data['lastName'] ?? null,
            phone: $data['phone'] ?? null,
            addresses: array_map(
                fn($addr) => AddressDTO::fromShopifyResponse($addr['node'] ?? $addr),
                $addresses
            ),
            tags: $data['tags'] ?? [],
            acceptsMarketing: $data['acceptsMarketing'] ?? false,
            createdAt: $data['createdAt'],
        );
    }

    /**
     * Get the customer's full name.
     * 
     * Combines first and last name, or returns email if names are not available.
     * 
     * @return string
     */
    public function getFullName(): string
    {
        $parts = array_filter([$this->firstName, $this->lastName]);
        return !empty($parts) ? implode(' ', $parts) : $this->email;
    }

    /**
     * Check if the customer has any addresses.
     * 
     * @return bool
     */
    public function hasAddresses(): bool
    {
        return !empty($this->addresses);
    }
}
