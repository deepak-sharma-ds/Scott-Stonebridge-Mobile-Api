<?php

namespace App\Http\Controllers\ShopifyApp;

use App\Models\Bundles\Bundle;
use App\Models\Bundles\BundleMapPrice;
use App\Models\Bundles\BundleSyncLog;

/**
 * JSON shapes returned to the embedded app.
 */
class Presenter
{
    public static function bundle(Bundle $b, bool $detail = false): array
    {
        $data = [
            'id'                => $b->id,
            'title'             => $b->title,
            'status'            => $b->status,
            'sync_status'       => $b->sync_status,
            'needs_review'      => $b->needs_review,
            'review_reason'     => $b->review_reason,
            'price'             => (float) $b->price,
            'compare_at_price'  => $b->compare_at_price !== null ? (float) $b->compare_at_price : null,
            'savings_amount'    => $b->savings_amount,
            'savings_percent'   => $b->savings_percent,
            'map_floor'         => $b->map_floor !== null ? (float) $b->map_floor : null,
            'components_count'  => $b->components_count ?? $b->components->count(),
            'items_count'       => $detail ? (int) $b->components->sum('quantity') : null,
            'updated_at'        => $b->updated_at?->toIso8601String(),
        ];

        if (! $detail) {
            return $data;
        }

        return $data + [
            'description'          => $b->description,
            'image'                => $b->images->first()?->url,
            'images'               => $b->images->map(fn ($i) => [
                'id'       => $i->id,
                'url'      => $i->url,
                'synced'   => (bool) $i->shopify_media_id,
                'name'     => $i->original_name,
            ])->values()->all(),
            'shopify_product_id'   => $b->shopify_product_id,
            'shopify_handle'       => $b->shopify_handle,
            'shopify_admin_url'    => $b->shopify_admin_url,
            'last_synced_at'       => $b->last_synced_at?->toIso8601String(),
            'last_sync_error'      => $b->last_sync_error,
            'created_at'           => $b->created_at?->toIso8601String(),
            'offer' => [
                'product_page'       => (bool) $b->offer_on_product_page,
                'cart'               => (bool) $b->offer_in_cart,
                'heading'            => $b->offer_heading,
                'include_components' => (bool) $b->offer_include_components,
                'products'           => array_values($b->offer_extra_products ?? []),
            ],
            'components' => $b->components->map(fn ($c) => [
                'variant_id'    => $c->shopify_variant_id,
                'product_id'    => $c->shopify_product_id,
                'product_title' => $c->product_title,
                'variant_title' => $c->variant_title,
                'title'         => $c->display_title,
                'sku'           => $c->sku,
                'quantity'      => $c->quantity,
                'price'         => (float) $c->unit_price,
                'map_price'     => $c->map_price !== null ? (float) $c->map_price : null,
                'line_total'    => $c->line_total,
                'image'         => $c->image_url,
            ])->values()->all(),
        ];
    }

    public static function mapPrice(BundleMapPrice $m): array
    {
        return [
            'id'         => $m->id,
            'sku'        => $m->sku,
            'map_price'  => (float) $m->map_price,
            'notes'      => $m->notes,
            'updated_at' => $m->updated_at?->toIso8601String(),
        ];
    }

    public static function log(BundleSyncLog $l): array
    {
        return [
            'id'          => $l->id,
            'bundle'      => $l->bundle ? ['id' => $l->bundle->id, 'title' => $l->bundle->title, 'deleted' => $l->bundle->trashed()] : null,
            'funnel'      => $l->funnel ? ['id' => $l->funnel->id, 'name' => $l->funnel->name, 'deleted' => $l->funnel->trashed()] : null,
            'operation'   => $l->operation,
            'status'      => $l->status,
            'error'       => $l->error,
            'steps'       => $l->details['steps'] ?? [],
            'duration_ms' => $l->duration_ms,
            'user'        => $l->user?->name ?? ($l->operation === 'webhook' ? 'Shopify' : 'Shopify admin'),
            'created_at'  => $l->created_at?->toIso8601String(),
        ];
    }
}
