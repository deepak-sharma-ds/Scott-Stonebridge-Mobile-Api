<?php

namespace App\Services\Bundles;

use App\Models\Bundles\BundleMapPrice;
use InvalidArgumentException;
use League\Csv\Reader;

/**
 * Imports MAP prices from a CSV with header columns: sku, map_price, optional notes.
 */
class MapPriceImporter
{
    public function __construct(protected MapPriceCheck $check)
    {
    }

    /**
     * @return array{imported: int, skipped: int[], rejected: array<int, string>}
     *         skipped = CSV line numbers with an empty SKU or a bad number;
     *         rejected = line => reason (SKU not in Shopify, or MAP above its Shopify price)
     */
    public function import(string $path): array
    {
        $csv = Reader::from($path, 'r');
        $csv->setHeaderOffset(0);

        $header = array_map(fn ($h) => strtolower(trim($h)), $csv->getHeader());
        if (! in_array('sku', $header, true) || ! in_array('map_price', $header, true)) {
            throw new InvalidArgumentException('The CSV must have "sku" and "map_price" header columns.');
        }

        $skipped = [];
        $rows = [];
        foreach ($csv->getRecords() as $offset => $record) {
            $row = array_change_key_case(array_combine(array_map('trim', array_keys($record)), $record));
            $sku = trim((string) ($row['sku'] ?? ''));
            $price = str_replace(['$', ',', '₹'], '', trim((string) ($row['map_price'] ?? '')));

            if ($sku === '' || ! is_numeric($price) || $price < 0) {
                $skipped[] = $offset + 1;
                continue;
            }
            $rows[] = ['line' => $offset + 1, 'sku' => $sku, 'price' => $price, 'notes' => $row['notes'] ?? null];
        }

        // Check every row against Shopify (one lookup for all SKUs); a repeated SKU is checked per row.
        $result = $this->check->checkRows(array_map(fn ($r) => ['sku' => $r['sku'], 'price' => $r['price']], $rows));

        $imported = 0;
        $rejected = [];
        foreach ($rows as $i => $row) {
            if ($error = $result['errors'][$i] ?? null) {
                $rejected[$row['line']] = "{$row['sku']}: {$error}";
                continue;
            }
            BundleMapPrice::updateOrCreate(
                ['sku' => $result['skus'][$i] ?? $row['sku']],
                ['map_price' => $row['price'], 'notes' => $row['notes'], 'updated_by' => auth()->id()]
            );
            $imported++;
        }

        return ['imported' => $imported, 'skipped' => $skipped, 'rejected' => $rejected];
    }

    public static function summary(array $result): string
    {
        $message = "{$result['imported']} MAP prices imported.";
        if ($result['skipped']) {
            $message .= ' Skipped invalid rows: ' . implode(', ', array_slice($result['skipped'], 0, 20)) . (count($result['skipped']) > 20 ? '…' : '');
        }
        if ($rejected = $result['rejected'] ?? []) {
            $lines = array_keys($rejected);
            $message .= ' Rejected (unknown SKU or MAP above the Shopify price), rows: ' . implode(', ', array_slice($lines, 0, 20)) . (count($lines) > 20 ? '…' : '') . '.';
        }

        return $message;
    }
}
