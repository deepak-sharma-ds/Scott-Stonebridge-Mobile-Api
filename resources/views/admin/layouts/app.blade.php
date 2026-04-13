<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="description" content="">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('Site.title', 'Admin Panel') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('admin/images/favicon.png') }}">

    @include('admin.elements.css_links')
    @yield('custom_css_links')
</head>

<body x-data="adminApp()">

    {{-- ─── Full-screen Loader ─── --}}
    <div id="loaderOverlay">
        <div class="loader"></div>
        <p>Processing…</p>
    </div>

    {{-- ─── Mobile overlay (closes sidebar on tap) ─── --}}
    <div class="sidebar-overlay"
         :class="{ 'active': sidebarMobileOpen }"
         @click="closeMobileSidebar()">
    </div>

    {{-- ─── Sidebar ─── --}}
    <aside class="admin-sidebar"
           :class="{
               'collapsed':    sidebarCollapsed,
               'mobile-open':  sidebarMobileOpen
           }">
        @include('admin.elements.header')
    </aside>

    {{-- ─── Top Navbar ─── --}}
    <header class="admin-navbar"
            :class="{ 'sidebar-collapsed': sidebarCollapsed }">

        {{-- Hamburger / toggle --}}
        <button class="admin-navbar-toggle"
                @click="toggleSidebar()"
                aria-label="Toggle Sidebar">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2">
                <line x1="3" y1="6"  x2="21" y2="6"></line>
                <line x1="3" y1="12" x2="21" y2="12"></line>
                <line x1="3" y1="18" x2="21" y2="18"></line>
            </svg>
        </button>

        {{-- Breadcrumb --}}
        <nav class="admin-navbar-breadcrumb" aria-label="Breadcrumb">
            <span style="color:var(--text-muted);">Admin</span>
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" style="opacity:0.4;">
                <polyline points="9 18 15 12 9 6"></polyline>
            </svg>
            <span style="font-weight:600;color:var(--text-primary);">
                @yield('page-title', 'Dashboard')
            </span>
        </nav>

        {{-- Right-side actions --}}
        <div class="admin-navbar-actions">

            {{-- User dropdown --}}
            <div class="admin-dropdown" x-data="{ open: false }">
                <div class="admin-user-menu"
                     @click="open = !open"
                     @keydown.escape="open = false"
                     role="button" tabindex="0"
                     @keydown.enter="open = !open">
                    <div class="admin-user-avatar">
                        {{ strtoupper(substr(auth()->user()->name, 0, 2)) }}
                    </div>
                    <div style="display:flex;flex-direction:column;line-height:1.25;">
                        <span style="font-size:0.8125rem;font-weight:600;color:var(--text-primary);">
                            {{ auth()->user()->name }}
                        </span>
                        <span style="font-size:0.6875rem;color:var(--text-muted);">
                            Administrator
                        </span>
                    </div>
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2"
                         style="color:var(--text-muted);transition:transform 0.2s;"
                         :style="open ? 'transform:rotate(180deg)' : ''">
                        <polyline points="6 9 12 15 18 9"></polyline>
                    </svg>
                </div>

                <div class="admin-dropdown-menu"
                     x-show="open"
                     x-cloak
                     @click.outside="open = false"
                     x-transition:enter="transition ease-out duration-100"
                     x-transition:enter-start="opacity-0"
                     x-transition:enter-end="opacity-100"
                     x-transition:leave="transition ease-in duration-75"
                     x-transition:leave-start="opacity-100"
                     x-transition:leave-end="opacity-0">

                    <a href="{{ route('admin.profile.edit') }}"
                       class="admin-dropdown-item">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                            <circle cx="12" cy="7" r="4"></circle>
                        </svg>
                        My Profile
                    </a>

                    <div class="admin-dropdown-divider"></div>

                    <button class="admin-dropdown-item danger"
                            onclick="document.getElementById('navbar-logout-form').submit()">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2">
                            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                            <polyline points="16 17 21 12 16 7"></polyline>
                            <line x1="21" y1="12" x2="9" y2="12"></line>
                        </svg>
                        Logout
                    </button>

                    <form id="navbar-logout-form"
                          action="{{ route('logout') }}"
                          method="POST"
                          style="display:none;">
                        @csrf
                    </form>
                </div>
            </div>
        </div>
    </header>

    {{-- ─── Main Content ─── --}}
    <main class="admin-content"
          :class="{ 'sidebar-collapsed': sidebarCollapsed }">

        @include('admin.elements.alert_message')

        @yield('content')

        @include('admin.elements.footer')
    </main>

    {{-- ─── Scripts ─── --}}
    @include('admin.elements.js_scripts')
    @yield('custom_js_scripts')

</body>
</html>
