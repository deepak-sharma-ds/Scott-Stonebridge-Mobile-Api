{{--
    Component: Empty State
    Usage: @include('admin.components.empty-state', ['message' => 'No data found', 'icon' => optional])
--}}

<div style="text-align: center; padding: 3rem; color: #94a3b8;">
    @if (isset($icon))
        {!! $icon !!}
    @else
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
            style="margin: 0 auto 1rem; opacity: 0.5;">
            <circle cx="12" cy="12" r="10"></circle>
            <line x1="12" y1="8" x2="12" y2="12"></line>
            <line x1="12" y1="16" x2="12.01" y2="16"></line>
        </svg>
    @endif
    <p style="margin: 0; font-size: 1rem;">{{ $message ?? 'No data available' }}</p>
</div>
