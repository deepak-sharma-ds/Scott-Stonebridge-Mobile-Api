<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\StoresHeaderImages;
use App\Http\Controllers\Controller;
use App\Http\Requests\CampaignProductRequest;
use App\Http\Requests\CampaignProductResponseRequest;
use App\Models\CampaignProduct;
use App\Models\CampaignProductResponse;
use App\Models\MarketingCampaign;
use App\Models\UnlistedProduct;
use App\Services\CampaignEmail\CampaignProductCatalogService;
use App\Services\CampaignEmail\CampaignResponseGenerationService;
use App\Support\SafeHtml;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

class CampaignProductController extends Controller
{
    use StoresHeaderImages;

    public function __construct(
        private readonly CampaignResponseGenerationService $generation,
        private readonly CampaignProductCatalogService $catalog
    ) {}

    /**
     * List unlisted Shopify products eligible to be linked to this campaign,
     * for the admin product picker.
     */
    public function available(MarketingCampaign $marketingCampaign): JsonResponse
    {
        return response()->json([
            'products' => $this->catalog->availableFor($marketingCampaign),
        ]);
    }

    public function store(CampaignProductRequest $request, MarketingCampaign $marketingCampaign): RedirectResponse
    {
        $data = $request->validated();
        $source = $data['source'] ?? CampaignProductResponse::SOURCE_MANUAL;
        $body = SafeHtml::clean($data['body'] ?? null);
        unset($data['source'], $data['body']);

        if ($request->hasFile('header_image')) {
            $data['header_image'] = $this->storeHeaderImage($request->file('header_image'), 'campaign-header-images');
        } elseif (filled($data['default_header_image'] ?? null)) {
            $data['header_image'] = $data['default_header_image'];
        }
        unset($data['default_header_image']);

        $campaignProduct = $marketingCampaign->campaignProducts()->create($data);

        $this->syncProductDefaults((int) $data['shopify_product_id'], $data);

        if ($source === CampaignProductResponse::SOURCE_MANUAL && ! SafeHtml::isBlank($body)) {
            $campaignProduct->response()->create([
                'source' => CampaignProductResponse::SOURCE_MANUAL,
                'body' => $body,
            ]);
        }

        return redirect()
            ->route('admin.marketing-campaigns.show', $marketingCampaign)
            ->with('success', 'Product linked to campaign.');
    }

    public function destroy(MarketingCampaign $marketingCampaign, CampaignProduct $campaignProduct): RedirectResponse
    {
        abort_unless($campaignProduct->marketing_campaign_id === $marketingCampaign->id, 404);

        $campaignProduct->delete();

        return redirect()
            ->route('admin.marketing-campaigns.show', $marketingCampaign)
            ->with('success', 'Product unlinked from campaign.');
    }

    /**
     * AJAX: Generate a proposed response for a campaign product pairing via OpenAI.
     * Produces the proposed body and validation result without persisting anything
     * to the database.
     */
    public function generate(Request $request, MarketingCampaign $marketingCampaign, CampaignProduct $campaignProduct): JsonResponse
    {
        abort_unless($campaignProduct->marketing_campaign_id === $marketingCampaign->id, 404);

        $validated = $request->validate([
            'prompt_template' => ['nullable', 'string'],
            'formatting_contract' => ['nullable', 'string'],
        ]);

        try {
            $result = $this->generation->preview(
                $campaignProduct,
                $validated['prompt_template'] ?? null,
                $validated['formatting_contract'] ?? null
            );

            return response()->json([
                'success' => true,
                'content' => $result['content'],
                'model' => $result['model'],
                'prompt_tokens' => $result['prompt_tokens'],
                'completion_tokens' => $result['completion_tokens'],
                'finish_reason' => $result['finish_reason'],
                'problems' => $result['problems'],
                'is_valid' => $result['is_valid'],
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate response: '.$e->getMessage(),
            ], 422);
        }
    }

    /**
     * AJAX: Validates a response body (from the admin editor) against the
     * product or shared formatting contract.
     */
    public function validateBody(Request $request, MarketingCampaign $marketingCampaign, CampaignProduct $campaignProduct): JsonResponse
    {
        abort_unless($campaignProduct->marketing_campaign_id === $marketingCampaign->id, 404);

        $validated = $request->validate([
            'body' => ['required', 'string'],
            'formatting_contract' => ['nullable', 'string'],
        ]);

        $validation = $this->generation->validateBody(
            $campaignProduct,
            $validated['body'],
            $validated['formatting_contract'] ?? null
        );

        return response()->json([
            'success' => true,
            'problems' => $validation->problems(),
            'is_valid' => $validation->isValid(),
        ]);
    }

