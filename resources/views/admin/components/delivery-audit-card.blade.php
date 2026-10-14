{{--
    Delivery & Legal Audit Trail card.
    Props: $delivery — an EmailReadingDelivery or CampaignDelivery.
    Delivery Proof (SMTP acceptance, Message-ID, Send History) is shown apart
    from Open Tracking (engagement), because the two prove different things.
--}}
@php
    $engagement = $delivery->engagementStatus();
    $history = (array) $delivery->send_history;
    $format = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->format('d M Y H:i:s') : '—';
    $label = 'color:var(--text-muted);';
    $value = 'margin:0.125rem 0 0;color:var(--text-primary);';
@endphp

<div class="card p-4">
    <h3 style="margin:0 0 1rem;font-size:1rem;">Delivery &amp; Legal Audit Trail</h3>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1.25rem;">
        <div>
            <h4 style="margin:0 0 0.75rem;font-size:0.8125rem;text-transform:uppercase;color:var(--text-muted);">Delivery proof</h4>
            <dl style="margin:0;display:flex;flex-direction:column;gap:0.75rem;font-size:0.875rem;">
                <div>
                    <dt style="{{ $label }}">Google SMTP status</dt>
                    <dd style="margin:0.125rem 0 0;">
                        @if($delivery->hasSmtpAcceptance())
                            <x-admin.badge type="success">Delivered (250 OK)</x-admin.badge>
                        @else
                            <x-admin.badge type="secondary">Pending / Sent</x-admin.badge>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt style="{{ $label }}">Accepted by mail server at</dt>
                    <dd style="{{ $value }}">{{ $format($delivery->dispatched_at) }}</dd>
                </div>
                <div>
                    <dt style="{{ $label }}">RFC Message-ID</dt>
                    <dd style="{{ $value }}"><code style="word-break:break-all;">{{ $delivery->message_id ?: '—' }}</code></dd>
                </div>
                <div>
                    <dt style="{{ $label }}">SMTP response</dt>
                    <dd style="{{ $value }}"><code style="word-break:break-all;white-space:pre-wrap;">{{ $delivery->smtp_response ?: '—' }}</code></dd>
                </div>
            </dl>
        </div>

        <div>
            <h4 style="margin:0 0 0.75rem;font-size:0.8125rem;text-transform:uppercase;color:var(--text-muted);">Customer engagement</h4>
            <dl style="margin:0;display:flex;flex-direction:column;gap:0.75rem;font-size:0.875rem;">
                <div>
                    <dt style="{{ $label }}">Open status</dt>
                    <dd style="margin:0.125rem 0 0;">
                        @if($engagement === 'direct')
                            <x-admin.badge type="success">Opened (Direct)</x-admin.badge>
                        @elseif($engagement === 'proxy')
                            <x-admin.badge type="info">Opened (Proxy)</x-admin.badge>
                        @else
                            <x-admin.badge type="secondary">Unopened</x-admin.badge>
                        @endif
                    </dd>
                </div>
                @if($engagement === 'unopened')
                    <div><dd style="{{ $value }}">Not opened yet</dd></div>
                @else
                    @if($engagement === 'proxy')
                        <div style="color:var(--text-muted);">
                            The email images were loaded through a privacy proxy such as Apple Mail Privacy Protection or Google Image Proxy, so this may be an automatic pre-fetch rather than the customer reading it.
                        </div>
                    @endif
                    <div><dt style="{{ $label }}">First opened</dt><dd style="{{ $value }}">{{ $format($delivery->opened_at) }}</dd></div>
                    <div><dt style="{{ $label }}">Last opened</dt><dd style="{{ $value }}">{{ $format($delivery->last_opened_at) }}</dd></div>
                    <div><dt style="{{ $label }}">Total opens</dt><dd style="{{ $value }}">{{ $delivery->open_count }}</dd></div>
                    <div><dt style="{{ $label }}">IP address</dt><dd style="{{ $value }}">{{ $delivery->opened_ip ?: '—' }}</dd></div>
                @endif
            </dl>
        </div>
    </div>

    @if(! empty($history))
        <details style="margin-top:1.25rem;">
            <summary style="cursor:pointer;font-weight:600;font-size:0.875rem;">Send History ({{ count($history) }} earlier {{ \Illuminate\Support\Str::plural('send', count($history)) }})</summary>
            <ol style="margin:0.75rem 0 0;padding-left:1.25rem;display:flex;flex-direction:column;gap:0.75rem;font-size:0.8125rem;">
                @foreach($history as $entry)
                    <li>
                        <div style="color:var(--text-primary);font-weight:600;">{{ $format($entry['dispatched_at'] ?? null) }}</div>
                        <div><span style="{{ $label }}">Message-ID:</span> <code style="word-break:break-all;">{{ $entry['message_id'] ?? '—' }}</code></div>
                        <div><span style="{{ $label }}">SMTP response:</span> <code style="word-break:break-all;white-space:pre-wrap;">{{ $entry['smtp_response'] ?? '—' }}</code></div>
                    </li>
                @endforeach
            </ol>
        </details>
    @endif
</div>
