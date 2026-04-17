<?php

namespace App\DTOs\Wishlist;

use App\DTOs\Base\BaseDTO;
use InvalidArgumentException;

/**
 * Wishlist Item Data Transfer Object
 * 
 * Represents a single product in a customer's wishlist.
 * Contains essential product information for wishlist display.
 * 
 * Requirements: 11.4, 11.5, 11.11, 11.12
 */
class WishlistItemDTO extends BaseDTO
{
    public function __construct(
        public readonly string $productId,
        public readonly string $title,
        public readonly string $handle,
        public readonly ?string $image,
        public readonly string $price,
        public readonly string $currency,
        public readonly bool $availableForSale,
        public readonly string $addedAt,
    ) {
        $this->validate();
    }

    /**
     * Validate the wishlist item data.
     * 
     * @throws InvalidArgumentException
     */
    protected function validate(): void
    {
        $this->validateRequired($this->productId, 'Product ID');
        $this->validateRequired($this->title, 'Title');
        $this->validateRequired($this->handle, 'Handle');
    }

    /**
     * Create a WishlistItemDTO from Shopify API response data.
     * 
     * Transforms raw Shopify product data into a typed DTO instance.
     * Extracts essential product information for wishlist display.
     * 
     * @param array $data Raw product data from Shopify GraphQL response
     * @return self
     */
    public static function fromShopifyResponse(array $data): self
    {
        // Extract image URL from various possible structures
        $image = null;
        if (isset($data['images']['edges'][0]['node']['url'])) {
            $image = $data['images']['edges'][0]['node']['url'];
        } elseif (isset($data['images'][0]['url'])) {
            $image = $data['images'][0]['url'];
        } elseif (isset($data['image']['url'])) {
            $image = $data['image']['url'];
        }

        // Extract price from priceRange structure
        $price = '0.00';
        $currency = 'GBP';
        if (isset($data['priceRange']['minVariantPrice'])) {
            $price = $data['priceRange']['minVariantPrice']['amount'] ?? '0.00';
            $currency = $data['priceRange']['minVariantPrice']['currencyCode'] ?? 'GBP';
        }

        return new self(
            productId: $data['id'],
            title: $data['title'],
            handle: $data['handle'],
            image: $image,
            price: $price,
            currency: $currency,
            availableForSale: $data['availableForSale'] ?? false,
            addedAt: $data['addedAt'] ?? now()->toIso8601String(),
        );
    }
}
