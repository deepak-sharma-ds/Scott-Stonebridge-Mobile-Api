<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\MarketingCampaignRequest;
use App\Models\MarketingCampaign;
use Illuminate\Http\RedirectResponse;
use Throwable;

class MarketingCampaignController extends Controller
{
    public function index()
    {
        $campaigns = MarketingCampaign::withCount('campaignProducts')
            ->orderBy('name')
            ->paginate(15);

        return view('admin.marketing_campaigns.index', compact('campaigns'));
    }

    public function create()
    {
        return view('admin.marketing_campaigns.create');
    }

    public function store(MarketingCampaignRequest $request): RedirectResponse
    {
        try {
            $campaign = MarketingCampaign::create($request->validated());

            return redirect()
                ->route('admin.marketing-campaigns.show', $campaign)
                ->with('success', 'Campaign created successfully.');
        } catch (Throwable $e) {
            report($e);

            return back()->withInput()->with('error', 'Failed to create campaign: '.$e->getMessage());
        }
    }

    public function show(MarketingCampaign $marketingCampaign)
    {
        $marketingCampaign->load(['campaignProducts.response']);

        return view('admin.marketing_campaigns.show', ['campaign' => $marketingCampaign]);
    }

    public function edit(MarketingCampaign $marketingCampaign)
    {
        return view('admin.marketing_campaigns.edit', ['campaign' => $marketingCampaign]);
    }

    public function update(MarketingCampaignRequest $request, MarketingCampaign $marketingCampaign): RedirectResponse
    {
        try {
            $marketingCampaign->update($request->validated());

            return redirect()
                ->route('admin.marketing-campaigns.show', $marketingCampaign)
                ->with('success', 'Campaign updated successfully.');
        } catch (Throwable $e) {
            report($e);

            return back()->withInput()->with('error', 'Failed to update campaign: '.$e->getMessage());
        }
    }

    public function destroy(MarketingCampaign $marketingCampaign): RedirectResponse
    {
        try {
            $marketingCampaign->delete();

            return redirect()
                ->route('admin.marketing-campaigns.index')
                ->with('success', 'Campaign deleted.');
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'Cannot delete: '.$e->getMessage());
        }
    }
}
