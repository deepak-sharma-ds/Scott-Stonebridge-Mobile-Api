@extends('admin.layouts.app')

@section('page-title', 'Reading Products')

@section('content')
<div class="container-fluid">

    @include('admin.components.page-header', [
        'title'    => 'Reading Products',
        'subtitle' => 'Email reading templates — questions, prompts and email settings',
        'action'   => '<a href="' . route('admin.email-reading-products.create') . '" class="btn btn-primary">+ New Product</a>',
    ])

    <div class="card p-4">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Shopify ID</th>
                        <th>Model</th>
                        <th class="text-center">Slots</th>
                        <th class="text-center">Readings</th>
                        <th class="text-center">Active</th>
                        <th class="text-end" style="width:200px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($products as $product)
                        <tr>
                            <td>
                                <div style="font-weight:600;color:var(--text-primary);">{{ $product->name }}</div>
                                <div style="font-size:0.75rem;color:var(--text-muted);">{{ $product->slug }}</div>
                            </td>
                            <td style="font-size:0.8125rem;color:var(--text-secondary);">{{ $product->shopify_product_id }}</td>
                            <td style="font-size:0.8125rem;color:var(--text-secondary);">{{ $product->model ?: 'default' }}</td>
                            <td class="text-center">
                                <x-admin.badge type="secondary">{{ count((array) $product->questions_schema) }}</x-admin.badge>
                            </td>
                            <td class="text-center">
                                <x-admin.badge type="primary">{{ $product->deliveries_count }}</x-admin.badge>
                            </td>
                            <td class="text-center">
                                <form action="{{ route('admin.email-reading-products.toggle', $product) }}" method="POST" style="display:inline;">
                                    @csrf
                                    <button type="submit" style="border:none;background:none;cursor:pointer;padding:0;">
                                        <x-admin.badge :type="$product->is_active ? 'success' : 'secondary'">
                                            {{ $product->is_active ? 'Active' : 'Inactive' }}
                                        </x-admin.badge>
                                    </button>
                                </form>
                            </td>
                            <td class="text-end">
                                <div style="display:inline-flex;gap:0.5rem;">
                                    <a href="{{ route('admin.email-reading-products.edit', $product) }}" class="btn btn-sm btn-warning">Edit</a>
                                    <form action="{{ route('admin.email-reading-products.destroy', $product) }}" method="POST" style="display:inline;">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-danger"
                                                data-confirm="Delete '{{ $product->name }}'? Products with existing readings cannot be deleted.">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">
                                @include('admin.components.empty-state', ['message' => 'No reading products yet.'])
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
