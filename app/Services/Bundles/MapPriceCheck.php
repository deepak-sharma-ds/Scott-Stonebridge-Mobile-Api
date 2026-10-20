<?php

namespace App\Services\Bundles;

use Throwable;

/**
 * Checks MAP entries against Shopify before they are saved:
 *  - the SKU must exist in Shopify (a mistyped SKU would protect nothing);
 *  - MAP is the lowest price PKB allows, so it can't be above what the product sells for
 *    (e.g. 7000 for a ₹7 product would block that SKU in every bundle).
 * When Shopify can't be reached nothing is saved: the caller asks the admin to try again.
 */
class MapPriceCheck
{
    public function __construct(protected BundleCatalogService $catalog)
    {
    }

    /**
     * @param  array<string, float|string>  $mapBySku  sku => MAP price
     * @return array{errors: array<string, string>, warnings: array<string, string>, fields: array<string, string>}  keyed by SKU
     */
    public function check(array $mapBySku): array
    {
        $rows = [];
        foreach ($mapBySku as $sku => $map) {
            $rows[] = ['sku' => (string) $sku, 'price' => $map];
        }
        $result = $this->checkRows($rows);

        $bySku = fn ($messages) => collect($messages)->mapWithKeys(fn ($m, $i) => [$rows[$i]['sku'] => $m])->all();

        return ['errors' => $bySku($result['errors']), 'warnings' => $bySku($result['warnings']), 'fields' => $bySku($result['fields']), 'skus' => $bySku($result['skus'])];
    }

    /**
     * Each row on its own (a CSV can repeat a SKU with different amounts).
     *
     * @param  array<int, array{sku: string, price: float|string}>  $rows
     * @return array{errors: array<int, string>, warnings: array<int, string>, fields: array<int, string>}
     *         keyed by row index; fields = which input an error belongs to ('sku' or 'map_price');
     *         skus = the SKU exactly as Shopify spells it (saved instead of what was typed)
     */
    public function checkRows(array $rows): array
    {
        $errors = [];
        $warnings = [];
        $fields = [];
        $skus = [];
        if (! $rows) {
            return compact('errors', 'warnings', 'fields', 'skus');
        }

        try {
            $found = $this->catalog->variantsBySku(array_column($rows, 'sku'));
        } catch (Throwable $e) {
            throw new ShopifyUnavailable("Couldn't reach Shopify to check the SKU and price. Nothing was saved; please try again.", 0, $e);
        }

        foreach ($rows as $i => $row) {
            $variants = $found[mb_strtolower($row['sku'])] ?? [];
            if (! $variants) {
                $errors[$i] = "SKU {$row['sku']} doesn't exist in Shopify (or its product is archived). Check the spelling; it must match the product's SKU exactly.";
                $fields[$i] = 'sku';
                continue;
            }

            // Several variants can share a SKU: the cheapest one decides.
            $cheapest = collect($variants)->sortBy('price')->first();
            $skus[$i] = $cheapest['sku'];
            if ((float) $row['price'] > (float) $cheapest['price']) {
                $errors[$i] = sprintf(
                    'The MAP %s is higher than the price of %s in Shopify (%s). MAP must be at or below the selling price; check the amount.',
                    number_format((float) $row['price'], 2),
                    $cheapest['label'],
                    number_format((float) $cheapest['price'], 2)
                );
                $fields[$i] = 'map_price';
            }
        }

        return compact('errors', 'warnings', 'fields', 'skus');
    }
}
