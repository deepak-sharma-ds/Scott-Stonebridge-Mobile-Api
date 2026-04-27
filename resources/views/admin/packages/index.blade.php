@extends('admin.layouts.app')

@section('page-title', 'Packages')

@section('content')
<div class="container-fluid">

    {{-- Page Header --}}
    @include('admin.components.page-header', [
        'title'    => 'Packages',
        'subtitle' => 'Manage audio subscription packages',
        'action'   => '<a href="' . route('packages.create') . '" class="btn btn-primary">
                          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                              <line x1="12" y1="5" x2="12" y2="19"></line>
                              <line x1="5" y1="12" x2="19" y2="12"></line>
                          </svg>
                          Add Package
                      </a>',
    ])

    {{-- Error Alert --}}
    @if($errors->has('error'))
        <div class="alert alert-danger alert-dismissible">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" style="flex-shrink:0;margin-top:1px;">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="15" y1="9"  x2="9"  y2="15"></line>
                <line x1="9"  y1="9"  x2="15" y2="15"></line>
            </svg>
            <span>{{ $errors->first('error') }}</span>
            <button type="button" class="btn-close"
                    onclick="this.closest('.alert').remove()">&times;</button>
        </div>
    @endif

    {{-- Packages Table --}}
    <div class="card p-4">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th class="text-center" style="width:72px;">Cover</th>
                        <th>Package</th>
                        <th class="text-end">Pricing</th>
                        <th class="text-center">Audios</th>
                        <th>Created</th>
                        <th class="text-end" style="width:160px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($packages as $pkg)
                        <tr>
                            {{-- Cover Image --}}
                            <td class="text-center">
                                @if($pkg->cover_image)
                                    <div style="width:56px;height:56px;border-radius:10px;
                                                overflow:hidden;box-shadow:var(--shadow-sm);">
                                        <img src="{{ asset('storage/' . $pkg->cover_image) }}"
                                             style="width:100%;height:100%;object-fit:cover;"
                                             alt="{{ $pkg->title }}">
                                    </div>
                                @else
                                    <div class="avatar avatar-lg">
                                        {{ strtoupper(substr($pkg->title, 0, 1)) }}
                                    </div>
                                @endif
                            </td>

                            {{-- Package Details --}}
                            <td>
                                <div style="font-weight:600;color:var(--text-primary);
                                            font-size:0.9375rem;margin-bottom:0.25rem;">
                                    {{ $pkg->title }}
                                </div>
                                @if($pkg->shopify_tag)
                                    <x-admin.badge type="primary">{{ $pkg->shopify_tag }}</x-admin.badge>
                                @endif
                            </td>

                            {{-- Pricing --}}
                            <td class="text-end">
                                <div style="font-weight:700;color:var(--color-success);font-size:1.0625rem;">
                                    {{ $pkg->price }}
                                </div>
                                <div style="font-size:0.6875rem;color:var(--text-muted);
                                            text-transform:uppercase;margin-top:0.125rem;">
                                    {{ $pkg->currency }}
                                </div>
                            </td>

                            {{-- Audio Count --}}
                            <td class="text-center">
                                <x-admin.badge type="secondary">
                                    {{ $pkg->audios()->count() }}
                                </x-admin.badge>
                            </td>

                            {{-- Created Date --}}
                            <td style="color:var(--text-secondary);font-weight:500;">
                                {{ $pkg->created_at->format('d M Y') }}
                            </td>

                            {{-- Actions --}}
                            <td class="text-end">
                                <div style="display:inline-flex;gap:0.5rem;">
                                    <a href="{{ route('packages.edit', $pkg) }}"
                                       class="btn btn-sm btn-warning">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                                             stroke="currentColor" stroke-width="2">
                                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                        </svg>
                                        Edit
                                    </a>

                                    <form action="{{ route('packages.destroy', $pkg) }}"
                                          method="POST"
                                          style="display:inline;">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit"
                                                class="btn btn-sm btn-danger"
                                                data-confirm="Delete '{{ $pkg->title }}'? This cannot be undone.">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                                                 stroke="currentColor" stroke-width="2">
                                                <polyline points="3 6 5 6 21 6"></polyline>
                                                <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
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
                                    'message' => 'No packages yet. Create your first package to get started.',
                                    'icon'    => '<svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                                      <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
                                                  </svg>',
                                ])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Pagination --}}
        @if($packages->hasPages())
            <div style="margin-top:1.25rem;">
                {!! $packages->appends(request()->query())->links('pagination::bootstrap-5') !!}
            </div>
        @endif
    </div>

</div>
@endsection
