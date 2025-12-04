@extends('admin.layouts.app')

@section('content')
    <div class="container-fluid">

        <!-- Page Header -->
        <div class="page-header">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h1 style="font-size: 2rem; font-weight: 900; color: #ffffff; margin: 0;">Customers</h1>
                    <p style="color: rgba(255, 255, 255, 0.9); font-size: 1rem; margin-top: 0.5rem;">Manage all customer
                        accounts</p>
                </div>
                <a href="{{ route('admin.customers.export') }}" class="btn btn-success" target="_blank">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" style="display: inline-block; vertical-align: middle; margin-right: 0.5rem;">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-15"></path>
                        <polyline points="7 10 12 15 17 10"></polyline>
                        <line x1="12" y1="15" x2="12" y2="3"></line>
                    </svg>
                    Export CSV
                </a>
            </div>
        </div>

        <!-- Search & Filter Card -->
        <form method="GET" class="card p-4 mb-4" style="position: relative; overflow: visible;">
            <div class="row g-3">
                <div class="col-md-3">
                    <label
                        style="font-size: 0.875rem; font-weight: 600; color: #475569; margin-bottom: 0.5rem; display: block;">Filter</label>
                    <select name="filter" class="form-select">
                        <option value="" {{ $request->filter === null ? 'selected' : '' }}>All Customers</option>
                        <option value="active" {{ $request->filter === 'active' ? 'selected' : '' }}>Active</option>
                        <option value="inactive" {{ $request->filter === 'inactive' ? 'selected' : '' }}>Inactive</option>
                        <option value="top_buyers" {{ $request->filter === 'top_buyers' ? 'selected' : '' }}>Top Buyers
                        </option>
                    </select>
                </div>

                <div class="col-md-7">
                    <label
                        style="font-size: 0.875rem; font-weight: 600; color: #475569; margin-bottom: 0.5rem; display: block;">Search</label>
                    <input type="text" name="search" value="{{ $request->search }}"
                        placeholder="Search name, email, phone" class="form-control">
                </div>

                <div class="col-md-2">
                    <label
                        style="font-size: 0.875rem; font-weight: 600; color: transparent; margin-bottom: 0.5rem; display: block;">Action</label>
                    <button class="btn btn-primary w-100" style="height: 48px;">
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

        <!--Customers Table -->
        <div class="card p-4">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Total Spent</th>
                            <th>Orders</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                        @forelse ($customers as $c)
                            <tr>
                                <td>
                                    <div style="display: flex; align-items: center;">
                                        <div
                                            style="width: 40px; height: 40px; border-radius: 50%; background: var(--gradient-primary); display: flex; align-items: center; justify-content: center; color: white; font-weight: 700; margin-right: 1rem;">
                                            {{ strtoupper(substr($c['firstName'], 0, 1)) }}{{ strtoupper(substr($c['lastName'], 0, 1)) }}
                                        </div>
                                        <div>
                                            <div style="font-weight: 600; color: #1e293b;">
                                                {{ $c['firstName'] . ' ' . $c['lastName'] }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td style="color: #64748b;">{{ $c['email'] }}</td>
                                <td>
                                    <span
                                        style="font-weight: 700; color: #10b981;">${{ number_format($c['amountSpent']['amount'], 2) }}</span>
                                    <span
                                        style="font-size: 0.75rem; color: #94a3b8; margin-left: 0.25rem;">{{ $c['amountSpent']['currencyCode'] }}</span>
                                </td>
                                <td>
                                    <span
                                        style="background: rgba(102, 126, 234, 0.1); color: var(--color-primary); padding: 0.25rem 0.75rem; border-radius: 12px; font-weight: 600; font-size: 0.875rem;">
                                        {{ $c['numberOfOrders'] }} {{ $c['numberOfOrders'] == 1 ? 'Order' : 'Orders' }}
                                    </span>
                                </td>
                                <td style="text-align: right;">
                                    <a href="{{ route('admin.customers.show', last(explode('/', $c['id']))) }}"
                                        class="btn btn-sm btn-info">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                                            stroke="currentColor" stroke-width="2"
                                            style="display: inline-block; vertical-align: middle; margin-right: 0.25rem;">
                                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                            <circle cx="12" cy="12" r="3"></circle>
                                        </svg>
                                        View Details
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" style="text-align: center; padding: 3rem; color: #94a3b8;">
                                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none"
                                        stroke="currentColor" stroke-width="2" style="margin: 0 auto 1rem; opacity: 0.5;">
                                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                        <circle cx="12" cy="7" r="4"></circle>
                                    </svg>
                                    <p style="margin: 0;">No customers found</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>

                </table>
            </div>

            @if ($hasNextPage)
                <div style="margin-top: 1.5rem;">
                    <a href="?after={{ $nextCursor }}" class="btn btn-light w-100"
                        style="border: 2px dashed #e2e8f0; background: transparent; color: var(--color-primary); font-weight: 600;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" style="display: inline-block; vertical-align: middle; margin-right: 0.5rem;">
                            <polyline points="6 9 12 15 18 9"></polyline>
                        </svg>
                        Load More Customers
                    </a>
                </div>
            @endif

        </div>

    </div>
@endsection
