@extends('admin.layouts.app')

@section('page-title', 'Dashboard')

@section('content')
<div class="container-fluid">

    {{-- Page Header --}}
    @include('admin.components.page-header', [
        'title'    => 'Dashboard',
        'subtitle' => 'Welcome back! Here\'s your real-time overview.',
    ])

    {{-- KPI Cards --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
                gap:1rem;margin-bottom:1.5rem;">

        <x-admin.stat-card id="kpi-downloads"    label="Downloads"
                           gradient="var(--gradient-primary)"   :delay="0.08">
            <x-slot:icon>
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                    <polyline points="7 10 12 15 17 10"></polyline>
                    <line x1="12" y1="15" x2="12" y2="3"></line>
                </svg>
            </x-slot:icon>
        </x-admin.stat-card>

        <x-admin.stat-card id="kpi-active-users" label="Active Users"
                           gradient="var(--gradient-success)"   :delay="0.16">
            <x-slot:icon>
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                    <circle cx="9" cy="7" r="4"></circle>
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                </svg>
            </x-slot:icon>
        </x-admin.stat-card>

        <x-admin.stat-card id="kpi-orders"       label="Orders (Shopify)"
                           gradient="var(--gradient-warning)"   :delay="0.24">
            <x-slot:icon>
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="9"  cy="21" r="1"></circle>
                    <circle cx="20" cy="21" r="1"></circle>
                    <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
                </svg>
            </x-slot:icon>
        </x-admin.stat-card>

        <x-admin.stat-card id="kpi-sales"        label="Sales (Shopify)"
                           gradient="var(--gradient-secondary)" :delay="0.32">
            <x-slot:icon>
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="12" y1="1"  x2="12" y2="23"></line>
                    <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                </svg>
            </x-slot:icon>
        </x-admin.stat-card>

    </div>

    {{-- Top Products --}}
    <div class="card p-4">
        <div style="display:flex;align-items:center;gap:0.625rem;margin-bottom:1.25rem;">
            <div style="width:34px;height:34px;border-radius:9px;background:var(--gradient-primary);
                        display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2">
                    <polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline>
                    <polyline points="17 6 23 6 23 12"></polyline>
                </svg>
            </div>
            <h2 style="font-size:1.125rem;font-weight:700;color:var(--text-primary);margin:0;">
                Top Products
            </h2>
        </div>

        {{-- Skeleton loaders shown while data loads --}}
        <div id="top-products">
            @for($i = 0; $i < 5; $i++)
                <div style="display:flex;justify-content:space-between;align-items:center;
                            padding:0.875rem 1rem;border-radius:10px;background:#f8fafc;
                            border:1px solid #e2e8f0;margin-bottom:0.5rem;">
                    <div style="display:flex;align-items:center;gap:0.75rem;">
                        <div class="skeleton-box" style="width:30px;height:30px;border-radius:8px;"></div>
                        <div>
                            <div class="skeleton-box" style="width:150px;height:13px;border-radius:4px;"></div>
                            <div class="skeleton-box" style="width:80px;height:10px;border-radius:4px;margin-top:6px;"></div>
                        </div>
                    </div>
                    <div class="skeleton-box" style="width:36px;height:18px;border-radius:4px;"></div>
                </div>
            @endfor
        </div>
    </div>

</div>
@endsection

@section('custom_js_scripts')
<script>
/* ── Dashboard KPI & Product Loader ── */
(function () {
    'use strict';

    // Skeleton pulse
    const skeletonStyle = document.createElement('style');
    skeletonStyle.textContent = `
        .skeleton-box {
            background: linear-gradient(90deg, #e2e8f0 25%, #f1f5f9 50%, #e2e8f0 75%);
            background-size: 200% 100%;
            animation: skeleton-shimmer 1.4s ease-in-out infinite;
        }
        @keyframes skeleton-shimmer {
            0%   { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }
    `;
    document.head.appendChild(skeletonStyle);

    function animateCounter(el, end, duration, prefix, suffix) {
        if (!el || !end) return;
        prefix = prefix || '';
        suffix = suffix || '';
        var start = performance.now();
        function step(now) {
            var p = Math.min((now - start) / duration, 1);
            var eased = 1 - Math.pow(1 - p, 3);
            el.textContent = prefix + Math.floor(eased * end).toLocaleString() + suffix;
            if (p < 1) requestAnimationFrame(step);
        }
        requestAnimationFrame(step);
    }

    function renderTopProducts(products) {
        var el = document.getElementById('top-products');
        if (!el) return;

        if (!products || !products.length) {
            el.innerHTML = '<div style="text-align:center;padding:3rem;color:var(--text-muted);">' +
                '<svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin:0 auto 1rem;display:block;opacity:0.35;">' +
                '<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path>' +
                '<line x1="7" y1="7" x2="7.01" y2="7"></line></svg>' +
                '<p style="font-size:0.9375rem;font-weight:500;margin:0;">No product data available</p></div>';
            return;
        }

        el.innerHTML = products.map(function (p, i) {
            return '<div style="display:flex;justify-content:space-between;align-items:center;' +
                   'padding:0.875rem 1rem;border-radius:10px;background:#f8fafc;' +
                   'border:1px solid #e2e8f0;margin-bottom:0.5rem;' +
                   'animation:slideInUp 0.4s ' + (i * 0.07).toFixed(2) + 's both;' +
                   'transition:all 0.2s ease;" ' +
                   'onmouseover="this.style.transform=\'translateX(4px)\';this.style.boxShadow=\'var(--shadow-md)\'" ' +
                   'onmouseout="this.style.transform=\'\';this.style.boxShadow=\'\'">' +
                   '<div style="display:flex;align-items:center;gap:0.75rem;">' +
                   '<div style="width:30px;height:30px;border-radius:8px;background:var(--gradient-primary);' +
                   'display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:0.75rem;flex-shrink:0;">' +
                   (i + 1) + '</div>' +
                   '<div>' +
                   '<div style="font-weight:600;color:var(--text-primary);font-size:0.9375rem;">' + (p.title || 'N/A') + '</div>' +
                   '<div style="font-size:0.75rem;color:var(--text-muted);margin-top:0.125rem;">ID: ' + (p.id || 'N/A') + '</div>' +
                   '</div></div>' +
                   '<div style="text-align:right;">' +
                   '<div style="font-size:1.25rem;font-weight:800;background:var(--gradient-primary);' +
                   '-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;">' +
                   (p.sales || 0) + '</div>' +
                   '<div style="font-size:0.6875rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.05em;">Sales</div>' +
                   '</div></div>';
        }).join('');
    }

    async function loadDashboard() {
        try {
            var kpiRes = await fetch('{{ route('admin.analytics.dashboard') }}');
            var topRes = await fetch('{{ route('admin.analytics.top.products') }}');
            var kpiJson = await kpiRes.json();
            var topJson = await topRes.json();
            var k = kpiJson.data || {};

            setTimeout(function () {
                animateCounter(document.getElementById('kpi-downloads'),
                    (k.downloads && k.downloads.local != null) ? k.downloads.local : (k.downloads || 0),
                    1200, '', '');
                animateCounter(document.getElementById('kpi-active-users'),
                    (k.active_users && k.active_users.local != null) ? k.active_users.local : (k.active_users || 0),
                    1200, '', '');
                animateCounter(document.getElementById('kpi-orders'),
                    (k.orders && k.orders.shopify != null) ? k.orders.shopify : (k.orders || 0),
                    1200, '', '');
                animateCounter(document.getElementById('kpi-sales'),
                    (k.sales && k.sales.shopify != null) ? k.sales.shopify : (k.sales || 0),
                    1200, '$', '');
            }, 200);

            renderTopProducts(topJson.data);

        } catch (err) {
            console.error('[Dashboard] Failed to load:', err);
            if (window.adminNotify) window.adminNotify('Failed to load dashboard data', 'error');
        }
    }

    document.addEventListener('DOMContentLoaded', loadDashboard);
}());
</script>
@endsection
