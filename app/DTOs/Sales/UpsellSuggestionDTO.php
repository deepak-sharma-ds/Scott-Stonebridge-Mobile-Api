<?php

declare(strict_types=1);

namespace App\DTOs\Sales;

use App\DTOs\Base\BaseDTO;

/**
 * Trimmed Shopify product payload returned by UpsellService.
 *
 * Source data comes from Storefront productRecommendations(productId).
 * This DTO is the only shape the controller/resource consumes, so changes
 * to the Shopify response are absorbed inside fromShopifyNode().
 */
class UpsellSuggestionDTO extends BaseDTO
{
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $handle,
        public readonly ?string $imageUrl,
        public readonly ?string $imageAlt,
        public readonly ?string $variantId,
        public readonly ?string $price,
        public readonly string $currency,
        public readonly bool $available,
    ) {
        $this->validate();
    }

    protected function validate(): void
    {
        if ($this->id === '' || $this->title === '' || $this->handle === '') {
            throw new \InvalidArgumentException('UpsellSuggestionDTO requires id, title and handle.');
        }
    }

    /**
     * Build from a single `productRecommendations` Shopify GraphQL node.
     *
     * @param  array<string, mixed>  $node
     */
    public static function fromShopifyNode(array $node, string $fallbackCurrency = 'GBP'): ?self
    {
        $id = (string) ($node['id'] ?? '');
        $title = (string) ($node['title'] ?? '');
        $handle = (string) ($node['handle'] ?? '');

        if ($id === '' || $title === '' || $handle === '') {
            return null;
        }

        $firstVariant = $node['variants']['edges'][0]['node'] ?? null;
        $variantPrice = $firstVariant['price']['amount'] ?? null;
        $variantCurrency = $firstVariant['price']['currencyCode'] ?? null;
        $minPrice = $node['priceRange']['minVariantPrice']['amount'] ?? null;
        $minCurrency = $node['priceRange']['minVariantPrice']['currencyCode'] ?? null;

        return new self(
            id: $id,
            title: $title,
            handle: $handle,
            imageUrl: $node['featuredImage']['url'] ?? null,
            imageAlt: $node['featuredImage']['altText'] ?? null,
            variantId: $firstVariant['id'] ?? null,
            price: $variantPrice !== null ? (string) $variantPrice : ($minPrice !== null ? (string) $minPrice : null),
            currency: (string) ($variantCurrency ?? $minCurrency ?? $fallbackCurrency),
            available: (bool) ($firstVariant['availableForSale'] ?? $node['availableForSale'] ?? true),
        );
    }

    /**
     * Compact array suitable for AI prompt injection (Phase 6).
     *
     * @return array<string, mixed>
     */
    public function toPromptArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'handle' => $this->handle,
            'price' => $this->price,
            'available' => $this->available,
        ];
    }
}
