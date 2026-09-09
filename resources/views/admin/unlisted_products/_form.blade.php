@php $p = $product; @endphp

@csrf
@method('PUT')

<div class="card p-4" style="border:1px solid var(--card-border);margin-bottom:1rem;background:var(--color-primary-muted);">
    <div class="row" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem;">
        <div>
            <div style="font-size:0.75rem;color:var(--text-muted);">Variants</div>
            <div style="font-weight:600;">{{ count($p->variants ?? []) }}</div>
        </div>
        <div>
            <div style="font-size:0.75rem;color:var(--text-muted);">Status</div>
            <x-admin.badge :type="$p->is_published ? 'success' : 'secondary'">
                {{ $p->is_published ? 'Published' : 'Unpublished' }}
            </x-admin.badge>
        </div>
        <div>
            <div style="font-size:0.75rem;color:var(--text-muted);">Last Synced</div>
            <div style="font-weight:600;">{{ $p->last_synced_at?->diffForHumans() ?? '—' }}</div>
        </div>
    </div>
</div>

{{-- Dynamic email template — prefilled onto the campaign product picker when this product is linked --}}
<div class="card p-4" style="border:1px solid var(--card-border);margin-bottom:1rem;">
    <h3 style="margin:0 0 0.75rem;font-size:1rem;">Email Template Defaults</h3>
    <p style="font-size:0.8125rem;color:var(--text-muted);margin-top:0;">These are suggested defaults only —
        picking this product on a campaign's "Link a product" form prefills them there, but the admin can still
        edit them per campaign before saving.</p>
    <div class="mb-3">
        <label for="header_image" class="form-label">Header Banner Image <small
                style="color:var(--text-muted);">(falls back to the site logo if left blank{{ $p->header_image ? '; leave blank to keep the current one' : '' }})</small></label>
        @if($p->header_image)
            <div style="margin-bottom:0.5rem;">
                <img src="{{ asset('storage/campaign-header-images/'.$p->header_image) }}" alt=""
                    style="max-height:60px;border-radius:6px;">
            </div>
        @endif
        <input type="file" name="header_image" id="header_image" class="form-control" accept="image/*">
    </div>
    <div class="mb-3">
        <label for="email_content" class="form-label">Email Content <small
                style="color:var(--text-muted);">(@{{ $productTitle }} / @{{ $campaignName }} available; leave
                blank to use the default copy)</small></label>
        <textarea name="email_content" id="email_content" class="form-control" rows="3" data-rich-text>{{ old('email_content', $p->email_content ?? '') }}</textarea>
    </div>
    <div class="mb-3">
        <label for="email_footer" class="form-label">Email Footer <small
                style="color:var(--text-muted);">(@{{ $productTitle }} / @{{ $campaignName }} available; leave
                blank to use the default copy)</small></label>
        <textarea name="email_footer" id="email_footer" class="form-control" rows="5" data-rich-text>{{ old('email_footer', $p->email_footer ?? '') }}</textarea>
    </div>
</div>

<div class="mt-2" style="display:flex;gap:0.5rem;">
    <button type="submit" class="btn btn-success">Save Template</button>
    <a href="{{ route('admin.unlisted-products.index') }}" class="btn btn-secondary">Cancel</a>
</div>

@section('custom_js_scripts')
    @include('admin.components.rich-text-editor-scripts')
@endsection
