@extends('admin.layouts.app')

@section('content')
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/litepicker/dist/css/litepicker.css" />
    <script src="https://cdn.jsdelivr.net/npm/litepicker/dist/bundle.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap"
        rel="stylesheet">

    <style>
        /* ====== ROOT & TYPOGRAPHY ====== */
        :root {
            --gradient-primary: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --gradient-secondary: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --gradient-success: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            --gradient-warning: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
            --color-primary: #667eea;
            --color-secondary: #764ba2;
            --color-accent: #f5576c;
            --shadow-sm: 0 2px 8px rgba(102, 126, 234, 0.1);
            --shadow-md: 0 4px 16px rgba(102, 126, 234, 0.15);
            --shadow-lg: 0 8px 32px rgba(102, 126, 234, 0.2);
            --shadow-xl: 0 16px 48px rgba(102, 126, 234, 0.25);
        }

        * {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }

        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            background-attachment: fixed;
            min-height: 100vh;
            position: relative;
            overflow-x: hidden;
        }

        /* ====== ANIMATED BACKGROUND ====== */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background:
                radial-gradient(circle at 20% 30%, rgba(255, 255, 255, 0.15) 0%, transparent 50%),
                radial-gradient(circle at 80% 70%, rgba(255, 255, 255, 0.1) 0%, transparent 50%);
            animation: backgroundPulse 15s ease-in-out infinite;
            pointer-events: none;
            z-index: 0;
        }

        @keyframes backgroundPulse {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: 0.7;
            }
        }

        /* Floating particles */
        .dashboard-wrapper {
            position: relative;
            z-index: 1;
        }

        .particle {
            position: fixed;
            width: 4px;
            height: 4px;
            background: rgba(255, 255, 255, 0.5);
            border-radius: 50%;
            pointer-events: none;
            animation: float 20s infinite;
        }

        @keyframes float {

            0%,
            100% {
                transform: translateY(0) translateX(0);
                opacity: 0;
            }

            10% {
                opacity: 1;
            }

            90% {
                opacity: 1;
            }

            100% {
                transform: translateY(-100vh) translateX(100px);
                opacity: 0;
            }
        }

        /* ====== GLASS CARDS ====== */
        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: 20px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: var(--shadow-md);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        .glass-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.5s;
        }

        .glass-card:hover::before {
            left: 100%;
        }

        .glass-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: var(--shadow-xl);
            border-color: rgba(255, 255, 255, 0.5);
        }

        /* ====== KPI CARDS ====== */
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
            animation: countUp 1s ease-out;
        }

        @keyframes countUp {
            from {
                opacity: 0;
                transform: scale(0.5);
            }

            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        .kpi-label {
            font-size: 0.875rem;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* ====== PAGE HEADER ====== */
        .page-header {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            border: 1px solid rgba(255, 255, 255, 0.2);
            animation: fadeInDown 0.6s ease-out;
        }

        @keyframes fadeInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .dashboard-title {
            font-size: 2.5rem;
            font-weight: 900;
            color: #ffffff;
            text-shadow: 0 2px 20px rgba(0, 0, 0, 0.1);
            margin: 0;
            letter-spacing: -0.5px;
        }

        .dashboard-subtitle {
            color: rgba(255, 255, 255, 0.9);
            font-size: 1rem;
            margin-top: 0.5rem;
            font-weight: 500;
        }

        /* ====== SECTION TITLES ====== */
        .section-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            color: #ffffff;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            position: relative;
            display: inline-block;
        }

        .section-title::after {
            content: '';
            position: absolute;
            bottom: -8px;
            left: 0;
            width: 60px;
            height: 4px;
            background: var(--gradient-warning);
            border-radius: 2px;
            animation: expandWidth 0.8s ease-out;
        }

        @keyframes expandWidth {
            from {
                width: 0;
            }

            to {
                width: 60px;
            }
        }

        /* ====== DATE FILTER ====== */
        .date-filter-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: var(--shadow-sm);
            animation: fadeIn 0.6s ease-out 0.3s backwards;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        .date-filter-card label {
            font-weight: 600;
            color: #475569;
            font-size: 0.95rem;
        }

        #dateRange {
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 0.75rem 1rem;
            width: 260px;
            cursor: pointer;
            background: white;
            font-weight: 500;
            transition: all 0.3s ease;
            color: #334155;
        }

        #dateRange:hover {
            border-color: var(--color-primary);
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
        }

        #dateRange:focus {
            outline: none;
            border-color: var(--color-primary);
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.15);
        }

        /* ====== SKELETON LOADERS ====== */
        @keyframes shimmer {
            0% {
                background-position: -1000px 0;
            }

            100% {
                background-position: 1000px 0;
            }
        }

        .skeleton {
            background: linear-gradient(90deg,
                    rgba(226, 232, 240, 0.4) 25%,
                    rgba(248, 250, 252, 0.8) 50%,
                    rgba(226, 232, 240, 0.4) 75%);
            background-size: 1000px 100%;
            animation: shimmer 2s infinite linear;
            border-radius: 12px;
        }

        .skeleton-text {
            height: 20px;
            margin-bottom: 12px;
        }

        /* ====== CHARTS ====== */
        .chart-container {
            animation: scaleIn 0.6s cubic-bezier(0.4, 0, 0.2, 1) 0.4s backwards;
        }

        @keyframes scaleIn {
            from {
                opacity: 0;
                transform: scale(0.9);
            }

            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        /* ====== ACTIVITY SECTION ====== */
        .activity-list {
            margin-top: 1rem;
            padding-left: 1.5rem;
        }

        .activity-list li {
            color: #475569;
            font-size: 0.95rem;
            line-height: 2;
            padding: 0.5rem 0;
            border-bottom: 1px solid #f1f5f9;
            transition: all 0.3s ease;
        }

        .activity-list li:last-child {
            border-bottom: none;
        }

        .activity-list li:hover {
            color: var(--color-primary);
            padding-left: 0.5rem;
        }

        /* ====== UTILITIES ====== */
        .hidden {
            display: none !important;
        }

        .block {
            display: block !important;
        }

        /* ====== GRID ANIMATIONS ====== */
        .grid-animate>* {
            animation: fadeInUp 0.6s cubic-bezier(0.4, 0, 0.2, 1) backwards;
        }

        .grid-animate>*:nth-child(1) {
            animation-delay: 0.1s;
        }

        .grid-animate>*:nth-child(2) {
            animation-delay: 0.2s;
        }

        .grid-animate>*:nth-child(3) {
            animation-delay: 0.3s;
        }

        .grid-animate>*:nth-child(4) {
            animation-delay: 0.4s;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* ====== ICON GRADIENT ====== */
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

        /* ====== RESPONSIVE ====== */
        @media (max-width: 768px) {
            .dashboard-title {
                font-size: 2rem;
            }

            .kpi-value {
                font-size: 2rem;
            }

            #dateRange {
                width: 100%;
            }
        }

        /* ====== LOADING STATE ====== */
        .pulse {
            animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }

        @keyframes pulse {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: 0.5;
            }
        }

        /* Container adjustments */
        .container-fluid {
            position: relative;
            z-index: 1;
        }

        .dashboard-content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }
    </style>

    <div class="dashboard-wrapper">
        <!-- Floating particles -->
        <div class="particle" style="left: 10%; animation-delay: 0s;"></div>
        <div class="particle" style="left: 25%; animation-delay: 3s;"></div>
        <div class="particle" style="left: 50%; animation-delay: 6s;"></div>
        <div class="particle" style="left: 75%; animation-delay: 9s;"></div>
        <div class="particle" style="left: 90%; animation-delay: 12s;"></div>

        <div class="container-fluid">
            <!-- Page Header -->
            <div class="page-header">
                <h1 class="dashboard-title">Analytics Dashboard</h1>
                <p class="dashboard-subtitle">Real-time insights and performance metrics</p>
            </div>

            <div class="dashboard-content">
                <!-- Date Filter -->
                <div class="date-filter-card">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" style="color: #667eea;">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                        <line x1="16" y1="2" x2="16" y2="6"></line>
                        <line x1="8" y1="2" x2="8" y2="6"></line>
                        <line x1="3" y1="10" x2="21" y2="10"></line>
                    </svg>
                    <label>Select Date Range:</label>
                    <input type="text" id="dateRange" placeholder="Choose dates..." readonly>
                </div>

                <!-- KPI Skeletons -->
                <div id="kpi-skeletons" class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                    <div class="glass-card p-6">
                        <div class="skeleton skeleton-text" style="width: 140px;"></div>
                        <div class="skeleton" style="height: 48px; width: 120px; margin-top: 12px;"></div>
                    </div>
                    <div class="glass-card p-6">
                        <div class="skeleton skeleton-text" style="width: 140px;"></div>
                        <div class="skeleton" style="height: 48px; width: 120px; margin-top: 12px;"></div>
                    </div>
                    <div class="glass-card p-6">
                        <div class="skeleton skeleton-text" style="width: 140px;"></div>
                        <div class="skeleton" style="height: 48px; width: 120px; margin-top: 12px;"></div>
                    </div>
                </div>

                <!-- KPI CARDS -->
                <div id="kpi-real" class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8 hidden">
                    <div class="glass-card kpi-card">
                        <div class="icon-gradient">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="white">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                <polyline points="7 10 12 15 17 10"></polyline>
                                <line x1="12" y1="15" x2="12" y2="3"></line>
                            </svg>
                        </div>
                        <div class="kpi-label">Audio Downloads</div>
                        <div class="kpi-value" id="kpi-downloads">0</div>
                    </div>

                    <div class="glass-card kpi-card">
                        <div class="icon-gradient" style="background: var(--gradient-success);">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="white"
                                stroke-width="2">
                                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                <line x1="16" y1="2" x2="16" y2="6"></line>
                                <line x1="8" y1="2" x2="8" y2="6"></line>
                                <line x1="3" y1="10" x2="21" y2="10"></line>
                                <path d="M8 14h.01M12 14h.01M16 14h.01"></path>
                            </svg>
                        </div>
                        <div class="kpi-label">Google Meeting Bookings</div>
                        <div class="kpi-value" id="kpi-bookings"
                            style="background: var(--gradient-success); -webkit-background-clip: text; background-clip: text;">
                            0</div>
                    </div>

                    <div class="glass-card kpi-card">
                        <div class="icon-gradient" style="background: var(--gradient-warning);">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="white"
                                stroke-width="2">
                                <circle cx="9" cy="21" r="1"></circle>
                                <circle cx="20" cy="21" r="1"></circle>
                                <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
                            </svg>
                        </div>
                        <div class="kpi-label">Audio Purchases</div>
                        <div class="kpi-value" id="kpi-audio_purchases"
                            style="background: var(--gradient-warning); -webkit-background-clip: text; background-clip: text;">
                            0</div>
                    </div>
                </div>

                <h2 class="section-title">Performance Metrics</h2>

                <!-- CHARTS -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">

                    <!-- Sales Chart Skeleton -->
                    <div id="sales-skeleton" class="glass-card p-6">
                        <div class="skeleton skeleton-text" style="width: 180px;"></div>
                        <div class="skeleton" style="height: 320px; width: 100%; margin-top: 16px;"></div>
                    </div>

                    <!-- Sales Chart -->
                    <div id="sales-real" class="glass-card p-6 hidden chart-container">
                        <h3 style="font-size: 1.25rem; font-weight: 700; color: #1e293b; margin-bottom: 1.5rem;">Daily
                            Sales (Shopify)</h3>
                        <div style="position: relative; height: 320px;">
                            <canvas id="salesChart"></canvas>
                        </div>
                    </div>

                    <!-- Product Chart Skeleton -->
                    <div id="products-skeleton" class="glass-card p-6">
                        <div class="skeleton skeleton-text" style="width: 160px;"></div>
                        <div class="skeleton" style="height: 320px; width: 100%; margin-top: 16px;"></div>
                    </div>

                    <!-- Product Chart -->
                    <div id="products-real" class="glass-card p-6 hidden chart-container">
                        <h3 style="font-size: 1.25rem; font-weight: 700; color: #1e293b; margin-bottom: 1.5rem;">Top
                            Products</h3>
                        <div style="position: relative; height: 320px;">
                            <canvas id="productsChart"></canvas>
                        </div>
                    </div>

                </div>

                <h2 class="section-title">Activity Insights</h2>

                <!-- Activity Skeleton -->
                <div id="activity-skeleton" class="glass-card p-6 pulse">
                    <div class="skeleton skeleton-text" style="width: 180px;"></div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6" style="margin-top: 1.5rem;">
                        <div>
                            <div class="skeleton skeleton-text" style="width: 140px;"></div>
                            <div class="skeleton skeleton-text" style="width: 180px; margin-top: 12px;"></div>
                            <div class="skeleton skeleton-text" style="width: 160px;"></div>
                            <div class="skeleton skeleton-text" style="width: 140px;"></div>
                        </div>
                        <div>
                            <div class="skeleton skeleton-text" style="width: 140px;"></div>
                            <div class="skeleton skeleton-text" style="width: 180px; margin-top: 12px;"></div>
                            <div class="skeleton skeleton-text" style="width: 160px;"></div>
                            <div class="skeleton skeleton-text" style="width: 140px;"></div>
                        </div>
                    </div>
                </div>

                <!-- Activity Real -->
                <div id="activity-real" class="glass-card p-6 hidden chart-container">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                        <div>
                            <h3
                                style="font-size: 1.125rem; font-weight: 700; color: #334155; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="2" style="color: #667eea;">
                                    <circle cx="11" cy="11" r="8"></circle>
                                    <path d="m21 21-4.35-4.35"></path>
                                </svg>
                                Top Searches
                            </h3>
                            <ul id="top-searches" class="activity-list"></ul>
                        </div>
                        <div>
                            <h3
                                style="font-size: 1.125rem; font-weight: 700; color: #334155; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="2" style="color: #f5576c;">
                                    <path
                                        d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z">
                                    </path>
                                </svg>
                                Wishlist Trends
                            </h3>
                            <ul id="wishlist-trends" class="activity-list"></ul>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
