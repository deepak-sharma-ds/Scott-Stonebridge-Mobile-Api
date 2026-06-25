@csrf
@if(isset($delivery) && $delivery->exists)
    @method('PUT')
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

{{-- AI response editor with regenerate --}}
<div class="card p-4" style="background:var(--color-primary-muted);border:1px solid var(--card-border);margin-bottom:1rem;"
     x-data="readingRegenerator(@js(route('admin.email_readings.regenerate', $delivery)), @js(old('ai_response', $delivery->ai_response ?? '')))">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.75rem;flex-wrap:wrap;gap:0.5rem;">
        <h3 style="margin:0;font-size:1rem;">AI Reading Response</h3>
        <span x-show="info" x-text="info" style="font-size:0.75rem;color:var(--text-muted);"></span>
    </div>

    <label for="instruction" class="form-label">Instruction to Scott-AI (optional)</label>
    <textarea id="instruction" x-model="instruction" class="form-control" rows="2"
              placeholder="e.g. Make it warmer, focus more on career, shorten to 3 paragraphs…"></textarea>

    <div style="margin:0.75rem 0;">
        <button type="button" class="btn btn-primary" @click="regenerate()" :disabled="loading">
            <span x-show="!loading">↻ Regenerate</span>
            <span x-show="loading">Generating…</span>
        </button>
        <small style="color:var(--text-muted);margin-left:0.5rem;">Preview only — review &amp; edit below, then Save to persist.</small>
    </div>

    <label for="ai_response" class="form-label">Response</label>
    <textarea name="ai_response" id="ai_response" x-model="response" class="form-control" rows="16"
              style="font-family:inherit;line-height:1.6;">{{ old('ai_response', $delivery->ai_response ?? '') }}</textarea>
</div>

<div class="mt-2" style="display:flex;gap:0.5rem;">
    <button type="submit" class="btn btn-success">Save Changes</button>
    <a href="{{ route('admin.email_readings.show', $delivery) }}" class="btn btn-secondary">Cancel</a>
</div>
