<?php

namespace App\Http\Controllers\ShopifyApp;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShopifyApp\BundleApiRequest;
use App\Models\Bundles\Bundle;
use App\Services\Bundles\BundleCatalogService;
use App\Services\Bundles\BundleService;
use App\Services\Bundles\BundleSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * Bundle API for the embedded Shopify app. Same services as the Laravel admin.
 */
class BundleApiController extends Controller
{
    public function __construct(
        protected BundleService $bundles,
        protected BundleSyncService $sync,
        protected BundleCatalogService $catalog,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $bundles = Bundle::withCount('components')
            ->when($request->filled('status') && $request->status !== 'review', fn ($q) => $q->where('status', $request->status))
            ->when($request->status === 'review', fn ($q) => $q->where('needs_review', true))
            ->when($request->filled('q'), fn ($q) => $q->where('title', 'like', '%' . $request->q . '%'))
            ->latest()
            ->paginate(in_array((int) $request->per_page, [10, 25, 50], true) ? (int) $request->per_page : 25);

        return response()->json([
            'data'   => $bundles->getCollection()->map(fn ($b) => Presenter::bundle($b))->all(),
            'meta'   => [
                'page'      => $bundles->currentPage(),
                'last_page' => $bundles->lastPage(),
                'per_page'  => $bundles->perPage(),
                'total'     => $bundles->total(),
                'from'      => $bundles->firstItem(),
                'to'        => $bundles->lastItem(),
            ],
            'counts' => Bundle::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->all()
                + ['review' => Bundle::where('needs_review', true)->count()],
        ]);
    }

    public function show(Bundle $bundle): JsonResponse
    {
        $bundle->load('components');

        return response()->json([
            'data' => Presenter::bundle($bundle, true),
            'logs' => $bundle->syncLogs()->with('user')->limit(25)->get()->map(fn ($l) => Presenter::log($l))->all(),
        ]);
    }

    public function store(BundleApiRequest $request): JsonResponse
    {
        return $this->persist($request, null);
    }

    public function update(BundleApiRequest $request, Bundle $bundle): JsonResponse
    {
        return $this->persist($request, $bundle);
    }

    public function destroy(Bundle $bundle): JsonResponse
    {
        try {
            $this->sync->deleteFromShopify($bundle);
        } catch (Throwable $e) {
            return $this->fail('Could not delete the bundle product in Shopify: ' . $this->message($e), 502);
        }

        $bundle->delete();

        return response()->json(['message' => 'Bundle deleted.']);
    }

    public function activate(Bundle $bundle): JsonResponse
    {
        try {
            $prepared = $this->bundles->refresh($bundle);
            if ($prepared['errors']) {
                return $this->fail('Cannot activate this bundle.', 422, $prepared['errors']);
            }

            $this->sync->changeStatus($bundle->fresh('components'), Bundle::STATUS_ACTIVE, fullPush: true);
        } catch (Throwable $e) {
            return $this->fail('Activation failed: ' . $this->message($e), 502);
        }

        return $this->ok($bundle, 'Bundle is now live on the storefront.');
    }

    public function pause(Bundle $bundle): JsonResponse
    {
        try {
            $this->sync->changeStatus($bundle, Bundle::STATUS_PAUSED);
        } catch (Throwable $e) {
            return $this->fail('Pause failed: ' . $this->message($e), 502);
        }

        return $this->ok($bundle, 'Bundle paused. It is hidden from the storefront.');
    }

    public function resync(Bundle $bundle): JsonResponse
    {
        try {
            $prepared = $this->bundles->refresh($bundle);
            if ($prepared['errors']) {
                $bundle->update(['needs_review' => true, 'review_reason' => implode(' ', $prepared['errors'])]);

                return $this->fail('The bundle needs changes before it can sync.', 422, $prepared['errors']);
            }

            $this->sync->push($bundle->fresh('components'), 'resync');
        } catch (Throwable $e) {
            return $this->fail('Sync failed: ' . $this->message($e), 502);
        }

        return $this->ok($bundle, 'Bundle synced to Shopify.', $prepared['warnings']);
    }

