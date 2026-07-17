<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CampaignProductRequest;
use App\Models\CampaignProduct;
use App\Models\MarketingCampaign;
use App\Services\CampaignEmail\CampaignResponseGenerationService;
use Illuminate\Http\RedirectResponse;
use Throwable;

class CampaignProductController extends Controller
{
    public function __construct(
        private readonly CampaignResponseGenerationService $generation
    ) {}

    public function store(CampaignProductRequest $request, MarketingCampaign $marketingCampaign): RedirectResponse
    {
        $marketingCampaign->campaignProducts()->create($request->validated());

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
     * Generate (or regenerate) the pre-generated response for this pairing.
     * A prior response is replaced in place, never duplicated.
     */
    public function generate(MarketingCampaign $marketingCampaign, CampaignProduct $campaignProduct): RedirectResponse
    {
        abort_unless($campaignProduct->marketing_campaign_id === $marketingCampaign->id, 404);

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
