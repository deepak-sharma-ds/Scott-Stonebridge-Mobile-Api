{{-- Delivery actions: every button maps to one lifecycle transition. --}}
@php
    $status = $delivery->status;
    $canSendNow = ! in_array($status, ['sending', 'cancelled'], true);
    $isUnsent = in_array($status, ['pending', 'failed'], true);
@endphp

<div style="display:flex;flex-direction:column;gap:0.5rem;margin-top:1.25rem;">
    @if($status === 'sending')
        <div class="alert alert-info" style="margin:0;">
            This delivery is being sent right now. Actions are unavailable until the send finishes (normally under a minute).
        </div>
    @else
        @if($canSendNow)
            <form action="{{ route('admin.campaign_deliveries.send', $delivery) }}" method="POST">
                @csrf
                <button type="submit" class="btn btn-success" style="width:100%;"
                        data-confirm="{{ $status === 'sent' ? 'Re-send this delivery?' : 'Send this delivery now?' }}">
                    {{ $status === 'sent' ? 'Resend Email' : 'Send Now' }}
                </button>
            </form>
        @endif

        @if(in_array($status, ['cancelled', 'failed'], true))
            <form action="{{ route('admin.campaign_deliveries.reactivate', $delivery) }}" method="POST">
                @csrf
                <button type="submit" class="btn btn-primary" style="width:100%;"
                        data-confirm="Reactivate this delivery? It will go back into the pipeline.">Reactivate</button>
            </form>
        @endif

        @if($isUnsent)
            <form action="{{ route('admin.campaign_deliveries.cancel', $delivery) }}" method="POST">
                @csrf
                <button type="submit" class="btn btn-secondary" style="width:100%;"
                        data-confirm="Cancel this delivery? No email will be sent.">Cancel</button>
            </form>

            <form action="{{ route('admin.campaign_deliveries.mark_sent', $delivery) }}" method="POST"
                  style="border-top:1px solid var(--card-border);padding-top:0.5rem;">
                @csrf
                <label style="display:flex;gap:0.375rem;align-items:center;font-size:0.8125rem;margin-bottom:0.5rem;">
                    <input type="checkbox" name="skip_fulfilment" value="1"> Skip Shopify fulfilment
                </label>
                <button type="submit" class="btn btn-outline-secondary" style="width:100%;"
                        data-confirm="Mark as sent manually? No email will be sent by the system.">Mark as sent manually</button>
            </form>
        @endif

        @if($delivery->needsFulfillment())
            @if($delivery->fulfillment_error)
                <div class="alert alert-danger" style="margin:0;font-size:0.8125rem;">{{ $delivery->fulfillment_error }}</div>
            @endif
            <form action="{{ route('admin.campaign_deliveries.retry_fulfillment', $delivery) }}" method="POST">
                @csrf
                <button type="submit" class="btn btn-warning" style="width:100%;">Retry Fulfilment</button>
            </form>
        @endif
    @endif
</div>
