@extends('admin.layouts.app')
@section('title', $bundle->exists ? 'Edit Bundle' : 'Create Bundle')
@section('content')

@php
    $rows = old('components', $components);
@endphp

<div class="d-flex justify-content-between align-items-center py-3 mb-2">
    <h4 class="fw-bold mb-0">
        <span class="text-muted fw-light">Bundles /</span> {{ $bundle->exists ? 'Edit: ' . $bundle->title : 'Create Bundle' }}
    </h4>
    <a href="{{ $bundle->exists ? route('admin.bundles.show', $bundle) : route('admin.bundles.index') }}" class="btn btn-secondary btn-sm">
        <i class="bx bx-arrow-back me-1"></i> Back
    </a>
</div>

@if($errors->any())
<div class="alert alert-danger">
    <h6 class="alert-heading mb-1"><i class="bx bx-error-circle me-1"></i> The bundle could not be saved</h6>
    <ul class="mb-0 ps-3">
        @foreach($errors->all() as $error)
            <li>{{ $error }}</li>
        @endforeach
    </ul>
</div>
@endif

<form method="POST" action="{{ $bundle->exists ? route('admin.bundles.update', $bundle) : route('admin.bundles.store') }}" id="bundle-form" enctype="multipart/form-data">
    @csrf
    @if($bundle->exists) @method('PUT') @endif

    <div class="row g-4">
        <div class="col-xl-8">
            <div class="card mb-4">
                <div class="card-header"><h5 class="mb-0">Bundle details</h5></div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label" for="title">Bundle title <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="title" name="title" value="{{ old('title', $bundle->title) }}" required maxlength="255" placeholder="e.g. Scott Reading Card Kit">
                        <div class="form-text">Shown to customers as the product name.</div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label" for="description">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="4" placeholder="Optional. HTML is allowed.">{{ old('description', $bundle->description) }}</textarea>
                        <div class="form-text">Leave empty to manage the description directly in Shopify.</div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label" for="image-picker">Bundle images</label>
                        <input type="hidden" name="images_present" value="1">
                        <div id="image-list" class="d-flex flex-wrap gap-2 mb-2">
                            @foreach($bundle->images ?? [] as $image)
                                <div class="bundle-image border rounded p-1 text-center" data-token="id:{{ $image->id }}">
                                    <img src="{{ $image->url }}" alt="" class="rounded" style="width:96px;height:96px;object-fit:contain;background:#fff">
                                    <div class="small image-badge"></div>
                                    <div class="btn-group btn-group-sm">
                                        <button type="button" class="btn btn-outline-secondary img-left" title="Move left"><i class="bx bx-chevron-left"></i></button>
                                        <button type="button" class="btn btn-outline-secondary img-right" title="Move right"><i class="bx bx-chevron-right"></i></button>
                                        <button type="button" class="btn btn-outline-danger img-remove" title="Remove"><i class="bx bx-x"></i></button>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <input type="file" class="form-control" id="image-picker" accept="image/jpeg,image/png,image/gif,image/webp" multiple>
                        <input type="file" name="images[]" id="image-files" multiple hidden>
                        <div class="form-text">Up to {{ \App\Services\Bundles\BundleImageService::MAX_IMAGES }} images, JPEG, PNG, GIF or WebP up to 10 MB each. The first image is the main image. Saving syncs them to Shopify. Without uploaded images, the component product images are used.</div>
                        @error('images') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                        @error('images.*') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Components</h5>
                    <small class="text-muted">Customers cannot change these products or quantities.</small>
                </div>
                <div class="card-body">
                    <label class="form-label" for="variant-picker">Add a product</label>
                    <select id="variant-picker" class="form-select" style="width: 100%"><option></option></select>
                    <div class="form-text mb-3">Search by product title or SKU. Each variant can be added once; use the quantity for multiples.</div>

                    <div class="table-responsive">
                        <table class="table align-middle" id="components-table">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>SKU</th>
                                    <th class="text-end">Unit price</th>
                                    <th style="width: 110px">Qty</th>
                                    <th class="text-end">Line total</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr class="empty-row {{ $rows ? 'd-none' : '' }}">
                                    <td colspan="6" class="text-center text-muted py-4">No components yet. Add at least two items.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            @php
                $offerPdp = (bool) old('offer_on_product_page', $bundle->offer_on_product_page ?? true);
                $offerCart = (bool) old('offer_in_cart', $bundle->offer_in_cart ?? true);
                $offerComponents = (bool) old('offer_include_components', $bundle->offer_include_components ?? false);
                $offerProducts = collect($bundle->offer_extra_products ?? []);
                if (old('offer_products')) {
                    $offerProducts = collect(old('offer_products'))->map(fn ($id) => ['id' => $id, 'title' => $offerProducts->firstWhere('id', $id)['title'] ?? $id]);
                }
            @endphp
            <div class="card mt-4">
                <div class="card-header">
                    <h5 class="mb-0">Storefront offer</h5>
                    <small class="text-muted">Promote this bundle to customers who view or add its products. Only live (active) bundles are shown.</small>
                </div>
                <div class="card-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <input type="hidden" name="offer_on_product_page" value="0">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="offer_on_product_page" name="offer_on_product_page" value="1" @checked($offerPdp)>
                                <label class="form-check-label" for="offer_on_product_page"><strong>Product page</strong> — show the bundle offer on the product detail page</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <input type="hidden" name="offer_in_cart" value="0">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="offer_in_cart" name="offer_in_cart" value="1" @checked($offerCart)>
                                <label class="form-check-label" for="offer_in_cart"><strong>Cart popup</strong> — offer the bundle when its product is in the cart</label>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="offer_heading">Offer heading</label>
                        <input type="text" class="form-control" id="offer_heading" name="offer_heading" maxlength="150"
                            value="{{ old('offer_heading', $bundle->offer_heading) }}" placeholder="Save even more with this bundle">
                        <div class="form-text">Optional. Leave empty to use the heading set on the theme block.</div>
                    </div>

                    <label class="form-label" for="offer-products">Show this offer on <span class="text-danger">*</span></label>
                    <select id="offer-products" name="offer_products[]" class="form-select" multiple style="width: 100%">
                        @foreach($offerProducts as $p)
                            <option value="{{ $p['id'] }}" selected>{{ $p['title'] }}</option>
                        @endforeach
                    </select>
                    <div class="form-text">The offer appears only on these products' pages, and in the cart popup when one of them is in the cart.</div>

                    <input type="hidden" name="offer_include_components" value="0">
                    <div class="form-check form-switch mt-3">
                        <input class="form-check-input" type="checkbox" id="offer_include_components" name="offer_include_components" value="1" @checked($offerComponents)>
                        <label class="form-check-label" for="offer_include_components">Also show on this bundle's own component products</label>
                    </div>
                    <div class="alert alert-warning small py-2 mt-3 mb-0 {{ $offerProducts->isNotEmpty() || $offerComponents ? 'd-none' : '' }}" id="offer-none-warning">
                        No products selected — the offer won't appear anywhere on the storefront.
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card mb-4 position-sticky" style="top: 90px">
                <div class="card-header"><h5 class="mb-0">Pricing</h5></div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label" for="price">Bundle price (USD) <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="number" class="form-control" id="price" name="price" step="0.01" min="0.01" value="{{ old('price', $bundle->price) }}" required>
                        </div>
                        <div class="form-text">PKB-approved price. Must comply with MAP.</div>
                    </div>

                    <ul class="list-group list-group-flush mb-3">
                        <li class="list-group-item d-flex justify-content-between px-0">
                            <span>Components total</span><strong id="sum-total">$0.00</strong>
                        </li>
                        <li class="list-group-item d-flex justify-content-between px-0">
                            <span>Customer savings</span><strong id="sum-savings" class="text-success">$0.00</strong>
                        </li>
                        <li class="list-group-item d-flex justify-content-between px-0">
                            <span>MAP floor</span><strong id="sum-map" class="text-muted">Validate to check</strong>
                        </li>
                    </ul>

                    <div id="validation-result" class="mb-3"></div>

                    <button type="button" class="btn btn-outline-info w-100 mb-2" id="btn-validate">
                        <i class="bx bx-check-shield me-1"></i> Validate pricing &amp; stock
                    </button>
                    <button type="submit" name="action" value="save" class="btn btn-primary w-100 mb-2">
                        <i class="bx bx-save me-1"></i> {{ $bundle->exists ? 'Save changes' : 'Save as draft' }}
                    </button>
                    @if(! $bundle->exists || ! $bundle->isActive())
                    <button type="submit" name="action" value="activate" class="btn btn-success w-100">
                        <i class="bx bx-play-circle me-1"></i> Save &amp; activate
                    </button>
                    @endif
                    <div class="form-text mt-2">
                        Saving syncs the bundle to Shopify. Draft bundles are not visible on the storefront.
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>
@endsection

