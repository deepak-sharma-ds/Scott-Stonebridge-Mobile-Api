<?php

namespace App\DTOs\Cart;

use App\DTOs\Base\BaseDTO;
use InvalidArgumentException;

/**
 * Cart Line Item Data Transfer Object
 * 
 * Represents a single line item in a Shopify cart with typed properties.
 * Each line item contains product variant information and quantity.
 * 
 * Requirements: 16.2, 16.6, 16.7
 */
class CartLineItemDTO extends BaseDTO
{
    public function __construct(
        public readonly string $id,
        public readonly string $variantId,
        public readonly string $productId,
        public readonly string $title,
        public readonly int $quantity,
        public readonly array $price,
        public readonly ?string $image,
        public readonly array $attributes,
    ) {
        $this->validate();
    }

    /**
     * Validate the cart line item data.
     * 
     * @throws InvalidArgumentException
     */
    protected function validate(): void
    {
        $this->validateRequired($this->id, 'Line item ID');
        $this->validateRequired($this->variantId, 'Variant ID');
        $this->validateRequired($this->productId, 'Product ID');
        $this->validateRequired($this->title, 'Line item title');
        $this->validatePositive($this->quantity, 'Quantity');
    }

    /**
     * Create a CartLineItemDTO from Shopify API response data.
     * 
     * Transforms raw Shopify GraphQL cart line item response into a typed DTO instance.
     * Handles nested merchandise and price information.
     * 
     * @param array $data Raw line item data from Shopify GraphQL response
     * @return self
     */
    public static function fromShopifyResponse(array $data): self
    {
        $merchandise = $data['merchandise'] ?? [];
        $product = $merchandise['product'] ?? [];
        
        return new self(
            id: $data['id'],
            variantId: $merchandise['id'] ?? '',
            productId: $product['id'] ?? '',
            title: $merchandise['title'] ?? $product['title'] ?? '',
            quantity: $data['quantity'] ?? 1,
            price: [
                'amount' => $merchandise['price']['amount'] ?? $data['price']['amount'] ?? '0.00',
                'currency' => $merchandise['price']['currencyCode'] ?? $data['price']['currencyCode'] ?? 'GBP',
            ],
            image: $merchandise['image']['url'] ?? $product['featuredImage']['url'] ?? null,
            attributes: $data['attributes'] ?? [],
        );
    }
}
