{{--
    Compact engagement pill for delivery list rows (sent deliveries only).
    Props: $delivery — an EmailReadingDelivery or CampaignDelivery.
--}}
@if($delivery->status === $delivery::STATUS_SENT)
    @php($engagement = $delivery->engagementStatus())
    <x-admin.badge :type="['direct' => 'success', 'proxy' => 'info', 'unopened' => 'secondary'][$engagement]"
                   data-engagement="{{ $engagement }}">
        {{ ['direct' => 'Opened', 'proxy' => 'Proxy Opened', 'unopened' => 'Unopened'][$engagement] }}
    </x-admin.badge>
@endif
