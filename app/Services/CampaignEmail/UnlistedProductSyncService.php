<?php

declare(strict_types=1);

namespace App\Services\CampaignEmail;

use App\Contracts\Shopify\AdminApiClientInterface;
use App\Models\UnlistedProduct;

/**
 * Syncs Shopify's Unlisted-status products into the local `unlisted_products`
 * table, so the campaign product picker (CampaignProductCatalogService) never
 * has to call Shopify live. Never overwrites admin-authored header_image /
 * email_content / email_footer — those are only ever written by the
 * campaign-linking flow (CampaignProductController::syncProductDefaults()).
 */
class UnlistedProductSyncService
{
    private const MAX_PAGES = 20;

    private const PAGE_SIZE = 250;

    public function __construct(
        private readonly AdminApiClientInterface $adminClient
    ) {}

    /**
     * @return array{synced:int, deactivated:int}
     */
    public function sync(): array
    {
        $seenIds = [];
        $cursor = null;
        $page = 0;

        do {
            $variables = ['first' => self::PAGE_SIZE];
            if ($cursor !== null) {
                $variables['after'] = $cursor;
            }

            $response = $this->adminClient->query('admin/products/list_unlisted', $variables);
            $productsData = $response['data']['products'] ?? [];

            foreach ($productsData['edges'] ?? [] as $edge) {
                $node = $edge['node'] ?? [];
                $productId = $this->numericId($node['id'] ?? '');
                $variants = $this->mapVariants($node['variants']['edges'] ?? []);

                if ($productId === null || $variants === []) {
                    continue;
                }

                $seenIds[] = $productId;

                UnlistedProduct::updateOrCreate(
                    ['shopify_product_id' => $productId],
                    [
                        'title' => (string) ($node['title'] ?? ''),
                        'shopify_image_url' => $node['featuredImage']['url'] ?? null,
                        'variants' => $variants,
                        'is_published' => (bool) ($node['publishedOnCurrentPublication'] ?? false),
                        'last_synced_at' => now(),
                    ]
                );
            }

            $pageInfo = $productsData['pageInfo'] ?? [];
            $hasNextPage = (bool) ($pageInfo['hasNextPage'] ?? false);
            $cursor = $pageInfo['endCursor'] ?? null;
            $page++;
        } while ($hasNextPage && $cursor !== null && $page < self::MAX_PAGES);

        $deactivated = UnlistedProduct::whereNotIn('shopify_product_id', $seenIds)
            ->where('is_published', true)
            ->update(['is_published' => false]);

        return ['synced' => count($seenIds), 'deactivated' => $deactivated];
    }

    /**
     * @param  array<int, array{node: array<string, mixed>}>  $edges
     * @return array<int, array{id:int, title:string, price:string, available_for_sale:bool}>
     */
    private function mapVariants(array $edges): array
    {
        $variants = [];

        foreach ($edges as $edge) {
            $node = $edge['node'] ?? [];
            $variantId = $this->numericId($node['id'] ?? '');

            if ($variantId === null) {
                continue;
            }

            $variants[] = [
                'id' => $variantId,
                'title' => (string) ($node['title'] ?? ''),
                'price' => (string) ($node['price'] ?? ''),
                'available_for_sale' => (bool) ($node['availableForSale'] ?? false),
            ];
        }

        return $variants;
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
}
