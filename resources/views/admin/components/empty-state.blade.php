{{--
    Component: Empty State
    Usage: @include('admin.components.empty-state', [
        'message' => 'No records found.',
        'icon'    => '<svg>...</svg>',   (optional, raw HTML)
    ])
--}}
<div style="text-align:center;padding:3rem 1.5rem;color:var(--text-muted);">
    @if(!empty($icon))
        <div style="margin:0 auto 1rem;display:flex;justify-content:center;opacity:0.4;">
            {!! $icon !!}
        </div>
    @else
        <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="1.5" style="margin:0 auto 1rem;display:block;opacity:0.35;">
            <circle cx="12" cy="12" r="10"></circle>
            <line x1="12" y1="8" x2="12" y2="12"></line>
            <line x1="12" y1="16" x2="12.01" y2="16"></line>
        </svg>
    @endif
    <p style="margin:0;font-size:0.9375rem;font-weight:500;">
        {{ $message ?? 'No data available.' }}
    </p>
</div>
