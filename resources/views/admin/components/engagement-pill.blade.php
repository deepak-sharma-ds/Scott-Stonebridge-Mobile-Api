{{--
    Compact engagement pill for delivery list rows (sent deliveries only).
    Props: $delivery — an EmailReadingDelivery or CampaignDelivery.
--}}
@if($delivery->status === $delivery::STATUS_SENT)
    @php
        $engagement = $delivery->engagementStatus();
        $engagementStyles = [
            'direct'   => 'background:#ecfdf5;color:#047857;border:1px solid #a7f3d0;',
            'proxy'    => 'background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;',
            'unopened' => 'background:#f8fafc;color:#475569;border:1px solid #cbd5e1;',
        ];
        $dotColors = [
            'direct'   => '#059669',
            'proxy'    => '#2563eb',
            'unopened' => '#64748b',
        ];
    @endphp
    <span class="badge badge-{{ ['direct' => 'success', 'proxy' => 'info', 'unopened' => 'secondary'][$engagement] }}"
          data-engagement="{{ $engagement }}"
          style="{{ $engagementStyles[$engagement] ?? '' }}display:inline-flex;align-items:center;gap:0.35rem;font-size:0.6875rem;font-weight:600;padding:0.2rem 0.55rem;border-radius:9999px;line-height:1.2;">
        @if($engagement === 'direct')
            <span style="width:6px;height:6px;border-radius:50%;background:{{ $dotColors['direct'] }};display:inline-block;flex-shrink:0;"></span>
            <span>Opened</span>
        @elseif($engagement === 'proxy')
            <span style="width:6px;height:6px;border-radius:50%;background:{{ $dotColors['proxy'] }};display:inline-block;flex-shrink:0;"></span>
            <span>Proxy Opened</span>
        @else
            <span style="width:6px;height:6px;border-radius:50%;border:1.5px solid {{ $dotColors['unopened'] }};display:inline-block;flex-shrink:0;"></span>
            <span>Unopened</span>
        @endif
    </span>
@endif


