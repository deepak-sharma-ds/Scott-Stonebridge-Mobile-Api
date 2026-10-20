<?php

namespace App\Services\Funnels;

use App\Models\Bundles\BundleMapPrice;
use App\Models\Funnels\Funnel;
use App\Models\Funnels\FunnelItem;
use App\Services\Bundles\BundleCatalogService;
use App\Services\Bundles\ShopifyBundleCredentials;
use Illuminate\Support\Facades\DB;

/**
 * Validates funnel input against live Shopify data and PKB rules, and persists it.
 */
class FunnelService
{
    public function __construct(protected BundleCatalogService $catalog)
    {
    }

    /**
     * Resolve offer items against Shopify and run all business rules.
     *
     * @param  array  $data  validated input: items[], trigger, placements, settings
     * @return array{items: array, errors: array, warnings: array, totals: array}
     */
    public function prepare(array $data): array
    {
        $errors = [];
        $warnings = [];
        $items = [];
        $rows = array_values($data['items'] ?? []);

        $variants = $this->catalog->fetchVariants(array_column($rows, 'variant_id'));
        $mapBySku = BundleMapPrice::whereIn('sku', collect($variants)->pluck('sku')->filter())->pluck('map_price', 'sku')
            ->map(fn ($p) => (float) $p)->all();
        $enforceMap = (bool) config('bundles.map.enforce');
        $requireAllMap = (bool) config('bundles.map.require_all_skus');

        foreach ($rows as $position => $row) {
            $variant = $variants[$row['variant_id']] ?? null;
            if (! $variant) {
                $errors[] = "An offer product no longer exists in Shopify ({$row['variant_id']}).";
                continue;
            }

            $label = $variant['product_title'] . ($variant['variant_title'] && $variant['variant_title'] !== 'Default Title' ? " ({$variant['variant_title']})" : '');
            $type = $row['discount_type'] ?? FunnelItem::PERCENTAGE;
            $value = (float) ($row['discount_value'] ?? 0);
            $price = (float) $variant['price'];
            $quantity = (int) ($row['quantity'] ?? 1);
            $maxQuantity = isset($row['max_quantity']) && $row['max_quantity'] !== '' && $row['max_quantity'] !== null
                ? (int) $row['max_quantity'] : null;

            if ($maxQuantity !== null && $maxQuantity < $quantity) {
                $errors[] = "{$label}: the max quantity must be at least the bundle quantity ({$quantity}).";
            }

            if ($variant['is_bundle']) {
                $errors[] = "{$label} is an old bundle product and can't be used in a funnel.";
            }
            if (! $variant['sku']) {
                $errors[] = "{$label} has no SKU. The warehouse fulfils by SKU.";
            }
            if ($type === FunnelItem::PERCENTAGE && ($value <= 0 || $value >= 100)) {
                $errors[] = "{$label}: the percentage discount must be between 0 and 100.";
            }
            // A fixed amount is taken off the product's line in the bundle (all its units together).
            if ($type === FunnelItem::FIXED && ($value <= 0 || $value >= $price * max(1, $quantity))) {
                $errors[] = $quantity > 1
                    ? sprintf('%s: the fixed discount must be more than 0 and less than %s (%d × %s).', $label, number_format($price * $quantity, 2), $quantity, number_format($price, 2))
                    : sprintf('%s: the fixed discount must be more than 0 and less than the price (%s).', $label, number_format($price, 2));
            }

            // MAP is checked on the price of one unit after the discount.
            $discounted = FunnelItem::discountedPrice($price, $type, $value, max(1, $quantity));

            // MAP: the price the customer pays after the bundle discount must not go below MAP.
            $sku = $variant['sku'];
            if ($sku && array_key_exists($sku, $mapBySku)) {
                if (round($discounted, 2) < $mapBySku[$sku]) {
                    $message = sprintf('%s: the discounted price %s is below its MAP of %s.', $label, number_format($discounted, 2), number_format($mapBySku[$sku], 2));
                    if ($enforceMap) {
                        $errors[] = $message;
                    } else {
                        $warnings[] = $message;
                    }
                }
            } elseif ($sku && $type !== FunnelItem::NONE) {
                $message = "{$label}: no MAP price on file for SKU {$sku}.";
                if ($requireAllMap) {
                    $errors[] = $message;
                } else {
                    $warnings[] = $message;
                }
            }

            // The storefront widget loads offer products from the Online Store; unpublished ones hide the offer.
            if (is_array($variant['channels'] ?? null) && ! in_array(BundleCatalogService::ONLINE_STORE, $variant['channels'], true)) {
                $errors[] = "{$label} isn't published on the Online Store sales channel, so customers can't see or buy it and the offer stays hidden. Publish it in Shopify (product → Sales channels) or pick another product.";
            }
            if ($variant['product_status'] !== 'ACTIVE') {
                $warnings[] = "{$label} is {$variant['product_status']} in Shopify.";
            }
            if ($variant['inventory_tracked'] && (int) $variant['inventory_quantity'] < $quantity) {
                $warnings[] = ($variant['continue_selling'] ?? false)
                    ? "{$label} has only {$variant['inventory_quantity']} in stock. \"Continue selling when out of stock\" is on, so the bundle can still be bought."
                    : "{$label} has only {$variant['inventory_quantity']} in stock and can't be sold below that (\"Continue selling when out of stock\" is off). Until it's restocked the bundle is hidden, or shown as unavailable when \"Hide out of stock\" is off.";
            }

            $items[] = [
                'product_id'     => $variant['product_id'],
                'variant_id'     => $variant['variant_id'],
                'product_title'  => $variant['product_title'],
                'variant_title'  => $variant['variant_title'],
                'handle'         => $variant['product_handle'],
                'sku'            => $sku,
                'image_url'      => $variant['image_url'],
                'price'          => $price,
                'quantity'       => $quantity,
                'max_quantity'   => $maxQuantity,
                'discount_type'  => $type,
                'discount_value' => $type === FunnelItem::NONE ? 0 : $value,
                'position'       => $position,
                'discounted'     => $discounted,
                'discounted_line' => FunnelItem::discountedLine($price, $type, $type === FunnelItem::NONE ? 0 : $value, max(1, $quantity)),
                'stock'          => $variant['inventory_tracked'] ? (int) $variant['inventory_quantity'] : null,
            ];
        }

        if (count($rows) === 0) {
            $errors[] = 'Add at least one offer product.';
        } elseif ($items && array_sum(array_column($items, 'quantity')) < 2) {
            $errors[] = 'A bundle must contain at least 2 items in total.';
        }
        if ($items && collect($items)->every(fn ($i) => $i['discount_type'] === FunnelItem::NONE)) {
            $warnings[] = 'No product has a discount, so no discount will show at checkout.';
        }
        if (count(array_unique(array_column($items, 'variant_id'))) !== count($items)) {
            $errors[] = 'The same product variant was added twice. Increase its quantity instead.';
        }

        $trigger = FunnelDefaults::merge('trigger', $data['trigger'] ?? []);
        $hasTrigger = $trigger['all'] || ($trigger['cart_value']['enabled'] ?? false)
            || $trigger['products'] || $trigger['collections'] || $trigger['tags'];
        if (! $hasTrigger) {
            $errors[] = 'Choose when the offer should appear (Trigger step).';
        }
        if (($trigger['cart_value']['enabled'] ?? false) && (float) ($trigger['cart_value']['min'] ?? 0) <= 0) {
            $errors[] = 'Enter a cart value greater than 0 for the cart value trigger.';
        }

        $placements = FunnelDefaults::merge('placements', $data['placements'] ?? []);
        if (! ($placements['product_page']['enabled'] ?? false) && ! ($placements['cart_page']['enabled'] ?? false)) {
            $errors[] = 'Choose at least one page to show the offer on (product page or cart page).';
        }

        $settings = FunnelDefaults::merge('settings', $data['settings'] ?? []);
        if ($settings['starts_at'] && $settings['ends_at'] && strtotime($settings['ends_at']) <= strtotime($settings['starts_at'])) {
            $errors[] = 'The schedule end date must be after the start date.';
        }

        $total = round(array_sum(array_map(fn ($i) => $i['price'] * $i['quantity'], $items)), 2);
        $discountedTotal = round(array_sum(array_column($items, 'discounted_line')), 2);

        return [
            'items'    => $items,
            'errors'   => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
            'totals'   => [
                'total'       => $total,
                'discounted'  => $discountedTotal,
                'savings'     => round($total - $discountedTotal, 2),
                'savings_pct' => $total > 0 ? round(($total - $discountedTotal) / $total * 100, 1) : 0,
                // Complete bundles the tracked stock allows: MIN(stock / bundle quantity); null when nothing is tracked
                'bundle_stock' => $this->bundleStock($items),
            ],
        ];
    }

