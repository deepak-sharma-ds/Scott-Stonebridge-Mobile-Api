<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CampaignProductRequest;
use App\Http\Requests\CampaignProductResponseRequest;
use App\Models\CampaignProduct;
use App\Models\CampaignProductResponse;
use App\Models\MarketingCampaign;
use App\Services\CampaignEmail\CampaignProductCatalogService;
use App\Services\CampaignEmail\CampaignResponseGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Throwable;

class CampaignProductController extends Controller
{
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

        $campaignProduct = $marketingCampaign->campaignProducts()->create($data);

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
}
