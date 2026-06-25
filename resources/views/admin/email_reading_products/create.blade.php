@extends('admin.layouts.app')

@section('page-title', 'New Reading Product')

@section('content')
<div class="container-fluid">

    @include('admin.components.page-header', [
        'title'    => 'New Reading Product',
        'subtitle' => 'Define a reading product: questions, prompt template and email settings',
        'action'   => '<a href="' . route('admin.email-reading-products.index') . '" class="btn btn-secondary">← Back</a>',
    ])

    @if($errors->any())
        <div class="alert alert-danger">
            <ul style="margin:0;padding-left:1.25rem;">
                @foreach($errors->all() as $err)<li>{{ $err }}</li>@endforeach
            </ul>
        </div>
    @endif

    <form action="{{ route('admin.email-reading-products.store') }}" method="POST" class="card p-4">
        @include('admin.email_reading_products._form')
    </form>

</div>
@endsection
