@extends('admin.layouts.app')

@section('content')
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Edit Audio: {{ $audio->title }}</h2>
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

        <form action="{{ route('audios.update', $audio) }}" method="POST" enctype="multipart/form-data"
            class="card p-4 shadow-sm">
            @csrf
            @method('PUT')

            @include('admin.audios._form', ['audio' => $audio])

            {{-- ✅ Show current HLS conversion status --}}
            {{-- <div class="mt-3">
                <h6 class="text-muted">Conversion Status:</h6>
                @if ($audio->is_hls_ready)
                    <span class="badge bg-success">Ready</span>
                @else
                    <span class="badge bg-warning text-dark">Processing or Not Converted</span>
                @endif
            </div> --}}

            {{-- ✅ Show current file and optional player preview --}}
            {{-- @if (!empty($audio->file_path))
                @php
                    $filePath = $audio->file_path;
                    $exists = \Illuminate\Support\Facades\Storage::disk('private')->exists($filePath);
                    $audioUrl = $exists ? route('audio.stream', $audio->id) : null;
                @endphp

                <div class="mt-3">
                    <h6 class="text-muted">Current File:</h6>
                    <p class="small text-secondary mb-1">{{ basename($audio->file_path) }}</p>

                    @if ($audioUrl)
                        <audio controls style="width: 100%; max-width: 400px;">
                            <source src="{{ $audioUrl }}" type="audio/mpeg">
                            Your browser does not support the audio element.
                        </audio>
                    @else
                        <div class="text-danger small mt-2">File not found in private storage.</div>
                    @endif
                </div>
            @endif --}}

            {{-- ✅ Submit button --}}
            {{-- <div class="mt-4">
                <button type="submit" class="btn btn-primary">
                    Update Audio
                </button>
            </div> --}}
        </form>
    </div>
@endsection
