<?php

declare(strict_types=1);

namespace App\DTOs\Shopify;

use App\DTOs\BaseDTO;

final readonly class VariantDTO extends BaseDTO
{
    public function __construct(
        public string $id,
        public string $title,
        public ?string $sku,
        public MoneyDTO $price,
        public ?MoneyDTO $compareAtPrice,
        public bool $availableForSale,
        public int $quantityAvailable,
        public ?float $weight,
        public ?string $weightUnit,
        public ?ImageDTO $image,
        public array $selectedOptions,
    ) {}
    
    /**
     * Create from Shopify variant node
     */
    public static function fromShopifyNode(array $variant): self
    {
        return new self(
            id: $variant['id'],
            title: $variant['title'],
            sku: $variant['sku'] ?? null,
            price: MoneyDTO::fromShopifyMoney($variant['price']),
            compareAtPrice: isset($variant['compareAtPrice']) 
                ? MoneyDTO::fromShopifyMoney($variant['compareAtPrice']) 
                : null,
            availableForSale: $variant['availableForSale'] ?? true,
            quantityAvailable: $variant['quantityAvailable'] ?? 0,
            weight: isset($variant['weight']) ? (float) $variant['weight'] : null,
            weightUnit: $variant['weightUnit'] ?? null,
            image: isset($variant['image']) 
                ? ImageDTO::fromShopifyNode($variant['image']) 
                : null,
            selectedOptions: $variant['selectedOptions'] ?? [],
        );
    }
}
