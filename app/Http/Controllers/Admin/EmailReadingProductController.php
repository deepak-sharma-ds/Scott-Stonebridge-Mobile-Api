<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmailReadingProductRequest;
use App\Models\EmailReadingProduct;
use App\Services\EmailReading\EmailReadingGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class EmailReadingProductController extends Controller
{
    public function __construct(
        private readonly EmailReadingGenerationService $generation
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
            EmailReadingProduct::create($request->validated());

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
            $emailReadingProduct->update($request->validated());

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
