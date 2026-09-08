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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
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
        $body = $data['body'] ?? null;
        unset($data['source'], $data['body']);

        if ($request->hasFile('header_image')) {
            $data['header_image'] = $this->storeHeaderImage($request->file('header_image'), 'campaign-header-images');
        } elseif (filled($data['default_header_image'] ?? null)) {
            $data['header_image'] = $data['default_header_image'];
        }
        unset($data['default_header_image']);

        $campaignProduct = $marketingCampaign->campaignProducts()->create($data);

        $this->syncProductDefaults((int) $data['shopify_product_id'], $data);

        if ($source === CampaignProductResponse::SOURCE_MANUAL && filled($body)) {
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
     * Save the pre-generated response for this pairing — either by calling
     * OpenAI (source=ai) or persisting an admin-authored body directly
     * (source=manual). A prior response is replaced in place, never
     * duplicated.
     */
    public function respond(CampaignProductResponseRequest $request, MarketingCampaign $marketingCampaign, CampaignProduct $campaignProduct): RedirectResponse
    {
        abort_unless($campaignProduct->marketing_campaign_id === $marketingCampaign->id, 404);

        $data = $request->validated();

        $templateFields = [
            'email_content' => $data['email_content'] ?? null,
            'email_footer' => $data['email_footer'] ?? null,
        ];
        if ($request->hasFile('header_image')) {
            $templateFields['header_image'] = $this->storeHeaderImage($request->file('header_image'), 'campaign-header-images');
        }
        $campaignProduct->update($templateFields);
        $this->syncProductDefaults($campaignProduct->shopify_product_id, $templateFields);

        if ($data['source'] === CampaignProductResponse::SOURCE_MANUAL) {
            $campaignProduct->response()->updateOrCreate(
                ['campaign_product_id' => $campaignProduct->id],
                [
                    'source' => CampaignProductResponse::SOURCE_MANUAL,
                    'body' => $data['body'],
                    'model_used' => null,
                    'prompt_tokens' => null,
                    'completion_tokens' => null,
                    'generated_at' => null,
                ]
            );

            return redirect()
                ->route('admin.marketing-campaigns.show', $marketingCampaign)
                ->with('success', 'Manual response saved.');
        }

        $campaignProduct->update(['prompt_template' => $data['prompt_template'] ?? null]);

        try {
            $this->generation->generate($campaignProduct);

            return redirect()
                ->route('admin.marketing-campaigns.show', $marketingCampaign)
                ->with('success', 'Response generated. Review it before activating the campaign.');
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'Failed to generate response: '.$e->getMessage());
        }
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