    public function markReviewed(Bundle $bundle): JsonResponse
    {
        $bundle->update(['needs_review' => false, 'review_reason' => null]);

        return $this->ok($bundle, 'Review flag cleared.');
    }

    /**
     * Live validation: pricing, MAP, SKUs, stock.
     */
    public function preview(Request $request): JsonResponse
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
            return $this->fail($this->message($e), 502);
        }

        return response()->json([
            'compare_at' => $prepared['compare_at'],
            'map_floor'  => $prepared['map']['floor'],
            'errors'     => $prepared['errors'],
            'warnings'   => $prepared['warnings'],
        ]);
    }

    /**
     * Normalised variant details for ids chosen in the App Bridge resource picker.
     */
    public function lookupVariants(Request $request): JsonResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array', 'max:50'], 'ids.*' => ['string']])['ids'];

        try {
            $variants = $this->catalog->fetchVariants($ids);
        } catch (Throwable $e) {
            return $this->fail($this->message($e), 502);
        }

        return response()->json(['data' => array_values(array_map(fn ($v) => [
            'variant_id'    => $v['variant_id'],
            'product_id'    => $v['product_id'],
            'title'         => $v['product_title'] . ($v['variant_title'] && $v['variant_title'] !== 'Default Title' ? " — {$v['variant_title']}" : ''),
            'sku'           => $v['sku'],
            'price'         => $v['price'],
            'image'         => $v['image_url'],
            'inventory'     => $v['inventory_tracked'] ? $v['inventory_quantity'] : null,
            'status'        => $v['product_status'],
            'is_bundle'     => $v['is_bundle'],
        ], $variants))]);
    }

    public function lookupProducts(Request $request): JsonResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array', 'max:50'], 'ids.*' => ['string']])['ids'];

        try {
            return response()->json(['data' => $this->catalog->fetchProducts($ids)]);
        } catch (Throwable $e) {
            return $this->fail($this->message($e), 502);
        }
    }

    protected function persist(BundleApiRequest $request, ?Bundle $bundle): JsonResponse
    {
        try {
            $prepared = $this->bundles->prepare($request->componentRows(), (float) $request->validated('price'));
        } catch (Throwable $e) {
            return $this->fail('Could not load components from Shopify: ' . $this->message($e), 502);
        }

        if ($prepared['errors']) {
            return $this->fail('The bundle could not be saved.', 422, $prepared['errors'], $prepared['warnings']);
        }

        try {
            $extraProducts = $this->catalog->fetchProducts($request->offerProductIds());
        } catch (Throwable $e) {
            return $this->fail('Could not load offer products from Shopify: ' . $this->message($e), 502);
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
            return response()->json([
                'message'    => 'Bundle saved, but the Shopify sync failed: ' . $this->message($e) . ' Use "Sync to Shopify" to retry.',
                'sync_error' => true,
                'data'       => Presenter::bundle($bundle->fresh('components'), true),
            ], 200);
        }

        return $this->ok(
            $bundle,
            $bundle->isActive() ? 'Bundle saved and live on the storefront.' : 'Bundle saved to Shopify as a draft.',
            $prepared['warnings']
        );
    }

    protected function ok(Bundle $bundle, string $message, array $warnings = []): JsonResponse
    {
        return response()->json([
            'message'  => $message,
            'warnings' => $warnings,
            'data'     => Presenter::bundle($bundle->fresh('components'), true),
        ]);
    }

    protected function fail(string $message, int $status, array $errors = [], array $warnings = []): JsonResponse
    {
        return response()->json(['message' => $message, 'errors' => ['components' => $errors], 'warnings' => $warnings], $status);
    }

    protected function message(Throwable $e): string
    {
        return Str::limit(Str::squish($e->getMessage()), 600);
    }
}
