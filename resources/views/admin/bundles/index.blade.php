@extends('admin.layouts.app')
@section('title', 'Product Bundles')
@section('content')

<div class="d-flex justify-content-between align-items-center py-3 mb-2">
    <h4 class="fw-bold mb-0">
        <span class="text-muted fw-light">Bundles /</span> Predefined Bundles
    </h4>
    @can('manage bundle')
    <a href="{{ route('admin.bundles.create') }}" class="btn btn-primary">
        <i class="bx bx-plus me-1"></i> Create Bundle
    </a>
    @endcan
</div>

<div class="row g-4 mb-4">
    @foreach(['active' => ['Live', 'success', 'bx-check-circle'], 'paused' => ['Paused', 'warning', 'bx-pause-circle'], 'draft' => ['Draft', 'secondary', 'bx-edit']] as $key => [$label, $color, $icon])
    <div class="col-sm-6 col-xl-3">
        <a href="{{ route('admin.bundles.index', ['status' => $key]) }}" class="card h-100 text-body">
            <div class="card-body d-flex align-items-center gap-3">
                <span class="avatar-initial rounded bg-label-{{ $color }} p-2"><i class="bx {{ $icon }} bx-md"></i></span>
                <div>
                    <h4 class="mb-0">{{ $counts[$key] ?? 0 }}</h4>
                    <small class="text-muted">{{ $label }}</small>
                </div>
            </div>
        </a>
    </div>
    @endforeach
    <div class="col-sm-6 col-xl-3">
        <a href="{{ route('admin.bundles.index', ['needs_review' => 1]) }}" class="card h-100 text-body">
            <div class="card-body d-flex align-items-center gap-3">
                <span class="avatar-initial rounded bg-label-danger p-2"><i class="bx bx-error-circle bx-md"></i></span>
                <div>
                    <h4 class="mb-0">{{ $reviewCount }}</h4>
                    <small class="text-muted">Needs review</small>
                </div>
            </div>
        </a>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-md-5">
                <input type="text" name="q" value="{{ request('q') }}" class="form-control" placeholder="Search bundle title...">
            </div>
            <div class="col-md-3">
                <select name="status" class="form-select">
                    <option value="">All statuses</option>
                    @foreach(['active' => 'Live', 'paused' => 'Paused', 'draft' => 'Draft'] as $value => $label)
                        <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button class="btn btn-outline-primary"><i class="bx bx-search me-1"></i> Filter</button>
                @if(request()->hasAny(['q', 'status', 'needs_review']))
                    <a href="{{ route('admin.bundles.index') }}" class="btn btn-label-secondary">Reset</a>
                @endif
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table table-hover border-top align-middle">
            <thead>
                <tr>
                    <th style="width: 60px">#</th>
                    <th>Bundle</th>
                    <th class="text-center">Items</th>
                    <th class="text-end">Bundle price</th>
                    <th class="text-end">Components total</th>
                    <th class="text-end">Savings</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($bundles as $bundle)
                <tr>
                    <td class="text-muted">{{ $bundles->firstItem() + $loop->index }}</td>
                    <td>
                        <a href="{{ route('admin.bundles.show', $bundle) }}" class="fw-semibold">{{ $bundle->title }}</a>
                        <div class="small text-muted">Updated {{ $bundle->updated_at->diffForHumans() }}</div>
                    </td>
                    <td class="text-center">{{ $bundle->components_count }}</td>
                    <td class="text-end fw-semibold">${{ number_format($bundle->price, 2) }}</td>
                    <td class="text-end text-muted">${{ number_format($bundle->compare_at_price, 2) }}</td>
                    <td class="text-end">
                        @if($bundle->savings_amount > 0)
                            <span class="text-success">${{ number_format($bundle->savings_amount, 2) }} ({{ $bundle->savings_percent }}%)</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td>@include('admin.bundles.partials.status-badges')</td>
                    <td class="text-end">
                        <div class="d-inline-flex gap-1">
                            <a href="{{ route('admin.bundles.show', $bundle) }}" class="btn btn-sm btn-icon btn-outline-secondary" title="View"><i class="bx bx-show"></i></a>
                            @can('manage bundle')
                            <a href="{{ route('admin.bundles.edit', $bundle) }}" class="btn btn-sm btn-icon btn-outline-primary" title="Edit"><i class="bx bx-edit-alt"></i></a>
                            @if($bundle->isActive())
                                <form method="POST" action="{{ route('admin.bundles.pause', $bundle) }}">@csrf
                                    <button class="btn btn-sm btn-icon btn-outline-warning" title="Pause"><i class="bx bx-pause"></i></button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('admin.bundles.activate', $bundle) }}">@csrf
                                    <button class="btn btn-sm btn-icon btn-outline-success" title="Activate"><i class="bx bx-play"></i></button>
                                </form>
                            @endif
                            @endcan
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" class="text-center py-5 text-muted">
                        <i class="bx bx-package bx-lg d-block mb-2"></i>
                        No bundles yet.
                        @can('manage bundle')
                            <a href="{{ route('admin.bundles.create') }}">Create the first bundle</a>.
                        @endcan
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($bundles->total() > 0)
    <div class="card-footer d-flex flex-wrap justify-content-between align-items-center gap-2">
        <small class="text-muted">Showing {{ $bundles->firstItem() }}–{{ $bundles->lastItem() }} of {{ $bundles->total() }} bundles</small>
        {{ $bundles->links() }}
    </div>
    @endif
</div>
@endsection
