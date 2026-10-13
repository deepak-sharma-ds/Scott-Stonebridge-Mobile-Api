@extends('admin.layouts.app')

@section('page-title', 'Edit Reading Product')

@section('content')
<div class="container-fluid">

    @include('admin.components.page-header', [
        'title'    => 'Edit: ' . $product->name,
        'subtitle' => 'Update questions, prompt template and email settings',
        'action'   => '<a href="' . route('admin.email-reading-products.index') . '" class="btn btn-secondary">← Back</a>',
    ])

    @if($errors->any())
        <div class="alert alert-danger">
            <ul style="margin:0;padding-left:1.25rem;">
                @foreach($errors->all() as $err)<li>{{ $err }}</li>@endforeach
            </ul>
        </div>
    @endif

    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(460px, 1fr));gap:1.5rem;align-items:start;">
        <form action="{{ route('admin.email-reading-products.update', $product) }}" method="POST" enctype="multipart/form-data" class="card p-4">
            @include('admin.email_reading_products._form', ['product' => $product])
        </form>

        @include('admin.components.email-preview', [
            'previewUrl' => route('admin.email-reading-products.preview', $product),
            'sendTestUrl' => route('admin.email-reading-products.send-test', $product),
            'editable' => true,
            'context' => 'reading_product',
            'title' => 'Email Template Preview',
        ])
    </div>

</div>
@endsection
