{{-- ═══════════════════════════════════════════════════════════════
     Sidebar — admin/elements/header.blade.php
     Powered by Alpine.js (no Bootstrap collapse dependency).
═══════════════════════════════════════════════════════════════ --}}

{{-- Logo strip --}}
<div class="admin-sidebar-logo">
    <a href="{{ url('/admin/dashboard') }}" style="display:flex;align-items:center;gap:0.625rem;text-decoration:none;"
        title="{{ config('Site.title', 'Admin') }}">
        @php $logo = config('Site.logo'); @endphp
        @if ($logo)
            <img src="{{ asset('storage/configuration-images/' . $logo) }}" alt="{{ config('Site.title', 'Logo') }}">
        @else
            <div
                style="width:34px;height:34px;background:var(--gradient-primary);border-radius:9px;
                        display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2">
                    <polygon
                        points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2">
                    </polygon>
                </svg>
            </div>
        @endif
        <span class="admin-sidebar-logo-text">
            {{ config('Site.title', 'SS Admin') }}
        </span>
    </a>
</div>

{{-- Scrollable navigation --}}
<nav class="admin-sidebar-nav" aria-label="Admin Navigation">

    {{-- ── Core ── --}}
    <div class="admin-sidebar-heading">Core</div>

    <a href="{{ url('/admin/dashboard') }}"
        class="admin-nav-link {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">
        <span class="admin-nav-link-icon">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="2">
                <rect x="3" y="3" width="7" height="7"></rect>
                <rect x="14" y="3" width="7" height="7"></rect>
                <rect x="14" y="14" width="7" height="7"></rect>
                <rect x="3" y="14" width="7" height="7"></rect>
            </svg>
        </span>
        <span class="admin-nav-link-text">Dashboard</span>
    </a>

    {{-- <a href="{{ url('/admin/booking-inquiries') }}"
        class="admin-nav-link {{ request()->routeIs('admin.scheduled-meetings') ? 'active' : '' }}">
        <span class="admin-nav-link-icon">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="2">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                <polyline points="14 2 14 8 20 8"></polyline>
                <line x1="16" y1="13" x2="8" y2="13"></line>
                <line x1="16" y1="17" x2="8" y2="17"></line>
            </svg>
        </span>
        <span class="admin-nav-link-text">Booking Inquiries</span>
    </a> --}}

    <a href="{{ url('/admin/customers') }}"
        class="admin-nav-link {{ request()->routeIs('admin.customers.index') ? 'active' : '' }}">
        <span class="admin-nav-link-icon">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="2">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                <circle cx="9" cy="7" r="4"></circle>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
            </svg>
        </span>
        <span class="admin-nav-link-text">Manage Users</span>
    </a>

    {{-- <a href="{{ route('admin.customer.entitlements.index') }}"
        class="admin-nav-link {{ request()->routeIs('admin.customer.entitlements.*') ? 'active' : '' }}">
        <span class="admin-nav-link-icon">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="2">
                <polyline points="9 11 12 14 22 4"></polyline>
                <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>
            </svg>
        </span>
        <span class="admin-nav-link-text">Customer Entitlements</span>
    </a> --}}

    @role('Admin')

        {{-- ── Webhook ── --}}
        <div class="admin-sidebar-heading">Webhooks</div>

        <a href="{{ route('admin.webhook_events.index') }}"
            class="admin-nav-link {{ request()->routeIs('admin.webhook_events.index') ? 'active' : '' }}">
            <span class="admin-nav-link-icon">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                    stroke-width="2">
                    <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline>
                </svg>
            </span>
            <span class="admin-nav-link-text">Webhook Events</span>
        </a>

        {{-- ── Availability & Calendar ── --}}
        <div class="admin-sidebar-heading">Meetings</div>
        @php
            $availActive =
                request()->routeIs('admin.availability_templates.*') ||
                request()->routeIs('admin.availability.*') ||
                request()->routeIs('admin.scheduled-meetings');
        @endphp

        <div x-data="{ open: {{ $availActive ? 'true' : 'false' }} }">
            <button @click="open = !open" class="admin-nav-link {{ $availActive ? 'active' : '' }}"
                :class="{ 'active': open && {{ $availActive ? 'true' : 'false' }} }" aria-expanded="open">
                <span class="admin-nav-link-icon">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                        <line x1="16" y1="2" x2="16" y2="6"></line>
                        <line x1="8" y1="2" x2="8" y2="6"></line>
                        <line x1="3" y1="10" x2="21" y2="10"></line>
                    </svg>
                </span>
                <span class="admin-nav-link-text">Calendar &amp; Bookings</span>
                <span class="admin-nav-link-arrow">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" :style="open ? 'transform:rotate(180deg)' : ''" style="transition:transform 0.2s;">
                        <polyline points="6 9 12 15 18 9"></polyline>
                    </svg>
                </span>
            </button>

            <div class="admin-nav-submenu" :class="{ 'open': open }">
                <div class="admin-nav-submenu-inner">
                    <a href="{{ route('admin.availability_templates.index') }}"
                        class="admin-nav-sublink {{ request()->routeIs('admin.availability_templates.*') ? 'active' : '' }}">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2">
                            <rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect>
                            <line x1="1" y1="10" x2="23" y2="10"></line>
                        </svg>
                        Availability Templates
                    </a>
                    <a href="{{ route('admin.availability.calendar') }}"
                        class="admin-nav-sublink {{ request()->routeIs('admin.availability.*') ? 'active' : '' }}">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2">
                            <polyline points="20 6 9 17 4 12"></polyline>
                        </svg>
                        Availability Calendar
                    </a>

                    <a href="{{ route('admin.scheduled-meetings') }}"
                        class="admin-nav-sublink {{ request()->routeIs('admin.scheduled-meetings') ? 'active' : '' }}">
                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24"
                            fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                            stroke-linejoin="round">
                            <rect x="3" y="5" width="18" height="16" rx="2" />
                            <path d="M16 3v4M8 3v4M3 10h18" />
                            <path d="m8.5 15 2.2 2.2 4.8-4.8" />
                        </svg>
                        Scheduled Meetings
                    </a>
                </div>
            </div>
        </div>

        {{-- ── Audio Subscriptions ── --}}
        <div class="admin-sidebar-heading">Audio</div>

        @php
            $audioActive =
                request()->routeIs('packages.*') ||
                request()->routeIs('audios.*') ||
                request()->routeIs('admin.customer.entitlements.*');
        @endphp

        <div x-data="{ open: {{ $audioActive ? 'true' : 'false' }} }">
            <button @click="open = !open" class="admin-nav-link {{ $audioActive ? 'active' : '' }}"
                aria-expanded="open">
                <span class="admin-nav-link-icon">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2">
                        <path d="M9 18V5l12-2v13"></path>
                        <circle cx="6" cy="18" r="3"></circle>
                        <circle cx="18" cy="16" r="3"></circle>
                    </svg>
                </span>
                <span class="admin-nav-link-text">Audio &amp; Subscriptions</span>
                <span class="admin-nav-link-arrow">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" :style="open ? 'transform:rotate(180deg)' : ''"
                        style="transition:transform 0.2s;">
                        <polyline points="6 9 12 15 18 9"></polyline>
                    </svg>
                </span>
            </button>

            <div class="admin-nav-submenu" :class="{ 'open': open }">
                <div class="admin-nav-submenu-inner">
                    <a href="{{ route('packages.index') }}"
                        class="admin-nav-sublink {{ request()->routeIs('packages.*') ? 'active' : '' }}">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2">
                            <path
                                d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z">
                            </path>
                        </svg>
                        Packages
                    </a>
                    <a href="{{ route('audios.index') }}"
                        class="admin-nav-sublink {{ request()->routeIs('audios.*') ? 'active' : '' }}">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2">
                            <path d="M3 18v-6a9 9 0 0 1 18 0v6"></path>
                            <path
                                d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z">
                            </path>
                        </svg>
                        Audios
                    </a>

                    <a href="{{ route('admin.customer.entitlements.index') }}"
                        class="admin-nav-sublink {{ request()->routeIs('admin.customer.entitlements.*') ? 'active' : '' }}">
                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24"
                            fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                            stroke-linejoin="round">
                            <!-- Customer profile -->
                            <circle cx="10" cy="9" r="4" />
                            <path d="M2.5 25v-2a7.5 7.5 0 0 1 15 0v2" />

                            <!-- Purchased audio -->
                            <path d="M19 4.5h9v12h-9z" />
                            <path d="M22 8v5.5a1.8 1.8 0 1 1-1.8-1.8" />
                            <path d="m22 8 4-1" />

                            <!-- Purchase confirmation -->
                            <circle cx="25" cy="24" r="5.5" fill="currentColor" stroke="currentColor" />
                            <path d="m22.5 24 1.7 1.7 3.3-3.5" stroke="#ffffff" stroke-width="1.6" />
                        </svg>
                        Subscribed Customers
                    </a>
                </div>
            </div>
        </div>

        {{-- ── Email Readings ── --}}
        <div class="admin-sidebar-heading">Email Readings</div>

        @php
            $readingActive =
                request()->routeIs('admin.email_readings.*') || request()->routeIs('admin.email-reading-products.*');
        @endphp

        <div x-data="{ open: {{ $readingActive ? 'true' : 'false' }} }">
            <button @click="open = !open" class="admin-nav-link {{ $readingActive ? 'active' : '' }}"
                aria-expanded="open">
                <span class="admin-nav-link-icon">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2">
                        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                        <polyline points="22,6 12,13 2,6"></polyline>
                    </svg>
                </span>
                <span class="admin-nav-link-text">Email Readings</span>
                <span class="admin-nav-link-arrow">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" :style="open ? 'transform:rotate(180deg)' : ''"
                        style="transition:transform 0.2s;">
                        <polyline points="6 9 12 15 18 9"></polyline>
                    </svg>
                </span>
            </button>

            <div class="admin-nav-submenu" :class="{ 'open': open }">
                <div class="admin-nav-submenu-inner">
                    <a href="{{ route('admin.email_readings.index') }}"
                        class="admin-nav-sublink {{ request()->routeIs('admin.email_readings.*') ? 'active' : '' }}">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2">
                            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                        </svg>
                        Email Readings Deliveries
                    </a>
                    <a href="{{ route('admin.email-reading-products.index') }}"
                        class="admin-nav-sublink {{ request()->routeIs('admin.email-reading-products.*') ? 'active' : '' }}">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                            <polyline points="14 2 14 8 20 8"></polyline>
                        </svg>
                        Reading Products
                    </a>
                </div>
            </div>
        </div>

        {{-- ── Campaigns ── --}}
        <div class="admin-sidebar-heading">VIP Campaigns</div>

        @php
            $campaignActive =
                request()->routeIs('admin.marketing-campaigns.*') ||
                request()->routeIs('admin.campaign_deliveries.*') ||
                request()->routeIs('admin.unlisted-products.*') ||
                request()->routeIs('admin.webhook_events.*');
        @endphp

        <div x-data="{ open: {{ $campaignActive ? 'true' : 'false' }} }">
            <button @click="open = !open" class="admin-nav-link {{ $campaignActive ? 'active' : '' }}"
                aria-expanded="open">
                <span class="admin-nav-link-icon">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2">
                        <path d="M22 12h-4l-3 9L9 3l-3 9H2"></path>
                    </svg>
                </span>
                <span class="admin-nav-link-text">VIP Campaigns</span>
                <span class="admin-nav-link-arrow">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" :style="open ? 'transform:rotate(180deg)' : ''"
                        style="transition:transform 0.2s;">
                        <polyline points="6 9 12 15 18 9"></polyline>
                    </svg>
                </span>
            </button>

            <div class="admin-nav-submenu" :class="{ 'open': open }">
                <div class="admin-nav-submenu-inner">
                    <a href="{{ route('admin.marketing-campaigns.index') }}"
                        class="admin-nav-sublink {{ request()->routeIs('admin.marketing-campaigns.*') ? 'active' : '' }}">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2">
                            <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                            <polyline points="22,6 12,13 2,6"></polyline>
                        </svg>
                        VIP Campaigns
                    </a>
                    <a href="{{ route('admin.unlisted-products.index') }}"
                        class="admin-nav-sublink {{ request()->routeIs('admin.unlisted-products.*') ? 'active' : '' }}">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                            <polyline points="14 2 14 8 20 8"></polyline>
                        </svg>
                        VIP Unlisted Products
                    </a>
                    <a href="{{ route('admin.campaign_deliveries.index') }}"
                        class="admin-nav-sublink {{ request()->routeIs('admin.campaign_deliveries.*') ? 'active' : '' }}">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2">
                            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                        </svg>
                        VIP Email Readings Deliveries
                    </a>
                    {{-- <a href="{{ route('admin.webhook_events.index') }}"
                        class="admin-nav-sublink {{ request()->routeIs('admin.webhook_events.*') ? 'active' : '' }}">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2">
                            <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline>
                        </svg>
                        Webhook Events
                    </a> --}}
                </div>
            </div>
        </div>

        {{-- ── Global Configurations ── --}}
        @php
            $configuration_menu = getConfigurationMenu();
            $configPrefix = '';
            $configActive = false;
            if (request()->routeIs('admin.configurations.admin_prefix')) {
                $configActive = true;
                $configPrefix = \Illuminate\Support\Facades\Request::segment(4);
            }
        @endphp

        @if (!empty($configuration_menu))
            <div class="admin-sidebar-heading">System</div>

            <div x-data="{ open: {{ $configActive ? 'true' : 'false' }} }">
                <button @click="open = !open" class="admin-nav-link {{ $configActive ? 'active' : '' }}"
                    aria-expanded="open">
                    <span class="admin-nav-link-icon">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2">
                            <circle cx="12" cy="12" r="3"></circle>
                            <path
                                d="M19.07 4.93l-1.06 1.06A7 7 0 0 0 5.86 18.14l1.06 1.06a1 1 0 0 0 1.42 0l.35-.35a1 1 0 0 0 0-1.42 5 5 0 0 1 0-7.07 1 1 0 0 0 0-1.42l-.35-.36a1 1 0 0 0-1.42 0z">
                            </path>
                        </svg>
                    </span>
                    <span class="admin-nav-link-text">Configurations</span>
                    <span class="admin-nav-link-arrow">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" :style="open ? 'transform:rotate(180deg)' : ''"
                            style="transition:transform 0.2s;">
                            <polyline points="6 9 12 15 18 9"></polyline>
                        </svg>
                    </span>
                </button>

                <div class="admin-nav-submenu" :class="{ 'open': open }">
                    <div class="admin-nav-submenu-inner">
                        @foreach ($configuration_menu as $cfg)
                            <a href="{{ route('admin.configurations.admin_prefix', $cfg) }}"
                                class="admin-nav-sublink {{ $configPrefix === $cfg ? 'active' : '' }}">
                                {{ $cfg }}
                            </a>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif

    @endrole

</nav>

{{-- Profile strip at sidebar bottom --}}
<div class="admin-sidebar-profile">
    <a href="{{ route('admin.profile.edit') }}" class="admin-sidebar-profile-inner" style="text-decoration:none;">
        <div class="admin-sidebar-avatar">
            {{ strtoupper(substr(auth()->user()->name, 0, 2)) }}
        </div>
        <div class="admin-sidebar-profile-info">
            <div
                style="font-size:0.8125rem;font-weight:600;color:#fff;
                        white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:170px;">
                {{ auth()->user()->name }}
            </div>
            <div style="font-size:0.6875rem;color:rgba(255,255,255,0.38);white-space:nowrap;">
                {{ auth()->user()->email }}
            </div>
        </div>
    </a>
</div>
