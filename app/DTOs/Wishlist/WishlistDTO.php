<?php

namespace App\DTOs\Wishlist;

use App\DTOs\Base\BaseDTO;
use InvalidArgumentException;

/**
 * Wishlist Data Transfer Object
 * 
 * Represents a customer's wishlist with saved products.
 * Wishlist data is stored in Shopify customer metafields.
 * 
 * Requirements: 11.4, 11.5, 11.11, 11.12
 */
class WishlistDTO extends BaseDTO
{
    public function __construct(
        public readonly string $customerId,
        public readonly array $items,
    ) {
        $this->validate();
    }

    /**
     * Validate the wishlist data.
     * 
     * @throws InvalidArgumentException
     */
    protected function validate(): void
    {
        $this->validateRequired($this->customerId, 'Customer ID');
        
        if (!is_array($this->items)) {
            throw new InvalidArgumentException('Items must be an array');
        }
    }

    /**
     * Create a WishlistDTO from Shopify API response data.
     * 
     * Transforms raw Shopify product data into a typed DTO instance.
     * Handles wishlist items stored in customer metafields.
     * 
     * @param array $data Raw wishlist data with products
     * @return self
     */
    public static function fromShopifyResponse(array $data): self
    {
        return new self(
            customerId: $data['customer_id'],
            items: array_map(
                fn($item) => WishlistItemDTO::fromShopifyResponse($item),
                $data['items'] ?? []
            ),
        );
    }

    /**
     * Check if a product is in the wishlist.
     * 
     * @param string $productId Shopify product ID
     * @return bool
     */
    public function hasProduct(string $productId): bool
    {
        return in_array($productId, $this->getProductIds());
    }

    /**
     * Get all product IDs in the wishlist.
     * 
     * @return array<string>
     */
    public function getProductIds(): array
    {
        return array_map(fn($item) => $item->productId, $this->items);
    }

    /**
     * Get the total number of items in the wishlist.
     * 
     * @return int
     */
    public function getTotalItems(): int
    {
        return count($this->items);
    }
}
