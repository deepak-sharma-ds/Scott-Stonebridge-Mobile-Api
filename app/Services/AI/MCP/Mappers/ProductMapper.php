<?php

declare(strict_types=1);

namespace App\Services\AI\MCP\Mappers;

use App\DTOs\Chat\ProductDetailDTO;
use App\DTOs\Chat\ProductRecommendationDTO;

/**
 * Adapter for Shopify Storefront MCP product tool responses.
 *
 * Shopify MCP tools return `{content: [{type: ..., data: <structured>}]}` or a
 * top-level `products` / `product` key. We probe defensively so a minor
 * upstream rename doesn't take the whole carousel offline.
 */
final class ProductMapper
{
    /**
     * @param  array<string, mixed>  $mcpResult  `result` payload from `search_catalog` / `lookup_catalog`.
     * @return list<ProductRecommendationDTO>
     */
    public static function fromSearchResult(array $mcpResult, ?string $shopDomain = null): array
    {
        $items = self::extractProductList($mcpResult);
        $out = [];
        foreach ($items as $node) {
            $dto = self::buildRecommendation((array) $node, $shopDomain);
            if ($dto !== null) {
                $out[] = $dto;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $mcpResult  `result` payload from `get_product`.
     */
    public static function fromGetProduct(array $mcpResult): ?ProductDetailDTO
    {
        $node = self::extractSingleProduct($mcpResult);
        if ($node === null) {
            return null;
        }

        $id = (string) ($node['id'] ?? '');
        $title = (string) ($node['title'] ?? '');
        $handle = (string) ($node['handle'] ?? '');
        if ($id === '' || $title === '' || $handle === '') {
            return null;
        }

        return new ProductDetailDTO(
            id: $id,
            title: $title,
            handle: $handle,
            descriptionHtml: self::stringOrNull($node['description_html'] ?? $node['descriptionHtml'] ?? null),
            images: self::extractImages($node),
            variants: self::extractVariants($node),
            priceMinorUnits: self::priceMinor($node['price'] ?? $node['price_range']['min'] ?? null),
            currency: self::extractCurrency($node),
            vendor: self::stringOrNull($node['vendor'] ?? null),
            tags: array_values(array_filter(array_map(
                static fn ($tag): string => (string) $tag,
                (array) ($node['tags'] ?? []),
            ), static fn (string $tag): bool => $tag !== '')),
        );
    }

    /**
     * @param  array<string, mixed>  $mcpResult
     * @return list<array<string, mixed>>
     */
    private static function extractProductList(array $mcpResult): array
    {
        foreach (['products', 'items', 'results'] as $key) {
            if (isset($mcpResult[$key]) && is_array($mcpResult[$key])) {
                return array_values($mcpResult[$key]);
            }
        }

        $content = $mcpResult['content'] ?? null;
        if (is_array($content)) {
            foreach ($content as $entry) {
                if (is_array($entry) && isset($entry['data']['products']) && is_array($entry['data']['products'])) {
                    return array_values($entry['data']['products']);
                }
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $mcpResult
     * @return array<string, mixed>|null
     */
    private static function extractSingleProduct(array $mcpResult): ?array
    {
        if (isset($mcpResult['product']) && is_array($mcpResult['product'])) {
            return $mcpResult['product'];
        }

        $list = self::extractProductList($mcpResult);

        return $list[0] ?? null;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private static function buildRecommendation(array $node, ?string $shopDomain): ?ProductRecommendationDTO
    {
        $id = (string) ($node['id'] ?? '');
        $title = (string) ($node['title'] ?? '');
        $handle = (string) ($node['handle'] ?? '');
        if ($id === '' || $title === '' || $handle === '') {
            return null;
        }

        $firstVariant = $node['variants'][0] ?? null;
        $priceMinor = self::priceMinor($firstVariant['price'] ?? $node['price'] ?? $node['price_range']['min'] ?? null);

        return new ProductRecommendationDTO(
            id: $id,
            title: $title,
            handle: $handle,
            vendor: self::stringOrNull($node['vendor'] ?? null),
            price: $priceMinor !== null ? number_format($priceMinor / 100, 2, '.', '') : null,
            currency: self::extractCurrency($node),
            image: self::firstImageUrl($node),
            available: (bool) ($node['available_for_sale'] ?? $node['availableForSale'] ?? true),
            url: $shopDomain !== null ? "https://{$shopDomain}/products/{$handle}" : null,
            variantId: self::stringOrNull($firstVariant['id'] ?? null),
            priceMinorUnits: $priceMinor,
        );
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<array{url:string,alt:?string}>
     */
    private static function extractImages(array $node): array
    {
        $images = (array) ($node['images'] ?? []);
        $out = [];
        foreach ($images as $image) {
            if (! is_array($image)) {
                continue;
            }
            $url = self::stringOrNull($image['url'] ?? $image['src'] ?? null);
            if ($url === null) {
                continue;
            }
            $out[] = ['url' => $url, 'alt' => self::stringOrNull($image['alt'] ?? $image['altText'] ?? null)];
        }

        if ($out === []) {
            $featured = self::stringOrNull($node['featured_image']['url'] ?? $node['featuredImage']['url'] ?? null);
            if ($featured !== null) {
                $out[] = ['url' => $featured, 'alt' => null];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<array{id:string,title:?string,price_minor_units:?int,available:bool,sku:?string,options:array<string,string>}>
     */
    private static function extractVariants(array $node): array
    {
        $variants = (array) ($node['variants'] ?? []);
        $out = [];
        foreach ($variants as $v) {
            if (! is_array($v)) {
                continue;
            }
            $id = self::stringOrNull($v['id'] ?? null);
            if ($id === null) {
                continue;
            }

            $rawOptions = (array) ($v['selected_options'] ?? $v['options'] ?? []);
            $options = [];
            foreach ($rawOptions as $opt) {
                if (is_array($opt) && isset($opt['name'], $opt['value'])) {
                    $options[(string) $opt['name']] = (string) $opt['value'];
                }
            }

            $out[] = [
                'id' => $id,
                'title' => self::stringOrNull($v['title'] ?? null),
                'price_minor_units' => self::priceMinor($v['price'] ?? null),
                'available' => (bool) ($v['available'] ?? $v['available_for_sale'] ?? $v['availableForSale'] ?? true),
                'sku' => self::stringOrNull($v['sku'] ?? null),
                'options' => $options,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private static function firstImageUrl(array $node): ?string
    {
        $images = self::extractImages($node);

        return $images[0]['url'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private static function extractCurrency(array $node): ?string
    {
        $candidates = [
            $node['currency'] ?? null,
            $node['currency_code'] ?? null,
            $node['price']['currency'] ?? null,
            $node['price']['currency_code'] ?? null,
            $node['price_range']['min']['currency'] ?? null,
            $node['price_range']['min']['currency_code'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Convert a price into minor units (e.g. pence). Shopify MCP returns money
     * as either a `{amount, currency_code}` object or a decimal/numeric string
     * in major units — never raw cents — so all numerics are × 100.
     * Explicit minor amounts can still come through via a `minor_units` key.
     */
    private static function priceMinor(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            if (isset($value['minor_units']) && is_numeric($value['minor_units'])) {
                return (int) $value['minor_units'];
            }
            $value = $value['amount'] ?? null;
            if ($value === null) {
                return null;
            }
        }

        if (is_numeric($value)) {
            return (int) round(((float) $value) * 100);
        }

        return null;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_string($value) ? $value : (string) $value;
    }
}
