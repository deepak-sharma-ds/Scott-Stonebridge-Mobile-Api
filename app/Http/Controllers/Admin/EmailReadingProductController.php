<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\StoresHeaderImages;
use App\Http\Controllers\Controller;
use App\Http\Requests\EmailReadingProductRequest;
use App\Models\EmailReadingProduct;
use App\Services\EmailReading\EmailReadingGenerationService;
use App\Services\EmailReading\ReadingCatalogSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class EmailReadingProductController extends Controller
{
    use StoresHeaderImages;

    public function __construct(
        private readonly EmailReadingGenerationService $generation,
        private readonly ReadingCatalogSyncService $catalogSync
    ) {}

    public function index()
    {
        $products = EmailReadingProduct::withCount('deliveries')
            ->orderBy('name')
            ->paginate(15);

        return view('admin.email_reading_products.index', compact('products'));
    }

    public function create()
    {
        return view('admin.email_reading_products.create');
    }

    public function store(EmailReadingProductRequest $request): RedirectResponse
    {
        try {
            $data = $request->validated();

            if ($request->hasFile('header_image')) {
                $data['header_image'] = $this->storeHeaderImage($request->file('header_image'), 'reading-header-images');
            }

            EmailReadingProduct::create($data);

            return redirect()
                ->route('admin.email-reading-products.index')
                ->with('success', 'Reading product created successfully.');
        } catch (Throwable $e) {
            report($e);

            return back()->withInput()->with('error', 'Failed to create product: '.$e->getMessage());
        }
    }

    public function edit(EmailReadingProduct $emailReadingProduct)
    {
        return view('admin.email_reading_products.edit', ['product' => $emailReadingProduct]);
    }

    public function update(EmailReadingProductRequest $request, EmailReadingProduct $emailReadingProduct): RedirectResponse
    {
        try {
            $data = $request->validated();

            if ($request->hasFile('header_image')) {
                $data['header_image'] = $this->storeHeaderImage($request->file('header_image'), 'reading-header-images');
            }

            $emailReadingProduct->update($data);

            return redirect()
                ->route('admin.email-reading-products.index')
                ->with('success', 'Reading product updated successfully.');
        } catch (Throwable $e) {
            report($e);

            return back()->withInput()->with('error', 'Failed to update product: '.$e->getMessage());
        }
    }

    public function destroy(EmailReadingProduct $emailReadingProduct): RedirectResponse
    {
        try {
            $emailReadingProduct->delete();

            return redirect()
                ->route('admin.email-reading-products.index')
                ->with('success', 'Reading product deleted.');
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'Cannot delete: this product has linked readings.');
        }
    }

    public function toggleActive(EmailReadingProduct $emailReadingProduct): RedirectResponse
    {
        $emailReadingProduct->forceFill(['is_active' => ! $emailReadingProduct->is_active])->save();

        return back()->with('success', 'Product '.($emailReadingProduct->is_active ? 'activated' : 'deactivated').'.');
    }

    /**
     * The Reading Catalog Sync picker page itself (collections/products are
     * loaded client-side from the AJAX endpoints below).
     */
    public function syncForm()
    {
        return view('admin.email_reading_products.sync');
    }

    /**
     * AJAX: first dropdown of the Reading Catalog Sync picker — live list of
     * Shopify collections.
     */
    public function syncCollections(): JsonResponse
    {
        try {
            return response()->json(['collections' => $this->catalogSync->collections()]);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Failed to load collections: '.$e->getMessage()], 502);
        }
    }

    /**
     * AJAX: dependent dropdown of the Reading Catalog Sync picker — products
     * belonging to the selected collection(s).
     */
    public function syncProducts(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'collection_ids' => ['required', 'array', 'min:1'],
            'collection_ids.*' => ['integer'],
        ]);

        try {
            return response()->json([
                'products' => $this->catalogSync->productsForCollections($validated['collection_ids']),
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Failed to load products: '.$e->getMessage()], 502);
        }
    }

    /**
     * Reading Catalog Sync submit: re-fetch the checked product ids from
     * Shopify server-side and upsert them. Runs synchronously in the
     * request — no queue job, same pattern as UnlistedProductController::sync().
     */
    public function syncStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'shopify_product_ids' => ['required', 'array', 'min:1'],
            'shopify_product_ids.*' => ['integer'],
        ]);

        try {
            $result = $this->catalogSync->syncSelected($validated['shopify_product_ids']);

            return redirect()
                ->route('admin.email-reading-products.index')
                ->with('success', "Synced {$result['synced']} reading product(s) from Shopify.");
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'Sync failed: '.$e->getMessage());
        }
    }

    /**
     * AJAX: render the prompt with sample answers and return a preview reading.
     */
    public function test(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'prompt_template' => ['required', 'string'],
            'model' => ['nullable', 'string', 'max:255'],
            'max_tokens' => ['nullable', 'integer', 'min:1', 'max:8000'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'answers' => ['nullable', 'array'],
            'answers.*' => ['nullable', 'string'],
        ]);

        // Build a transient (unsaved) product so testForProduct can reuse the
        // exact render + system-prompt path without persisting anything.
        $product = new EmailReadingProduct([
            'prompt_template' => $validated['prompt_template'],
            'model' => $validated['model'] ?? null,
            'max_tokens' => $validated['max_tokens'] ?? null,
        ]);

        try {
            $result = $this->generation->testForProduct(
                $product,
                $validated['answers'] ?? [],
                $validated['customer_name'] ?? null
            );

            return response()->json([
                'success' => true,
                'content' => $result['content'],
                'model' => $result['model'],
                'completion_tokens' => $result['completion_tokens'],
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Test failed: '.$e->getMessage(),
            ], 422);
        }
    }
}
