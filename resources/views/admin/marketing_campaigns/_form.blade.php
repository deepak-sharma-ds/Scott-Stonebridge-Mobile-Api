@php
    $c = $campaign ?? null;
@endphp

@csrf
@if ($c)
    @method('PUT')
@endif

<div class="row" style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
    <div class="mb-3">
        <label for="name" class="form-label">Name</label>
        <input type="text" name="name" id="name" class="form-control"
            value="{{ old('name', $c->name ?? '') }}" required>
    </div>
    <div class="mb-3">
        <label for="campaign_key" class="form-label">Campaign Key <small style="color:var(--text-muted);">(auto from name if blank)</small></label>
        <input type="text" name="campaign_key" id="campaign_key" class="form-control"
            value="{{ old('campaign_key', $c->campaign_key ?? '') }}" placeholder="lowercase-with-dashes">
    </div>
</div>

<div class="row" style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
    <div class="mb-3">
        <label for="klaviyo_campaign_id" class="form-label">Klaviyo Campaign ID <small style="color:var(--text-muted);">(optional, soft reference only)</small></label>
        <input type="text" name="klaviyo_campaign_id" id="klaviyo_campaign_id" class="form-control"
            value="{{ old('klaviyo_campaign_id', $c->klaviyo_campaign_id ?? '') }}">
    </div>
    <div class="mb-3">
        <label for="status" class="form-label">Status</label>
        <select name="status" id="status" class="form-control">
            @foreach (\App\Models\MarketingCampaign::statusLabels() as $value => $label)
                <option value="{{ $value }}" @selected(old('status', $c->status ?? 'draft') === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
</div>

<button type="submit" class="btn btn-primary">{{ $c ? 'Save Changes' : 'Create Campaign' }}</button>
