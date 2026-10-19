<?php

namespace App\Http\Controllers\Admin\Bundles;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bundles\BundleRequest;
use App\Models\Bundles\Bundle;
use App\Services\Bundles\BundleCatalogService;
use App\Services\Bundles\BundleService;
use App\Services\Bundles\BundleSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class BundleController extends Controller
{
    public function __construct(
        protected BundleService $bundles,
        protected BundleSyncService $sync,
    ) {
    }

    public function index(Request $request)
    {
        $bundles = Bundle::withCount('components')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->boolean('needs_review'), fn ($q) => $q->where('needs_review', true))
            ->when($request->filled('q'), fn ($q) => $q->where('title', 'like', '%' . $request->q . '%'))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $counts = Bundle::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $reviewCount = Bundle::where('needs_review', true)->count();

        return view('admin.bundles.index', compact('bundles', 'counts', 'reviewCount'));
    }

    public function create()
    {
        return view('admin.bundles.form', ['bundle' => new Bundle(), 'components' => []]);
    }

    public function store(BundleRequest $request)
    {
        return $this->persist($request, null);
    }

    public function show(Bundle $bundle)
    {
        $bundle->load(['components', 'creator']);
        $logs = $bundle->syncLogs()->with('user')->limit(25)->get();

        return view('admin.bundles.show', compact('bundle', 'logs'));
    }

    public function edit(Bundle $bundle)
    {
        $components = $bundle->components->map(fn ($c) => [
            'variant_id' => $c->shopify_variant_id,
            'quantity'   => $c->quantity,
            'label'      => $c->display_title,
            'sku'        => $c->sku,
            'price'      => (float) $c->unit_price,
            'image'      => $c->image_url,
        ])->all();

        return view('admin.bundles.form', compact('bundle', 'components'));
    }

    public function update(BundleRequest $request, Bundle $bundle)
    {
        return $this->persist($request, $bundle);
    }

    public function destroy(Request $request, Bundle $bundle)
    {
        try {
            $this->sync->deleteFromShopify($bundle);
        } catch (Throwable $e) {
            return $this->respond($request, false, 'Could not delete the bundle product in Shopify: ' . $this->message($e));
        }

        $bundle->delete();

        return $this->respond($request, true, 'Bundle deleted.', route('admin.bundles.index'));
    }

    public function activate(Request $request, Bundle $bundle)
    {
        try {
            $prepared = $this->bundles->refresh($bundle);
            if ($prepared['errors']) {
                return back()->with('error', 'Cannot activate: ' . implode(' ', $prepared['errors']));
            }

            $this->sync->changeStatus($bundle->fresh('components'), Bundle::STATUS_ACTIVE, fullPush: true);
        } catch (Throwable $e) {
            return back()->with('error', 'Activation failed: ' . $this->message($e));
        }

        return back()->with('success', 'Bundle is now live on the storefront.');
    }

    public function pause(Request $request, Bundle $bundle)
    {
        try {
            $this->sync->changeStatus($bundle, Bundle::STATUS_PAUSED);
        } catch (Throwable $e) {
            return back()->with('error', 'Pause failed: ' . $this->message($e));
        }

        return back()->with('success', 'Bundle paused. It is hidden from the storefront.');
    }

    /**
     * Refresh component data from Shopify and push everything again. Clears the review flag.
     */
    public function resync(Request $request, Bundle $bundle)
    {
        try {
            $prepared = $this->bundles->refresh($bundle);
            if ($prepared['errors']) {
                $bundle->update(['needs_review' => true, 'review_reason' => implode(' ', $prepared['errors'])]);

                return back()->with('error', 'Bundle needs changes before it can sync: ' . implode(' ', $prepared['errors']));
            }

            $this->sync->push($bundle->fresh('components'), 'resync');
        } catch (Throwable $e) {
            return back()->with('error', 'Sync failed: ' . $this->message($e));
        }

        $message = 'Bundle synced to Shopify.';
        if ($prepared['warnings']) {
            $message .= ' Warnings: ' . implode(' ', $prepared['warnings']);
        }

        return back()->with('success', $message);
    }

    public function markReviewed(Bundle $bundle)
    {
        $bundle->update(['needs_review' => false, 'review_reason' => null]);

        return back()->with('success', 'Review flag cleared.');
    }

    /**
     * Select2 AJAX source for the component picker.
     */
    public function searchVariants(Request $request, BundleCatalogService $catalog)
    {
        try {
            $variants = $catalog->searchVariants((string) $request->input('q', ''));
        } catch (Throwable $e) {
            return response()->json(['results' => [], 'error' => $this->message($e)], 502);
        }

        return response()->json(['results' => array_map(fn ($v) => [
            'id'        => $v['variant_id'],
            'text'      => $v['product_title'] . ($v['variant_title'] !== 'Default Title' ? " — {$v['variant_title']}" : ''),
            'sku'       => $v['sku'],
            'price'     => $v['price'],
            'image'     => $v['image_url'],
            'inventory' => $v['inventory_tracked'] ? $v['inventory_quantity'] : null,
            'status'    => $v['product_status'],
        ], $variants)]);
    }

    /**
     * Select2 AJAX source for the "also show this offer on" product picker.
     */
    public function searchProducts(Request $request, BundleCatalogService $catalog)
    {
        try {
            $products = $catalog->searchProducts((string) $request->input('q', ''));
        } catch (Throwable $e) {
            return response()->json(['results' => [], 'error' => $this->message($e)], 502);
        }

        return response()->json(['results' => array_map(fn ($p) => [
            'id'     => $p['id'],
            'text'   => $p['title'],
            'image'  => $p['image'],
            'status' => $p['status'],
        ], $products)]);
    }

    /**
     * Live validation for the bundle form (pricing, MAP, SKUs, stock).
     */
    public function preview(Request $request)
    {
        $data = $request->validate([
            'price'                   => ['nullable', 'numeric', 'min:0'],
            'components'              => ['required', 'array', 'min:1'],
            'components.*.variant_id' => ['required', 'string'],
            'components.*.quantity'   => ['required', 'integer', 'min:1'],
        ]);

        try {
            $prepared = $this->bundles->prepare(array_values($data['components']), (float) ($data['price'] ?? 0));
        } catch (Throwable $e) {
            return response()->json(['message' => $this->message($e)], 502);
        }

        return response()->json([
            'compare_at' => $prepared['compare_at'],
            'map_floor'  => $prepared['map']['floor'],
            'errors'     => $prepared['errors'],
            'warnings'   => $prepared['warnings'],
        ]);
    }

    protected function persist(BundleRequest $request, ?Bundle $bundle)
    {
        $price = (float) $request->validated('price');

        try {
            $prepared = $this->bundles->prepare($request->componentRows(), $price);
        } catch (Throwable $e) {
            return back()->withInput()->with('error', 'Could not load components from Shopify: ' . $this->message($e));
        }

        if ($prepared['errors']) {
            return back()->withInput()->withErrors(['components' => $prepared['errors']]);
        }

        try {
            $extraProducts = app(BundleCatalogService::class)->fetchProducts($request->offerProductIds());
        } catch (Throwable $e) {
            return back()->withInput()->with('error', 'Could not load offer products from Shopify: ' . $this->message($e));
        }

        $bundle = $this->bundles->save(
            $bundle,
            $request->validated() + $request->offerSettings() + [
                'offer_extra_products' => $extraProducts,
                'images_input'         => $request->imageInput(),
            ],
            $prepared
        );

        try {
            if ($request->input('action') === 'activate' && ! $bundle->isActive()) {
                $this->sync->changeStatus($bundle, Bundle::STATUS_ACTIVE, fullPush: true);
            } else {
                $this->sync->push($bundle, $bundle->wasRecentlyCreated ? 'create' : 'update');
            }
        } catch (Throwable $e) {
            return redirect()->route('admin.bundles.show', $bundle)
                ->with('error', 'Bundle saved, but the Shopify sync failed: ' . $this->message($e) . ' Use "Sync to Shopify" to retry.');
        }

        $message = $bundle->isActive() ? 'Bundle saved and live on the storefront.' : 'Bundle saved to Shopify as a draft.';
        if ($prepared['warnings']) {
            $message .= ' Warnings: ' . implode(' ', $prepared['warnings']);
        }

        return redirect()->route('admin.bundles.show', $bundle)->with('success', $message);
    }

    /**
     * Single-line error text (flash messages are rendered inside a JS string in the layout).
     */
    protected function message(Throwable $e): string
    {
        return Str::limit(Str::squish($e->getMessage()), 600);
    }

    protected function respond(Request $request, bool $ok, string $message, ?string $redirect = null)
    {
        if ($request->ajax()) {
            return response()->json(['success' => $ok, 'message' => $message], $ok ? 200 : 422);
        }

        $response = $redirect ? redirect($redirect) : back();

        return $response->with($ok ? 'success' : 'error', $message);
    }
}
