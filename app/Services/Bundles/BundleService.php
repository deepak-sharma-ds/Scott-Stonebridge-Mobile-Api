<?php

namespace App\Services\Bundles;

use App\Models\Bundles\Bundle;
use Illuminate\Support\Facades\DB;

/**
 * Validates bundle input against live Shopify data and persists it locally.
 */
class BundleService
{
    public function __construct(
        protected BundleCatalogService $catalog,
        protected MapPricingService $mapPricing,
        protected BundleImageService $images,
    ) {
    }

    /**
     * Resolve component rows against Shopify and run all business rules.
     *
     * @param  array  $rows  each: ['variant_id' => gid, 'quantity' => int]
     * @return array{components: array, compare_at: float, map: array, errors: array, warnings: array}
     */
    public function prepare(array $rows, float $price): array
    {
        $errors = [];
        $warnings = [];
        $components = [];

        $variants = $this->catalog->fetchVariants(array_column($rows, 'variant_id'));

        foreach (array_values($rows) as $position => $row) {
            $variant = $variants[$row['variant_id']] ?? null;

            if (! $variant) {
                $errors[] = "Component {$row['variant_id']} no longer exists in Shopify.";
                continue;
            }

            $label = trim($variant['product_title'] . ($variant['variant_title'] !== 'Default Title' ? " ({$variant['variant_title']})" : ''));

            if ($variant['is_bundle']) {
                $errors[] = "{$label} is itself a bundle and cannot be used as a component.";
            }
            if (! $variant['sku']) {
                $errors[] = "{$label} has no SKU. The warehouse needs component SKUs to fulfil bundle orders.";
            }
            if ($variant['product_status'] !== 'ACTIVE') {
                $warnings[] = "{$label} is {$variant['product_status']} in Shopify.";
            }
            if ($variant['inventory_tracked'] && (int) $variant['inventory_quantity'] < (int) $row['quantity']) {
                $warnings[] = "{$label} has only {$variant['inventory_quantity']} in stock (bundle needs {$row['quantity']}).";
            }

            $components[] = $variant + ['quantity' => (int) $row['quantity'], 'position' => $position];
        }

        $totalQty = array_sum(array_column($components, 'quantity'));
        if ($components && $totalQty < 2) {
            $errors[] = 'A bundle must contain at least 2 items in total (e.g. 2 products, or 1 product × 2).';
        }

        $compareAt = round(array_sum(array_map(fn ($c) => $c['price'] * $c['quantity'], $components)), 2);

        if ($components && round($price, 2) > $compareAt) {
            $errors[] = sprintf('Bundle price $%s is higher than the combined component price $%s.', number_format($price, 2), number_format($compareAt, 2));
        }

        $map = $this->mapPricing->evaluate($components, $price);
        if ($map['message'] && $map['blocking']) {
            $errors[] = $map['message'];
        } elseif ($map['message']) {
            $warnings[] = $map['message'];
        }

        return [
            'components' => $components,
            'compare_at' => $compareAt,
            'map'        => $map,
            'errors'     => $errors,
            'warnings'   => $warnings,
        ];
    }

    /**
     * Persist a prepared bundle (create or update). Does not talk to Shopify.
     */
    public function save(?Bundle $bundle, array $attributes, array $prepared): Bundle
    {
        return DB::transaction(function () use ($bundle, $attributes, $prepared) {
            $data = [
                'title'            => $attributes['title'],
                'description'      => $attributes['description'] ?? null,
                'price'            => $attributes['price'],
                'offer_on_product_page' => $attributes['offer_on_product_page'] ?? true,
                'offer_in_cart'    => $attributes['offer_in_cart'] ?? true,
                'offer_heading'    => $attributes['offer_heading'] ?? null,
                'offer_include_components' => $attributes['offer_include_components'] ?? false,
                'offer_extra_products' => $attributes['offer_extra_products'] ?? [],
                'compare_at_price' => $prepared['compare_at'],
                'map_floor'        => $prepared['map']['floor'],
                'sync_status'      => Bundle::SYNC_PENDING,
                'needs_review'     => false,
                'review_reason'    => null,
                'updated_by'       => auth()->id(),
            ];

            if ($bundle) {
                $bundle->update($data);
            } else {
                $bundle = Bundle::create($data + [
                    'shop_domain' => app(ShopifyBundleCredentials::class)->shopDomain(),
                    'status'      => Bundle::STATUS_DRAFT,
                    'created_by'  => auth()->id(),
                ]);
            }

            $this->replaceComponents($bundle, $prepared['components'], $prepared['map']['map_by_sku']);

            // Uploaded images (only when the form sent its image list)
            $images = $attributes['images_input'] ?? null;
            if ($images && ($images['present'] || $images['files'])) {
                $this->images->apply($bundle, $images['order'], $images['files']);
            }

            return $bundle->load(['components', 'images']);
        });
    }

    /**
     * Refresh the component snapshot (titles, SKUs, prices) from Shopify for an existing bundle.
     */
    public function refresh(Bundle $bundle): array
    {
        $rows = $bundle->components->map(fn ($c) => ['variant_id' => $c->shopify_variant_id, 'quantity' => $c->quantity])->all();
        $prepared = $this->prepare($rows, (float) $bundle->price);

        if (! $prepared['errors']) {
            DB::transaction(function () use ($bundle, $prepared) {
                $bundle->update([
                    'compare_at_price' => $prepared['compare_at'],
                    'map_floor'        => $prepared['map']['floor'],
                    'needs_review'     => false,
                    'review_reason'    => null,
                ]);
                $this->replaceComponents($bundle, $prepared['components'], $prepared['map']['map_by_sku']);
            });
            $bundle->load('components');
        }

        return $prepared;
    }

    protected function replaceComponents(Bundle $bundle, array $components, array $mapBySku): void
    {
        $bundle->components()->delete();

        foreach ($components as $c) {
            $bundle->components()->create([
                'shopify_product_id' => $c['product_id'],
                'shopify_variant_id' => $c['variant_id'],
                'product_title'      => $c['product_title'],
                'variant_title'      => $c['variant_title'],
                'sku'                => $c['sku'],
                'quantity'           => $c['quantity'],
                'unit_price'         => $c['price'],
                'map_price'          => $c['sku'] ? ($mapBySku[$c['sku']] ?? null) : null,
                'image_url'          => $c['image_url'],
                'product_handle'     => $c['product_handle'],
                'position'           => $c['position'],
            ]);
        }
    }
}
