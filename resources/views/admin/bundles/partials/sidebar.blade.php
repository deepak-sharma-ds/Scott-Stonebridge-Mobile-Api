{{-- Product Bundles menu — included from admin/layouts/sidebar.blade.php --}}
@can('view bundle')
@php $bundleRoute = Route::currentRouteName() ?? ''; @endphp
<li class="menu-header small text-uppercase">
    <span class="menu-header-text">Product Bundles</span>
</li>
<li class="menu-item {{ str_starts_with($bundleRoute, 'admin.bundles.') ? 'active open' : '' }}">
    <a href="javascript:void(0);" class="menu-link menu-toggle">
        <i class="menu-icon icon-base bx bx-package"></i>
        <div class="text-truncate" data-i18n="Bundles">Bundles</div>
    </a>
    <ul class="menu-sub">
        <li class="menu-item">
            <a href="https://admin.shopify.com/store/{{ str_replace('.myshopify.com', '', config('bundles.shop_domain')) }}/apps/scott-bundles" target="_blank" rel="noopener" class="menu-link">
                <div class="text-truncate">Bundle funnels <i class="bx bx-link-external small"></i></div>
            </a>
        </li>
        <li class="menu-item {{ $bundleRoute === 'admin.bundles.map-prices.index' ? 'active' : '' }}">
            <a href="{{ route('admin.bundles.map-prices.index') }}" class="menu-link">
                <div class="text-truncate">MAP Prices</div>
            </a>
        </li>
        <li class="menu-item {{ $bundleRoute === 'admin.bundles.logs' ? 'active' : '' }}">
            <a href="{{ route('admin.bundles.logs') }}" class="menu-link">
                <div class="text-truncate">Sync Logs</div>
            </a>
        </li>
        <li class="menu-item {{ $bundleRoute === 'admin.bundles.setup' ? 'active' : '' }}">
            <a href="{{ route('admin.bundles.setup') }}" class="menu-link">
                <div class="text-truncate">App Setup</div>
            </a>
        </li>
    </ul>
</li>
@endcan
