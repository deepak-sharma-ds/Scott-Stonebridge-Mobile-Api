<?php

namespace App\Http\Controllers\Admin\Bundles;

use App\Http\Controllers\Controller;
use App\Models\Bundles\BundleMapPrice;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use App\Services\Bundles\MapPriceImporter;
use InvalidArgumentException;
use Throwable;

/**
 * PKB-provided MAP (Minimum Advertised Price) per SKU, used to validate bundle pricing.
 */
class BundleMapPriceController extends Controller
{
    public function index(Request $request)
    {
        $prices = BundleMapPrice::query()
            ->when($request->filled('q'), fn ($q) => $q->where('sku', 'like', '%' . $request->q . '%'))
            ->orderBy('sku')
            ->paginate(50)
            ->withQueryString();

        return view('admin.bundles.map-prices', compact('prices'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'sku'       => ['required', 'string', 'max:255'],
            'map_price' => ['required', 'numeric', 'min:0', 'max:99999'],
            'notes'     => ['nullable', 'string', 'max:255'],
        ]);

        BundleMapPrice::updateOrCreate(
            ['sku' => trim($data['sku'])],
            ['map_price' => $data['map_price'], 'notes' => $data['notes'] ?? null, 'updated_by' => auth()->id()]
        );

        return back()->with('success', "MAP price saved for {$data['sku']}.");
    }

    public function update(Request $request, BundleMapPrice $mapPrice)
    {
        $request->merge(['sku' => trim((string) $request->input('sku'))]);

        $data = $request->validate([
            'sku'       => ['required', 'string', 'max:255', Rule::unique('bundle_map_prices', 'sku')->ignore($mapPrice->id)],
            'map_price' => ['required', 'numeric', 'min:0', 'max:99999'],
            'notes'     => ['nullable', 'string', 'max:255'],
        ], [
            'sku.unique' => 'Another MAP entry already uses this SKU.',
        ]);

        $mapPrice->update($data + ['updated_by' => auth()->id()]);

        return redirect()->route('admin.bundles.map-prices.index', $request->only('q', 'page'))
            ->with('success', "MAP price updated for {$mapPrice->sku}.");
    }

    public function destroy(BundleMapPrice $mapPrice)
    {
        $mapPrice->delete();

        return back()->with('success', "MAP price for {$mapPrice->sku} removed.");
    }

    /**
     * CSV import. Required header columns: sku, map_price. Optional: notes.
     */
    public function import(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']]);

        try {
            $message = MapPriceImporter::summary(app(MapPriceImporter::class)->import($request->file('file')->getRealPath()));
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            return back()->with('error', 'Import failed: ' . Str::squish($e->getMessage()));
        }

        return back()->with('success', $message);
    }
}
