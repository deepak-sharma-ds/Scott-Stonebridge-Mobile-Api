<?php

namespace App\Services\Bundles;

/**
 * Reads component products/variants from Shopify.
 */
class BundleCatalogService
{
    protected const VARIANT_FIELDS = <<<'GQL'
        id
        title
        sku
        price
        compareAtPrice
        inventoryQuantity
        inventoryPolicy
        availableForSale
        inventoryItem { tracked }
        image { url }
        product {
            id
            title
            handle
            status
            tags
            featuredMedia { preview { image { url } } }
        }
    GQL;

    /** Sales channels a product is published on (only published ones are returned). */
    protected const PUBLICATION_FIELDS = 'resourcePublicationsV2(first: 25) { nodes { publication { name } } }';

    /** The storefront sales channel: the widget loads offer products from it. */
    public const ONLINE_STORE = 'Online Store';

    public function __construct(protected ShopifyAdminClient $client)
    {
    }

    /**
     * Search variants for the component picker. Bundle products themselves are excluded
     * (bundles cannot contain other bundles).
     */
    public function searchVariants(string $term, int $limit = 25): array
    {
        $query = sprintf('query($q: String, $n: Int!) { productVariants(first: $n, query: $q) { nodes { %s } } }', self::VARIANT_FIELDS);

        $data = $this->client->query($query, [
            'q' => trim($term) !== '' ? trim($term) : null,
            'n' => $limit,
        ]);

        return collect($data['productVariants']['nodes'] ?? [])
            ->reject(fn ($v) => $this->isBundleProduct($v['product'] ?? []))
            ->reject(fn ($v) => ($v['product']['status'] ?? '') === 'ARCHIVED')
            ->map(fn ($v) => $this->normalize($v))
            ->values()
            ->all();
    }

    /**
     * Fetch variants by GID, keyed by GID. Missing/deleted variants are absent from the result.
     */
    public function fetchVariants(array $variantIds): array
    {
        $variantIds = array_values(array_unique(array_filter($variantIds)));
        if (! $variantIds) {
            return [];
        }

        $fields = str_replace('featuredMedia {', self::PUBLICATION_FIELDS . "
            featuredMedia {", self::VARIANT_FIELDS);
        $query = sprintf('query($ids: [ID!]!) { nodes(ids: $ids) { ... on ProductVariant { %s } } }', $fields);

        $result = [];
        foreach (array_chunk($variantIds, 100) as $chunk) {
            $data = $this->client->query($query, ['ids' => $chunk]);
            foreach ($data['nodes'] ?? [] as $node) {
                if (! empty($node['id'])) {
                    $result[$node['id']] = $this->normalize($node) + ['is_bundle' => $this->isBundleProduct($node['product'] ?? [])];
                }
            }
        }

        return $result;
    }

    /**
     * Variants by SKU (exact, case-insensitive), keyed by lower-cased SKU: [['label' => 'Product (Variant)', 'price' => 7.0], ...].
     * Archived products are ignored.
     */
    public function variantsBySku(array $skus): array
    {
        $skus = array_values(array_unique(array_filter(array_map(fn ($s) => trim((string) $s), $skus))));
        $wanted = array_flip(array_map('mb_strtolower', $skus));
        $result = [];

        foreach (array_chunk($skus, 25) as $chunk) {
            $search = implode(' OR ', array_map(fn ($s) => 'sku:"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"', $chunk));
            $data = $this->client->query(<<<'GQL'
                query($q: String!) {
                    productVariants(first: 250, query: $q) {
                        nodes { sku title price product { title status } }
                    }
                }
            GQL, ['q' => $search]);

            foreach ($data['productVariants']['nodes'] ?? [] as $v) {
                $key = mb_strtolower(trim((string) ($v['sku'] ?? '')));
                if ($key === '' || ! isset($wanted[$key]) || ($v['product']['status'] ?? '') === 'ARCHIVED') {
                    continue;
                }
                $variant = ($v['title'] ?? '') !== '' && $v['title'] !== 'Default Title' ? " ({$v['title']})" : '';
                $result[$key][] = ['sku' => trim($v['sku']), 'label' => ($v['product']['title'] ?? $v['sku']) . $variant, 'price' => (float) $v['price']];
            }
        }

        return $result;
    }

    /**
     * Product search for the "also show this offer on" picker. Bundles are excluded.
     */
    public function searchProducts(string $term, int $limit = 25): array
    {
        $data = $this->client->query(<<<'GQL'
            query($q: String, $n: Int!) {
                products(first: $n, query: $q) {
                    nodes { id title status tags featuredMedia { preview { image { url } } } }
                }
            }
        GQL, ['q' => trim($term) !== '' ? trim($term) : null, 'n' => $limit]);

        return collect($data['products']['nodes'] ?? [])
            ->reject(fn ($p) => $this->isBundleProduct($p) || $p['status'] === 'ARCHIVED')
            ->map(fn ($p) => [
                'id'     => $p['id'],
                'title'  => $p['title'],
                'status' => $p['status'],
                'image'  => $p['featuredMedia']['preview']['image']['url'] ?? null,
            ])
            ->values()
            ->all();
    }

    /**
     * Resolve product GIDs to [{id, title}], dropping missing products and bundles.
     */
    public function fetchProducts(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter($productIds)));
        if (! $productIds) {
            return [];
        }

        $data = $this->client->query(<<<'GQL'
            query($ids: [ID!]!) { nodes(ids: $ids) { ... on Product { id title tags } } }
        GQL, ['ids' => $productIds]);

        return collect($data['nodes'] ?? [])
            ->filter(fn ($p) => ! empty($p['id']) && ! $this->isBundleProduct($p))
            ->map(fn ($p) => ['id' => $p['id'], 'title' => $p['title']])
            ->values()
            ->all();
    }

    public function isBundleProduct(array $product): bool
    {
        return in_array(config('bundles.product_tag'), $product['tags'] ?? [], true);
    }

    protected function normalize(array $v): array
    {
        $product = $v['product'] ?? [];

        return [
            'variant_id'         => $v['id'],
            'product_id'         => $product['id'] ?? null,
            'product_title'      => $product['title'] ?? '',
            'product_handle'     => $product['handle'] ?? null,
            'product_status'     => $product['status'] ?? null,
            'variant_title'      => $v['title'] ?? null,
            'sku'                => $v['sku'] ?: null,
            'price'              => (float) ($v['price'] ?? 0),
            'inventory_quantity' => $v['inventoryQuantity'] ?? null,
            'inventory_tracked'  => (bool) ($v['inventoryItem']['tracked'] ?? false),
            'continue_selling'   => ($v['inventoryPolicy'] ?? 'DENY') === 'CONTINUE',
            'available_for_sale' => (bool) ($v['availableForSale'] ?? false),
            'image_url'          => $v['image']['url'] ?? ($product['featuredMedia']['preview']['image']['url'] ?? null),
            // null = unknown (channels not requested)
            'channels'           => isset($product['resourcePublicationsV2']['nodes'])
                ? array_values(array_filter(array_map(fn ($n) => $n['publication']['name'] ?? null, $product['resourcePublicationsV2']['nodes'])))
                : null,
        ];
    }
}
