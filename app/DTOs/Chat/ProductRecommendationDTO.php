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
        public readonly ?string $variantId = null,
        public readonly ?int $priceMinorUnits = null,
        /** @var list<array{url:string,alt:?string}> */
        public readonly array $images = [],
        /** @var list<array{id:string,title:?string,price_minor_units:?int,available:bool,options:array<string,string>,image:?string}> */
        public readonly array $variants = [],
        /** @var list<array{name:string,values:list<string>}> */
        public readonly array $options = [],
        public readonly bool $hasVariants = false,
        /** @var list<string> */
        public readonly array $tags = [],
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

        // Shopify Storefront 2024+ returns `variant.price` as a MoneyV2 object
        // `{amount, currencyCode}`. Older deprecated path returned a scalar
        // string. Support both so a schema update or fallback query path
        // doesn't blow up with "Array to string conversion".
        $variantPrice = $variant['price'] ?? null;
        if (is_array($variantPrice)) {
            $price = $variantPrice['amount'] ?? null;
            $currency = $variantPrice['currencyCode'] ?? null;
        } else {
            $price = $variantPrice;
            $currency = $variant['priceV2']['currencyCode'] ?? null;
        }

        $price ??= $node['priceRange']['minVariantPrice']['amount'] ?? null;
        $currency ??= $node['priceRange']['minVariantPrice']['currencyCode'] ?? null;

        $image = $node['featuredImage']['url']
            ?? $variant['image']['url']
            ?? $node['images']['edges'][0]['node']['url']
            ?? null;
        $handle = (string) ($node['handle'] ?? '');

        // Storefront money is a major-unit decimal string ("31.11") — convert
        // to integer minor units for the SSE `products` chunk.
        $priceMinor = is_numeric($price) ? (int) round(((float) $price) * 100) : null;

        $images = self::collectImages($node);
        if ($images === [] && $image !== null) {
            $images = [['url' => (string) $image, 'alt' => null]];
        }
        $variants = self::collectVariants($node);
        $optionGroups = self::collectOptionGroups($node, $variants);
        $hasVariants = count($variants) > 1 || (isset($variants[0]) && ($variants[0]['options'] ?? []) !== []);

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
            variantId: isset($variant['id']) ? (string) $variant['id'] : null,
            priceMinorUnits: $priceMinor,
            images: $images,
            variants: $variants,
            options: $optionGroups,
            hasVariants: $hasVariants,
            tags: self::extractTags($node),
        );
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    private static function extractTags(array $node): array
    {
        return array_values(array_filter(array_map(
            static fn ($tag): string => (string) $tag,
            (array) ($node['tags'] ?? []),
        ), static fn (string $tag): bool => $tag !== ''));
    }

    /**
     * Shopify's synthetic single variant / option label — never selectable.
     */
    private const DEFAULT_VARIANT_TITLE = 'Default Title';

    /**
     * @param  array<string, mixed>  $node
     * @return list<array{url:string,alt:?string}>
     */
    private static function collectImages(array $node): array
    {
        $out = [];
        $seen = [];
        foreach ((array) ($node['images']['edges'] ?? []) as $edge) {
            $img = is_array($edge) ? ($edge['node'] ?? $edge) : null;
            $url = is_array($img) ? ($img['url'] ?? $img['src'] ?? null) : null;
            if (! is_string($url) || $url === '' || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $out[] = ['url' => $url, 'alt' => isset($img['altText']) && is_string($img['altText']) ? $img['altText'] : null];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<array{id:string,title:?string,price_minor_units:?int,available:bool,options:array<string,string>,image:?string}>
     */
    private static function collectVariants(array $node): array
    {
        $out = [];
        foreach ((array) ($node['variants']['edges'] ?? []) as $edge) {
            $v = is_array($edge) ? ($edge['node'] ?? $edge) : null;
            if (! is_array($v) || empty($v['id'])) {
                continue;
            }

            $vp = $v['price'] ?? null;
            $amount = is_array($vp) ? ($vp['amount'] ?? null) : $vp;
            $priceMinor = is_numeric($amount) ? (int) round(((float) $amount) * 100) : null;

            $options = [];
            foreach ((array) ($v['selectedOptions'] ?? $v['selected_options'] ?? []) as $opt) {
                $name = is_array($opt) ? ($opt['name'] ?? null) : null;
                $value = is_array($opt) ? ($opt['value'] ?? $opt['label'] ?? null) : null;
                if (! is_string($name) || $name === '' || ! is_string($value) || $value === '') {
                    continue;
                }
                if ($value === self::DEFAULT_VARIANT_TITLE) {
                    continue;
                }
                $options[$name] = $value;
            }

            $title = isset($v['title']) && is_string($v['title']) ? $v['title'] : null;

            $out[] = [
                'id' => (string) $v['id'],
                'title' => $title === self::DEFAULT_VARIANT_TITLE ? null : $title,
                'price_minor_units' => $priceMinor,
                'available' => (bool) ($v['availableForSale'] ?? $v['available'] ?? true),
                'options' => $options,
                'image' => isset($v['image']['url']) && is_string($v['image']['url']) ? $v['image']['url'] : null,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<array<string, mixed>>  $variants
     * @return list<array{name:string,values:list<string>}>
     */
    private static function collectOptionGroups(array $node, array $variants): array
    {
        $out = [];
        foreach ((array) ($node['options'] ?? []) as $opt) {
            $name = is_array($opt) ? ($opt['name'] ?? null) : null;
            $values = array_values(array_filter(array_map(
                static fn ($v): string => is_string($v) ? $v : (string) $v,
                (array) (is_array($opt) ? ($opt['values'] ?? []) : []),
            ), static fn (string $v): bool => $v !== '' && $v !== self::DEFAULT_VARIANT_TITLE));
            if (! is_string($name) || $name === '' || $name === 'Title' || $name === self::DEFAULT_VARIANT_TITLE || $values === []) {
                continue;
            }
            $out[] = ['name' => $name, 'values' => $values];
        }
        if ($out !== []) {
            return $out;
        }

        // Derive from variant option maps when the product-level block is absent.
        $grouped = [];
        foreach ($variants as $variant) {
            foreach ((array) ($variant['options'] ?? []) as $name => $value) {
                if (is_string($name) && is_string($value) && $value !== '') {
                    $grouped[$name][$value] = true;
                }
            }
        }
        foreach ($grouped as $name => $values) {
            $out[] = ['name' => $name, 'values' => array_keys($values)];
        }

        return $out;
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

    /**
     * SSE-chunk shape used by the new MCP `products` chunk. Snake-cased and
     * matches the schema agreed with the frontend renderer for product cards.
     *
     * @return array<string, mixed>
     */
    public function toMcpChunk(): array
    {
        return [
            'id' => $this->id,
            'variant_id' => $this->variantId,
            'title' => $this->title,
            'handle' => $this->handle,
            'image' => $this->image,
            'images' => $this->images,
            'price_minor_units' => $this->priceMinorUnits,
            'currency' => $this->currency,
            'available' => $this->available,
            'options' => $this->options,
            'variants' => $this->variants,
            'has_variants' => $this->hasVariants,
            'tags' => $this->tags,
        ];
    }
}
