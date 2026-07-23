@csrf
@method('PUT')

<div class="row" style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
    <div class="mb-3">
        <label class="form-label">Order</label>
        <input type="text" class="form-control" value="#{{ $delivery->shopify_order_id }} (line item #{{ $delivery->shopify_line_item_id }})" disabled>
    </div>
    <div class="mb-3">
        <label class="form-label">Attempts</label>
        <input type="text" class="form-control" value="{{ $delivery->attempts }}" disabled>
    </div>
</div>

@if($delivery->error_message)
    <div class="alert alert-danger">{{ $delivery->error_message }}</div>
@endif

<div class="row" style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
    <div class="mb-3">
        <label for="customer_email" class="form-label">Customer Email</label>
        <input type="email" name="customer_email" id="customer_email" class="form-control"
               value="{{ old('customer_email', $delivery->customer_email ?? '') }}" required>
    </div>
    <div class="mb-3">
        <label for="customer_name" class="form-label">Customer Name</label>
        <input type="text" name="customer_name" id="customer_name" class="form-control"
               value="{{ old('customer_name', $delivery->customer_name ?? '') }}">
    </div>
</div>

<div class="mb-3">
    <label for="campaign_product_id" class="form-label">Campaign Product (pairing)</label>
    <select name="campaign_product_id" id="campaign_product_id" class="form-control">
        <option value="">— Unassigned —</option>
        @foreach($campaignProducts as $campaignProduct)
            <option value="{{ $campaignProduct->id }}"
                    @selected((string) old('campaign_product_id', $delivery->campaign_product_id ?? '') === (string) $campaignProduct->id)>
                {{ $campaignProduct->marketingCampaign?->name ?? 'Unknown campaign' }}
                ({{ $campaignProduct->marketingCampaign?->campaign_key }})
                — {{ $campaignProduct->product_title ?? 'Product #'.$campaignProduct->shopify_product_id }}
                @unless($campaignProduct->response) [no response yet] @endunless
            </option>
        @endforeach
    </select>
    <small style="color:var(--text-muted);">
        Re-pairing recovers an Attribution Failure — pick the correct (campaign, product) pairing, then Save and Send/Resend.
    </small>
</div>

<div class="row" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem;">
    <div class="mb-3">
        <label for="status" class="form-label">Status</label>
        <select name="status" id="status" class="form-control">
            @foreach($statuses as $value => $label)
                <option value="{{ $value }}" @selected(old('status', $delivery->status ?? '') === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="mb-3">
        <label for="scheduled_at" class="form-label">Scheduled At</label>
        <input type="datetime-local" name="scheduled_at" id="scheduled_at" class="form-control"
               value="{{ old('scheduled_at', optional($delivery->scheduled_at ?? null)->format('Y-m-d\TH:i')) }}">
    </div>
    <div class="mb-3">
        <label for="expedited_at" class="form-label">Expedited At</label>
        <input type="datetime-local" name="expedited_at" id="expedited_at" class="form-control"
               value="{{ old('expedited_at', optional($delivery->expedited_at ?? null)->format('Y-m-d\TH:i')) }}">
    </div>
</div>

<div class="mt-2" style="display:flex;gap:0.5rem;">
    <button type="submit" class="btn btn-success">Save Changes</button>
    <a href="{{ route('admin.campaign_deliveries.show', $delivery) }}" class="btn btn-secondary">Cancel</a>
</div>
