<?php

namespace App\DTOs\Order;

use App\DTOs\Base\BaseDTO;
use InvalidArgumentException;

/**
 * Order Line Item Data Transfer Object
 * 
 * Represents a single line item in a Shopify order with typed properties.
 * Each line item contains product variant information, quantity, and pricing.
 * 
 * Requirements: 16.3, 16.6, 16.7
 */
class OrderLineItemDTO extends BaseDTO
{
    public function __construct(
        public readonly string $title,
        public readonly int $quantity,
        public readonly array $discountedTotalPrice,
        public readonly ?array $originalTotalPrice,
        public readonly array $customAttributes,
        public readonly ?string $variantId,
        public readonly ?string $variantTitle,
        public readonly ?string $variantSku,
        public readonly ?string $image,
        public readonly ?string $imageAltText,
        public readonly ?string $productId,
        public readonly ?string $productTitle,
        public readonly ?string $productHandle,
    ) {
        $this->validate();
    }

    /**
     * Validate the order line item data.
     * 
     * @throws InvalidArgumentException
     */
    protected function validate(): void
    {
        $this->validateRequired($this->title, 'Line item title');
        $this->validatePositive($this->quantity, 'Quantity');
    }

    /**
     * Create an OrderLineItemDTO from Shopify API response data.
     * 
     * Transforms raw Shopify GraphQL order line item response into a typed DTO instance.
     * Handles nested variant and product information.
     * 
     * @param array $data Raw line item data from Shopify GraphQL response
     * @return self
     */
    public static function fromShopifyResponse(array $data): self
    {
        $variant = $data['variant'] ?? [];
        $product = $variant['product'] ?? [];
        
        return new self(
            title: $data['title'],
            quantity: $data['quantity'],
            discountedTotalPrice: [
                'amount' => $data['discountedTotalPrice']['amount'] ?? '0.00',
                'currency' => $data['discountedTotalPrice']['currencyCode'] ?? 'GBP',
            ],
            originalTotalPrice: isset($data['originalTotalPrice']) ? [
                'amount' => $data['originalTotalPrice']['amount'] ?? '0.00',
                'currency' => $data['originalTotalPrice']['currencyCode'] ?? 'GBP',
            ] : null,
            customAttributes: $data['customAttributes'] ?? [],
            variantId: $variant['id'] ?? null,
            variantTitle: $variant['title'] ?? null,
            variantSku: $variant['sku'] ?? null,
            image: $variant['image']['url'] ?? null,
            imageAltText: $variant['image']['altText'] ?? null,
            productId: $product['id'] ?? null,
            productTitle: $product['title'] ?? null,
            productHandle: $product['handle'] ?? null,
        );
    }
}
