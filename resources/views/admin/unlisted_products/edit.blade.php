@extends('admin.layouts.app')

@section('page-title', 'Edit Unlisted Product')

@section('content')
<div class="container-fluid">

    @include('admin.components.page-header', [
        'title'    => $product->title,
        'subtitle' => 'Shopify ID: ' . $product->shopify_product_id,
        'action'   => '<a href="' . route('admin.unlisted-products.index') . '" class="btn btn-secondary">← Back</a>',
    ])

    @if($errors->any())
        <div class="alert alert-danger">
            <ul style="margin:0;padding-left:1.25rem;">
                @foreach($errors->all() as $err)<li>{{ $err }}</li>@endforeach
            </ul>
        </div>
    @endif

    <form action="{{ route('admin.unlisted-products.update', $product) }}" method="POST" enctype="multipart/form-data" class="card p-4">
        @include('admin.unlisted_products._form', ['product' => $product])
    </form>

</div>
@endsection
