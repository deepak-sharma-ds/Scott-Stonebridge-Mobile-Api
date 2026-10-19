<?php

namespace App\Console\Commands;

use App\Models\Bundles\Bundle;
use App\Models\Funnels\Funnel;
use App\Models\Funnels\FunnelItem;
use App\Services\Bundles\BundleSyncService;
use App\Services\Funnels\FunnelDefaults;
use App\Services\Funnels\FunnelService;
use App\Services\Funnels\FunnelSyncService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Converts the old bundle products into bundle funnels (individual products + checkout discount).
 *
 *   php artisan funnels:import-bundles              # create funnels, keep the old bundle products
 *   php artisan funnels:import-bundles --delete-old # also delete the old bundle products from Shopify
 */
class ImportBundlesToFunnels extends Command
{
    protected $signature = 'funnels:import-bundles
        {--delete-old : Delete the old bundle products from Shopify after importing}
        {--dry-run : Show what would be created without saving}';

    protected $description = 'Convert the old bundle products into bundle funnels';

    public function handle(FunnelService $funnels, FunnelSyncService $sync, BundleSyncService $bundleSync): int
    {
        $bundles = Bundle::with('components')->get();
        if ($bundles->isEmpty()) {
            $this->info('No bundles to import.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($bundles as $bundle) {
            $existing = Funnel::withTrashed()->where('bundle_id', $bundle->id)->first();
            if ($existing) {
                $rows[] = [$bundle->id, $bundle->title, "already imported (funnel #{$existing->id})", ''];
                $this->deleteOld($bundle, $bundleSync, $rows);
                continue;
            }

            $data = $this->funnelData($bundle);

            if ($this->option('dry-run')) {
                $rows[] = [$bundle->id, $bundle->title, 'would create (' . count($data['items']) . ' products, ' . $data['items'][0]['discount_value'] . '%)', $data['status']];
                continue;
            }

            try {
                $prepared = $funnels->prepare($data);
                if ($prepared['errors']) {
                    // Keep it, but inactive, so PKB can fix it in the app.
                    $data['status'] = Funnel::INACTIVE;
                }
                $funnel = $funnels->save(null, $data, $prepared);
                $funnel->forceFill([
                    'bundle_id'     => $bundle->id,
                    'needs_review'  => (bool) $prepared['errors'],
                    'review_reason' => $prepared['errors'] ? 'Imported from a bundle: ' . implode(' ', $prepared['errors']) : null,
                ])->saveQuietly();

                $sync->sync($funnel->fresh('items'), 'import');
                $rows[] = [$bundle->id, $bundle->title, "funnel #{$funnel->id}" . ($prepared['errors'] ? ' (needs review)' : ''), $funnel->status];
            } catch (Throwable $e) {
                $rows[] = [$bundle->id, $bundle->title, 'FAILED: ' . $e->getMessage(), ''];
                continue;
            }

            $this->deleteOld($bundle, $bundleSync, $rows);
        }

        $this->table(['Bundle', 'Title', 'Result', 'Status'], $rows);

        return self::SUCCESS;
    }

    /**
     * Map an old bundle to funnel input.
     */
    public function funnelData(Bundle $bundle): array
    {
        $total = (float) $bundle->compare_at_price;
        $pct = $total > 0 ? round((1 - (float) $bundle->price / $total) * 100, 2) : 0;

        $triggerProducts = collect($bundle->offer_extra_products ?? [])
            ->map(fn ($p) => ['id' => $p['id'], 'title' => $p['title'] ?? '']);
        if ($bundle->offer_include_components || $triggerProducts->isEmpty()) {
            $triggerProducts = $triggerProducts->merge($bundle->components->map(fn ($c) => [
                'id' => $c->shopify_product_id, 'title' => $c->product_title, 'image' => $c->image_url, 'handle' => $c->product_handle,
            ]));
        }

        $translations = FunnelDefaults::translations();
        if ($bundle->offer_heading) {
            $translations['title'] = $bundle->offer_heading;
        }

        return [
            'name'   => $bundle->title,
            'status' => $bundle->isActive() ? Funnel::ACTIVE : Funnel::INACTIVE,
            'items'  => $bundle->components->map(fn ($c) => [
                'variant_id'     => $c->shopify_variant_id,
                'quantity'       => $c->quantity,
                'discount_type'  => $pct > 0 ? FunnelItem::PERCENTAGE : FunnelItem::NONE,
                'discount_value' => $pct,
            ])->values()->all(),
            'trigger' => ['products' => $triggerProducts->unique('id')->values()->all()],
            'placements' => [
                'product_page' => ['enabled' => (bool) ($bundle->offer_on_product_page ?? true), 'style' => 'inpage'],
                'cart_page'    => ['enabled' => (bool) ($bundle->offer_in_cart ?? false)],
            ],
            'translations' => $translations,
        ];
    }

    protected function deleteOld(Bundle $bundle, BundleSyncService $bundleSync, array &$rows): void
    {
        if (! $this->option('delete-old') || $this->option('dry-run')) {
            return;
        }

        try {
            $bundleSync->deleteFromShopify($bundle);
            $bundle->delete();
            $rows[] = [$bundle->id, $bundle->title, 'old bundle product deleted from Shopify', ''];
        } catch (Throwable $e) {
            $rows[] = [$bundle->id, $bundle->title, 'could not delete old bundle product: ' . $e->getMessage(), ''];
        }
    }
}
