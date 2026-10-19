@extends('admin.layouts.app')
@section('title', $bundle->title)
@section('content')

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 py-3 mb-2">
    <div>
        <h4 class="fw-bold mb-1">
            <span class="text-muted fw-light">Bundles /</span> {{ $bundle->title }}
        </h4>
        @include('admin.bundles.partials.status-badges')
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="{{ route('admin.bundles.index') }}" class="btn btn-secondary btn-sm"><i class="bx bx-arrow-back me-1"></i> All bundles</a>
        @if($bundle->shopify_admin_url)
            <a href="{{ $bundle->shopify_admin_url }}" target="_blank" rel="noopener" class="btn btn-outline-dark btn-sm"><i class="bx bxl-shopify me-1"></i> Open in Shopify</a>
        @endif
        @can('manage bundle')
            <a href="{{ route('admin.bundles.edit', $bundle) }}" class="btn btn-primary btn-sm"><i class="bx bx-edit-alt me-1"></i> Edit</a>
            @if($bundle->isActive())
                <form method="POST" action="{{ route('admin.bundles.pause', $bundle) }}">@csrf
                    <button class="btn btn-warning btn-sm"><i class="bx bx-pause me-1"></i> Pause</button>
                </form>
            @else
                <form method="POST" action="{{ route('admin.bundles.activate', $bundle) }}">@csrf
                    <button class="btn btn-success btn-sm"><i class="bx bx-play me-1"></i> Activate</button>
                </form>
            @endif
            <form method="POST" action="{{ route('admin.bundles.resync', $bundle) }}">@csrf
                <button class="btn btn-outline-info btn-sm" title="Refresh component data from Shopify and push again"><i class="bx bx-refresh me-1"></i> Sync to Shopify</button>
            </form>
            <form method="POST" action="{{ route('admin.bundles.destroy', $bundle) }}" id="delete-bundle-form">@csrf @method('DELETE')
                <button type="button" class="btn btn-outline-danger btn-sm" id="btn-delete-bundle"><i class="bx bx-trash me-1"></i> Delete</button>
            </form>
        @endcan
    </div>
</div>

@if($bundle->needs_review)
<div class="alert alert-danger d-flex justify-content-between align-items-start gap-3">
    <div>
        <h6 class="alert-heading mb-1"><i class="bx bx-error-circle me-1"></i> This bundle needs review</h6>
        <div>{{ $bundle->review_reason }}</div>
        <small>Check the components and price, then use <strong>Sync to Shopify</strong> to refresh.</small>
    </div>
    @can('manage bundle')
    <form method="POST" action="{{ route('admin.bundles.reviewed', $bundle) }}">@csrf
        <button class="btn btn-sm btn-outline-danger text-nowrap">Mark reviewed</button>
    </form>
    @endcan
</div>
@endif

@if($bundle->sync_status === 'failed' && $bundle->last_sync_error)
<div class="alert alert-warning">
    <h6 class="alert-heading mb-1"><i class="bx bx-sync me-1"></i> Last Shopify sync failed</h6>
    <div class="small">{{ $bundle->last_sync_error }}</div>
</div>
@endif

