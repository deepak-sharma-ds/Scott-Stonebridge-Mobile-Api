@extends('admin.layouts.app')

@section('page-title', 'Sync Reading Products from Shopify')

@section('content')
<div class="container-fluid" x-data="readingCatalogSync(
        @js(route('admin.email-reading-products.sync.collections')),
        @js(route('admin.email-reading-products.sync.products')),
    )" x-init="loadCollections()">

    @include('admin.components.page-header', [
        'title'    => 'Sync Reading Products from Shopify',
        'subtitle' => 'Pick collections, then pick the specific products to register — never type a Shopify ID by hand',
        'action'   => '<a href="' . route('admin.email-reading-products.index') . '" class="btn btn-secondary">← Back to Reading Products</a>',
    ])

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <form method="POST" action="{{ route('admin.email-reading-products.sync.store') }}" @submit="return checked.length > 0">
        @csrf

        <div class="card p-4" style="margin-bottom:1rem;">
            <div class="mb-3">
                <label class="form-label">1. Collections</label>
                <div x-show="collectionsLoading" style="color:var(--text-muted);font-size:0.875rem;">Loading collections…</div>
                <div x-show="collectionsError" x-text="collectionsError" class="alert alert-danger" style="margin:0;"></div>
                <div x-show="!collectionsLoading && !collectionsError && collections.length === 0" style="color:var(--text-muted);font-size:0.875rem;">
                    No collections found in Shopify.
                </div>
                <div style="display:flex;flex-wrap:wrap;gap:0.5rem;">
                    <template x-for="c in collections" :key="c.id">
                        <label style="display:flex;align-items:center;gap:0.375rem;border:1px solid var(--card-border);border-radius:8px;padding:0.375rem 0.75rem;cursor:pointer;font-size:0.875rem;">
                            <input type="checkbox" :value="c.id" x-model.number="selectedCollectionIds" @change="loadProducts()">
                            <span x-text="c.title"></span>
                        </label>
                    </template>
                </div>
            </div>
        </div>

        <div class="card p-4" style="margin-bottom:1rem;">
            <div class="mb-3">
                <label class="form-label">2. Products</label>
                <div x-show="selectedCollectionIds.length === 0" style="color:var(--text-muted);font-size:0.875rem;">
                    Select one or more collections above to see their products.
                </div>
                <div x-show="productsLoading" style="color:var(--text-muted);font-size:0.875rem;">Loading products…</div>
                <div x-show="productsError" x-text="productsError" class="alert alert-danger" style="margin:0;"></div>
                <div x-show="!productsLoading && selectedCollectionIds.length > 0 && !productsError && products.length === 0" style="color:var(--text-muted);font-size:0.875rem;">
                    No products found in the selected collection(s).
                </div>

                <div x-show="products.length > 0" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.5rem;padding-bottom:0.5rem;border-bottom:1px solid var(--card-border);">
                    <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;font-size:0.875rem;font-weight:600;">
                        <input type="checkbox" :checked="products.length > 0 && checked.length === products.length"
                            @change="toggleSelectAll($event.target.checked)">
                        <span>Select All (<span x-text="products.length"></span>)</span>
                    </label>
                    <span style="font-size:0.8125rem;color:var(--text-muted);" x-text="checked.length + ' selected'"></span>
                </div>

                <div style="display:flex;flex-direction:column;gap:0.375rem;max-height:420px;overflow-y:auto;">
                    <template x-for="p in products" :key="p.id">
                        <label style="display:flex;align-items:center;gap:0.625rem;border:1px solid var(--card-border);border-radius:8px;padding:0.5rem 0.75rem;cursor:pointer;">
                            <input type="checkbox" :value="p.id" x-model.number="checked" name="shopify_product_ids[]">
                            <img :src="p.image_url" x-show="p.image_url" style="width:32px;height:32px;object-fit:cover;border-radius:6px;">
                            <span style="font-weight:600;color:var(--text-primary);" x-text="p.title"></span>
                            <span style="font-size:0.75rem;color:var(--text-muted);" x-text="'#' + p.id"></span>
                        </label>
                    </template>
                </div>
            </div>
        </div>

        <div class="mt-2" style="display:flex;align-items:center;gap:0.75rem;">
            <button type="submit" class="btn btn-success" :disabled="checked.length === 0" x-text="syncButtonLabel()"></button>
            <a href="{{ route('admin.email-reading-products.index') }}" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
</div>

<script>
    function readingCatalogSync(collectionsUrl, productsUrl) {
        return {
            collections: [],
            collectionsLoading: false,
            collectionsError: '',
            selectedCollectionIds: [],
            products: [],
            productsLoading: false,
            productsError: '',
            checked: [],
            async loadCollections() {
                this.collectionsLoading = true;
                this.collectionsError = '';
                try {
                    const res = await fetch(collectionsUrl, {headers: {'Accept': 'application/json'}});
                    const data = await res.json();
                    if (!res.ok) throw new Error(data.message || 'Failed to load collections.');
                    this.collections = data.collections || [];
                } catch (e) {
                    this.collectionsError = e.message;
                } finally {
                    this.collectionsLoading = false;
                }
            },
            async loadProducts() {
                this.products = [];
                this.checked = [];
                if (this.selectedCollectionIds.length === 0) return;
                this.productsLoading = true;
                this.productsError = '';
                try {
                    const params = new URLSearchParams();
                    this.selectedCollectionIds.forEach(id => params.append('collection_ids[]', id));
                    const res = await fetch(productsUrl + '?' + params.toString(), {headers: {'Accept': 'application/json'}});
                    const data = await res.json();
                    if (!res.ok) throw new Error(data.message || 'Failed to load products.');
                    this.products = data.products || [];
                } catch (e) {
                    this.productsError = e.message;
                } finally {
                    this.productsLoading = false;
                }
            },
            toggleSelectAll(selectAll) {
                this.checked = selectAll ? this.products.map(p => p.id) : [];
            },
            syncButtonLabel() {
                const n = this.checked.length;
                return 'Sync ' + n + ' Selected Product' + (n === 1 ? '' : 's');
            },
        };
    }
</script>
@endsection
