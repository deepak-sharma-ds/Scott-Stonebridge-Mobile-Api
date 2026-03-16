<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomerEntitlement;
use Illuminate\Http\Request;

class CustomerEntitlementController extends Controller
{
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

        $entitlements = CustomerEntitlement::query()
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($subQuery) use ($search) {
                    $subQuery->where('email', 'like', "%{$search}%")
                        ->orWhere('shopify_customer_id', 'like', "%{$search}%")
                        ->orWhere('package_tag', 'like', "%{$search}%");
                });
            })
            ->latest()
            ->paginate($perPage)
            ->appends($request->query());

        return view('admin.entitlements.index', [
            'entitlements' => $entitlements,
            'request' => $request,
        ]);
    }
}
