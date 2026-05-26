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
        $unwrapped = McpEnvelope::unwrap($mcpResult);
        $items = self::extractProductList($unwrapped);
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
     * @param  array<string, mixed>  $mcpResult  `result` payload from `get_product_details`.
     */
    public static function fromGetProduct(array $mcpResult): ?ProductDetailDTO
    {
        $unwrapped = McpEnvelope::unwrap($mcpResult);
        $node = self::extractSingleProduct($unwrapped);
        if ($node === null) {
            return null;
        }

        // Shopify `get_product_details` returns `product_id` (not `id`) and
        // exposes the variant under `selectedOrFirstAvailableVariant` rather
        // than a `variants[]` array.
        $id = (string) ($node['product_id'] ?? $node['id'] ?? '');
        $title = (string) ($node['title'] ?? '');
        $handle = (string) ($node['handle'] ?? self::handleFromUrl($node['url'] ?? null) ?? '');
        if ($id === '' || $title === '' || $handle === '') {
            return null;
        }

        $variants = self::extractVariants($node);
        if ($variants === [] && isset($node['selectedOrFirstAvailableVariant']) && is_array($node['selectedOrFirstAvailableVariant'])) {
            $variants = self::extractVariants(['variants' => [$node['selectedOrFirstAvailableVariant']]]);
        }

        $priceMinor = self::priceMinor(
            $node['price']
            ?? $node['price_range']['min']
            ?? ($node['selectedOrFirstAvailableVariant']['price'] ?? null),
        );

        return new ProductDetailDTO(
            id: $id,
            title: $title,
            handle: $handle,
            descriptionHtml: self::extractDescription($node),
            images: self::extractImages($node),
            variants: $variants,
            priceMinorUnits: $priceMinor,
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
        $handle = (string) ($node['handle'] ?? self::handleFromUrl($node['url'] ?? null) ?? '');
        if ($id === '' || $title === '' || $handle === '') {
            return null;
        }

        $firstVariant = $node['variants'][0] ?? null;
        $priceMinor = self::priceMinor($firstVariant['price'] ?? $node['price'] ?? $node['price_range']['min'] ?? null);

        $url = self::stringOrNull($node['url'] ?? null)
            ?? ($shopDomain !== null ? "https://{$shopDomain}/products/{$handle}" : null);

        return new ProductRecommendationDTO(
            id: $id,
            title: $title,
            handle: $handle,
            vendor: self::stringOrNull($node['vendor'] ?? null),
            price: $priceMinor !== null ? number_format($priceMinor / 100, 2, '.', '') : null,
            currency: self::extractCurrency($node),
            image: self::firstImageUrl($node),
            available: (bool) (
                $node['available_for_sale']
                ?? $node['availableForSale']
                ?? $node['available']
                ?? $node['availability']['available']
                ?? ($firstVariant['availability']['available'] ?? null)
                ?? true
            ),
            url: $url,
            variantId: self::stringOrNull($firstVariant['id'] ?? null),
            priceMinorUnits: $priceMinor,
        );
    }

    private static function handleFromUrl(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        if (preg_match('~/products/([^/?#]+)~', $url, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Shopify MCP ships HTML as `description: {html: "..."}`. Older shapes
     * use scalar `description_html`. Read both.
     *
     * @param  array<string, mixed>  $node
     */
    private static function extractDescription(array $node): ?string
    {
        $desc = $node['description'] ?? null;
        if (is_array($desc) && isset($desc['html']) && is_string($desc['html'])) {
            return $desc['html'];
        }

        return self::stringOrNull($node['description_html'] ?? $node['descriptionHtml'] ?? (is_string($desc) ? $desc : null));
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<array{url:string,alt:?string}>
     */
    private static function extractImages(array $node): array
    {
        $out = [];

        // Shopify Storefront MCP ships imagery under `media[]` where each item
        // is `{type: "image"|"video"|..., url, alt_text}`. Filter for images.
        $media = (array) ($node['media'] ?? []);
        foreach ($media as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $type = $entry['type'] ?? 'image';
            if ($type !== 'image') {
                continue;
            }
            $url = self::stringOrNull($entry['url'] ?? $entry['src'] ?? null);
            if ($url === null) {
                continue;
            }
            $out[] = [
                'url' => $url,
                'alt' => self::stringOrNull($entry['alt_text'] ?? $entry['altText'] ?? $entry['alt'] ?? null),
            ];
        }

        // `get_product_details` ships images as either a string array
        // `["https://..."]` or `[{url, alt_text}]`. Plus a top-level `image_url`.
        if ($out === []) {
            $imageUrl = self::stringOrNull($node['image_url'] ?? null);
            if ($imageUrl !== null) {
                $out[] = ['url' => $imageUrl, 'alt' => null];
            }

            foreach ((array) ($node['images'] ?? []) as $image) {
                if (is_string($image) && $image !== '') {
                    $out[] = ['url' => $image, 'alt' => null];
                } elseif (is_array($image)) {
                    $url = self::stringOrNull($image['url'] ?? $image['src'] ?? null);
                    if ($url === null) {
                        continue;
                    }
                    $out[] = [
                        'url' => $url,
                        'alt' => self::stringOrNull($image['alt_text'] ?? $image['altText'] ?? $image['alt'] ?? null),
                    ];
                }
            }
        }

        // Fallback to legacy `images[]` shape.
        if ($out === []) {
            $images = (array) ($node['images'] ?? []);
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
        }

        if ($out === []) {
            $featured = self::stringOrNull(
                $node['featured_image']['url']
                ?? $node['featuredImage']['url']
                ?? $node['image']['url']
                ?? (is_string($node['image'] ?? null) ? $node['image'] : null)
                ?? null,
            );
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
            // Shopify `get_product_details` ships variant id as `variant_id`.
            $id = self::stringOrNull($v['variant_id'] ?? $v['id'] ?? null);
            if ($id === null) {
                continue;
            }

            $rawOptions = (array) ($v['selected_options'] ?? $v['options'] ?? []);
            $options = [];
            foreach ($rawOptions as $opt) {
                if (! is_array($opt)) {
                    continue;
                }
                $name = $opt['name'] ?? null;
                // Shopify MCP variant options carry `label` (the chosen value),
                // not `value`. Read both for backward compat.
                $value = $opt['value'] ?? $opt['label'] ?? null;
                if (is_string($name) && $name !== '' && is_string($value) && $value !== '') {
                    $options[$name] = $value;
                }
            }

            $available = $v['availability']['available']
                ?? $v['available']
                ?? $v['available_for_sale']
                ?? $v['availableForSale']
                ?? true;

            $out[] = [
                'id' => $id,
                'title' => self::stringOrNull($v['title'] ?? null),
                'price_minor_units' => self::priceMinor($v['price'] ?? null),
                'available' => (bool) $available,
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
            $node['price_range']['currency'] ?? null,
            $node['price_range']['min']['currency'] ?? null,
            $node['price_range']['min']['currency_code'] ?? null,
            $node['selectedOrFirstAvailableVariant']['currency'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Convert a price into minor units.
     *
     * Shopify Storefront MCP returns money as `{amount: int, currency: "GBP"}`
     * where `amount` is ALREADY in minor units (pence). Older fixtures /
     * legacy paths ship decimal strings like `"24.99"` which are major.
     *
     * Rule: integer amount → minor (Shopify MCP). Decimal string / float
     * anywhere → major (× 100). Explicit `minor_units` key wins.
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

        if (is_int($value)) {
            return $value;
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
