@extends('admin.layouts.app')

@section('page-title', 'Campaign Delivery Detail')

@php
    $badgeFor = [
        'pending'   => 'warning',
        'generated' => 'info',
        'sent'      => 'success',
        'failed'    => 'danger',
        'cancelled' => 'secondary',
    ];
    $statuses = \App\Models\CampaignDelivery::statusLabels();
    $cancellable = ['pending', 'generated', 'failed'];
    $campaign = $delivery->campaignProduct?->marketingCampaign;
    $response = $delivery->campaignProduct?->response;
@endphp

@section('content')
<div class="container-fluid">

    @include('admin.components.page-header', [
        'title'    => 'Delivery #' . $delivery->id,
        'subtitle' => $delivery->campaignProduct?->product_title ?? 'Campaign delivery',
        'action'   => '<div style="display:inline-flex;gap:0.5rem;">'
            . '<a href="' . route('admin.campaign_deliveries.edit', $delivery) . '" class="btn btn-warning">Edit</a>'
            . '<a href="' . route('admin.campaign_deliveries.index') . '" class="btn btn-secondary">← Back</a>'
            . '</div>',
    ])

    <div style="display:grid;grid-template-columns:1fr 320px;gap:1.25rem;align-items:start;">

        {{-- Main column --}}
        <div style="display:flex;flex-direction:column;gap:1.25rem;">
            <div class="card p-4">
                <h3 style="margin:0 0 1rem;font-size:1rem;">Campaign Pairing</h3>
                @if($campaign)
                    <dl style="margin:0;display:flex;flex-direction:column;gap:0.5rem;font-size:0.875rem;">
                        <div>
                            <dt style="color:var(--text-muted);">Marketing Campaign</dt>
                            <dd style="margin:0.125rem 0 0;">
                                <a href="{{ route('admin.marketing-campaigns.show', $campaign) }}">{{ $campaign->name }}</a>
                                <span style="color:var(--text-muted);">({{ $campaign->campaign_key }})</span>
                            </dd>
                        </div>
                        <div>
                            <dt style="color:var(--text-muted);">Campaign Product</dt>
                            <dd style="margin:0.125rem 0 0;color:var(--text-primary);">{{ $delivery->campaignProduct->product_title ?? '—' }}</dd>
                        </div>
                    </dl>
                @else
                    <p style="color:var(--text-muted);margin:0;">No Campaign Product resolved — this delivery is an Attribution Failure. Use Edit to re-pair it.</p>
                @endif
            </div>

            <div class="card p-4">
                <h3 style="margin:0 0 1rem;font-size:1rem;">Campaign Response (read-only)</h3>
                @if($response)
                    <div style="white-space:pre-wrap;line-height:1.7;color:var(--text-primary);font-size:0.9375rem;">{{ $response->body }}</div>
                    <p style="color:var(--text-muted);font-size:0.75rem;margin-top:0.75rem;">
                        Source: {{ \App\Models\CampaignProductResponse::sourceLabels()[$response->source] ?? $response->source }}
                        — edit the response itself on the <a href="{{ $campaign ? route('admin.marketing-campaigns.show', $campaign) : '#' }}">Marketing Campaign page</a>.
                    </p>
                @else
                    <p style="color:var(--text-muted);margin:0;">No response generated for this pairing yet.</p>
                @endif
                @if($delivery->error_message)
                    <div class="alert alert-danger" style="margin-top:1rem;">{{ $delivery->error_message }}</div>
                @endif
            </div>
        </div>

        {{-- Side meta --}}
        <div class="card p-4">
            <h3 style="margin:0 0 1rem;font-size:1rem;">Details</h3>
            <dl style="margin:0;display:flex;flex-direction:column;gap:0.75rem;font-size:0.875rem;">
                <div>
                    <dt style="color:var(--text-muted);">Status</dt>
                    <dd style="margin:0.125rem 0 0;">
                        <x-admin.badge :type="$badgeFor[$delivery->status] ?? 'secondary'">
                            {{ $statuses[$delivery->status] ?? ucfirst($delivery->status) }}
                        </x-admin.badge>
                    </dd>
                </div>
                <div><dt style="color:var(--text-muted);">Customer</dt><dd style="margin:0.125rem 0 0;color:var(--text-primary);">{{ $delivery->customer_name ?: '—' }}</dd></div>
                <div><dt style="color:var(--text-muted);">Email</dt><dd style="margin:0.125rem 0 0;color:var(--text-primary);">{{ $delivery->customer_email }}</dd></div>
                <div><dt style="color:var(--text-muted);">Order</dt><dd style="margin:0.125rem 0 0;color:var(--text-primary);">#{{ $delivery->shopify_order_id }}</dd></div>
                <div><dt style="color:var(--text-muted);">Line Item</dt><dd style="margin:0.125rem 0 0;color:var(--text-primary);">#{{ $delivery->shopify_line_item_id }}</dd></div>
                <div><dt style="color:var(--text-muted);">Attempts</dt><dd style="margin:0.125rem 0 0;color:var(--text-primary);">{{ $delivery->attempts }}</dd></div>
                <div><dt style="color:var(--text-muted);">Scheduled</dt><dd style="margin:0.125rem 0 0;color:var(--text-primary);">{{ $delivery->scheduled_at?->format('d M Y H:i') ?? '—' }}</dd></div>
                <div><dt style="color:var(--text-muted);">Expedited</dt><dd style="margin:0.125rem 0 0;color:var(--text-primary);">{{ $delivery->expedited_at?->format('d M Y H:i') ?? '—' }}</dd></div>
                <div><dt style="color:var(--text-muted);">Sent</dt><dd style="margin:0.125rem 0 0;color:var(--text-primary);">{{ $delivery->sent_at?->format('d M Y H:i') ?? '—' }}</dd></div>
                <div><dt style="color:var(--text-muted);">Fulfilled</dt><dd style="margin:0.125rem 0 0;color:var(--text-primary);">{{ $delivery->fulfilled_at?->format('d M Y H:i') ?? '—' }}</dd></div>
                <div><dt style="color:var(--text-muted);">Created</dt><dd style="margin:0.125rem 0 0;color:var(--text-primary);">{{ $delivery->created_at?->format('d M Y H:i') }}</dd></div>
            </dl>

            <div style="display:flex;flex-direction:column;gap:0.5rem;margin-top:1.25rem;">
                <form action="{{ route('admin.campaign_deliveries.send', $delivery) }}" method="POST">
                    @csrf
                    <button type="submit" class="btn btn-success" style="width:100%;"
                            data-confirm="{{ $delivery->status === 'sent' ? 'Re-send this delivery?' : 'Send this delivery now?' }}">
                        {{ $delivery->status === 'sent' ? 'Resend Email' : 'Send Now' }}
                    </button>
                </form>
                @if(in_array($delivery->status, $cancellable, true))
                    <form action="{{ route('admin.campaign_deliveries.cancel', $delivery) }}" method="POST">
                        @csrf
                        <button type="submit" class="btn btn-secondary" style="width:100%;"
                                data-confirm="Cancel this delivery? No email will be sent.">Cancel Delivery</button>
                    </form>
                @endif
            </div>
        </div>

    </div>
</div>
@endsection
