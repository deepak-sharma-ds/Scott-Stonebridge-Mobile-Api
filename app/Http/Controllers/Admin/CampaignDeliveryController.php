<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CampaignDeliveryUpdateRequest;
use App\Models\CampaignDelivery;
use App\Models\CampaignProduct;
use App\Models\MarketingCampaign;
use App\Services\CampaignEmail\CampaignDeliveryAdminService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class CampaignDeliveryController extends Controller
{
    public function __construct(
        private readonly CampaignDeliveryAdminService $service
    ) {}

    public function index(Request $request)
    {
        $filters = $request->only(['status', 'search', 'campaign', 'from', 'to']);
        $deliveries = $this->service->paginateDeliveries($filters);

        return view('admin.campaign_deliveries.index', [
            'deliveries' => $deliveries,
            'statuses' => CampaignDelivery::statusLabels(),
            'campaigns' => MarketingCampaign::orderBy('name')->get(),
            'filters' => $filters,
        ]);
    }

    public function show(CampaignDelivery $delivery)
    {
        $delivery->load('campaignProduct.marketingCampaign', 'campaignProduct.response');

        return view('admin.campaign_deliveries.show', compact('delivery'));
    }

    public function edit(CampaignDelivery $delivery)
    {
        $delivery->load('campaignProduct.marketingCampaign');

        return view('admin.campaign_deliveries.edit', [
            'delivery' => $delivery,
            'statuses' => CampaignDelivery::statusLabels(),
            'campaignProducts' => CampaignProduct::with('marketingCampaign')->orderBy('product_title')->get(),
        ]);
    }

    public function update(CampaignDeliveryUpdateRequest $request, CampaignDelivery $delivery): RedirectResponse
    {
        try {
            $this->service->updateDelivery($delivery, $request->validated());

            return redirect()
                ->route('admin.campaign_deliveries.show', $delivery)
                ->with('success', 'Delivery updated successfully.');
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return back()->withInput()->with('error', 'Failed to update delivery: '.$e->getMessage());
        }
    }

    public function send(CampaignDelivery $delivery): RedirectResponse
    {
        $resend = $delivery->status === CampaignDelivery::STATUS_SENT;

        try {
            $this->service->sendNow($delivery, $resend);

            return back()->with('success', $resend ? 'Delivery re-sent.' : 'Delivery queued to send now.');
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'Failed to send delivery: '.$e->getMessage());
        }
    }

    public function cancel(CampaignDelivery $delivery): RedirectResponse
    {
        try {
            if (! $this->service->cancel($delivery)) {
                return back()->with('error', 'Only pending or failed deliveries can be cancelled.');
            }

            return back()->with('success', 'Delivery cancelled. No email will be sent.');
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function reactivate(CampaignDelivery $delivery): RedirectResponse
    {
        try {
            if ($this->service->reactivate($delivery) === null) {
                return back()->with('error', 'Only cancelled or failed deliveries can be reactivated.');
            }

            return back()->with('success', 'Delivery reactivated. It will send at its scheduled time.');
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function markSent(Request $request, CampaignDelivery $delivery): RedirectResponse
    {
        try {
            $this->service->markSentManually($delivery, ! $request->boolean('skip_fulfilment'));

            return back()->with('success', 'Delivery marked as sent manually.');
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function retryFulfillment(CampaignDelivery $delivery): RedirectResponse
    {
        try {
            $this->service->retryFulfillment($delivery);

            return back()->with('success', 'Fulfilment re-queued.');
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function destroy(CampaignDelivery $delivery): RedirectResponse
    {
        if (! $this->service->delete($delivery)) {
            return back()->with('error', 'Cannot delete a delivery that is being sent or has already been sent.');
        }

        return redirect()
            ->route('admin.campaign_deliveries.index')
            ->with('success', 'Delivery deleted.');
    }

    public function export(Request $request): StreamedResponse
    {
        return $this->service->exportCsv($request->only(['status', 'search', 'campaign', 'from', 'to']));
    }
}
