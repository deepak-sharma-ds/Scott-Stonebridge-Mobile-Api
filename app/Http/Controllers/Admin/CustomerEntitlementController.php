<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerEntitlementEmailsRequest;
use App\Models\CustomerEntitlement;
use App\Services\CustomerEntitlementService;
use Illuminate\Http\Request;

class CustomerEntitlementController extends Controller
{
    public function __construct(
        private CustomerEntitlementService $customerEntitlementService
    ) {}

    /**
     * Show customer entitlement listing page.
     */
    public function index(Request $request)
    {
        $request->validate([
            'search' => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $search = trim((string) $request->input('search'));
        $perPage = (int) $request->input('per_page', config('Reading.nodes_per_page', 20));

        $entitlements = $this->customerEntitlementService
            ->getPaginatedEntitlements($search, $perPage)
            ->appends($request->query());

        return view('admin.entitlements.index', [
            'entitlements' => $entitlements,
            'request' => $request,
        ]);
    }

    /**
     * Show the multi-email add/edit screen for an entitlement.
     */
    public function edit(CustomerEntitlement $customerEntitlement)
    {
        $package = $this->customerEntitlementService->findPackageByTag($customerEntitlement->package_tag);

        return view('admin.entitlements.edit', [
            'customerEntitlement' => $customerEntitlement,
            'package' => $package,
        ]);
    }

    /**
     * Add multiple emails to the selected entitlement package.
     */
    public function update(CustomerEntitlementEmailsRequest $request, CustomerEntitlement $customerEntitlement)
    {
        $summary = $this->customerEntitlementService->addEmails(
            $customerEntitlement,
            $request->emails()
        );

        $message = $this->customerEntitlementService->buildSyncMessage($summary);
        $successCount = count($summary['added']) + count($summary['skipped']);

        return redirect()
            ->route('admin.customer.entitlements.edit', $customerEntitlement)
            ->with($successCount > 0 ? 'success' : 'error', $message);
    }
}