    protected function bundleStock(array $items): ?int
    {
        $counts = array_map(fn ($i) => intdiv(max(0, $i['stock']), max(1, $i['quantity'])),
            array_filter($items, fn ($i) => $i['stock'] !== null));

        return $counts ? min($counts) : null;
    }

    /**
     * Persist a prepared funnel (create or update). Does not talk to Shopify.
     */
    public function save(?Funnel $funnel, array $data, array $prepared): Funnel
    {
        return DB::transaction(function () use ($funnel, $data, $prepared) {
            $attributes = [
                'name'         => $data['name'],
                'trigger'      => FunnelDefaults::merge('trigger', $data['trigger'] ?? []),
                'placements'   => FunnelDefaults::merge('placements', $data['placements'] ?? []),
                'settings'     => FunnelDefaults::merge('settings', $data['settings'] ?? []),
                'design'       => FunnelDefaults::merge('design', $data['design'] ?? []),
                'translations' => FunnelDefaults::merge('translations', $data['translations'] ?? []),
                'sync_status'  => 'pending',
                'needs_review' => false,
                'review_reason' => null,
            ];
            if (isset($data['status'])) {
                $attributes['status'] = $data['status'];
            }

            if ($funnel) {
                $funnel->update($attributes);
            } else {
                $funnel = Funnel::create($attributes + [
                    'shop_domain' => app(ShopifyBundleCredentials::class)->shopDomain(),
                    'status'      => $data['status'] ?? Funnel::INACTIVE,
                ]);
            }

            $funnel->items()->delete();
            foreach ($prepared['items'] as $item) {
                unset($item['discounted'], $item['discounted_line'], $item['stock']);
                $funnel->items()->create($item);
            }

            return $funnel->load('items');
        });
    }

    /**
     * Copy a funnel (inactive, no discounts yet).
     */
    public function duplicate(Funnel $funnel): Funnel
    {
        return DB::transaction(function () use ($funnel) {
            $copy = $funnel->replicate(['discount_ids', 'last_synced_at', 'last_sync_error', 'bundle_id']);
            $copy->name = mb_substr($funnel->name . ' (Copy)', 0, 255);
            $copy->status = Funnel::INACTIVE;
            $copy->sync_status = 'pending';
            $copy->discount_ids = [];
            $copy->save();

            foreach ($funnel->items as $item) {
                $copy->items()->create($item->only($item->getFillable()));
            }

            return $copy->load('items');
        });
    }
}
