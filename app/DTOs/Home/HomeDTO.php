<?php

namespace App\DTOs\Home;

use App\DTOs\Base\BaseDTO;
use App\DTOs\Product\ProductDTO;
use App\DTOs\Product\CollectionDTO;
use InvalidArgumentException;

/**
 * Home Data Transfer Object
 * 
 * Represents home page data with featured products, collections, and banners.
 * Used for displaying curated content on the mobile app home screen.
 * 
 * Requirements: 11.1, 11.12
 */
class HomeDTO extends BaseDTO
{
    public function __construct(
        public readonly array $featuredProducts,
        public readonly array $collections,
        public readonly ?array $banners = null,
    ) {
        $this->validate();
    }

    /**
     * Validate the home data.
     * 
     * @throws InvalidArgumentException
     */
    protected function validate(): void
    {
        // Featured products and collections are optional but should be arrays
        if (!is_array($this->featuredProducts)) {
            throw new InvalidArgumentException('Featured products must be an array');
        }
        if (!is_array($this->collections)) {
            throw new InvalidArgumentException('Collections must be an array');
        }
    }

    /**
     * Create a HomeDTO from Shopify API response data.
     * 
     * Transforms raw Shopify GraphQL responses into a typed DTO instance.
     * Handles featured products, collections, and optional banner data.
     * 
     * @param array $data Raw home page data from Shopify GraphQL responses
     * @return self
     */
    public static function fromShopifyResponse(array $data): self
    {
        return new self(
            featuredProducts: array_map(
                fn($p) => ProductDTO::fromShopifyResponse($p['node'] ?? $p),
                $data['featured_products'] ?? []
            ),
            collections: array_map(
                fn($c) => CollectionDTO::fromShopifyResponse($c['node'] ?? $c),
                $data['collections'] ?? []
            ),
            banners: $data['banners'] ?? null,
        );
    }
}
