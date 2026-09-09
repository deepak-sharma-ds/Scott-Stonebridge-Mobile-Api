<?php

declare(strict_types=1);

namespace App\Services\CampaignEmail;

use App\Models\CampaignProduct;

/**
 * Builds the Shopify cart permalink an admin pastes into a Klaviyo campaign
 * email, carrying the hidden `_campaign_key` line-item property that the
 * order-paid campaign webhook (ticket 04) will read to attribute the
 * purchase.
 *
 * One permalink covers exactly one (campaign, product) pairing — Shopify only
 * attaches permalink properties to the first product in a multi-variant
 * permalink, so callers must generate one link per product and never combine
 * them (see ADR 0002).
 */
class CampaignLinkGenerator
{
    /**
     * Returns null when the pairing has no Shopify variant ID yet — there is
     * nothing valid to generate a link for.
     */
    public function generate(CampaignProduct $campaignProduct, int $quantity = 1): ?string
    {
        if (! $campaignProduct->shopify_variant_id) {
            return null;
        }

        $campaignKey = $campaignProduct->marketingCampaign?->campaign_key;

        if (! $campaignKey) {
            return null;
        }

        $properties = rawurlencode(base64_encode(json_encode(['_campaign_key' => $campaignKey])));

        $domain = (string) config('shopify.store_domain');

        return "https://{$domain}/cart/{$campaignProduct->shopify_variant_id}:{$quantity}?properties={$properties}";
    }
}
