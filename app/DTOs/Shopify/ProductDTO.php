<?php

declare(strict_types=1);

namespace App\DTOs\Shopify;

use App\DTOs\BaseDTO;
use Illuminate\Support\Collection;

final readonly class ProductDTO extends BaseDTO
{
    public function __construct(
        public string $id,
        public string $title,
        public string $handle,
        public ?string $description,
        public ?string $descriptionHtml,
        public string $vendor,
        public string $productType,
        public array $tags,
        public Collection $images,      // Collection<ImageDTO>
        public Collection $variants,    // Collection<VariantDTO>
        public array $options,
        public bool $availableForSale,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {}
    
    /**
     * Create from Shopify product node
     */
    public static function fromShopifyNode(array $product): self
    {
        return new self(
            id: $product['id'],
            title: $product['title'],
            handle: $product['handle'],
            description: $product['description'] ?? null,
            descriptionHtml: $product['descriptionHtml'] ?? null,
            vendor: $product['vendor'] ?? '',
            productType: $product['productType'] ?? '',
            tags: $product['tags'] ?? [],
            images: collect($product['images']['edges'] ?? [])->map(
                fn($edge) => ImageDTO::fromShopifyNode($edge['node'])
            ),
            variants: collect($product['variants']['edges'] ?? [])->map(
                fn($edge) => VariantDTO::fromShopifyNode($edge['node'])
            ),
            options: $product['options'] ?? [],
            availableForSale: $product['availableForSale'] ?? true,
            createdAt: new \DateTimeImmutable($product['createdAt']),
            updatedAt: new \DateTimeImmutable($product['updatedAt']),
        );
    }
}
