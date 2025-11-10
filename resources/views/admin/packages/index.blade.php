@extends('admin.layouts.app')

@section('content')
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Packages</h2>
            <a href="{{ route('packages.create') }}" class="btn btn-success">+ Add Package</a>
        </div>

        {{-- @if (session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif --}}

        <table class="table table-bordered align-middle shadow-sm">
            <thead class="table-light">
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
                                <img src="{{ asset('storage/' . $pkg->cover_image) }}" width="60" class="rounded">
                            @endif
                        </td>
                        <td>{{ $pkg->title }}</td>
                        <td><span class="badge bg-info text-dark">{{ $pkg->shopify_tag ?? '-' }}</span></td>
                        <td>{{ $pkg->price }}</td>
                        <td>{{ $pkg->currency }}</td>
                        <td>{{ $pkg->audios()->count() }}</td>
                        <td>{{ $pkg->created_at->format('d M Y') }}</td>
                        <td>
                            <a href="{{ route('packages.edit', $pkg) }}" class="btn btn-sm btn-warning">Edit</a>
                            <form action="{{ route('packages.destroy', $pkg) }}" method="POST" class="d-inline">
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

        <div class="mt-3">
            {{ $packages->links() }}
        </div>
    </div>
@endsection
