@extends('admin.layouts.app')
@section('title', 'MAP Prices')
@section('content')

<h4 class="fw-bold py-3 mb-2">
    <span class="text-muted fw-light">Bundles /</span> MAP Prices
</h4>

<div class="alert alert-info d-flex align-items-start mb-4">
    <i class="bx bx-info-circle bx-sm me-2 mt-1"></i>
    <div>
        A bundle's price must be at least the sum of <strong>MAP × quantity</strong> of its components.
        @if(config('bundles.map.enforce'))
            Bundles priced below this floor cannot be saved.
        @else
            MAP is currently advisory (BUNDLE_MAP_ENFORCE=false).
        @endif
        @unless(config('bundles.map.require_all_skus'))
            SKUs without a MAP price produce a warning only.
        @endunless
    </div>
</div>

<div class="row g-4">
    <div class="col-xl-8">
        <div class="card">
            <div class="card-header">
                <form method="GET" class="d-flex gap-2">
                    <input type="text" name="q" value="{{ request('q') }}" class="form-control" placeholder="Search SKU...">
                    <button class="btn btn-outline-primary"><i class="bx bx-search"></i></button>
                </form>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th class="text-end">MAP price</th>
                            <th>Notes</th>
                            <th>Updated</th>
                            @can('manage bundle')<th></th>@endcan
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($prices as $price)
                        <tr>
                            <td><code>{{ $price->sku }}</code></td>
                            <td class="text-end fw-semibold">${{ number_format($price->map_price, 2) }}</td>
                            <td class="small text-muted">{{ $price->notes }}</td>
                            <td class="small text-muted">{{ $price->updated_at->format('M j, Y') }}</td>
                            @can('manage bundle')
                            <td class="text-end">
                                <div class="d-inline-flex gap-1">
                                    <button type="button" class="btn btn-sm btn-icon btn-outline-primary js-edit-map" title="Edit"
                                        data-id="{{ $price->id }}" data-sku="{{ $price->sku }}"
                                        data-price="{{ number_format($price->map_price, 2, '.', '') }}" data-notes="{{ $price->notes }}">
                                        <i class="bx bx-edit-alt"></i>
                                    </button>
                                    <form method="POST" action="{{ route('admin.bundles.map-prices.destroy', $price) }}" class="js-confirm-delete">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-sm btn-icon btn-outline-danger" title="Remove"><i class="bx bx-trash"></i></button>
                                    </form>
                                </div>
                            </td>
                            @endcan
                        </tr>
                        @empty
                        <tr><td colspan="5" class="text-center text-muted py-4">No MAP prices on file yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($prices->hasPages())
            <div class="card-footer">{{ $prices->links() }}</div>
            @endif
        </div>
    </div>

    @can('manage bundle')
    <div class="col-xl-4">
        <div class="card mb-4" id="map-form-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0" id="map-form-title">Add MAP</h5>
                <button type="button" class="btn btn-sm btn-label-secondary d-none" id="map-form-cancel">Cancel edit</button>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.bundles.map-prices.store') }}" id="map-form">
                    @csrf
                    <input type="hidden" name="_method" value="POST" id="map-form-method">
                    <input type="hidden" name="editing_id" value="{{ old('editing_id') }}" id="map-form-editing">
                    <div class="mb-3">
                        <label class="form-label" for="sku">SKU <span class="text-danger">*</span></label>
                        <input type="text" id="sku" name="sku" class="form-control" value="{{ old('sku') }}" required maxlength="255">
                        @error('sku') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="map_price">MAP price <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="number" id="map_price" name="map_price" step="0.01" min="0" class="form-control" value="{{ old('map_price') }}" required>
                        </div>
                        @error('map_price') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="notes">Notes</label>
                        <input type="text" id="notes" name="notes" class="form-control" value="{{ old('notes') }}" maxlength="255">
                    </div>
                    <button class="btn btn-primary w-100" id="map-form-submit"><i class="bx bx-save me-1"></i> Save</button>
                    <div class="form-text mt-2" id="map-form-hint">Adding an SKU that already exists updates its MAP.</div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h5 class="mb-0">Import CSV</h5></div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.bundles.map-prices.import') }}" enctype="multipart/form-data">
                    @csrf
                    <input type="file" name="file" accept=".csv,text/csv" class="form-control mb-2" required>
                    @error('file') <div class="text-danger small mb-2">{{ $message }}</div> @enderror
                    <div class="form-text mb-3">Columns: <code>sku</code>, <code>map_price</code>, optional <code>notes</code>. Existing SKUs are updated.</div>
                    <button class="btn btn-outline-primary w-100"><i class="bx bx-upload me-1"></i> Import</button>
                </form>
            </div>
        </div>
    </div>
    @endcan
</div>
@endsection

@push('scripts')
<script>
    $('.js-confirm-delete').on('submit', function (e) {
        if (!confirm('Remove this MAP price?')) e.preventDefault();
    });

    // Edit: load the row into the side form and switch it to PUT.
    const storeUrl = @json(route('admin.bundles.map-prices.store'));
    const updateUrl = @json(route('admin.bundles.map-prices.update', '__ID__'));

    function editMode(id) {
        $('#map-form').attr('action', updateUrl.replace('__ID__', id));
        $('#map-form-method').val('PUT');
        $('#map-form-editing').val(id);
        $('#map-form-title').text('Edit MAP');
        $('#map-form-submit').html('<i class="bx bx-save me-1"></i> Update');
        $('#map-form-hint').text('Changing the SKU renames this entry.');
        $('#map-form-cancel').removeClass('d-none');
        $('#map-form-card').addClass('border border-primary');
    }

    function addMode() {
        $('#map-form').attr('action', storeUrl)[0].reset();
        $('#map-form-method').val('POST');
        $('#map-form-editing').val('');
        $('#sku, #map_price, #notes').val('');
        $('#map-form-title').text('Add MAP');
        $('#map-form-submit').html('<i class="bx bx-save me-1"></i> Save');
        $('#map-form-hint').text('Adding an SKU that already exists updates its MAP.');
        $('#map-form-cancel').addClass('d-none');
        $('#map-form-card').removeClass('border border-primary');
    }

    $('.js-edit-map').on('click', function () {
        const d = $(this).data();
        $('#sku').val(d.sku);
        $('#map_price').val(d.price);
        $('#notes').val(d.notes || '');
        editMode(d.id);
        $('#map-form-card')[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
        $('#map_price').trigger('focus').select();
    });

    $('#map-form-cancel').on('click', addMode);

    // Keep edit mode after a failed validation.
    if ($('#map-form-editing').val()) {
        editMode($('#map-form-editing').val());
    }
</script>
@endpush
