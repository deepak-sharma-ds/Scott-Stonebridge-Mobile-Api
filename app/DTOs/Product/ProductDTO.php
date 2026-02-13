<?php

namespace App\DTOs\Product;

use App\DTOs\Base\BaseDTO;
use InvalidArgumentException;

/**
 * Product Data Transfer Object
 * 
 * Represents a Shopify product with typed properties and validation.
 * Products contain multiple variants and represent the main catalog items.
 * 
 * Requirements: 16.1, 16.6, 16.7
 */
class ProductDTO extends BaseDTO
{
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $handle,
        public readonly ?string $description,
        public readonly ?string $vendor,
        public readonly ?string $productType,
        public readonly array $tags,
        public readonly bool $availableForSale,
        public readonly array $images,
        public readonly array $variants,
        public readonly array $options,
        public readonly ?string $publishedAt,
        public readonly ?string $updatedAt,
    ) {
        $this->validate();
    }

    /**
     * Validate the product data.
     * 
     * @throws InvalidArgumentException
     */
    protected function validate(): void
    {
        $this->validateRequired($this->id, 'Product ID');
        $this->validateRequired($this->title, 'Product title');
        $this->validateRequired($this->handle, 'Product handle');
    }

    /**
     * Create a ProductDTO from Shopify API response data.
     * 
     * Transforms raw Shopify GraphQL response into a typed DTO instance.
     * Handles nested variant transformation and image formatting.
     * 
     * @param array $data Raw product data from Shopify GraphQL response
     * @return self
     */
    public static function fromShopifyResponse(array $data): self
    {
        return new self(
            id: $data['id'],
            title: $data['title'],
            handle: $data['handle'],
            description: $data['description'] ?? null,
            vendor: $data['vendor'] ?? null,
            productType: $data['productType'] ?? null,
            tags: $data['tags'] ?? [],
            availableForSale: $data['availableForSale'] ?? false,
            images: array_map(
                fn($img) => [
                    'url' => $img['url'] ?? $img['src'] ?? '',
                    'alt' => $img['altText'] ?? $img['alt'] ?? null,
                ],
                $data['images']['edges'] ?? $data['images'] ?? []
            ),
            variants: array_map(
                fn($v) => ProductVariantDTO::fromShopifyResponse($v['node'] ?? $v),
                $data['variants']['edges'] ?? $data['variants'] ?? []
            ),
            options: $data['options'] ?? [],
            publishedAt: $data['publishedAt'] ?? null,
            updatedAt: $data['updatedAt'] ?? null,
        );
    }
}
