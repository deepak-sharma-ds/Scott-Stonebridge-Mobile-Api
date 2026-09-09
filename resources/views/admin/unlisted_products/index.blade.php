@extends('admin.layouts.app')

@section('page-title', 'Unlisted Products')

@section('content')
<div class="container-fluid">

    @include('admin.components.page-header', [
        'title'    => 'Unlisted Products',
        'subtitle' => 'Shopify Unlisted-status products synced for the campaign product picker',
        'action'   => '<form action="' . route('admin.unlisted-products.sync') . '" method="POST" style="display:inline;">' . csrf_field() . '<button type="submit" class="btn btn-primary">↻ Sync Now</button></form>',
    ])

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="card p-4">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Shopify ID</th>
                        <th class="text-center">Variants</th>
                        <th class="text-center">Status</th>
                        <th class="text-center">Template</th>
                        <th>Last Synced</th>
                        <th class="text-end" style="width:120px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($products as $product)
                        <tr>
                            <td style="display:flex;align-items:center;gap:0.625rem;">
                                @if($product->shopify_image_url)
                                    <img src="{{ $product->shopify_image_url }}" alt=""
                                        style="width:32px;height:32px;object-fit:cover;border-radius:6px;">
                                @endif
                                <span style="font-weight:600;color:var(--text-primary);">{{ $product->title }}</span>
                            </td>
                            <td style="font-size:0.8125rem;color:var(--text-secondary);">{{ $product->shopify_product_id }}</td>
                            <td class="text-center">
                                <x-admin.badge type="secondary">{{ count($product->variants ?? []) }}</x-admin.badge>
                            </td>
                            <td class="text-center">
                                <x-admin.badge :type="$product->is_published ? 'success' : 'secondary'">
                                    {{ $product->is_published ? 'Published' : 'Unpublished' }}
                                </x-admin.badge>
                            </td>
                            <td class="text-center">
                                <x-admin.badge :type="$product->email_content || $product->header_image ? 'primary' : 'secondary'">
                                    {{ $product->email_content || $product->header_image ? 'Configured' : 'Not set' }}
                                </x-admin.badge>
                            </td>
                            <td style="font-size:0.8125rem;color:var(--text-secondary);">
                                {{ $product->last_synced_at?->diffForHumans() ?? '—' }}
                            </td>
                            <td class="text-end">
                                <a href="{{ route('admin.unlisted-products.edit', $product) }}" class="btn btn-sm btn-warning">Edit</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">
                                @include('admin.components.empty-state', ['message' => 'No unlisted products synced yet. Click "Sync Now" to pull them from Shopify.'])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($products->hasPages())
            <div style="margin-top:1.25rem;">
                {!! $products->links('pagination::bootstrap-5') !!}
            </div>
        @endif
    </div>

</div>
@endsection
