@extends('admin.layouts.app')

@section('content')
    <style>
        .kpi-card {
            position: relative;
            padding: 1.5rem;
            animation: slideInUp 0.6s cubic-bezier(0.4, 0, 0.2, 1) backwards;
        }

        .kpi-card:nth-child(1) {
            animation-delay: 0.1s;
        }

        .kpi-card:nth-child(2) {
            animation-delay: 0.2s;
        }

        .kpi-card:nth-child(3) {
            animation-delay: 0.3s;
        }

        .kpi-card:nth-child(4) {
            animation-delay: 0.4s;
        }

        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .kpi-value {
            font-size: 2.5rem;
            font-weight: 800;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-top: 0.5rem;
        }

        .kpi-label {
            font-size: 0.875rem;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .icon-gradient {
            background: var(--gradient-primary);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 48px;
            height: 48px;
            border-radius: 12px;
            margin-bottom: 1rem;
            box-shadow: var(--shadow-sm);
        }

        .top-product-card {
            background: white;
            padding: 1rem;
            border-radius: 12px;
            margin-bottom: 0.75rem;
            border-left: 4px solid var(--color-primary);
            transition: all 0.3s ease;
        }

        .top-product-card:hover {
            transform: translateX(8px);
            box-shadow: var(--shadow-md);
        }
    </style>

    <div class="container-fluid">
        <!-- Page Header -->
        <div class="page-header">
            <h1 style="font-size: 2rem; font-weight: 900; color: #ffffff; margin: 0;">Dashboard</h1>
            <p style="color: rgba(255, 255, 255, 0.9); font-size: 1rem; margin-top: 0.5rem;">Welcome back! Here's your
                overview</p>
        </div>

        <div class="container-fluid">
            <!-- KPI Cards -->
            <div class="row g-4 mb-4">
                <div class="col-md-3">
                    <div class="card kpi-card">
                        <div class="icon-gradient">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="white">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                <polyline points="7 10 12 15 17 10"></polyline>
                                <line x1="12" y1="15" x2="12" y2="3"></line>
                            </svg>
                        </div>
                        <div class="kpi-label">Downloads</div>
                        <div class="kpi-value" id="downloads">0</div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="card kpi-card">
                        <div class="icon-gradient" style="background: var(--gradient-success);">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="white"
                                stroke-width="2">
                                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                <circle cx="9" cy="7" r="4"></circle>
                                <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                                <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                            </svg>
                        </div>
                        <div class="kpi-label">Active Users</div>
                        <div class="kpi-value" id="active_users"
                            style="background: var(--gradient-success); -webkit-background-clip: text; background-clip: text;">
                            0</div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="card kpi-card">
                        <div class="icon-gradient" style="background: var(--gradient-warning);">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="white"
                                stroke-width="2">
                                <circle cx="9" cy="21" r="1"></circle>
                                <circle cx="20" cy="21" r="1"></circle>
                                <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
                            </svg>
                        </div>
                        <div class="kpi-label">Orders (Shopify)</div>
                        <div class="kpi-value" id="orders"
                            style="background: var(--gradient-warning); -webkit-background-clip: text; background-clip: text;">
                            0</div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="card kpi-card">
                        <div class="icon-gradient" style="background: var(--gradient-secondary);">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="white"
                                stroke-width="2">
                                <line x1="12" y1="1" x2="12" y2="23"></line>
                                <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                            </svg>
                        </div>
                        <div class="kpi-label">Sales (Shopify)</div>
                        <div class="kpi-value" id="sales"
                            style="background: var(--gradient-secondary); -webkit-background-clip: text; background-clip: text;">
                            $0</div>
                    </div>
                </div>
            </div>

            <!-- Top Products Section -->
            <div class="card p-4">
                <h2 style="font-size: 1.5rem; font-weight: 700; color: #1e293b; margin-bottom: 1.5rem;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2"
                        style="display: inline-block; vertical-align: middle; margin-right: 0.5rem; color: var(--color-primary);">
                        <line x1="12" y1="5" x2="12" y2="19"></line>
                        <polyline points="5 12 12 5 19 12"></polyline>
                    </svg>
                    Top Products
                </h2>
                <div id="top-products">
                    <div style="text-align: center; padding: 3rem; color: #94a3b8;">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" style="margin: 0 auto 1rem; opacity: 0.5;">
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="12" y1="8" x2="12" y2="12"></line>
                            <line x1="12" y1="16" x2="12.01" y2="16"></line>
                        </svg>
                        <p>Loading top products...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('custom_js_scripts')
    <script>
        function animateValue(element, start, end, duration, prefix = '', suffix = '') {
            const startTime = performance.now();
            const step = (currentTime) => {
                const elapsed = currentTime - startTime;
                const progress = Math.min(elapsed / duration, 1);
                const value = Math.floor(progress * (end - start) + start);
                element.textContent = prefix + value.toLocaleString() + suffix;
                if (progress < 1) {
                    requestAnimationFrame(step);
                }
            };
            requestAnimationFrame(step);
        }

        async function loadDashboard() {
            try {
                const res = await fetch("{{ route('admin.analytics.dashboard') }}");
                const json = await res.json();
                const k = json.data;

                // Animate KPI values
                setTimeout(() => {
                    const downloadsEl = document.getElementById('downloads');
                    const downloads = k.downloads?.local ?? k.downloads ?? 0;
                    if (downloadsEl) animateValue(downloadsEl, 0, downloads, 1000);

                    const activeUsersEl = document.getElementById('active_users');
                    const activeUsers = k.active_users?.local ?? k.active_users ?? 0;
                    if (activeUsersEl) animateValue(activeUsersEl, 0, activeUsers, 1000);

                    const ordersEl = document.getElementById('orders');
                    const orders = k.orders?.shopify ?? k.orders ?? 0;
                    if (ordersEl) animateValue(ordersEl, 0, orders, 1000);

                    const salesEl = document.getElementById('sales');
                    const sales = k.sales?.shopify ?? k.sales ?? 0;
                    if (salesEl) animateValue(salesEl, 0, sales, 1000, '$');
                }, 300);

                // Load top products
                const topRes = await fetch("{{ route('admin.analytics.top.products') }}");
                const top = await topRes.json();
                const el = document.getElementById('top-products');

                if (top.data && top.data.length > 0) {
                    el.innerHTML = top.data.map((product, index) => `
                        <div class="top-product-card" style="animation-delay: ${index * 0.1}s;">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <div>
                                    <div style="font-weight: 700; color: #1e293b; font-size: 1rem;">${product.title || 'N/A'}</div>
                                    <div style="font-size: 0.875rem; color: #64748b; margin-top: 0.25rem;">Product ID: ${product.id || 'N/A'}</div>
                                </div>
                                <div style="text-align: right;">
                                    <div style="font-size: 1.5rem; font-weight: 800; background: var(--gradient-primary); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;">
                                        ${product.sales || 0}
                                    </div>
                                    <div style="font-size: 0.75rem; color: #94a3b8; text-transform: uppercase;">Sales</div>
                                </div>
                            </div>
                        </div>
                    `).join('');
                } else {
                    el.innerHTML = `
                        <div style="text-align: center; padding: 3rem; color: #94a3b8;">
                            <p>No product data available</p>
                        </div>
                    `;
                }

            } catch (error) {
                console.error('Failed to load dashboard:', error);
                toastr.error('Failed to load dashboard data');
            }
        }

        loadDashboard();
    </script>
@endsection
