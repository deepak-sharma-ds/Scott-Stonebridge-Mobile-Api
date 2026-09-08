<?php

declare(strict_types=1);

namespace App\Services\CampaignEmail;

use App\Models\MarketingCampaign;
use App\Models\UnlistedProduct;

/**
 * Lists locally-synced Shopify Unlisted products eligible for linking to a
 * marketing campaign, minus any already linked to the given campaign. Feeds
 * the admin product picker on the campaign show page. The catalog itself is
 * kept in sync with Shopify by UnlistedProductSyncService (see
 * SyncUnlistedProductsCommand) rather than calling Shopify on every request.
 */
class CampaignProductCatalogService
{
    /**
     * @return array<int, array{id:int, title:string, image_url:?string, variants:array<int, array<string, mixed>>, header_image:?string, header_image_url:?string, email_content:?string, email_footer:?string}>
     */
    public function availableFor(MarketingCampaign $marketingCampaign): array
    {
        $linkedProductIds = $marketingCampaign->campaignProducts()->pluck('shopify_product_id')->all();

        return UnlistedProduct::query()
            ->published()
            ->whereNotIn('shopify_product_id', $linkedProductIds)
            ->orderBy('title')
            ->get()
            ->map(fn (UnlistedProduct $product) => [
                'id' => $product->shopify_product_id,
                'title' => $product->title,
                'image_url' => $product->shopify_image_url,
                'variants' => $product->variants ?? [],
                'header_image' => $product->header_image,
                'header_image_url' => $product->header_image
                    ? asset('storage/campaign-header-images/'.$product->header_image)
                    : null,
                'email_content' => $product->email_content,
                'email_footer' => $product->email_footer,
            ])
            ->values()
            ->all();
    }
}
