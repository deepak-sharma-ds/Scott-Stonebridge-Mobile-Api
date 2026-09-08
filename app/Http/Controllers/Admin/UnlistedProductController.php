<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\StoresHeaderImages;
use App\Http\Controllers\Controller;
use App\Http\Requests\UnlistedProductRequest;
use App\Models\UnlistedProduct;
use App\Services\CampaignEmail\UnlistedProductSyncService;
use Illuminate\Http\RedirectResponse;
use Throwable;

/**
 * Admin view of the local Unlisted-Products catalog synced from Shopify
 * (see UnlistedProductSyncService / SyncUnlistedProductsCommand). Products
 * themselves are never created/deleted here — only the per-product email
 * template defaults (header image / content / footer) are editable, the
 * same fields Campaign Email linking prefills from and saves back onto.
 */
class UnlistedProductController extends Controller
{
    use StoresHeaderImages;

    public function index()
    {
        $products = UnlistedProduct::orderBy('title')->paginate(15);

        return view('admin.unlisted_products.index', compact('products'));
    }

    public function edit(UnlistedProduct $unlistedProduct)
    {
        return view('admin.unlisted_products.edit', ['product' => $unlistedProduct]);
    }

    public function update(UnlistedProductRequest $request, UnlistedProduct $unlistedProduct): RedirectResponse
    {
        $data = $request->validated();

        if ($request->hasFile('header_image')) {
            $data['header_image'] = $this->storeHeaderImage($request->file('header_image'), 'campaign-header-images');
        }

        $unlistedProduct->update($data);

        return redirect()
            ->route('admin.unlisted-products.index')
            ->with('success', 'Product template updated.');
    }

    public function sync(UnlistedProductSyncService $sync): RedirectResponse
    {
        try {
            $result = $sync->sync();

            return redirect()
                ->route('admin.unlisted-products.index')
                ->with('success', "Synced {$result['synced']} unlisted products ({$result['deactivated']} deactivated).");
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'Sync failed: '.$e->getMessage());
        }
    }
}