@push('scripts')
<script>
// Runs on DOM ready so it initialises after the layout's global `$('.select2')` pass.
// Otherwise that pass re-initialises this picker's `.select2` container span.
$(function () {
    const $table = $('#components-table tbody');
    const initialRows = @json(array_values($rows));
    let rowIndex = 0;

    const money = (n) => '$' + (Number(n) || 0).toFixed(2);
    const esc = (s) => $('<div>').text(s ?? '').html();

    function addRow(item) {
        const existing = $table.find('input[data-variant="' + item.variant_id + '"]');
        if (existing.length) {
            const $qty = existing.closest('tr').find('.qty-input');
            $qty.val(Math.min(99, parseInt($qty.val() || 0, 10) + 1));
            recalc();
            return;
        }

        const i = rowIndex++;
        const img = item.image
            ? '<img src="' + esc(item.image) + '" class="rounded me-2" width="40" height="40" style="object-fit:cover">'
            : '<span class="avatar-initial rounded bg-label-secondary me-2 d-inline-flex align-items-center justify-content-center" style="width:40px;height:40px"><i class="bx bx-image"></i></span>';

        const $row = $(
            '<tr class="component-row">' +
                '<td><div class="d-flex align-items-center">' + img + '<span>' + esc(item.label) + '</span></div></td>' +
                '<td>' + (item.sku ? '<code>' + esc(item.sku) + '</code>' : '<span class="badge bg-label-danger">No SKU</span>') + '</td>' +
                '<td class="text-end">' + money(item.price) + '</td>' +
                '<td><input type="number" class="form-control form-control-sm qty-input" min="1" max="99" required></td>' +
                '<td class="text-end line-total"></td>' +
                '<td class="text-end"><button type="button" class="btn btn-sm btn-icon btn-outline-danger remove-row" title="Remove"><i class="bx bx-trash"></i></button></td>' +
            '</tr>'
        );

        const base = 'components[' + i + ']';
        $row.find('.qty-input').attr('name', base + '[quantity]').val(item.quantity || 1);
        $row.append(
            $('<input type="hidden">').attr({ name: base + '[variant_id]', 'data-variant': item.variant_id }).val(item.variant_id),
            $('<input type="hidden">').attr('name', base + '[label]').val(item.label),
            $('<input type="hidden">').attr('name', base + '[sku]').val(item.sku || ''),
            $('<input type="hidden">').attr('name', base + '[price]').val(item.price),
            $('<input type="hidden">').attr('name', base + '[image]').val(item.image || '')
        );
        $row.data('price', Number(item.price) || 0);

        $table.append($row);
        recalc();
    }

    function recalc() {
        let total = 0;
        $table.find('.component-row').each(function () {
            const qty = parseInt($(this).find('.qty-input').val() || 0, 10);
            const line = $(this).data('price') * qty;
            $(this).find('.line-total').text(money(line));
            total += line;
        });

        const price = parseFloat($('#price').val() || 0);
        const savings = total - price;
        $('#sum-total').text(money(total));
        $('#sum-savings')
            .text(savings > 0 ? money(savings) + ' (' + (savings / total * 100).toFixed(1) + '%)' : '$0.00')
            .toggleClass('text-danger', price > total && total > 0)
            .toggleClass('text-success', !(price > total && total > 0));
        $('.empty-row').toggleClass('d-none', $table.find('.component-row').length > 0);
    }

    function collect() {
        const components = [];
        $table.find('.component-row').each(function () {
            components.push({
                variant_id: $(this).find('input[data-variant]').val(),
                quantity: parseInt($(this).find('.qty-input').val() || 1, 10),
            });
        });
        return components;
    }

    // Component picker (searches Shopify live)
    $('#variant-picker').select2({
        placeholder: 'Search products or SKUs…',
        minimumInputLength: 0,
        ajax: {
            url: @json(route('admin.bundles.search-variants')),
            dataType: 'json',
            delay: 300,
            data: (params) => ({ q: params.term || '' }),
            processResults: (data) => ({ results: data.results || [] }),
            error: (xhr) => {
                if (xhr.statusText === 'abort') return;
                Swal.fire({ icon: 'error', title: 'Shopify search failed', text: xhr.responseJSON?.error || xhr.statusText, toast: true, position: 'top-end', showConfirmButton: false, timer: 5000 });
            },
        },
        templateResult: (item) => {
            if (!item.id) return item.text;
            const img = item.image ? '<img src="' + esc(item.image) + '" class="rounded me-2" width="32" height="32" style="object-fit:cover">' : '';
            const stock = item.inventory === null ? '' : ' · ' + item.inventory + ' in stock';
            const status = item.status !== 'ACTIVE' ? ' · <span class="text-warning">' + esc(item.status) + '</span>' : '';
            return $('<div class="d-flex align-items-center">' + img + '<div><div>' + esc(item.text) + '</div><small class="text-muted">' +
                (item.sku ? 'SKU ' + esc(item.sku) : 'No SKU') + ' · ' + money(item.price) + stock + status + '</small></div></div>');
        },
    }).on('select2:select', function (e) {
        const d = e.params.data;
        addRow({ variant_id: d.id, label: d.text, sku: d.sku, price: d.price, image: d.image, quantity: 1 });
        $(this).val(null).trigger('change');
    });

    // Extra products that also show the offer
    $('#offer-products').select2({
        placeholder: 'Search products to also show this offer on…',
        ajax: {
            url: @json(route('admin.bundles.search-products')),
            dataType: 'json',
            delay: 300,
            data: (params) => ({ q: params.term || '' }),
            processResults: (data) => ({ results: data.results || [] }),
        },
        templateResult: (item) => {
            if (!item.id || !item.image) return item.text;
            return $('<div class="d-flex align-items-center"><img src="' + esc(item.image) + '" class="rounded me-2" width="28" height="28" style="object-fit:cover"><span>' + esc(item.text) + '</span></div>');
        },
    });

    $('#offer-products, #offer_include_components').on('change', function () {
        const none = !($('#offer-products').val() || []).length && !$('#offer_include_components').is(':checked');
        $('#offer-none-warning').toggleClass('d-none', !none);
    });

    $table.on('click', '.remove-row', function () { $(this).closest('tr').remove(); recalc(); });
    $table.on('input change', '.qty-input', recalc);
    $('#price').on('input', recalc);

    // Server-side validation against live Shopify data + MAP table
    $('#btn-validate').on('click', function () {
        const components = collect();
        const $out = $('#validation-result');
        if (!components.length) {
            $out.html('<div class="alert alert-warning py-2 mb-0">Add components first.</div>');
            return;
        }

        const $btn = $(this).prop('disabled', true);
        $out.html('<div class="text-center"><span class="spinner-border spinner-border-sm"></span></div>');

        $.ajax({
            url: @json(route('admin.bundles.preview')),
            method: 'POST',
            data: { _token: @json(csrf_token()), price: $('#price').val(), components },
        }).done(function (res) {
            $('#sum-map').text(res.map_floor === null ? 'No MAP on file' : money(res.map_floor));
            let html = '';
            if (res.errors.length) {
                html += '<div class="alert alert-danger py-2 mb-2"><ul class="mb-0 ps-3">' + res.errors.map(e => '<li>' + esc(e) + '</li>').join('') + '</ul></div>';
            }
            if (res.warnings.length) {
                html += '<div class="alert alert-warning py-2 mb-2"><ul class="mb-0 ps-3">' + res.warnings.map(e => '<li>' + esc(e) + '</li>').join('') + '</ul></div>';
            }
            if (!res.errors.length && !res.warnings.length) {
                html = '<div class="alert alert-success py-2 mb-0"><i class="bx bx-check me-1"></i> Pricing, MAP and components look good.</div>';
            }
            $out.html(html);
        }).fail(function (xhr) {
            $out.html('<div class="alert alert-danger py-2 mb-0">' + esc(xhr.responseJSON?.message || 'Validation failed.') + '</div>');
        }).always(() => $btn.prop('disabled', false));
    });

    initialRows.forEach(addRow);
    recalc();

    // --- Bundle images: add several, reorder, remove -------------------------
    const MAX_IMAGES = {{ \App\Services\Bundles\BundleImageService::MAX_IMAGES }};
    const $images = $('#image-list');

    function refreshImages() {
        $images.children().each(function (i) {
            $(this).toggleClass('border-primary', i === 0)
                .find('.image-badge').html(i === 0 ? '<span class="badge bg-label-primary">Main</span>' : (this.zbFile ? '<span class="badge bg-label-warning">New</span>' : '&nbsp;'));
            $(this).find('.img-left').prop('disabled', i === 0);
            $(this).find('.img-right').prop('disabled', i === $images.children().length - 1);
        });
        $('#image-picker').prop('disabled', $images.children().length >= MAX_IMAGES);
    }

    function imageTile(src) {
        return $('<div class="bundle-image border rounded p-1 text-center">' +
            '<img alt="" class="rounded" style="width:96px;height:96px;object-fit:contain;background:#fff">' +
            '<div class="small image-badge"></div>' +
            '<div class="btn-group btn-group-sm">' +
            '<button type="button" class="btn btn-outline-secondary img-left" title="Move left"><i class="bx bx-chevron-left"></i></button>' +
            '<button type="button" class="btn btn-outline-secondary img-right" title="Move right"><i class="bx bx-chevron-right"></i></button>' +
            '<button type="button" class="btn btn-outline-danger img-remove" title="Remove"><i class="bx bx-x"></i></button>' +
            '</div></div>').find('img').attr('src', src).end();
    }

    $('#image-picker').on('change', function () {
        Array.from(this.files).forEach(function (file) {
            if ($images.children().length >= MAX_IMAGES) return;
            if (file.size > 10 * 1024 * 1024) {
                Swal.fire({ icon: 'error', title: file.name + ' is larger than 10 MB', toast: true, position: 'top-end', showConfirmButton: false, timer: 4000 });
                return;
            }
            const $tile = imageTile(URL.createObjectURL(file));
            $tile[0].zbFile = file;
            $images.append($tile);
        });
        this.value = '';
        refreshImages();
    });

    $images.on('click', '.img-left', function () { const $t = $(this).closest('.bundle-image'); $t.prev().before($t); refreshImages(); });
    $images.on('click', '.img-right', function () { const $t = $(this).closest('.bundle-image'); $t.next().after($t); refreshImages(); });
    $images.on('click', '.img-remove', function () { $(this).closest('.bundle-image').remove(); refreshImages(); });

    // On submit: final order + new files
    $('#bundle-form').on('submit', function () {
        $(this).find('input[name="image_order[]"]').remove();
        const transfer = new DataTransfer();
        $images.children().each(function () {
            let token = this.dataset.token;
            if (this.zbFile) {
                token = 'new:' + transfer.items.length;
                transfer.items.add(this.zbFile);
            }
            $('<input type="hidden" name="image_order[]">').val(token).appendTo('#bundle-form');
        });
        document.getElementById('image-files').files = transfer.files;
    });

    refreshImages();
});
</script>
@endpush
