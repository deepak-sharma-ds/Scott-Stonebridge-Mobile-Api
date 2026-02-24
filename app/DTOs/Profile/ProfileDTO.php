<?php

namespace App\DTOs\Profile;

use App\DTOs\Base\BaseDTO;
use App\DTOs\Customer\CustomerDTO;
use App\DTOs\Customer\AddressDTO;
use InvalidArgumentException;

/**
 * Profile Data Transfer Object
 * 
 * Represents customer profile data with personal details and addresses.
 * Used for profile management endpoints in the mobile API.
 * 
 * Requirements: 11.2, 11.3, 11.11, 11.12
 */
class ProfileDTO extends BaseDTO
{
    public function __construct(
        public readonly string $id,
        public readonly string $email,
        public readonly ?string $firstName,
        public readonly ?string $lastName,
        public readonly ?string $phone,
        public readonly array $addresses,
        public readonly bool $acceptsMarketing,
        public readonly string $createdAt,
    ) {
        $this->validate();
    }

    /**
     * Validate the profile data.
     * 
     * @throws InvalidArgumentException
     */
    protected function validate(): void
    {
        $this->validateRequired($this->id, 'Customer ID');
        $this->validateRequired($this->email, 'Email');
        $this->validateEmail($this->email, 'Email');
    }

    /**
     * Create a ProfileDTO from Shopify API response data.
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
            acceptsMarketing: $data['acceptsMarketing'] ?? false,
            createdAt: $data['createdAt'],
        );
    }

    /**
     * Create a ProfileDTO from an existing CustomerDTO.
     * 
     * Reuses CustomerDTO data to create a ProfileDTO instance.
     * This allows reusing the existing CustomerService logic.
     * 
     * @param CustomerDTO $customer Customer DTO instance
     * @return self
     */
    public static function fromCustomerDTO(CustomerDTO $customer): self
    {
        return new self(
            id: $customer->id,
            email: $customer->email,
            firstName: $customer->firstName,
            lastName: $customer->lastName,
            phone: $customer->phone,
            addresses: $customer->addresses,
            acceptsMarketing: $customer->acceptsMarketing,
            createdAt: $customer->createdAt,
        );
    }
}
