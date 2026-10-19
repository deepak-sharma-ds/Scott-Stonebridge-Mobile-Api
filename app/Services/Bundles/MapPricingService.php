<?php

namespace App\Services\Bundles;

use App\Models\Bundles\BundleMapPrice;

/**
 * MAP (Minimum Advertised Price) compliance for bundle pricing.
 *
 * Rule: bundle price >= Σ (component MAP × quantity).
 * PKB maintains MAP values per SKU in Bundles → MAP Prices.
 */
class MapPricingService
{
    /**
     * @param  array  $components  each: ['sku' => ?string, 'quantity' => int]
     * @return array{floor: ?float, missing_skus: array, compliant: bool, blocking: bool, message: ?string, map_by_sku: array}
     */
    public function evaluate(array $components, float $price): array
    {
        $skus = collect($components)->pluck('sku')->filter()->unique()->values();
        $mapBySku = BundleMapPrice::whereIn('sku', $skus)->pluck('map_price', 'sku')->map(fn ($p) => (float) $p)->all();

        $floor = 0.0;
        $missing = [];
        foreach ($components as $component) {
            $sku = $component['sku'] ?? null;
            if ($sku && array_key_exists($sku, $mapBySku)) {
                $floor += $mapBySku[$sku] * (int) $component['quantity'];
            } else {
                $missing[] = $sku ?: '(no SKU)';
            }
        }

        $floor = $mapBySku ? round($floor, 2) : null;
        $belowFloor = $floor !== null && round($price, 2) < $floor;
        $missingBlocks = $missing && config('bundles.map.require_all_skus');

        $message = null;
        if ($belowFloor) {
            $message = sprintf('Bundle price $%s is below the combined MAP of $%s.', number_format($price, 2), number_format($floor, 2));
        } elseif ($missing) {
            $message = 'No MAP price on file for: ' . implode(', ', array_unique($missing)) . '.';
        }

        return [
            'floor'        => $floor,
            'missing_skus' => array_values(array_unique($missing)),
            'compliant'    => ! $belowFloor && ! $missing,
            'blocking'     => (config('bundles.map.enforce') && $belowFloor) || $missingBlocks,
            'message'      => $message,
            'map_by_sku'   => $mapBySku,
        ];
    }
}
