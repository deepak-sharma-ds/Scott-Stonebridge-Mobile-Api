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
        public readonly ?string $descriptionHtml,
        public readonly ?string $vendor,
        public readonly ?string $productType,
        public readonly array $tags,
        public readonly bool $availableForSale,
        public readonly array $images,
        public readonly array $variants,
        public readonly array $options,
        public readonly ?string $publishedAt,
        public readonly ?string $updatedAt,
        public readonly array $metafields = [],
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
     * @param  array  $data  Raw product data from Shopify GraphQL response
     */
    public static function fromShopifyResponse(array $data): self
    {
        // Map variants from Shopify response
        $variants = array_map(
            fn ($v) => ProductVariantDTO::fromShopifyResponse($v['node'] ?? $v),
            $data['variants']['edges'] ?? $data['variants'] ?? []
        );

        // Sort variants by price (ascending)
        usort($variants, function ($a, $b) {
            return (float) $a->price <=> (float) $b->price;
        });

        return new self(
            id: $data['id'],
            title: $data['title'],
            handle: $data['handle'],
            description: $data['description'] ?? null,
            descriptionHtml: $data['descriptionHtml'] ?? null,
            vendor: $data['vendor'] ?? null,
            productType: $data['productType'] ?? null,
            tags: $data['tags'] ?? [],
            availableForSale: $data['availableForSale'] ?? false,
            images: array_map(
                fn ($edge) => [
                    'url' => $edge['node']['url'] ?? $edge['url'] ?? $edge['src'] ?? '',
                    'alt' => $edge['node']['altText'] ?? $edge['altText'] ?? $edge['alt'] ?? null,
                    'width' => $edge['node']['width'] ?? null,
                    'height' => $edge['node']['height'] ?? null,
                ],
                $data['images']['edges'] ?? $data['images'] ?? []
            ),
            variants: $variants,
            options: $data['options'] ?? [],
            publishedAt: $data['publishedAt'] ?? null,
            updatedAt: $data['updatedAt'] ?? null,
            metafields: self::buildMetafields($data['metafields'] ?? []),
        );
    }

    /**
     * Build a flat metafield map keyed by metafield key.
     *
     * Values are cast based on their Shopify metafield type, and file/media
     * references (e.g. the audio player) are resolved to their URL.
     *
     * @param  mixed  $metafields  Raw Shopify metafields list (may contain nulls for missing keys)
     * @return array<string, mixed>
     */
    private static function buildMetafields(mixed $metafields): array
    {
        if (! is_array($metafields)) {
            return [];
        }

        $map = [];

        foreach ($metafields as $metafield) {
            if (! is_array($metafield) || empty($metafield['key'])) {
                continue;
            }

            $map[$metafield['key']] = self::castMetafieldValue($metafield);
        }

        return $map;
    }

    /**
     * Cast a single metafield to a native value based on its type.
     *
     * @param  array  $metafield  Single Shopify metafield node
     */
    private static function castMetafieldValue(array $metafield): mixed
    {
        if (! empty($metafield['reference'])) {
            return self::resolveReferenceUrl($metafield['reference']);
        }

        $value = $metafield['value'] ?? null;

        if ($value === null) {
            return null;
        }

        return match ($metafield['type'] ?? null) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'number_integer' => (int) $value,
            'number_decimal' => (float) $value,
            default => $value,
        };
    }

    /**
     * Resolve a Shopify file/media reference to its URL.
     *
     * @param  array  $reference  Resolved reference node from the Storefront API
     */
    private static function resolveReferenceUrl(array $reference): ?string
    {
        return $reference['url']
            ?? $reference['image']['url']
            ?? $reference['sources'][0]['url']
            ?? null;
    }
}
