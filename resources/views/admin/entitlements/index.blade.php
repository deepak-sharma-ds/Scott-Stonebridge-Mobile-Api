@extends('admin.layouts.app')

@section('content')
    <div class="container-fluid">
        <div class="page-header">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h1 style="font-size: 2rem; font-weight: 900; color: #ffffff; margin: 0;">Customer Entitlements</h1>
                    <p style="color: rgba(255, 255, 255, 0.9); font-size: 1rem; margin-top: 0.5rem;">Manage purchased audio
                        access records</p>
                </div>
            </div>
        </div>

        <form method="GET" action="{{ route('admin.customer.entitlements.index') }}" class="card p-4 mb-4"
            style="position: relative; overflow: visible;">
            <div class="row g-3">
                <div class="col-md-10">
                    <label
                        style="font-size: 0.875rem; font-weight: 600; color: #475569; margin-bottom: 0.5rem; display: block;">Search</label>
                    <input type="text" name="search" value="{{ $request->search }}"
                        placeholder="Search by email, Shopify customer id, or package tag" class="form-control">
                </div>

                <div class="col-md-2">
                    <label
                        style="font-size: 0.875rem; font-weight: 600; color: transparent; margin-bottom: 0.5rem; display: block;">Action</label>
                    <button type="submit" class="btn btn-primary w-100" style="height: 48px;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" style="display: inline-block; vertical-align: middle; margin-right: 0.5rem;">
                            <circle cx="11" cy="11" r="8"></circle>
                            <path d="m21 21-4.35-4.35"></path>
                        </svg>
                        Search
                    </button>
                </div>
            </div>
        </form>

        <div class="card p-4">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Email</th>
                            <th>Shopify Customer ID</th>
                            <th>Package Tag</th>
                            {{-- <th>Download Allowed</th> --}}
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($entitlements as $entitlement)
                            <tr>
                                <td>{{ $entitlement->id }}</td>
                                <td>{{ $entitlement->email ?: 'N/A' }}</td>
                                <td>{{ $entitlement->shopify_customer_id }}</td>
                                <td>
                                    <span
                                        style="background: rgba(102, 126, 234, 0.1); color: var(--color-primary); padding: 0.25rem 0.75rem; border-radius: 12px; font-weight: 600; font-size: 0.875rem;">
                                        {{ $entitlement->package_tag }}
                                    </span>
                                </td>
                                {{-- <td>
                                    @if ($entitlement->is_download_allowed)
                                        <span
                                            style="background: rgba(16, 185, 129, 0.12); color: #059669; padding: 0.25rem 0.75rem; border-radius: 12px; font-weight: 600; font-size: 0.875rem;">
                                            Yes
                                        </span>
                                    @else
                                        <span
                                            style="background: rgba(239, 68, 68, 0.12); color: #dc2626; padding: 0.25rem 0.75rem; border-radius: 12px; font-weight: 600; font-size: 0.875rem;">
                                            No
                                        </span>
                                    @endif
                                </td> --}}
                                <td>
                                    @if ($entitlement->created_at)
                                        <div style="font-weight: 600; color: #1e293b;">
                                            {{ $entitlement->created_at->format('d M Y') }}
                                        </div>
                                        <div style="font-size: 0.875rem; color: #64748b;">
                                            {{ $entitlement->created_at->format('h:i A') }}
                                        </div>
                                    @else
                                        <span style="color: #94a3b8;">N/A</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 3rem; color: #94a3b8;">
                                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none"
                                        stroke="currentColor" stroke-width="2" style="margin: 0 auto 1rem; opacity: 0.5;">
                                        <path d="M3 7h18"></path>
                                        <path d="M6 3h12"></path>
                                        <path d="M6 12h12"></path>
                                        <path d="M8 17h8"></path>
                                    </svg>
                                    <p style="margin: 0;">No customer entitlements found</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($entitlements->hasPages())
                <div style="margin-top: 1.5rem;">
                    {!! $entitlements->links('pagination::bootstrap-5') !!}
                </div>
            @endif
        </div>
    </div>
@endsection
