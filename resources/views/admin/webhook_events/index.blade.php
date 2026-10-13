@extends('admin.layouts.app')

@section('page-title', 'Webhook Events')

@php
    $badgeFor = [
        'received'   => 'warning',
        'processing' => 'info',
        'processed'  => 'success',
        'ignored'    => 'secondary',
        'failed'     => 'danger',
    ];
@endphp

@section('content')
<div class="container-fluid">

    @include('admin.components.page-header', [
        'title'    => 'Webhook Events',
        'subtitle' => 'Every Shopify order webhook received, with its processing outcome',
    ])

    <div class="card p-4" style="margin-bottom:1.25rem;">
        <form method="GET" action="{{ route('admin.webhook_events.index') }}"
              style="display:flex;flex-wrap:wrap;gap:1rem;align-items:flex-end;">
            <div style="flex:1;min-width:200px;">
                <label class="form-label">Order ID</label>
                <input type="text" name="search" value="{{ $filters['search'] ?? '' }}" class="form-control" placeholder="Shopify order ID">
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
            <div style="min-width:170px;">
                <label class="form-label">Endpoint</label>
                <select name="endpoint" class="form-control">
                    <option value="">All endpoints</option>
                    @foreach($endpoints as $endpoint)
                        <option value="{{ $endpoint }}" @selected(($filters['endpoint'] ?? '') === $endpoint)>{{ $endpoint }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Filter</button>
        </form>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Endpoint</th>
                        <th>Topic</th>
                        <th>Order</th>
                        <th class="text-center">Status</th>
                        <th>Reason / Error</th>
                        <th class="text-center">Attempts</th>
                        <th>Received</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($events as $event)
                        <tr>
                            <td>{{ $event->id }}</td>
                            <td>{{ $event->endpoint ?? '—' }}</td>
                            <td>{{ $event->topic }}</td>
                            <td>#{{ $event->shopify_order_id }}</td>
                            <td class="text-center">
                                <x-admin.badge :type="$badgeFor[$event->status] ?? 'secondary'">
                                    {{ $statuses[$event->status] ?? ucfirst($event->status) }}
                                </x-admin.badge>
                            </td>
                            <td style="font-size:0.8125rem;color:var(--text-secondary);max-width:320px;">
                                {{ $event->reason ?: \Illuminate\Support\Str::limit((string) $event->error, 120) ?: '—' }}
                            </td>
                            <td class="text-center">{{ $event->attempts }}</td>
                            <td style="font-size:0.8125rem;color:var(--text-secondary);">{{ $event->received_at?->format('d M Y H:i') ?? '—' }}</td>
                            <td class="text-end">
                                @if(! in_array($event->status, ['processed', 'ignored'], true))
                                    <form action="{{ route('admin.webhook_events.replay', $event) }}" method="POST" style="display:inline;">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-warning"
                                                data-confirm="Replay this webhook event?">Replay</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="text-center" style="padding:2rem;color:var(--text-muted);">No webhook events found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div style="margin-top:1rem;">{{ $events->links() }}</div>
</div>
@endsection