@endsection

@section('custom_js_scripts')
    <script>
        let from = null;
        let to = null;
        let salesChartInstance = null;
        let productsChartInstance = null;

        // Litepicker with custom styling
        const picker = new Litepicker({
            element: document.getElementById('dateRange'),
            singleMode: false,
            format: 'YYYY-MM-DD',
            numberOfMonths: 2,
            numberOfColumns: 2,
            autoApply: true,
            setup: picker => {
                picker.on('selected', (date1, date2) => {
                    from = date1.format('YYYY-MM-DD');
                    to = date2.format('YYYY-MM-DD');
                    loadDashboard();
                });
            }
        });

        // Safe show/hide helpers
        function hideEl(id) {
            const el = document.getElementById(id);
            if (!el) return;
            el.classList.add('hidden');
            el.style.display = 'none';
        }

        function showEl(id, display = 'block') {
            const el = document.getElementById(id);
            if (!el) return;
            el.classList.remove('hidden');
            el.style.display = display;
        }

        // Animated number counter
        function animateValue(element, start, end, duration) {
            const startTime = performance.now();
            const step = (currentTime) => {
                const elapsed = currentTime - startTime;
                const progress = Math.min(elapsed / duration, 1);
                const value = Math.floor(progress * (end - start) + start);
                element.textContent = value.toLocaleString();
                if (progress < 1) {
                    requestAnimationFrame(step);
                }
            };
            requestAnimationFrame(step);
        }

        async function loadDashboard() {
            const qs = from && to ? `?from=${from}&to=${to}` : '';

            try {
                // Show skeletons
                showEl('kpi-skeletons', 'grid');
                hideEl('kpi-real');
                showEl('sales-skeleton', 'block');
                hideEl('sales-real');
                showEl('products-skeleton', 'block');
                hideEl('products-real');
                showEl('activity-skeleton', 'block');
                hideEl('activity-real');

                // Fetch KPIs
                const kpi = await fetch("{{ route('admin.analytics.dashboard') }}" + qs).then(r => r.json());
                const d = kpi.data;

                // Animate KPIs
                setTimeout(() => {
                    const downloadsEl = document.getElementById('kpi-downloads');
                    if (downloadsEl) animateValue(downloadsEl, 0, d.downloads ?? 0, 1000);

                    const bookingsEl = document.getElementById('kpi-bookings');
                    if (bookingsEl) animateValue(bookingsEl, 0, d.bookings ?? 0, 1000);

                    const audioPurchasesEl = document.getElementById('kpi-audio_purchases');
                    if (audioPurchasesEl) animateValue(audioPurchasesEl, 0, d.audio_purchases ?? 0, 1000);
                }, 300);

                // Sales Chart
                const sales = await fetch("{{ route('admin.analytics.sales.timeseries') }}" + qs).then(r => r.json());
                renderSalesChart(sales.data || []);

                // Top Products
                const top = await fetch("{{ route('admin.analytics.top.products') }}" + qs).then(r => r.json());
                renderTopProducts(top.data || []);

                // Activity
                const activity = await fetch("{{ route('admin.analytics.activity') }}" + qs).then(r => r.json());
                renderActivity(activity.data || {
                    top_searches: [],
                    wishlist_trends: []
                });

                // Show real UI with delay for smooth transition
                setTimeout(() => {
                    hideEl('kpi-skeletons');
                    showEl('kpi-real', 'grid');
                    hideEl('sales-skeleton');
                    showEl('sales-real', 'block');
                    hideEl('products-skeleton');
                    showEl('products-real', 'block');
                    hideEl('activity-skeleton');
                    showEl('activity-real', 'block');
                }, 400);

            } catch (e) {
                console.error('Dashboard load failed', e);
            }
        }

        function renderSalesChart(data) {
            const ctx = document.getElementById('salesChart').getContext('2d');

            if (salesChartInstance) {
                salesChartInstance.destroy();
            }

            // Create gradient
            const gradient = ctx.createLinearGradient(0, 0, 0, 320);
            gradient.addColorStop(0, "rgba(102, 126, 234, 0.3)");
            gradient.addColorStop(1, "rgba(118, 75, 162, 0.05)");

            const labels = data.map(r => r.date);
            const values = data.map(r => r.sales);

            salesChartInstance = new Chart(ctx, {
                type: 'line',
                data: {
                    labels,
                    datasets: [{
                        label: 'Sales',
                        data: values,
                        borderColor: "#667eea",
                        backgroundColor: gradient,
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4,
                        pointRadius: 0,
                        pointHoverRadius: 8,
                        pointHoverBackgroundColor: "#667eea",
                        pointHoverBorderColor: "#fff",
                        pointHoverBorderWidth: 3
                    }]
                },
                options: {
                    animation: {
                        duration: 2000,
                        easing: 'easeInOutQuart'
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            backgroundColor: "rgba(30, 41, 59, 0.95)",
                            titleColor: "#fff",
                            bodyColor: "#cbd5e1",
                            padding: 16,
                            borderColor: "rgba(102, 126, 234, 0.5)",
                            borderWidth: 2,
                            displayColors: false,
                            titleFont: {
                                size: 14,
                                weight: '600'
                            },
                            bodyFont: {
                                size: 13
                            },
                            callbacks: {
                                label: function(context) {
                                    return 'Sales: $' + context.parsed.y.toLocaleString();
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                color: "#94a3b8",
                                font: {
                                    size: 11,
                                    weight: '500'
                                }
                            }
                        },
                        y: {
                            beginAtZero: true,
                            ticks: {
                                color: "#94a3b8",
                                font: {
                                    size: 11,
                                    weight: '500'
                                },
                                callback: function(value) {
                                    return '$' + value.toLocaleString();
                                }
                            },
                            grid: {
                                color: "rgba(148, 163, 184, 0.08)",
                                drawBorder: false
                            }
                        }
                    },
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        intersect: false,
                        mode: 'index'
                    }
                }
            });
        }

        function renderTopProducts(data) {
            const ctx = document.getElementById('productsChart').getContext('2d');

            if (productsChartInstance) {
                productsChartInstance.destroy();
            }

            const labels = data.map(p => p.title);
            const values = data.map(p => p.sales);

            // Create gradient for bars
            const gradient = ctx.createLinearGradient(0, 0, 0, 320);
            gradient.addColorStop(0, "#667eea");
            gradient.addColorStop(1, "#764ba2");

            productsChartInstance = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels,
                    datasets: [{
                        label: 'Sales',
                        data: values,
                        backgroundColor: gradient,
                        borderRadius: 8,
                        borderSkipped: false
                    }]
                },
                options: {
                    animation: {
                        duration: 2000,
                        easing: 'easeInOutQuart'
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            backgroundColor: "rgba(30, 41, 59, 0.95)",
                            titleColor: "#fff",
                            bodyColor: "#cbd5e1",
                            padding: 16,
                            borderColor: "rgba(102, 126, 234, 0.5)",
                            borderWidth: 2,
                            displayColors: false,
                            titleFont: {
                                size: 14,
                                weight: '600'
                            },
                            bodyFont: {
                                size: 13
                            },
                            callbacks: {
                                label: function(context) {
                                    return 'Sales: ' + context.parsed.y.toLocaleString();
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                color: "#94a3b8",
                                font: {
                                    size: 11,
                                    weight: '500'
                                }
                            }
                        },
                        y: {
                            beginAtZero: true,
                            ticks: {
                                color: "#94a3b8",
                                font: {
                                    size: 11,
                                    weight: '500'
                                }
                            },
                            grid: {
                                color: "rgba(148, 163, 184, 0.08)",
                                drawBorder: false
                            }
                        }
                    },
                    responsive: true,
                    maintainAspectRatio: false
                }
            });
        }

        function renderActivity(data) {
            const searchesHtml = (data.top_searches || []).length > 0 ?
                (data.top_searches || []).map(s => `<li>${s.query} <strong>(${s.total})</strong></li>`).join('') :
                '<li style="color: #94a3b8; font-style: italic;">No search data available</li>';

            const wishlistHtml = (data.wishlist_trends || []).length > 0 ?
                (data.wishlist_trends || []).map(w => `<li>Product #${w.product_id} <strong>(${w.total})</strong></li>`)
                .join('') :
                '<li style="color: #94a3b8; font-style: italic;">No wishlist data available</li>';

            document.getElementById('top-searches').innerHTML = searchesHtml;
            document.getElementById('wishlist-trends').innerHTML = wishlistHtml;
        }

        // Initial load
        loadDashboard();
    </script>
@endsection
