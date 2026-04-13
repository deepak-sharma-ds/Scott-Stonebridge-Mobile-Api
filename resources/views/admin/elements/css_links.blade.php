{{-- ─── Admin CSS Links ───────────────────────────────────────────
     Loads the Tailwind-based admin design system compiled by Vite.
     External CDN links are kept minimal — only font-awesome icons
     and daterangepicker (used on specific pages).
─────────────────────────────────────────────────────────────── --}}

{{-- Vite-compiled admin CSS (Tailwind + design tokens + components) --}}
@vite(['resources/css/admin.css'])

{{-- Font Awesome icons (local copy already in public/icons) --}}
<link rel="stylesheet" href="{{ asset('icons/font-awesome/css/all.min.css') }}">

{{-- DateRangePicker styles (needed on booking / analytics pages) --}}
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.css">
