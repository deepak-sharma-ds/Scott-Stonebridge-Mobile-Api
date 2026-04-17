{{-- ─── Session Flash Alerts ──────────────────────────────────────
     Rendered once per page load. Auto-dismissible via inline onclick.
─────────────────────────────────────────────────────────────── --}}

@if(Session::has('success'))
    <div class="alert alert-success alert-dismissible" role="alert">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" style="flex-shrink:0;margin-top:1px;">
            <polyline points="20 6 9 17 4 12"></polyline>
        </svg>
        <span>{{ Session::get('success') }}</span>
        <button type="button" class="btn-close"
                onclick="this.closest('.alert').remove()"
                aria-label="Close"></button>
    </div>

@elseif(Session::has('info'))
    <div class="alert alert-info alert-dismissible" role="alert">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" style="flex-shrink:0;margin-top:1px;">
            <circle cx="12" cy="12" r="10"></circle>
            <line x1="12" y1="8" x2="12" y2="12"></line>
            <line x1="12" y1="16" x2="12.01" y2="16"></line>
        </svg>
        <span>{{ Session::get('info') }}</span>
        <button type="button" class="btn-close"
                onclick="this.closest('.alert').remove()"
                aria-label="Close"></button>
    </div>

@elseif(Session::has('warning'))
    <div class="alert alert-warning alert-dismissible" role="alert">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" style="flex-shrink:0;margin-top:1px;">
            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
            <line x1="12" y1="9"  x2="12"    y2="13"></line>
            <line x1="12" y1="17" x2="12.01" y2="17"></line>
        </svg>
        <span>{{ Session::get('warning') }}</span>
        <button type="button" class="btn-close"
                onclick="this.closest('.alert').remove()"
                aria-label="Close"></button>
    </div>

@elseif(Session::has('error'))
    <div class="alert alert-danger alert-dismissible" role="alert">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" style="flex-shrink:0;margin-top:1px;">
            <circle cx="12" cy="12" r="10"></circle>
            <line x1="15" y1="9"  x2="9"  y2="15"></line>
            <line x1="9"  y1="9"  x2="15" y2="15"></line>
        </svg>
        <span>{{ Session::get('error') }}</span>
        <button type="button" class="btn-close"
                onclick="this.closest('.alert').remove()"
                aria-label="Close"></button>
    </div>

@endif
