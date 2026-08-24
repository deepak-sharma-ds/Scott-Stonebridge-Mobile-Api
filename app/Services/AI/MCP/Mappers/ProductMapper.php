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
     * Shopify auto-creates this single variant + option pair for products that
     * have no real options. It must never surface in the UI as a selectable
     * variant.
     */
    private const DEFAULT_VARIANT_TITLE = 'Default Title';

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
            tags: self::extractTags($node),
            options: self::extractOptions($node, $variants),
            hasVariants: self::hasRealVariants($variants),
        );
    }

    /**
     * Map a Storefront GraphQL `productByHandle` node (from
     * `storefront/products/get_product_details`) — the richer source that
     * carries ALL variants, per-variant images, and product option groups.
     * Shopify MCP `get_product_details` only returns one variant, so this is
     * the preferred detail source.
     *
     * @param  array<string, mixed>  $node
     */
    public static function fromStorefrontDetailNode(array $node): ?ProductDetailDTO
    {
        $id = (string) ($node['id'] ?? '');
        $title = (string) ($node['title'] ?? '');
        $handle = (string) ($node['handle'] ?? '');
        if ($id === '' || $title === '' || $handle === '') {
            return null;
        }

        $variants = self::variantsFromEdges($node['variants']['edges'] ?? []);
        $images = self::imagesFromEdges($node['images']['edges'] ?? []);

        // Fall back to the first variant image when the product has no gallery.
        if ($images === []) {
            foreach ($variants as $variant) {
                if (! empty($variant['image'])) {
                    $images[] = ['url' => (string) $variant['image'], 'alt' => null];
                    break;
                }
            }
        }

        $priceMinor = $variants[0]['price_minor_units']
            ?? self::priceMinor($node['priceRange']['minVariantPrice'] ?? null);

        return new ProductDetailDTO(
            id: $id,
            title: $title,
            handle: $handle,
            descriptionHtml: self::stringOrNull($node['descriptionHtml'] ?? $node['description'] ?? null),
            images: $images,
            variants: $variants,
            priceMinorUnits: $priceMinor,
            currency: self::currencyFromEdges($node),
            vendor: self::stringOrNull($node['vendor'] ?? null),
            tags: self::extractTags($node),
            options: self::optionGroups($node['options'] ?? [], $variants),
            hasVariants: self::hasRealVariants($variants),
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
     * Variant list from a Storefront GraphQL `variants.edges[].node` shape,
     * including the per-variant image url.
     *
     * @return list<array{id:string,title:?string,price_minor_units:?int,available:bool,sku:?string,options:array<string,string>,image:?string}>
     */
    private static function variantsFromEdges(mixed $edges): array
    {
        if (! is_array($edges)) {
            return [];
        }

        $out = [];
        foreach ($edges as $edge) {
            $v = is_array($edge) ? ($edge['node'] ?? $edge) : null;
            if (! is_array($v)) {
                continue;
            }
            $id = self::stringOrNull($v['id'] ?? $v['variant_id'] ?? null);
            if ($id === null) {
                continue;
            }

            $title = self::stringOrNull($v['title'] ?? null);

            $out[] = [
                'id' => $id,
                'title' => $title === self::DEFAULT_VARIANT_TITLE ? null : $title,
                'price_minor_units' => self::priceMinor($v['price'] ?? null),
                'available' => (bool) ($v['availableForSale'] ?? $v['available'] ?? true),
                'sku' => self::stringOrNull($v['sku'] ?? null),
                'options' => self::cleanSelectedOptions($v['selectedOptions'] ?? $v['selected_options'] ?? []),
                'image' => self::stringOrNull($v['image']['url'] ?? $v['image'] ?? null),
            ];
        }

        return $out;
    }

    /**
     * @return list<array{url:string,alt:?string}>
     */
    private static function imagesFromEdges(mixed $edges): array
    {
        if (! is_array($edges)) {
            return [];
        }

        $out = [];
        foreach ($edges as $edge) {
            $img = is_array($edge) ? ($edge['node'] ?? $edge) : null;
            if (! is_array($img)) {
                continue;
            }
            $url = self::stringOrNull($img['url'] ?? $img['src'] ?? null);
            if ($url === null) {
                continue;
            }
            $out[] = ['url' => $url, 'alt' => self::stringOrNull($img['altText'] ?? $img['alt_text'] ?? $img['alt'] ?? null)];
        }

        return $out;
    }

    /**
     * Drop Shopify's synthetic "Title: Default Title" pair so it never renders
     * as a selectable option.
     *
     * @return array<string,string>
     */
    private static function cleanSelectedOptions(mixed $rawOptions): array
    {
        if (! is_array($rawOptions)) {
            return [];
        }

        $options = [];
        foreach ($rawOptions as $opt) {
            if (! is_array($opt)) {
                continue;
            }
            $name = $opt['name'] ?? null;
            $value = $opt['value'] ?? $opt['label'] ?? null;
            if (! is_string($name) || $name === '' || ! is_string($value) || $value === '') {
                continue;
            }
            // Drop Shopify's synthetic default option. The value is always
            // "Default Title"; the name is usually "Title" but some legacy
            // products carry the name "Default Title" too — match on value.
            if ($value === self::DEFAULT_VARIANT_TITLE) {
                continue;
            }
            $options[$name] = $value;
        }

        return $options;
    }

    /**
     * Product-level option groups for the variant picker, derived from the
     * GraphQL `options { name, values }` block. Drops the synthetic "Title"
     * group.
     *
     * @param  list<array<string, mixed>>  $variants
     * @return list<array{name:string,values:list<string>}>
     */
    private static function optionGroups(mixed $rawOptions, array $variants): array
    {
        $out = [];
        if (is_array($rawOptions)) {
            foreach ($rawOptions as $opt) {
                if (! is_array($opt)) {
                    continue;
                }
                $name = self::stringOrNull($opt['name'] ?? null);
                $values = array_values(array_filter(array_map(
                    static fn ($v): string => is_string($v) ? $v : (string) $v,
                    (array) ($opt['values'] ?? []),
                ), static fn (string $v): bool => $v !== '' && $v !== self::DEFAULT_VARIANT_TITLE));
                if ($name === null || $name === 'Title' || $name === self::DEFAULT_VARIANT_TITLE || $values === []) {
                    continue;
                }
                $out[] = ['name' => $name, 'values' => $values];
            }
        }

        if ($out !== []) {
            return $out;
        }

        // Derive from variant selected_options when the explicit group is absent.
        return self::extractOptions([], $variants);
    }

    /**
     * Build option groups by collecting distinct values across variant
     * `options` maps. Used for the MCP shape where product-level option groups
     * aren't returned.
     *
     * @param  array<string, mixed>  $node
     * @param  list<array<string, mixed>>  $variants
     * @return list<array{name:string,values:list<string>}>
     */
    private static function extractOptions(array $node, array $variants): array
    {
        $grouped = [];
        foreach ($variants as $variant) {
            foreach ((array) ($variant['options'] ?? []) as $name => $value) {
                if (! is_string($name) || ! is_string($value) || $value === '') {
                    continue;
                }
                $grouped[$name][$value] = true;
            }
        }

        $out = [];
        foreach ($grouped as $name => $values) {
            $out[] = ['name' => $name, 'values' => array_keys($values)];
        }

        return $out;
    }

    /**
     * A product has real, selectable variants when there is more than one
     * variant OR the single variant carries a non-default option.
     *
     * @param  list<array<string, mixed>>  $variants
     */
    private static function hasRealVariants(array $variants): bool
    {
        if (count($variants) > 1) {
            return true;
        }
        if ($variants === []) {
            return false;
        }

        return ($variants[0]['options'] ?? []) !== [];
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private static function currencyFromEdges(array $node): ?string
    {
        $firstVariant = $node['variants']['edges'][0]['node'] ?? null;

        return self::stringOrNull(
            ($firstVariant['price']['currencyCode'] ?? null)
            ?? ($node['priceRange']['minVariantPrice']['currencyCode'] ?? null)
            ?? self::extractCurrency($node),
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
            tags: self::extractTags($node),
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

            // Shopify MCP variant options carry `label` (the chosen value),
            // not `value` — cleanSelectedOptions reads both and strips the
            // synthetic "Default Title" pair.
            $options = self::cleanSelectedOptions($v['selected_options'] ?? $v['options'] ?? []);

            $available = $v['availability']['available']
                ?? $v['available']
                ?? $v['available_for_sale']
                ?? $v['availableForSale']
                ?? true;

            $title = self::stringOrNull($v['title'] ?? null);

            $out[] = [
                'id' => $id,
                'title' => $title === self::DEFAULT_VARIANT_TITLE ? null : $title,
                'price_minor_units' => self::priceMinor($v['price'] ?? null),
                'available' => (bool) $available,
                'sku' => self::stringOrNull($v['sku'] ?? null),
                'options' => $options,
                'image' => self::stringOrNull($v['image']['url'] ?? $v['image'] ?? $v['image_url'] ?? null),
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
