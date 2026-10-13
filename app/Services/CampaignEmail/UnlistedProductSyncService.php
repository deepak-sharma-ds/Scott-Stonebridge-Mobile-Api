<?php

declare(strict_types=1);

namespace App\Services\CampaignEmail;

use App\Contracts\Shopify\AdminApiClientInterface;
use App\Models\UnlistedProduct;
use RuntimeException;

/**
 * Syncs Shopify's Unlisted-status products into the local `unlisted_products`
 * table, so the campaign product picker (CampaignProductCatalogService) never
 * has to call Shopify live.
 *
 * Deliberately conservative: a product already in the table only has its
 * Shopify-owned fields refreshed (title, image, variants, vip flag, sync
 * stamp). `is_published` and the admin-authored header_image / email_content
 * / email_footer are never touched, and nothing is ever unpublished or
 * deleted automatically, so a throttled or partial Shopify response can
 * never wipe the catalog. A product seen for the first time is created
 * published.
 */
class UnlistedProductSyncService
{
    private const MAX_PAGES = 20;

    private const PAGE_SIZE = 250;

    public function __construct(
        private readonly AdminApiClientInterface $adminClient
    ) {}

    /**
     * @return array{synced:int, created:int}
     */
    public function sync(): array
    {
        $synced = 0;
        $created = 0;
        $cursor = null;
        $page = 0;

        do {
            $variables = ['first' => self::PAGE_SIZE];
            if ($cursor !== null) {
                $variables['after'] = $cursor;
            }

            $response = $this->adminClient->query('admin/products/list_unlisted', $variables);

            if (! isset($response['data']['products']) && ! empty($response['errors'])) {
                throw new RuntimeException('Shopify unlisted-products sync failed: '.json_encode($response['errors']));
            }

            $productsData = $response['data']['products'] ?? [];

            foreach ($productsData['edges'] ?? [] as $edge) {
                $node = $edge['node'] ?? [];
                $productId = $this->numericId($node['id'] ?? '');
                $variants = $this->mapVariants($node['variants']['edges'] ?? []);

                if ($productId === null || $variants === []) {
                    continue;
                }

                $shopifyOwned = [
                    'title' => (string) ($node['title'] ?? ''),
                    'shopify_image_url' => $node['featuredImage']['url'] ?? null,
                    'variants' => $variants,
                    'is_vip' => $this->hasVipTag((array) ($node['tags'] ?? [])),
                    'last_synced_at' => now(),
                ];

                $existing = UnlistedProduct::where('shopify_product_id', $productId)->first();

                if ($existing) {
                    $existing->update($shopifyOwned);
                } else {
                    UnlistedProduct::create($shopifyOwned + [
                        'shopify_product_id' => $productId,
                        'is_published' => true,
                    ]);
                    $created++;
                }

                $synced++;
            }

            $pageInfo = $productsData['pageInfo'] ?? [];
            $hasNextPage = (bool) ($pageInfo['hasNextPage'] ?? false);
            $cursor = $pageInfo['endCursor'] ?? null;
            $page++;
        } while ($hasNextPage && $cursor !== null && $page < self::MAX_PAGES);

        return ['synced' => $synced, 'created' => $created];
    }

    /**
     * @param  array<int, mixed>  $tags
     */
    private function hasVipTag(array $tags): bool
    {
        $vipTag = strtolower(trim((string) config('campaign_email.vip_tag', 'vip')));

        foreach ($tags as $tag) {
            if (strtolower(trim((string) $tag)) === $vipTag) {
                return true;
            }
        }

        return false;
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
