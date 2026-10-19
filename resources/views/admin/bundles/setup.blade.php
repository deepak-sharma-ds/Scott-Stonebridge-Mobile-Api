@extends('admin.layouts.app')
@section('title', 'Bundle App Setup')
@section('content')

@php $d = $diagnostics; @endphp

<h4 class="fw-bold py-3 mb-2">
    <span class="text-muted fw-light">Bundles /</span> App Setup
</h4>

<div class="row g-4">
    <div class="col-xl-7">
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Shopify connection</h5>
                @if($d['connected'])
                    <span class="badge bg-label-success"><i class="bx bx-check me-1"></i>Connected</span>
                @elseif($d['configured'])
                    <span class="badge bg-label-danger"><i class="bx bx-x me-1"></i>Not connected</span>
                @else
                    <span class="badge bg-label-warning"><i class="bx bx-cog me-1"></i>Not configured</span>
                @endif
            </div>
            <ul class="list-group list-group-flush">
                <li class="list-group-item d-flex justify-content-between"><span>Store</span><strong>{{ $d['shop_domain'] ?: '—' }}</strong></li>
                <li class="list-group-item d-flex justify-content-between"><span>Token source</span><span>{{ $d['token_source'] ?? '—' }}</span></li>
                <li class="list-group-item d-flex justify-content-between"><span>Admin API version</span><code>{{ $d['api_version'] }}</code></li>
                @if($d['shop'])
                <li class="list-group-item d-flex justify-content-between"><span>Shop name</span><span>{{ $d['shop']['name'] }}</span></li>
                <li class="list-group-item d-flex justify-content-between">
                    <span>Plan</span>
                    <span>
                        {{ $d['shop']['plan']['displayName'] ?? '—' }}
                        @if($d['shop']['plan']['partnerDevelopment'] ?? false)<span class="badge bg-label-info ms-1">Development store</span>@endif
                        @if($d['shop']['plan']['shopifyPlus'] ?? false)<span class="badge bg-label-primary ms-1">Plus</span>@endif
                    </span>
                </li>
                <li class="list-group-item d-flex justify-content-between"><span>Currency</span><span>{{ $d['shop']['currencyCode'] }}</span></li>
                @endif
            </ul>
            @if($d['error'])
            <div class="card-body">
                <div class="alert alert-danger mb-0 small">{{ $d['error'] }}</div>
            </div>
            @endif
        </div>

        @if($d['connected'])
        <div class="card mb-4">
            <div class="card-header"><h5 class="mb-0">Access scopes</h5></div>
            <div class="card-body">
                @foreach(\App\Services\Bundles\BundleSetupService::REQUIRED_SCOPES as $scope)
                    @if(in_array($scope, $d['scopes']))
                        <span class="badge bg-label-success mb-1"><i class="bx bx-check"></i> {{ $scope }}</span>
                    @else
                        <span class="badge bg-label-danger mb-1"><i class="bx bx-x"></i> {{ $scope }}</span>
                    @endif
                @endforeach
                @if($d['missing_scopes'])
                    <div class="alert alert-warning small mt-3 mb-0">
                        Add the missing scopes to <code>shopify-bundle-app/shopify.app.toml</code>, run <code>shopify app deploy</code>, then approve the update on the store.
                    </div>
                @endif
                <div class="mt-3 small text-muted">
                    Online Store publication:
                    @if($d['publication_id'])
                        <code>{{ $d['publication_id'] }}</code>
                    @else
                        <span class="text-danger">not found — active bundles won't be published. Set BUNDLE_PUBLICATION_ID.</span>
                    @endif
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h5 class="mb-0">Cart transform <small class="text-muted">(only if Discovery shows it is needed)</small></h5></div>
            <div class="card-body small">
                <p class="mb-2">
                    Fixed bundles are expanded into their component SKUs by Shopify. If testing shows the store also needs the
                    app's cart transform function registered, deploy the <code>bundle-cart-transform</code> extension and register it here.
                    Custom apps with Shopify Functions only run on <strong>Shopify Plus</strong> (development stores are exempt).
                </p>
                @if(! in_array('read_cart_transforms', $d['scopes']))
                    <div class="text-muted">The app does not have the <code>read_cart_transforms</code> / <code>write_cart_transforms</code> scopes, so this check is skipped.</div>
                @else
                    <div class="mb-2">Registered on store:
                        @forelse($d['cart_transforms'] as $ct)
                            <code>{{ $ct['id'] }}</code>
                        @empty
                            <span class="text-muted">none</span>
                        @endforelse
                    </div>
                    @can('manage bundle')
                    @if(! $d['cart_transforms'] && $d['functions'])
                    <form method="POST" action="{{ route('admin.bundles.setup.cart-transform') }}" class="d-flex gap-2">
                        @csrf
                        <select name="function_id" class="form-select form-select-sm">
                            @foreach($d['functions'] as $fn)
                                <option value="{{ $fn['id'] }}">{{ $fn['title'] }}</option>
                            @endforeach
                        </select>
                        <button class="btn btn-sm btn-primary text-nowrap">Register</button>
                    </form>
                    @elseif(! $d['functions'])
                        <div class="text-muted">No cart transform function deployed for this app yet.</div>
                    @endif
                    @endcan
                @endif
            </div>
        </div>
        @endif
    </div>

    <div class="col-xl-5">
        <div class="card mb-4">
            <div class="card-header"><h5 class="mb-0">Configuration (.env)</h5></div>
            <div class="card-body small">
<pre class="bg-lighter p-3 rounded mb-3" style="white-space: pre-wrap">BUNDLE_SHOP_DOMAIN=your-dev-store.myshopify.com
BUNDLE_APP_CLIENT_ID=
BUNDLE_APP_CLIENT_SECRET=
# or a static token instead of client credentials:
# BUNDLE_ADMIN_ACCESS_TOKEN=
BUNDLE_SHOPIFY_API_VERSION=2026-07
BUNDLE_MAP_ENFORCE=true
BUNDLE_MAP_REQUIRE_ALL=false</pre>
                <p class="mb-0 text-muted">Run <code>php artisan config:clear</code> after changing these values.</p>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h5 class="mb-0">Webhooks</h5></div>
            <div class="card-body small">
                <p class="mb-2">Set this URI in <code>shopify.app.toml</code> for <code>products/update</code> and <code>products/delete</code>:</p>
                <div class="input-group input-group-sm">
                    <input type="text" class="form-control" value="{{ $webhookUrl }}" readonly id="webhook-url">
                    <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('webhook-url').value)"><i class="bx bx-copy"></i></button>
                </div>
                <p class="text-muted mt-2 mb-0">Must be publicly reachable over HTTPS (use a tunnel such as ngrok or Cloudflare Tunnel when testing locally).</p>
            </div>
        </div>
    </div>
</div>
@endsection
