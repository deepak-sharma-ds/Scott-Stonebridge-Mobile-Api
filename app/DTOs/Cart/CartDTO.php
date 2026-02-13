<?php

namespace App\DTOs\Cart;

use App\DTOs\Base\BaseDTO;
use InvalidArgumentException;

/**
 * Cart Data Transfer Object
 * 
 * Represents a Shopify cart with typed properties and validation.
 * Carts contain line items and cost information for guest or authenticated users.
 * 
 * Requirements: 16.2, 16.6, 16.7
 */
class CartDTO extends BaseDTO
{
    public function __construct(
        public readonly string $id,
        public readonly array $lineItems,
        public readonly string $checkoutUrl,
        public readonly array $cost,
        public readonly ?array $buyerIdentity,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {
        $this->validate();
    }

    /**
     * Validate the cart data.
     * 
     * @throws InvalidArgumentException
     */
    protected function validate(): void
    {
        $this->validateRequired($this->id, 'Cart ID');
    }

    /**
     * Create a CartDTO from Shopify API response data.
     * 
     * Transforms raw Shopify GraphQL cart response into a typed DTO instance.
     * Handles nested line items and cost information.
     * 
     * @param array $data Raw cart data from Shopify GraphQL response
     * @return self
     */
    public static function fromShopifyResponse(array $data): self
    {
        // Handle both edge/node structure and flat array structure for line items
        $lines = $data['lines']['edges'] ?? $data['lines'] ?? [];
        
        return new self(
            id: $data['id'],
            lineItems: array_map(
                fn($item) => CartLineItemDTO::fromShopifyResponse($item['node'] ?? $item),
                $lines
            ),
            checkoutUrl: $data['checkoutUrl'] ?? '',
            cost: [
                'subtotal' => $data['cost']['subtotalAmount']['amount'] ?? '0.00',
                'total' => $data['cost']['totalAmount']['amount'] ?? '0.00',
                'currency' => $data['cost']['totalAmount']['currencyCode'] ?? 'GBP',
            ],
            buyerIdentity: $data['buyerIdentity'] ?? null,
            createdAt: $data['createdAt'] ?? now()->toIso8601String(),
            updatedAt: $data['updatedAt'] ?? now()->toIso8601String(),
        );
    }

    /**
     * Get the total number of items in the cart.
     * 
     * Sums the quantity of all line items.
     * 
     * @return int
     */
    public function getTotalItems(): int
    {
        return array_reduce(
            $this->lineItems,
            fn($sum, $item) => $sum + $item->quantity,
            0
        );
    }
}
