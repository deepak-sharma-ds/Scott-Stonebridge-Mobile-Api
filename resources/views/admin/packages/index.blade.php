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
                    <h4>Packages</h4>
                </div>
            </div>
            <div class="col-sm-6 p-0 justify-content-sm-end mt-2 mt-sm-0 d-flex">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
                    <li class="breadcrumb-item active">Package List</li>
                </ol>
            </div>
        </div>

        <div class="row">
            <div class="col-xl-12">
                <div class="card w-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <strong>Packages</strong>
                        <a href="{{ route('packages.create') }}" class="btn btn-primary">Add</a>
                    </div>
                    <div class="pe-4 ps-4 pt-2 pb-2">
                        <div class="table-responsive" style="overflow-x:auto;">
                            <table class="table table-bordered table-hover table-striped align-middle mb-0">
                                <thead class="text-secondary">
                                    <tr>
                                        <th>Cover</th>
                                        <th>Title</th>
                                        <th>Tag</th>
                                        <th>Price</th>
                                        <th>Currency</th>
                                        <th>Audios</th>
                                        <th>Created</th>
                                        <th width="150">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($packages as $pkg)
                                        <tr>
                                            <td>
                                                @if ($pkg->cover_image)
                                                    <img src="{{ asset('storage/' . $pkg->cover_image) }}" width="60"
                                                        class="rounded">
                                                @endif
                                            </td>
                                            <td>{{ $pkg->title }}</td>
                                            <td><span class="badge bg-info text-dark">{{ $pkg->shopify_tag ?? '-' }}</span>
                                            </td>
                                            <td>{{ $pkg->price }}</td>
                                            <td>{{ $pkg->currency }}</td>
                                            <td>{{ $pkg->audios()->count() }}</td>
                                            <td>{{ $pkg->created_at->format('d M Y') }}</td>
                                            <td>
                                                <a href="{{ route('packages.edit', $pkg) }}"
                                                    class="btn btn-sm btn-warning">Edit</a>
                                                <form action="{{ route('packages.destroy', $pkg) }}" method="POST"
                                                    class="d-inline">
                                                    @csrf @method('DELETE')
                                                    <button class="btn btn-sm btn-danger"
                                                        onclick="return confirm('Delete this package?')">Delete</button>
                                                </form>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="8" class="text-center text-muted">No packages found</td>
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
            {!! $packages->appends(request()->query())->links('pagination::bootstrap-5') !!}
        </div>
    </div>
@endsection
