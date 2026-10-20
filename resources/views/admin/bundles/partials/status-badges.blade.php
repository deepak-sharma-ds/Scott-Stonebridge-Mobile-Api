@php
    $statusClass = ['active' => 'success', 'paused' => 'warning', 'draft' => 'secondary'][$bundle->status] ?? 'secondary';
    $syncClass = ['synced' => 'success', 'pending' => 'info', 'failed' => 'danger'][$bundle->sync_status] ?? 'secondary';
@endphp
<span class="badge bg-label-{{ $statusClass }}">{{ ucfirst($bundle->status) }}</span>
<span class="badge bg-label-{{ $syncClass }}" @if($bundle->last_sync_error) data-bs-toggle="tooltip" title="{{ $bundle->last_sync_error }}" @endif>
    {{ $bundle->sync_status === 'synced' ? 'Synced' : ucfirst($bundle->sync_status) }}
</span>
@if($bundle->needs_review)
    <span class="badge bg-label-danger" data-bs-toggle="tooltip" title="{{ $bundle->review_reason }}"><i class="bx bx-error-circle"></i> Review</span>
@endif
