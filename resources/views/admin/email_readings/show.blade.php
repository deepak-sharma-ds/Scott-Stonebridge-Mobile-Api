@extends('admin.layouts.app')

@section('page-title', 'Reading Detail')

@php
    $badgeFor = [
        'pending'   => 'warning',
        'generated' => 'info',
        'sent'      => 'success',
        'failed'    => 'danger',
        'cancelled' => 'secondary',
    ];
    $statuses = \App\Models\EmailReadingDelivery::statusLabels();

    // Resolve each schema slot to its captured answer (mirrors the
    // key → label → positional fallback used during generation).
    $schema = (array) ($delivery->product?->questions_schema ?? []);
    $answers = (array) $delivery->questions;
    $resolved = [];
    $pos = 0;
    foreach ($schema as $slot) {
        $pos++;
        $candidates = [
            \App\Models\EmailReadingDelivery::normalizeKey($slot['key'] ?? ''),
            \App\Models\EmailReadingDelivery::normalizeKey($slot['label'] ?? ''),
            'q' . $pos,
        ];
        $value = '';
        foreach ($candidates as $c) {
            if ($c !== '' && trim((string) ($answers[$c] ?? '')) !== '') {
                $value = (string) $answers[$c];
                break;
            }
        }
        $resolved[] = ['label' => $slot['label'] ?? ($slot['key'] ?? '—'), 'value' => $value];
    }
@endphp

@section('content')
<div class="container-fluid">

    @include('admin.components.page-header', [
        'title'    => 'Reading #' . $delivery->id,
        'subtitle' => $delivery->product?->name ?? 'Reading delivery',
        'action'   => '<div style="display:inline-flex;gap:0.5rem;">'
            . '<a href="' . route('admin.email_readings.edit', $delivery) . '" class="btn btn-warning">Edit</a>'
            . '<a href="' . route('admin.email_readings.index') . '" class="btn btn-secondary">← Back</a>'
            . '</div>',
    ])

    <div style="display:grid;grid-template-columns:1fr 320px;gap:1.25rem;align-items:start;">

        {{-- Main column --}}
        <div style="display:flex;flex-direction:column;gap:1.25rem;">
            <div class="card p-4">
                <h3 style="margin:0 0 1rem;font-size:1rem;">Customer Questions</h3>
                @if(empty($resolved))
                    <p style="color:var(--text-muted);margin:0;">No question schema on this product.</p>
                @else
                    <table class="table">
                        <tbody>
                            @foreach($resolved as $row)
                                <tr>
                                    <td style="width:40%;font-weight:600;color:var(--text-primary);vertical-align:top;">{{ $row['label'] }}</td>
                                    <td style="color:var(--text-secondary);white-space:pre-wrap;">{{ $row['value'] !== '' ? $row['value'] : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            <div class="card p-4">
                <h3 style="margin:0 0 1rem;font-size:1rem;">AI Reading Response</h3>
                @if($delivery->ai_response)
                    <div style="white-space:pre-wrap;line-height:1.7;color:var(--text-primary);font-size:0.9375rem;">{{ $delivery->ai_response }}</div>
                @else
                    <p style="color:var(--text-muted);margin:0;">Not generated yet.</p>
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
                <div><dt style="color:var(--text-muted);">Order</dt><dd style="margin:0.125rem 0 0;color:var(--text-primary);">{{ $delivery->shopify_order_id ? '#' . $delivery->shopify_order_id : 'Manual' }}</dd></div>
                <div><dt style="color:var(--text-muted);">Model</dt><dd style="margin:0.125rem 0 0;color:var(--text-primary);">{{ $delivery->model_used ?: '—' }}</dd></div>
                <div><dt style="color:var(--text-muted);">Tokens (prompt / completion)</dt><dd style="margin:0.125rem 0 0;color:var(--text-primary);">{{ $delivery->prompt_tokens ?? '—' }} / {{ $delivery->completion_tokens ?? '—' }}</dd></div>
                <div><dt style="color:var(--text-muted);">Attempts</dt><dd style="margin:0.125rem 0 0;color:var(--text-primary);">{{ $delivery->attempts }}</dd></div>
                <div><dt style="color:var(--text-muted);">Scheduled</dt><dd style="margin:0.125rem 0 0;color:var(--text-primary);">{{ $delivery->scheduled_at?->format('d M Y H:i') ?? '—' }}</dd></div>
                <div><dt style="color:var(--text-muted);">Expedited</dt><dd style="margin:0.125rem 0 0;color:var(--text-primary);">{{ $delivery->expedited_at?->format('d M Y H:i') ?? '—' }}</dd></div>
                <div><dt style="color:var(--text-muted);">Sent</dt><dd style="margin:0.125rem 0 0;color:var(--text-primary);">{{ $delivery->sent_at?->format('d M Y H:i') ?? '—' }}</dd></div>
                <div><dt style="color:var(--text-muted);">Fulfilled</dt><dd style="margin:0.125rem 0 0;color:var(--text-primary);">{{ $delivery->fulfilled_at?->format('d M Y H:i') ?? '—' }}</dd></div>
                <div><dt style="color:var(--text-muted);">Created</dt><dd style="margin:0.125rem 0 0;color:var(--text-primary);">{{ $delivery->created_at?->format('d M Y H:i') }}</dd></div>
            </dl>

            <div style="display:flex;flex-direction:column;gap:0.5rem;margin-top:1.25rem;">
                <form action="{{ route('admin.email_readings.send', $delivery) }}" method="POST">
                    @csrf
                    <button type="submit" class="btn btn-success" style="width:100%;"
                            data-confirm="{{ $delivery->status === 'sent' ? 'Re-send this reading?' : 'Send this reading now?' }}">
                        {{ $delivery->status === 'sent' ? 'Resend Email' : 'Send Now' }}
                    </button>
                </form>
                @if(! in_array($delivery->status, ['sent', 'cancelled']))
                    <form action="{{ route('admin.email_readings.cancel', $delivery) }}" method="POST">
                        @csrf
                        <button type="submit" class="btn btn-secondary" style="width:100%;"
                                data-confirm="Cancel this reading? No email will be sent.">Cancel Reading</button>
                    </form>
                @endif
            </div>
        </div>

    </div>
</div>
@endsection
