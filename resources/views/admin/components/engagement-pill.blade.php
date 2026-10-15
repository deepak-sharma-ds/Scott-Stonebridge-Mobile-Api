{{--
    Compact engagement pill for delivery list rows (sent deliveries only).
    Props: $delivery — an EmailReadingDelivery or CampaignDelivery.
--}}
@if($delivery->status === $delivery::STATUS_SENT)
    @php($engagement = $delivery->engagementStatus())
    <x-admin.badge :type="['direct' => 'success', 'proxy' => 'info', 'unopened' => 'secondary'][$engagement]"
                   data-engagement="{{ $engagement }}"
                   style="display:inline-flex;align-items:center;gap:0.35rem;font-size:0.6875rem;padding:0.2rem 0.55rem;">
        @if($engagement === 'direct')
            <span style="width:6px;height:6px;border-radius:50%;background:currentColor;display:inline-block;"></span>
            <span>Opened</span>
        @elseif($engagement === 'proxy')
            <span style="width:6px;height:6px;border-radius:50%;background:currentColor;display:inline-block;"></span>
            <span>Proxy Opened</span>
        @else
            <span style="width:6px;height:6px;border-radius:50%;border:1px solid currentColor;display:inline-block;"></span>
            <span>Unopened</span>
        @endif
    </x-admin.badge>
@endif

