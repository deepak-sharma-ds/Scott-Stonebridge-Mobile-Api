@extends('admin.layouts.app')
@section('title', 'Bundle Sync Logs')
@section('content')

<h4 class="fw-bold py-3 mb-2">
    <span class="text-muted fw-light">Bundles /</span> Sync Logs
</h4>

<div class="card">
    <div class="card-header">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-md-3">
                <select name="status" class="form-select">
                    <option value="">All results</option>
                    <option value="success" @selected(request('status') === 'success')>Success</option>
                    <option value="failed" @selected(request('status') === 'failed')>Failed / flagged</option>
                </select>
            </div>
            <div class="col-md-3">
                <select name="operation" class="form-select">
                    <option value="">All operations</option>
                    @foreach(['create', 'update', 'activate', 'pause', 'resync', 'delete', 'webhook'] as $op)
                        <option value="{{ $op }}" @selected(request('operation') === $op)>{{ ucfirst($op) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <button class="btn btn-outline-primary"><i class="bx bx-filter me-1"></i> Filter</button>
            </div>
        </form>
    </div>
    <div class="table-responsive">
        @include('admin.bundles.partials.logs-table', ['logs' => $logs, 'showBundle' => true])
    </div>
    @if($logs->hasPages())
    <div class="card-footer">{{ $logs->links() }}</div>
    @endif
</div>
@endsection
