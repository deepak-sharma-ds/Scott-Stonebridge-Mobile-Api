<?php

namespace App\DTOs\Product;

use App\DTOs\Base\BaseDTO;
use InvalidArgumentException;

/**
 * Product Variant Data Transfer Object
 * 
 * Represents a single variant of a Shopify product with typed properties.
 * Variants represent different options of a product (e.g., size, color combinations).
 * 
 * Requirements: 16.1, 16.6, 16.7
 */
class ProductVariantDTO extends BaseDTO
{
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly ?string $sku,
        public readonly string $price,
        public readonly string $currencyCode,
        public readonly ?string $compareAtPrice,
        public readonly bool $availableForSale,
        public readonly ?int $quantityAvailable,
        public readonly ?string $image,
        public readonly array $selectedOptions,
        public readonly ?float $weight,
        public readonly ?string $weightUnit,
    ) {
        $this->validate();
    }

    /**
     * Validate the product variant data.
     * 
     * @throws InvalidArgumentException
     */
    protected function validate(): void
    {
        $this->validateRequired($this->id, 'Variant ID');
        $this->validateRequired($this->title, 'Variant title');
        $this->validateRequired($this->price, 'Variant price');
        $this->validateRequired($this->currencyCode, 'Currency code');
    }

    /**
     * Create a ProductVariantDTO from Shopify API response data.
     * 
     * @param array $data Raw variant data from Shopify GraphQL response
     * @return self
     */
    public static function fromShopifyResponse(array $data): self
    {
        return new self(
            id: $data['id'],
            title: $data['title'],
            sku: $data['sku'] ?? null,
            price: $data['price']['amount'] ?? $data['priceV2']['amount'] ?? '0.00',
            currencyCode: $data['price']['currencyCode'] ?? $data['priceV2']['currencyCode'] ?? 'GBP',
            compareAtPrice: $data['compareAtPrice']['amount'] ?? $data['compareAtPriceV2']['amount'] ?? null,
            availableForSale: $data['availableForSale'] ?? false,
            quantityAvailable: $data['quantityAvailable'] ?? null,
            image: $data['image']['url'] ?? null,
            selectedOptions: array_map(
                fn($option) => [
                    'name' => $option['name'],
                    'value' => $option['value'],
                ],
                $data['selectedOptions'] ?? []
            ),
            weight: $data['weight'] ?? null,
            weightUnit: $data['weightUnit'] ?? null,
        );
    }
}
