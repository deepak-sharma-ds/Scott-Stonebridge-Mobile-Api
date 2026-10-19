<?php

namespace App\Http\Controllers\ShopifyApp;

use App\Http\Controllers\Controller;
use App\Models\Bundles\BundleMapPrice;
use App\Services\Bundles\MapPriceCheck;
use App\Services\Bundles\MapPriceImporter;
use App\Services\Bundles\ShopifyUnavailable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class MapPriceApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $prices = BundleMapPrice::query()
            ->when($request->filled('q'), fn ($q) => $q->where('sku', 'like', '%' . $request->q . '%'))
            ->orderBy('sku')
            ->paginate(50);

        return response()->json([
            'data' => $prices->getCollection()->map(fn ($m) => Presenter::mapPrice($m))->all(),
            'meta' => ['page' => $prices->currentPage(), 'last_page' => $prices->lastPage(), 'total' => $prices->total()],
            'settings' => [
                'enforce'          => (bool) config('bundles.map.enforce'),
                'require_all_skus' => (bool) config('bundles.map.require_all_skus'),
            ],
        ]);
    }

    public function store(Request $request, MapPriceCheck $check): JsonResponse
    {
        $data = $this->validated($request);
        [$blocked, $warning, $data] = $this->checkPrice($check, $data);
        if ($blocked) {
            return $blocked;
        }
        $price = BundleMapPrice::updateOrCreate(['sku' => $data['sku']], $data);

        return response()->json(['message' => trim("MAP price saved for {$price->sku}. {$warning}"), 'warning' => $warning, 'data' => Presenter::mapPrice($price)]);
    }

    public function update(Request $request, BundleMapPrice $mapPrice, MapPriceCheck $check): JsonResponse
    {
        $data = $this->validated($request, $mapPrice);
        [$blocked, $warning, $data] = $this->checkPrice($check, $data);
        if ($blocked) {
            return $blocked;
        }
        $mapPrice->update($data);

        return response()->json(['message' => trim("MAP price updated for {$mapPrice->sku}. {$warning}"), 'warning' => $warning, 'data' => Presenter::mapPrice($mapPrice)]);
    }

    /**
     * The SKU must exist in Shopify and the MAP can't be above its price; errors show under the field.
     *
     * @return array{0: ?JsonResponse, 1: string, 2: array}  2 = data with the SKU spelled as in Shopify
     */
    protected function checkPrice(MapPriceCheck $check, array $data): array
    {
        try {
            $result = $check->check([$data['sku'] => $data['map_price']]);
        } catch (ShopifyUnavailable $e) {
            return [response()->json(['message' => $e->getMessage()], 503), '', $data];
        }

        if ($error = $result['errors'][$data['sku']] ?? null) {
            $field = $result['fields'][$data['sku']] ?? 'map_price';

            return [response()->json([
                'message' => $field === 'sku' ? 'Check the SKU.' : 'Check the MAP price.',
                'errors'  => [$field => [$error]],
            ], 422), '', $data];
        }

        $warning = $result['warnings'][$data['sku']] ?? '';
        $data['sku'] = $result['skus'][$data['sku']] ?? $data['sku'];

        return [null, $warning, $data];
    }

    public function destroy(BundleMapPrice $mapPrice): JsonResponse
    {
        $mapPrice->delete();

        return response()->json(['message' => "MAP price for {$mapPrice->sku} removed."]);
    }

    public function import(Request $request, MapPriceImporter $importer): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']]);

        try {
            $result = $importer->import($request->file('file')->getRealPath());
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (ShopifyUnavailable $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        } catch (Throwable $e) {
            return response()->json(['message' => 'Import failed: ' . Str::squish($e->getMessage())], 422);
        }

        return response()->json(['message' => MapPriceImporter::summary($result)] + $result);
    }

    /**
     * Sample file for "Import CSV": the exact columns the importer reads, with example rows.
     */
    public function sample(): StreamedResponse
    {
        $rows = [
            ['sku', 'map_price', 'notes'],
            ['POMOZM1', '230.00', 'Example: Ozempic 1 mg'],
            ['GNUR', '3.50', 'Example: Nurofen For Children 100ml'],
            ['POMWEG25', '95.00', 'Notes are optional'],
        ];

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            foreach ($rows as $row) {
                fputcsv($out, $row, ',', '"', '');
            }
            fclose($out);
        }, 'map-prices-sample.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    protected function validated(Request $request, ?BundleMapPrice $existing = null): array
    {
        $request->merge(['sku' => trim((string) $request->input('sku'))]);

        $unique = Rule::unique('bundle_map_prices', 'sku');
        $rules = [
            'sku'       => ['required', 'string', 'max:255'],
            'map_price' => ['required', 'numeric', 'min:0', 'max:99999'],
            'notes'     => ['nullable', 'string', 'max:255'],
        ];
        if ($existing) {
            $rules['sku'][] = $unique->ignore($existing->id);
        }

        return $request->validate($rules, ['sku.unique' => 'Another MAP entry already uses this SKU.']);
    }
}