<div class="row g-4">
    <div class="col-xl-8">
        <div class="card mb-4">
            <div class="card-header"><h5 class="mb-0">Components <small class="text-muted">({{ $bundle->components->sum('quantity') }} items)</small></h5></div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>SKU (fulfillment)</th>
                            <th class="text-center">Qty</th>
                            <th class="text-end">Unit price</th>
                            <th class="text-end">MAP</th>
                            <th class="text-end">Line total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($bundle->components as $component)
                        <tr>
                            <td>
                                <div class="d-flex align-items-center">
                                    @if($component->image_url)
                                        <img src="{{ $component->image_url }}" class="rounded me-2" width="40" height="40" style="object-fit:cover" alt="">
                                    @endif
                                    <span>{{ $component->display_title }}</span>
                                </div>
                            </td>
                            <td><code>{{ $component->sku ?? '—' }}</code></td>
                            <td class="text-center">{{ $component->quantity }}</td>
                            <td class="text-end">${{ number_format($component->unit_price, 2) }}</td>
                            <td class="text-end text-muted">{{ $component->map_price !== null ? '$' . number_format($component->map_price, 2) : '—' }}</td>
                            <td class="text-end">${{ number_format($component->line_total, 2) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Sync history</h5>
                <a href="{{ route('admin.bundles.logs') }}" class="small">All logs</a>
            </div>
            <div class="table-responsive">
                @include('admin.bundles.partials.logs-table', ['logs' => $logs, 'showBundle' => false])
            </div>
        </div>
    </div>

    <div class="col-xl-4">
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Images</h5>
                @can('manage bundle')<a href="{{ route('admin.bundles.edit', $bundle) }}" class="small">Manage</a>@endcan
            </div>
            <div class="card-body">
                @forelse($bundle->images as $image)
                    <img src="{{ $image->url }}" alt="" class="rounded border me-1 mb-1" style="width:{{ $loop->first ? 96 : 64 }}px;height:{{ $loop->first ? 96 : 64 }}px;object-fit:contain;background:#fff">
                @empty
                    <span class="text-muted small">No uploaded images. The component product images are used.</span>
                @endforelse
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header"><h5 class="mb-0">Pricing</h5></div>
            <ul class="list-group list-group-flush">
                <li class="list-group-item d-flex justify-content-between"><span>Bundle price</span><strong>${{ number_format($bundle->price, 2) }}</strong></li>
                <li class="list-group-item d-flex justify-content-between"><span>Components total (compare at)</span><span>${{ number_format($bundle->compare_at_price, 2) }}</span></li>
                <li class="list-group-item d-flex justify-content-between"><span>Customer savings</span>
                    <span class="text-success">${{ number_format($bundle->savings_amount, 2) }} ({{ $bundle->savings_percent }}%)</span></li>
                <li class="list-group-item d-flex justify-content-between"><span>MAP floor</span>
                    @if($bundle->map_floor === null)
                        <span class="text-muted">No MAP on file</span>
                    @elseif((float) $bundle->price >= (float) $bundle->map_floor)
                        <span class="text-success">${{ number_format($bundle->map_floor, 2) }} <i class="bx bx-check"></i></span>
                    @else
                        <span class="text-danger">${{ number_format($bundle->map_floor, 2) }} <i class="bx bx-x"></i></span>
                    @endif
                </li>
            </ul>
        </div>

        <div class="card mb-4">
            <div class="card-header"><h5 class="mb-0">Storefront offer</h5></div>
            <ul class="list-group list-group-flush small">
                <li class="list-group-item d-flex justify-content-between">
                    <span>Product page</span>
                    {!! $bundle->offer_on_product_page ? '<span class="badge bg-label-success">On</span>' : '<span class="badge bg-label-secondary">Off</span>' !!}
                </li>
                <li class="list-group-item d-flex justify-content-between">
                    <span>Cart popup</span>
                    {!! $bundle->offer_in_cart ? '<span class="badge bg-label-success">On</span>' : '<span class="badge bg-label-secondary">Off</span>' !!}
                </li>
                <li class="list-group-item">
                    <div class="text-muted">Heading</div>{{ $bundle->offer_heading ?: 'Theme default' }}
                </li>
                <li class="list-group-item">
                    <div class="text-muted mb-1">Shown on</div>
                    @foreach($bundle->offer_extra_products ?? [] as $p)
                        <div>• {{ $p['title'] }}</div>
                    @endforeach
                    @if($bundle->offer_include_components)
                        @foreach($bundle->components->unique('shopify_product_id') as $c)
                            <div>• {{ $c->product_title }} <span class="text-muted">(component)</span></div>
                        @endforeach
                    @endif
                    @if(empty($bundle->offer_extra_products) && ! $bundle->offer_include_components)
                        <div class="text-muted">No products selected</div>
                    @endif
                    @unless($bundle->isActive())
                        <div class="text-warning mt-1">Not visible until the bundle is active.</div>
                    @endunless
                </li>
            </ul>
        </div>

        <div class="card">
            <div class="card-header"><h5 class="mb-0">Shopify</h5></div>
            <ul class="list-group list-group-flush small">
                <li class="list-group-item"><div class="text-muted">Store</div>{{ $bundle->shop_domain }}</li>
                <li class="list-group-item"><div class="text-muted">Product ID</div><code>{{ $bundle->shopify_product_id ?? 'Not created yet' }}</code></li>
                <li class="list-group-item"><div class="text-muted">Handle</div>{{ $bundle->shopify_handle ?? '—' }}</li>
                <li class="list-group-item"><div class="text-muted">Last synced</div>{{ $bundle->last_synced_at?->format('M j, Y g:i A') ?? 'Never' }}</li>
                <li class="list-group-item"><div class="text-muted">Created by</div>{{ $bundle->creator?->name ?? '—' }} · {{ $bundle->created_at->format('M j, Y') }}</li>
            </ul>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    $('#btn-delete-bundle').on('click', function () {
        Swal.fire({
            title: 'Delete this bundle?',
            text: 'The bundle product will be deleted from Shopify. Past orders are not affected.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Yes, delete it',
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('delete-bundle-form').submit();
            }
        });
    });
</script>
@endpush
