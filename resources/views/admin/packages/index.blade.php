@extends('admin.layouts.app')

@section('content')
    <div class="container-fluid">

        {{-- Page Header --}}
        @include('admin.components.page-header', [
            'title' => 'Packages',
            'subtitle' => 'Manage audio subscription packages',
            'action' =>
                '<a href="' .
                route('packages.create') .
                '" class="btn btn-primary">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display: inline-block; vertical-align: middle; margin-right: 0.5rem;">
                                        <line x1="12" y1="5" x2="12" y2="19"></line>
                                        <line x1="5" y1="12" x2="19" y2="12"></line>
                                    </svg>
                                    Add Package
                                </a>',
        ])

        {{-- Alert Messages --}}
        @if ($errors->has('error'))
            <div class="alert alert-danger alert-dismissible fade show card" role="alert"
                style="border-left: 4px solid #ef4444;">
                <strong>Error:</strong> {{ $errors->first('error') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        {{-- Packages Table --}}
        <div class="card p-4">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width: 80px;">Cover</th>
                            <th>Package Details</th>
                            <th>Pricing</th>
                            <th style="text-align: center;">Audios</th>
                            <th>Created</th>
                            <th style="text-align: right; width: 180px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($packages as $pkg)
                            <tr>
                                <td>
                                    @if ($pkg->cover_image)
                                        <div
                                            style="width: 60px; height: 60px; border-radius: 12px; overflow: hidden; box-shadow: var(--shadow-sm);">
                                            <img src="{{ asset('storage/' . $pkg->cover_image) }}"
                                                style="width: 100%; height: 100%; object-fit: cover;"
                                                alt="{{ $pkg->title }}">
                                        </div>
                                    @else
                                        <div
                                            style="width: 60px; height: 60px; border-radius: 12px; background: var(--gradient-primary); display: flex; align-items: center; justify-content: center; color: white; font-weight: 700; font-size: 1.25rem;">
                                            {{ strtoupper(substr($pkg->title, 0, 1)) }}
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    <div style="font-weight: 600; color: #1e293b; font-size: 1rem; margin-bottom: 0.25rem;">
                                        {{ $pkg->title }}
                                    </div>
                                    @if ($pkg->shopify_tag)
                                        <span
                                            style="background: rgba(102, 126, 234, 0.1); color: var(--color-primary); padding: 0.125rem 0.5rem; border-radius: 8px; font-weight: 600; font-size: 0.75rem;">
                                            {{ $pkg->shopify_tag }}
                                        </span>
                                    @endif
                                </td>
                                <td>
                                    <div style="font-weight: 700; color: #10b981; font-size: 1.125rem;">
                                        {{ $pkg->price }}
                                    </div>
                                    <div
                                        style="font-size: 0.75rem; color: #94a3b8; text-transform: uppercase; margin-top: 0.125rem;">
                                        {{ $pkg->currency }}
                                    </div>
                                </td>
                                <td style="text-align: center;">
                                    <span
                                        style="background: rgba(245, 87, 108, 0.1); color: var(--color-accent); padding: 0.375rem 0.875rem; border-radius: 12px; font-weight: 700;">
                                        {{ $pkg->audios()->count() }}
                                    </span>
                                </td>
                                <td style="color: #64748b; font-weight: 500;">
                                    {{ $pkg->created_at->format('d M Y') }}
                                </td>
                                <td style="text-align: right;">
                                    <div style="display: inline-flex; gap: 0.5rem;">
                                        <a href="{{ route('packages.edit', $pkg) }}" class="btn btn-sm"
                                            style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: white; border: none; padding: 0.5rem 1rem; border-radius: 8px; font-weight: 600;">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                                                stroke="currentColor" stroke-width="2"
                                                style="display: inline-block; vertical-align: middle; margin-right: 0.25rem;">
                                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                            </svg>
                                            Edit
                                        </a>
                                        <form action="{{ route('packages.destroy', $pkg) }}" method="POST"
                                            style="display: inline;"
                                            onsubmit="return confirm('Are you sure you want to delete this package?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm"
                                                style="background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); color: white; border: none; padding: 0.5rem 1rem; border-radius: 8px; font-weight: 600;">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                                                    stroke="currentColor" stroke-width="2"
                                                    style="display: inline-block; vertical-align: middle; margin-right: 0.25rem;">
                                                    <polyline points="3 6 5 6 21 6"></polyline>
                                                    <path
                                                        d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2">
                                                    </path>
                                                </svg>
                                                Delete
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">
                                    @include('admin.components.empty-state', [
                                        'message' =>
                                            'No packages found. Create your first package to get started!',
                                        'icon' => '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin: 0 auto 1rem; opacity: 0.5;">
                                                                                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                                                                        <line x1="12" y1="8" x2="12" y2="16"></line>
                                                                                        <line x1="8" y1="12" x2="16" y2="12"></line>
                                                                                    </svg>',
                                    ])
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Pagination --}}
            @if ($packages->hasPages())
                <div style="margin-top: 1.5rem;">
                    {!! $packages->appends(request()->query())->links('pagination::bootstrap-5') !!}
                </div>
            @endif
        </div>

    </div>
@endsection
