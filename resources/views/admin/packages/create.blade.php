@extends('admin.layouts.app')

@section('content')
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Add New Package</h2>
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

        <form action="{{ route('packages.store') }}" method="POST" enctype="multipart/form-data" class="card p-4 shadow-sm">
            @include('admin.packages._form')
        </form>
    </div>
@endsection
