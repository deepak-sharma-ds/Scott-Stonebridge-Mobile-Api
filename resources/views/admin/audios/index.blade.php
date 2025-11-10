@extends('admin.layouts.app')

@section('content')
    <style>
        .table-responsive {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .table-responsive :is(td, th) {
            white-space: nowrap;
        }
    </style>
    <div class="container">
        @if ($errors->has('error'))
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                {{ $errors->first('error') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        <div class="row page-titles mx-0 mb-3">
            <div class="col-sm-6 p-0">
                <div class="welcome-text">
                    <h4>Audios</h4>
                </div>
            </div>
            <div class="col-sm-6 p-0 justify-content-sm-end mt-2 mt-sm-0 d-flex">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
                    <li class="breadcrumb-item active">Audios List</li>
                </ol>
            </div>
        </div>

        <div class="row">
            <div class="col-xl-12">
                <div class="card w-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <strong>Audios</strong>
                        <a href="{{ route('audios.create') }}" class="btn btn-primary">Add</a>
                    </div>
                    <div class="pe-4 ps-4 pt-2 pb-2">
                        <div class="table-responsive" style="overflow-x:auto;">
                            <table class="table table-bordered table-hover table-striped align-middle mb-0">
                                <thead class="text-secondary">
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
                                            <td style="max-width:220px;">
                                                @if ($audio->id)
                                                    <audio controls preload="none" style="width: 200px; height: 30px;">
                                                        <source src="{{ route('audio.stream', $audio->id) }}"
                                                            type="audio/mpeg"
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
                                                <a href="{{ route('audios.edit', $audio) }}"
                                                    class="btn btn-sm btn-warning">Edit</a>
                                                <form action="{{ route('audios.destroy', $audio) }}" method="POST"
                                                    class="d-inline">
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
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-1">
            {!! $audios->appends(request()->query())->links('pagination::bootstrap-5') !!}
        </div>
    </div>
@endsection
