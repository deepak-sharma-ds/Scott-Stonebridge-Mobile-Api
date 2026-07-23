@extends('admin.layouts.app')

@section('page-title', 'Campaign Deliveries')

@php
    $badgeFor = [
        'pending'   => 'warning',
        'generated' => 'info',
        'sent'      => 'success',
        'failed'    => 'danger',
        'cancelled' => 'secondary',
    ];
    $cancellable = ['pending', 'generated', 'failed'];
@endphp

@section('content')
<div class="container-fluid">

    @include('admin.components.page-header', [
        'title'    => 'Campaign Deliveries',
        'subtitle' => 'Manage per-order campaign email deliveries and their status',
        'action'   => '<a href="' . route('admin.campaign_deliveries.export', request()->query()) . '" class="btn btn-secondary">Export CSV</a>',
    ])

    {{-- Filters --}}
    <div class="card p-4" style="margin-bottom:1.25rem;">
        <form method="GET" action="{{ route('admin.campaign_deliveries.index') }}"
              style="display:flex;flex-wrap:wrap;gap:1rem;align-items:flex-end;">
            <div style="flex:1;min-width:220px;">
                <label class="form-label">Search</label>
                <input type="text" name="search" value="{{ $filters['search'] ?? '' }}"
                       class="form-control" placeholder="Email, name or order ID">
            </div>
            <div style="min-width:170px;">
                <label class="form-label">Status</label>
                <select name="status" class="form-control">
                    <option value="">All statuses</option>
                    @foreach($statuses as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div style="min-width:200px;">
                <label class="form-label">Campaign</label>
                <select name="campaign" class="form-control">
                    <option value="">All campaigns</option>
                    @foreach($campaigns as $campaign)
                        <option value="{{ $campaign->id }}" @selected((string) ($filters['campaign'] ?? '') === (string) $campaign->id)>
                            {{ $campaign->name }} ({{ $campaign->campaign_key }})
                        </option>
                    @endforeach
                </select>
            </div>
            <div style="min-width:150px;">
                <label class="form-label">From</label>
                <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="form-control">
            </div>
            <div style="min-width:150px;">
                <label class="form-label">To</label>
                <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="form-control">
            </div>
            <div style="display:flex;gap:0.5rem;">
                <button type="submit" class="btn btn-primary">Filter</button>
                <a href="{{ route('admin.campaign_deliveries.index') }}" class="btn btn-secondary">Reset</a>
            </div>
        </form>
    </div>

    <div class="card p-4">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th>Campaign</th>
                        <th>Product</th>
                        <th class="text-center">Status</th>
                        <th>Scheduled</th>
                        <th>Sent</th>
                        <th class="text-end" style="width:230px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($deliveries as $delivery)
                        <tr>
                            <td>
                                <div style="font-weight:600;color:var(--text-primary);">
                                    {{ $delivery->customer_name ?: '—' }}
                                </div>
                                <div style="font-size:0.75rem;color:var(--text-muted);">{{ $delivery->customer_email }}</div>
                                <div style="font-size:0.6875rem;color:var(--text-muted);">Order #{{ $delivery->shopify_order_id }}</div>
                            </td>
                            <td style="color:var(--text-secondary);">
                                {{ $delivery->campaignProduct?->marketingCampaign?->name ?? '—' }}
                            </td>
                            <td style="color:var(--text-secondary);">{{ $delivery->campaignProduct?->product_title ?? '—' }}</td>
                            <td class="text-center">
                                <x-admin.badge :type="$badgeFor[$delivery->status] ?? 'secondary'">
                                    {{ $statuses[$delivery->status] ?? ucfirst($delivery->status) }}
                                </x-admin.badge>
                            </td>
                            <td style="font-size:0.8125rem;color:var(--text-secondary);">
                                {{ $delivery->scheduled_at?->format('d M Y H:i') ?? '—' }}
                            </td>
                            <td style="font-size:0.8125rem;color:var(--text-secondary);">
                                {{ $delivery->sent_at?->format('d M Y H:i') ?? '—' }}
                            </td>
                            <td class="text-end">
                                <div style="display:inline-flex;gap:0.375rem;flex-wrap:wrap;justify-content:flex-end;">
                                    <a href="{{ route('admin.campaign_deliveries.show', $delivery) }}" class="btn btn-sm btn-secondary">View</a>
                                    <a href="{{ route('admin.campaign_deliveries.edit', $delivery) }}" class="btn btn-sm btn-warning">Edit</a>

                                    <form action="{{ route('admin.campaign_deliveries.send', $delivery) }}" method="POST" style="display:inline;">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-success"
                                                data-confirm="{{ $delivery->status === 'sent' ? 'Re-send this delivery to the customer?' : 'Send this delivery now (bypassing the schedule)?' }}">
                                            {{ $delivery->status === 'sent' ? 'Resend' : 'Send' }}
                                        </button>
                                    </form>

                                    @if(in_array($delivery->status, $cancellable, true))
                                        <form action="{{ route('admin.campaign_deliveries.cancel', $delivery) }}" method="POST" style="display:inline;">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-secondary"
                                                    data-confirm="Cancel this delivery? No email will be sent.">Cancel</button>
                                        </form>
                                    @endif

                                    @if($delivery->status !== 'sent')
                                        <form action="{{ route('admin.campaign_deliveries.destroy', $delivery) }}" method="POST" style="display:inline;">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-danger"
                                                    data-confirm="Delete this delivery permanently? This cannot be undone.">Delete</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">
                                @include('admin.components.empty-state', ['message' => 'No campaign deliveries found for these filters.'])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($deliveries->hasPages())
            <div style="margin-top:1.25rem;">
                {!! $deliveries->appends(request()->query())->links('pagination::bootstrap-5') !!}
            </div>
        @endif
    </div>

</div>
@endsection
