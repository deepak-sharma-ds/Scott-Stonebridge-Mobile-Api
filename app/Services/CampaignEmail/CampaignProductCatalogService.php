<?php

declare(strict_types=1);

namespace App\Services\CampaignEmail;

use App\Contracts\Shopify\AdminApiClientInterface;
use App\Models\MarketingCampaign;

/**
 * Lists Shopify products eligible for linking to a marketing campaign: those
 * set to Shopify's native Unlisted status and published to the Online Store
 * channel, minus any already linked to the given campaign. Feeds the admin
 * product picker on the campaign show page.
 */
class CampaignProductCatalogService
{
    public function __construct(
        private readonly AdminApiClientInterface $adminClient
    ) {}

    /**
     * @return array<int, array{id:int, title:string, image_url:?string, variants:array<int, array{id:int, title:string, price:string}>}>
     */
    public function availableFor(MarketingCampaign $marketingCampaign): array
    {
        $linkedProductIds = $marketingCampaign->campaignProducts()->pluck('shopify_product_id')->all();

        $response = $this->adminClient->query('admin/products/list_unlisted', ['first' => 250]);

        $edges = $response['data']['products']['edges'] ?? [];

        $products = [];

        foreach ($edges as $edge) {
            $node = $edge['node'] ?? [];

            if (empty($node['publishedOnCurrentPublication'])) {
                continue;
            }

            $productId = $this->numericId($node['id'] ?? '');

            if ($productId === null || in_array($productId, $linkedProductIds, true)) {
                continue;
            }

            $variants = [];

            foreach ($node['variants']['edges'] ?? [] as $variantEdge) {
                $variantNode = $variantEdge['node'] ?? [];
                $variantId = $this->numericId($variantNode['id'] ?? '');

                if ($variantId === null) {
                    continue;
                }

                $variants[] = [
                    'id' => $variantId,
                    'title' => (string) ($variantNode['title'] ?? ''),
                    'price' => (string) ($variantNode['price'] ?? ''),
                ];
            }

            if ($variants === []) {
                continue;
            }

            $products[] = [
                'id' => $productId,
                'title' => (string) ($node['title'] ?? ''),
                'image_url' => $node['featuredImage']['url'] ?? null,
                'variants' => $variants,
            ];
        }

        return $products;
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