    /**
     * Save/accept the response for this pairing — either accepting a validated AI
     * proposed body (source=ai) or persisting an admin-authored body directly
     * (source=manual). A prior response is replaced in place, never duplicated.
     */
    public function respond(CampaignProductResponseRequest $request, MarketingCampaign $marketingCampaign, CampaignProduct $campaignProduct): RedirectResponse|JsonResponse
    {
        abort_unless($campaignProduct->marketing_campaign_id === $marketingCampaign->id, 404);

        $data = $request->validated();
        $source = $data['source'];
        $body = SafeHtml::clean($data['body'] ?? null);

        if (SafeHtml::isBlank($body)) {
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'The response body cannot be empty.',
                ], 422);
            }

            return back()->withInput()->withErrors(['body' => 'The response body cannot be empty.']);
        }

        if ($source === CampaignProductResponse::SOURCE_AI) {
            $validation = $this->generation->validateBody(
                $campaignProduct,
                $body,
                $data['formatting_contract'] ?? null
            );

            if ($validation->isInvalid()) {
                $errorMessage = 'Cannot accept response: '.implode('; ', $validation->problems());

                if ($request->wantsJson()) {
                    return response()->json([
                        'success' => false,
                        'message' => $errorMessage,
                        'problems' => $validation->problems(),
                    ], 422);
                }

                return back()->withInput()->withErrors(['body' => $errorMessage]);
            }
        }

        $templateFields = [
            'email_content' => $data['email_content'] ?? null,
            'email_footer' => $data['email_footer'] ?? null,
        ];
        if ($request->hasFile('header_image')) {
            $templateFields['header_image'] = $this->storeHeaderImage($request->file('header_image'), 'campaign-header-images');
        }

        if ($source === CampaignProductResponse::SOURCE_AI) {
            $templateFields['prompt_template'] = $data['prompt_template'] ?? null;
            $templateFields['formatting_contract'] = $data['formatting_contract'] ?? null;
        }

        $campaignProduct->update($templateFields);
        $this->syncProductDefaults($campaignProduct->shopify_product_id, $templateFields);

        if ($source === CampaignProductResponse::SOURCE_MANUAL) {
            $campaignProduct->response()->updateOrCreate(
                ['campaign_product_id' => $campaignProduct->id],
                [
                    'source' => CampaignProductResponse::SOURCE_MANUAL,
                    'body' => $body,
                    'model_used' => null,
                    'prompt_tokens' => null,
                    'completion_tokens' => null,
                    'generated_at' => null,
                ]
            );

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Manual response saved.',
                ]);
            }

            return redirect()
                ->route('admin.marketing-campaigns.show', $marketingCampaign)
                ->with('success', 'Manual response saved.');
        }

        $campaignProduct->response()->updateOrCreate(
            ['campaign_product_id' => $campaignProduct->id],
            [
                'source' => CampaignProductResponse::SOURCE_AI,
                'body' => $body,
                'model_used' => $data['model_used'] ?? null,
                'prompt_tokens' => isset($data['prompt_tokens']) ? (int) $data['prompt_tokens'] : null,
                'completion_tokens' => isset($data['completion_tokens']) ? (int) $data['completion_tokens'] : null,
                'generated_at' => Carbon::now(),
            ]
        );

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Response accepted and saved.',
            ]);
        }

        return redirect()
            ->route('admin.marketing-campaigns.show', $marketingCampaign)
            ->with('success', 'Response accepted and saved.');
    }

    /**
     * Remember this product's header image / content / footer on its
     * UnlistedProduct catalog row, so the next time it's linked to a
     * different campaign the picker can prefill them. Only ever updates an
     * existing catalog row — never fabricates one for a product outside the
     * synced Unlisted catalog.
     *
     * @param  array<string, mixed>  $data
     */
    private function syncProductDefaults(int $shopifyProductId, array $data): void
    {
        $defaults = array_filter(
            array_intersect_key($data, array_flip(['header_image', 'email_content', 'email_footer'])),
            fn ($value) => filled($value)
        );

        if ($defaults !== []) {
            UnlistedProduct::where('shopify_product_id', $shopifyProductId)->update($defaults);
        }
    }
}
