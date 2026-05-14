<?php

declare(strict_types=1);

namespace App\DTOs\Chat;

use App\DTOs\Base\BaseDTO;

/**
 * Trimmed product representation returned by ProductRecommendationService and
 * rendered by the frontend as product cards. Only the fields needed for the
 * card UI + AI prompt injection — full product data lives in Shopify.
 */
class ProductRecommendationDTO extends BaseDTO
{
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $handle,
        public readonly ?string $vendor,
        public readonly ?string $price,
        public readonly ?string $currency,
        public readonly ?string $image,
        public readonly bool $available,
        public readonly ?string $url,
    ) {
        $this->validate();
    }

    protected function validate(): void
    {
        $this->validateRequired($this->id, 'Product id');
        $this->validateRequired($this->title, 'Product title');
        $this->validateRequired($this->handle, 'Product handle');
    }

    /**
     * @param  array<string, mixed>  $node  Shopify product node from a GraphQL query
     */
    public static function fromShopifyNode(array $node, ?string $shopDomain = null): self
    {
        $variant = $node['variants']['edges'][0]['node'] ?? null;
        $price = $variant['price'] ?? $node['priceRange']['minVariantPrice']['amount'] ?? null;
        $currency = $variant['priceV2']['currencyCode'] ?? $node['priceRange']['minVariantPrice']['currencyCode'] ?? null;
        $image = $node['featuredImage']['url'] ?? $node['images']['edges'][0]['node']['url'] ?? null;
        $handle = (string) ($node['handle'] ?? '');

        return new self(
            id: (string) ($node['id'] ?? ''),
            title: (string) ($node['title'] ?? ''),
            handle: $handle,
            vendor: isset($node['vendor']) ? (string) $node['vendor'] : null,
            price: $price !== null ? (string) $price : null,
            currency: $currency !== null ? (string) $currency : null,
            image: $image !== null ? (string) $image : null,
            available: (bool) ($node['availableForSale'] ?? true),
            url: $shopDomain !== null && $handle !== '' ? "https://{$shopDomain}/products/{$handle}" : null,
        );
    }

    /**
     * Compact array representation used when injected into the OpenAI prompt.
     * Strips long descriptions / URLs to keep token usage minimal.
     *
     * @return array<string, mixed>
     */
    public function toPromptArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'vendor' => $this->vendor,
            'price' => $this->price,
            'currency' => $this->currency,
            'available' => $this->available,
        ];
    }
}
