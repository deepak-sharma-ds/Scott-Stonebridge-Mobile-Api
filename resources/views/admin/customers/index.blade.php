@extends('admin.layouts.app')

@section('page-title', 'Customers')

@section('content')
<div class="container-fluid">

    {{-- Page Header --}}
    @include('admin.components.page-header', [
        'title'    => 'Customers',
        'subtitle' => 'Manage all customer accounts',
        'action'   => '<a href="' . route('admin.customers.export') . '" class="btn btn-success" target="_blank">
                          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                              <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                              <polyline points="7 10 12 15 17 10"></polyline>
                              <line x1="12" y1="15" x2="12" y2="3"></line>
                          </svg>
                          Export CSV
                      </a>',
    ])

    {{-- Search & Filter --}}
    <form method="GET" class="card p-4 mb-4">
        <div style="display:grid;grid-template-columns:1fr 3fr auto;gap:1rem;align-items:end;">

            <div>
                <label class="form-label">Filter</label>
                <select name="filter" class="form-select">
                    <option value=""           {{ $request->filter === null         ? 'selected' : '' }}>All Customers</option>
                    <option value="active"     {{ $request->filter === 'active'     ? 'selected' : '' }}>Active</option>
                    <option value="inactive"   {{ $request->filter === 'inactive'   ? 'selected' : '' }}>Inactive</option>
                    <option value="top_buyers" {{ $request->filter === 'top_buyers' ? 'selected' : '' }}>Top Buyers</option>
                </select>
            </div>

            <div>
                <label class="form-label">Search</label>
                <input type="text" name="search" value="{{ $request->search }}"
                       placeholder="Search name, email, phone…"
                       class="form-control">
            </div>

            <div>
                <button type="submit" class="btn btn-primary w-100" style="height:42px;">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="8"></circle>
                        <path d="m21 21-4.35-4.35"></path>
                    </svg>
                    Search
                </button>
            </div>

        </div>
    </form>

    {{-- Customers Table --}}
    <div class="card p-4">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th>Email</th>
                        <th>Total Spent</th>
                        <th>Orders</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($customers as $c)
                        <tr>
                            {{-- Name + Avatar --}}
                            <td>
                                <div style="display:flex;align-items:center;gap:0.75rem;">
                                    <div class="avatar avatar-md">
                                        {{ strtoupper(substr($c['firstName'], 0, 1)) }}{{ strtoupper(substr($c['lastName'], 0, 1)) }}
                                    </div>
                                    <div>
                                        <div style="font-weight:600;color:var(--text-primary);">
                                            {{ $c['firstName'] . ' ' . $c['lastName'] }}
                                        </div>
                                    </div>
                                </div>
                            </td>

                            {{-- Email --}}
                            <td style="color:var(--text-secondary);">
                                {{ $c['email'] }}
                            </td>

                            {{-- Total Spent --}}
                            <td>
                                <span style="font-weight:700;color:var(--color-success);">
                                    ${{ number_format($c['amountSpent']['amount'], 2) }}
                                </span>
                                <span style="font-size:0.6875rem;color:var(--text-muted);margin-left:0.25rem;">
                                    {{ $c['amountSpent']['currencyCode'] }}
                                </span>
                            </td>

                            {{-- Orders --}}
                            <td>
                                <x-admin.badge type="primary">
                                    {{ $c['numberOfOrders'] }}
                                    {{ $c['numberOfOrders'] == 1 ? 'Order' : 'Orders' }}
                                </x-admin.badge>
                            </td>

                            {{-- Actions --}}
                            <td style="text-align:right;">
                                <a href="{{ route('admin.customers.show', last(explode('/', $c['id']))) }}"
                                   class="btn btn-sm btn-info">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                                         stroke="currentColor" stroke-width="2">
                                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                        <circle cx="12" cy="12" r="3"></circle>
                                    </svg>
                                    View
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                @include('admin.components.empty-state', [
                                    'message' => 'No customers found.',
                                    'icon'    => '<svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                                      <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                                      <circle cx="12" cy="7" r="4"></circle>
                                                  </svg>',
                                ])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Load More --}}
        @if($hasNextPage)
            <div style="margin-top:1.25rem;">
                <a href="?after={{ $nextCursor }}" class="btn btn-light w-100"
                   style="border:2px dashed var(--card-border);background:transparent;color:var(--color-primary);font-weight:600;">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2">
                        <polyline points="6 9 12 15 18 9"></polyline>
                    </svg>
                    Load More Customers
                </a>
            </div>
        @endif
    </div>

</div>
@endsection
