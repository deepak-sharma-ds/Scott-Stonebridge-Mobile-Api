<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\StoresHeaderImages;
use App\Http\Controllers\Controller;
use App\Http\Requests\EmailReadingProductRequest;
use App\Mail\EmailReadingMail;
use App\Models\EmailReadingDelivery;
use App\Models\EmailReadingProduct;
use App\Services\EmailReading\EmailReadingGenerationService;
use App\Services\EmailReading\ReadingCatalogSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;
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
            'formatting_contract' => ['nullable', 'string'],
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
            'formatting_contract' => $validated['formatting_contract'] ?? null,
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

    /**
     * Renders the real EmailReadingMail Mailable for this product using sample data
     * and optional unsaved form overrides, without persisting anything.
     */
    public function preview(Request $request, EmailReadingProduct $emailReadingProduct): Response
    {
        $previewDelivery = $this->buildPreviewDelivery($request, $emailReadingProduct);
        $mailable = new EmailReadingMail($previewDelivery);
        $html = $mailable->render();

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * Sends the real EmailReadingMail Mailable for this product to the logged-in admin's
     * email address with sample data / unsaved overrides. Creates no delivery row, touches
     * no customer, and triggers no queue or webhook lifecycle side effects.
     */
    public function sendTest(Request $request, EmailReadingProduct $emailReadingProduct): JsonResponse
    {
        $admin = $request->user();
        if (! $admin || ! $admin->email) {
            return response()->json([
                'success' => false,
                'message' => 'Logged-in user has no email address configured.',
            ], 422);
        }

        $previewDelivery = $this->buildPreviewDelivery($request, $emailReadingProduct);
        try {
            Mail::to($admin->email)->send(new EmailReadingMail($previewDelivery));
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to send test email: '.$e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Test email sent to '.$admin->email.'.',
        ]);
    }

    private function buildPreviewDelivery(Request $request, EmailReadingProduct $emailReadingProduct): EmailReadingDelivery
    {
        $previewProduct = clone $emailReadingProduct;

        if ($request->has('name')) {
            $previewProduct->name = (string) $request->input('name');
        }
        if ($request->has('email_subject')) {
            $previewProduct->email_subject = (string) $request->input('email_subject');
        }
        if ($request->has('email_content')) {
            $previewProduct->email_content = $request->input('email_content');
        }
        if ($request->has('email_footer')) {
            $previewProduct->email_footer = $request->input('email_footer');
        }
        // if ($request->has('email_view')) {
        //     $previewProduct->email_view = $request->input('email_view');
        // }

        $body = $request->input('body') ?? $request->input('ai_response');
        if (! $body) {
            $body = $this->defaultSampleReadingBody();
        }

        $customerName = (string) ($request->input('customer_name') ?: 'Jane Doe');

        $delivery = new EmailReadingDelivery([
            'customer_name' => $customerName,
            'customer_email' => $request->user()?->email ?: 'admin@example.com',
            'ai_response' => (string) $body,
            'questions' => $this->sampleQuestions($previewProduct),
        ]);
        $delivery->setRelation('product', $previewProduct);

        return $delivery;
    }

    /**
     * @return array<string,string>
     */
    private function sampleQuestions(EmailReadingProduct $product): array
    {
        $schema = (array) ($product->questions_schema ?? []);
        $questions = [];
        foreach ($schema as $slot) {
            $key = $slot['key'] ?? null;
            if ($key) {
                $questions[$key] = 'Sample answer for '.($slot['label'] ?? $key);
            }
        }

        return $questions;
    }

    private function defaultSampleReadingBody(): string
    {
        return <<<'HTML'
<h2>Your Current Path</h2>
<p>There is a strong, warm energy surrounding you right now, urging you to trust the steps you have already taken.</p>
<p><strong>A significant shift in perspective is arriving sooner than expected.</strong></p>
<h2>What Lies Ahead</h2>
<p>Keep your focus steady on what brings you genuine peace. New opportunities are preparing to open up for you.</p>
<h2>From My Heart to Yours</h2>
<p>Thank you for letting me look into this for you. Walk with quiet confidence, trusting in the timing of your life.</p>
HTML;
    }
}
