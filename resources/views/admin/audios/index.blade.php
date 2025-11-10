@extends('admin.layouts.app')

@section('content')
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Audios</h2>
            <a href="{{ route('audios.create') }}" class="btn btn-success">+ Add Audio</a>
        </div>

        {{-- @if (session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif --}}

        <table class="table table-bordered align-middle shadow-sm">
            <thead class="table-light">
                <tr>
                    <th>Package</th>
                    <th>Title</th>
                    <th>File</th>
                    <th>Duration (s)</th>
                    <th>Order</th>
                    <th>Created</th>
                    <th width="150">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($audios as $audio)
                    <tr>
                        <td>{{ $audio->package->title ?? '—' }}</td>
                        <td>{{ $audio->title }}</td>
                        {{-- <td class="text-truncate" style="max-width:180px;">
                            {{ basename($audio->file_path) }}
                        </td> --}}
                        <td style="max-width:220px;">
                            @if ($audio->id)
                                <audio controls preload="none" style="width: 200px; height: 30px;">
                                    <source src="{{ route('audio.stream', $audio->id) }}" type="audio/mpeg"
                                        style="width: 180px; height: 28px; display:block; margin:auto;">
                                    Your browser does not support the audio element.
                                </audio>
                            @else
                                <span class="text-muted">No file</span>
                            @endif
                        </td>

                        <td>{{ $audio->duration_seconds ?? '-' }}</td>
                        <td>{{ $audio->order_index }}</td>
                        <td>{{ $audio->created_at->format('d M Y') }}</td>
                        <td>
                            <a href="{{ route('audios.edit', $audio) }}" class="btn btn-sm btn-warning">Edit</a>
                            <form action="{{ route('audios.destroy', $audio) }}" method="POST" class="d-inline">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-danger"
                                    onclick="return confirm('Delete this audio?')">Delete</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted">No audios found</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div class="mt-3">
            {{ $audios->links() }}
        </div>
    </div>
@endsection
