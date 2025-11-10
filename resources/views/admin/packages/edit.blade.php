@extends('admin.layouts.app')

@section('content')
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Edit Package: {{ $package->title }}</h2>
            <a href="{{ route('packages.index') }}" class="btn btn-outline-primary">← Back to list</a>
        </div>

        @if ($errors->any())
            <div class="alert alert-danger">
                <strong>Oops!</strong> Please fix the following errors:
                <ul class="mb-0 mt-2">
                    @foreach ($errors->all() as $err)
                        <li>{{ $err }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form action="{{ route('packages.update', $package) }}" method="POST" enctype="multipart/form-data"
            class="card p-4 shadow-sm">
            @method('PUT')
            @include('admin.packages._form', ['package' => $package])
        </form>
    </div>
@endsection
