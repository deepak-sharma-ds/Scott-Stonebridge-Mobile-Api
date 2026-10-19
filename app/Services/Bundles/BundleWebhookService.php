<?php

namespace App\Services\Bundles;

use App\Models\Bundles\Bundle;
use App\Models\Bundles\BundleComponent;
use App\Models\Bundles\BundleSyncLog;
use Throwable;

/**
 * Keeps bundles honest when products change in Shopify outside the app.
 * Nothing is changed in Shopify here; affected bundles are flagged for PKB to review.
 */
class BundleWebhookService
{
    public function __construct(protected ShopifyAdminClient $client)
    {
    }

    public function handle(string $topic, array $payload): void
    {
        if (empty($payload['id'])) {
            return;
        }

        $productGid = str_starts_with($topic, 'products/') ? Gid::make('Product', $payload['id']) : '';

        if (str_starts_with($topic, 'products/')) {
            $this->flagFunnels($topic, $productGid, $payload);
        }

        match ($topic) {
            'products/update' => $this->productUpdated($productGid, $payload),
            'products/delete' => $this->productDeleted($productGid),
            'orders/create'   => app(\App\Services\Funnels\FunnelTrackingService::class)->recordOrder($payload),
            default           => null,
        };
    }

    protected function productUpdated(string $productGid, array $payload): void
    {
        // A bundle parent product edited directly in the Shopify admin.
        foreach (Bundle::where('shopify_product_id', $productGid)->get() as $bundle) {
            $expected = $bundle->isActive() ? 'active' : 'draft';
            $actual = $payload['status'] ?? $expected;
            // Webhooks can arrive late (e.g. the "draft" event from our own create step lands after
            // we've activated the product), so confirm against the live status before flagging.
            if ($actual !== $expected && $this->liveStatus($productGid) !== $expected) {
                $this->flag($bundle, "Bundle product status was changed to \"{$actual}\" in Shopify admin (app expects \"{$expected}\").");
            }
        }

        // A component product changed.
        $variants = collect($payload['variants'] ?? [])->keyBy(fn ($v) => Gid::make('ProductVariant', $v['id']));
        $components = BundleComponent::with('bundle')->where('shopify_product_id', $productGid)->get();

        foreach ($components as $component) {
            if (! $component->bundle) {
                continue;
            }

            $variant = $variants->get($component->shopify_variant_id);
            $reasons = [];

            if (! $variant) {
                $reasons[] = "Component variant for {$component->display_title} was removed.";
            } else {
                if (($variant['sku'] ?? null) !== $component->sku) {
                    $reasons[] = "SKU of {$component->display_title} changed from {$component->sku} to " . (($variant['sku'] ?? null) ?: '(none)') . '.';
                }
                if (round((float) ($variant['price'] ?? 0), 2) !== round((float) $component->unit_price, 2)) {
                    $reasons[] = "Price of {$component->display_title} changed from {$component->unit_price} to {$variant['price']}. Review bundle price, savings and MAP.";
                }
            }

            if (($payload['status'] ?? 'active') !== 'active') {
                $reasons[] = "{$component->product_title} is now {$payload['status']} in Shopify.";
            }

            if ($reasons) {
                $this->flag($component->bundle, implode(' ', $reasons));
            }
        }
    }

    protected function productDeleted(string $productGid): void
    {
        foreach (Bundle::where('shopify_product_id', $productGid)->get() as $bundle) {
            $bundle->update([
                'status'             => Bundle::STATUS_PAUSED,
                'sync_status'        => Bundle::SYNC_FAILED,
                'shopify_product_id' => null,
                'shopify_variant_id' => null,
                'last_sync_error'    => 'Bundle product was deleted in Shopify.',
            ]);
            $this->flag($bundle, 'Bundle product was deleted in Shopify. Use "Sync to Shopify" to recreate it.');
        }

        $components = BundleComponent::with('bundle')->where('shopify_product_id', $productGid)->get();
        foreach ($components as $component) {
            if ($component->bundle) {
                $this->flag($component->bundle, "Component {$component->display_title} was deleted in Shopify.");
            }
        }
    }

    /**
     * Funnels whose offer products changed: deleted product/variant or a price change (MAP re-check).
     */
    protected function flagFunnels(string $topic, string $productGid, array $payload): void
    {
        $items = \App\Models\Funnels\FunnelItem::with('funnel')->where('product_id', $productGid)->get();
        $variants = collect($payload['variants'] ?? [])->keyBy(fn ($v) => Gid::make('ProductVariant', $v['id']));

        foreach ($items as $item) {
            if (! $item->funnel) {
                continue;
            }

            $reason = null;
            if ($topic === 'products/delete') {
                $reason = "Offer product {$item->title} was deleted in Shopify.";
            } elseif (! $variants->has($item->variant_id)) {
                $reason = "Offer variant {$item->title} was removed in Shopify.";
            } elseif (round((float) ($variants[$item->variant_id]['price'] ?? 0), 2) !== round((float) $item->price, 2)) {
                $reason = "Price of {$item->title} changed from {$item->price} to {$variants[$item->variant_id]['price']}. Check the discount and MAP.";
            }

            if ($reason) {
                $item->funnel->update(['needs_review' => true, 'review_reason' => $reason]);
            }
        }
    }

    protected function liveStatus(string $productGid): ?string
    {
        try {
            $data = $this->client->query('query($id: ID!) { product(id: $id) { status } }', ['id' => $productGid]);

            return isset($data['product']['status']) ? strtolower($data['product']['status']) : null;
        } catch (Throwable) {
            return null; // can't confirm -> flag, to be safe
        }
    }

    protected function flag(Bundle $bundle, string $reason): void
    {
        $bundle->update(['needs_review' => true, 'review_reason' => $reason]);

        BundleSyncLog::create([
            'bundle_id' => $bundle->id,
            'operation' => 'webhook',
            'status'    => 'failed',
            'error'     => $reason,
        ]);
    }
}
