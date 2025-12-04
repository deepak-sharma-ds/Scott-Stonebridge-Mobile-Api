@extends('admin.layouts.app')

@section('content')
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- In your <head> or layout -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/litepicker/dist/css/litepicker.css" />
    <script src="https://cdn.jsdelivr.net/npm/litepicker/dist/bundle.js"></script>
    <div class="container-fluid">
        <div class="row page-titles mx-0 mb-3">
            <div class="col-sm-6 p-0">
                <div class="welcome-text">
                    <h4>Dashboard</h4>
                </div>
            </div>
        </div>

        <div class="container mx-auto p-6">
            <h1 class="text-2xl font-bold mb-4">Analytics Dashboard</h1>
            <div class="grid grid-cols-4 gap-4" id="kpi-cards">
                <div class="card p-4 shadow">
                    <div class="text-sm">Downloads</div>
                    <div id="downloads" class="text-2xl font-semibold">—</div>
                </div>
                <div class="card p-4 shadow">
                    <div class="text-sm">Active Users</div>
                    <div id="active_users" class="text-2xl font-semibold">—</div>
                </div>
                <div class="card p-4 shadow">
                    <div class="text-sm">Orders (Shopify)</div>
                    <div id="orders" class="text-2xl font-semibold">—</div>
                </div>
                <div class="card p-4 shadow">
                    <div class="text-sm">Sales (Shopify)</div>
                    <div id="sales" class="text-2xl font-semibold">—</div>
                </div>
            </div>

            <div class="mt-8">
                <h2 class="text-lg font-medium">Top Products</h2>
                <div id="top-products" class="mt-4"></div>
            </div>
        </div>
    </div>
@endsection

@section('custom_js_scripts')
    <script>
        async function loadDashboard() {
            const res = await fetch("{{ route('admin.analytics.dashboard') }}");
            const json = await res.json();
            const k = json.data;
            document.getElementById('downloads').innerText = k.downloads.local ?? 0;
            document.getElementById('active_users').innerText = k.active_users.local ?? 0;
            document.getElementById('orders').innerText = k.orders.shopify ?? 0;
            document.getElementById('sales').innerText = k.sales.shopify ?? 0;

            const topRes = await fetch("{{ route('admin.analytics.top.products') }}");
            const top = await topRes.json();
            const el = document.getElementById('top-products');
            el.innerHTML = '<pre style="white-space:pre-wrap">' + JSON.stringify(top.data, null, 2) + '</pre>';
        }
        console.log('dgdgdf');

        loadDashboard();
    </script>
@endsection
