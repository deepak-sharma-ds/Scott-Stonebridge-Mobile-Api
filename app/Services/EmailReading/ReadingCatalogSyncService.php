<?php

declare(strict_types=1);

namespace App\Services\EmailReading;

use App\Contracts\Shopify\AdminApiClientInterface;
use App\Models\EmailReadingProduct;
use Illuminate\Support\Str;

/**
 * Backs the Reading Catalog Sync admin picker: browse Shopify collections and
 * their products live (no local mirror table — see ADR 0018), then persist
 * only the products an admin explicitly checks. Never overwrites an existing
 * row's admin-authored fields (questions_schema, prompt_template, email
 * fields, is_active, is_automation_enabled) — sync only ever touches
 * Shopify-sourced metadata (name).
 */
class ReadingCatalogSyncService
{
    private const PAGE_SIZE = 250;

    private const MAX_PAGES = 20;

    public function __construct(
        private readonly AdminApiClientInterface $adminClient
    ) {}

    /**
     * @return array<int, array{id:int, title:string, handle:string}>
     */
    public function collections(): array
    {
        $collections = [];
        $cursor = null;
        $page = 0;

        do {
            $variables = ['first' => self::PAGE_SIZE];
            if ($cursor !== null) {
                $variables['after'] = $cursor;
            }

            $response = $this->adminClient->query('admin/collections/list', $variables);
            $data = $response['data']['collections'] ?? [];

            foreach ($data['edges'] ?? [] as $edge) {
                $node = $edge['node'] ?? [];
                $id = $this->numericId($node['id'] ?? '');

                if ($id === null) {
                    continue;
                }

                $collections[] = [
                    'id' => $id,
                    'title' => (string) ($node['title'] ?? ''),
                    'handle' => (string) ($node['handle'] ?? ''),
                ];
            }

            $pageInfo = $data['pageInfo'] ?? [];
            $hasNextPage = (bool) ($pageInfo['hasNextPage'] ?? false);
            $cursor = $pageInfo['endCursor'] ?? null;
            $page++;
        } while ($hasNextPage && $cursor !== null && $page < self::MAX_PAGES);

        return $collections;
    }

    /**
     * Products across one or more collections, deduplicated by Shopify
     * product id (the same product can appear in multiple selected
     * collections).
     *
     * @param  array<int, int>  $collectionIds
     * @return array<int, array{id:int, title:string, image_url:?string}>
     */
    public function productsForCollections(array $collectionIds): array
    {
        $products = [];

        // Callers (e.g. the admin controller, fed straight from validated
        // request input) may hand this numeric strings rather than ints —
        // this file is `strict_types=1`, so `toGid()` below would otherwise
        // throw a TypeError. Same defensive cast `syncSelected()` already
        // does for shopify product ids.
        $collectionIds = array_map('intval', $collectionIds);

        foreach ($collectionIds as $collectionId) {
            $cursor = null;
            $page = 0;

            do {
                $variables = [
                    'id' => $this->toGid('Collection', $collectionId),
                    'first' => self::PAGE_SIZE,
                ];
                if ($cursor !== null) {
                    $variables['after'] = $cursor;
                }

                $response = $this->adminClient->query('admin/products/list_by_collection', $variables);
                $collectionData = $response['data']['collection'] ?? null;

                if ($collectionData === null) {
                    break;
                }

                $productsData = $collectionData['products'] ?? [];

                foreach ($productsData['edges'] ?? [] as $edge) {
                    $node = $edge['node'] ?? [];
                    $id = $this->numericId($node['id'] ?? '');

                    if ($id === null) {
                        continue;
                    }

                    $products[$id] = [
                        'id' => $id,
                        'title' => (string) ($node['title'] ?? ''),
                        'image_url' => $node['featuredImage']['url'] ?? null,
                    ];
                }

                $pageInfo = $productsData['pageInfo'] ?? [];
                $hasNextPage = (bool) ($pageInfo['hasNextPage'] ?? false);
                $cursor = $pageInfo['endCursor'] ?? null;
                $page++;
            } while ($hasNextPage && $cursor !== null && $page < self::MAX_PAGES);
        }

        return array_values($products);
    }

    /**
     * Re-fetch the given Shopify product ids server-side (never trusting
     * whatever the browser posted) and upsert them into
     * `email_reading_products`. An already-registered product only has its
     * `name` refreshed — its question schema, prompt template, email fields,
     * and both flags are left exactly as the admin last set them.
     *
     * @param  array<int, int>  $shopifyProductIds
     * @return array{synced:int}
     */
    public function syncSelected(array $shopifyProductIds): array
    {
        $shopifyProductIds = array_values(array_unique(array_filter(
            array_map('intval', $shopifyProductIds)
        )));

        if ($shopifyProductIds === []) {
            return ['synced' => 0];
        }

        $gids = array_map(fn (int $id) => $this->toGid('Product', $id), $shopifyProductIds);

        $response = $this->adminClient->query('admin/products/list_by_ids', ['ids' => $gids]);
        $nodes = $response['data']['nodes'] ?? [];

        $synced = 0;

        foreach ($nodes as $node) {
            if ($node === null) {
                continue;
            }

            $id = $this->numericId($node['id'] ?? '');
            $title = (string) ($node['title'] ?? '');

            if ($id === null || $title === '') {
                continue;
            }

            $existing = EmailReadingProduct::where('shopify_product_id', $id)->first();

            if ($existing) {
                // Refresh only Shopify-owned display text. slug,
                // questions_schema, prompt_template, email fields, and both
                // flags are admin-authored and left exactly as set.
                $existing->update(['name' => $title]);
            } else {
                EmailReadingProduct::create([
                    'shopify_product_id' => $id,
                    'name' => $title,
                    'slug' => $this->uniqueSlug($title, $id),
                    'is_active' => false,
                    'is_automation_enabled' => false,
                ]);
            }

            $synced++;
        }

        return ['synced' => $synced];
    }

    /**
     * Extract the trailing numeric ID from a Shopify gid, e.g.
     * "gid://shopify/Product/123456789" -> 123456789.
     */
    private function numericId(string $gid): ?int
    {
        if (preg_match('/(\d+)$/', $gid, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    private function toGid(string $type, int $id): string
    {
        return "gid://shopify/{$type}/{$id}";
    }

    /**
     * Mirrors EmailReadingProductRequest::prepareForValidation()'s slug
     * derivation. Only called on first creation of a row — an existing
     * row's slug is never touched by sync.
     */
    private function uniqueSlug(string $title, int $shopifyProductId): string
    {
        $base = Str::slug($title) ?: (string) $shopifyProductId;
        $slug = $base;
        $suffix = 1;

        while (EmailReadingProduct::where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }
}
