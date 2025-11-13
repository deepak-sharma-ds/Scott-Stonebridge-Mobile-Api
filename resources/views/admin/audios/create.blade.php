@extends('admin.layouts.app')

@section('content')
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Add New Audio</h2>
            <a href="{{ route('audios.index') }}" class="btn btn-outline-primary">← Back to list</a>
        </div>

        @if ($errors->any())
            <div class="alert alert-danger">
                <strong>Oops!</strong> Please fix the following:
                <ul class="mb-0 mt-2">
                    @foreach ($errors->all() as $err)
                        <li>{{ $err }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form action="{{ route('audios.store') }}" method="POST" enctype="multipart/form-data" class="card p-4 shadow-sm">
            @csrf

            {{-- Include shared form fields --}}
            @include('admin.audios._form', ['audio' => new \App\Models\Audio()])

            {{-- Conversion Status Placeholder --}}
            {{-- <div class="mt-3">
                <h6 class="text-muted mb-1">Conversion Status:</h6>
                <span class="badge bg-secondary">Pending Upload</span>
            </div> --}}
        </form>
    </div>
@endsection
