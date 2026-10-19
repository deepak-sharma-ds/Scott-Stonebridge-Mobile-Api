<table class="table table-sm align-middle mb-0">
    <thead>
        <tr>
            <th>When</th>
            @if($showBundle ?? true)<th>Bundle</th>@endif
            <th>Operation</th>
            <th>Result</th>
            <th>Details</th>
            <th>By</th>
        </tr>
    </thead>
    <tbody>
        @forelse($logs as $log)
        <tr>
            <td class="text-nowrap small">{{ $log->created_at->format('M j, g:i A') }}</td>
            @if($showBundle ?? true)
            <td class="small">
                @if($log->bundle && ! $log->bundle->trashed())
                    <a href="{{ route('admin.bundles.show', $log->bundle) }}">{{ $log->bundle->title }}</a>
                @else
                    {{ $log->bundle?->title ?? '—' }} <span class="text-muted">(deleted)</span>
                @endif
            </td>
            @endif
            <td><span class="badge bg-label-primary">{{ $log->operation }}</span></td>
            <td>
                @if($log->status === 'success')
                    <span class="badge bg-label-success">Success</span>
                @else
                    <span class="badge bg-label-danger">{{ $log->operation === 'webhook' ? 'Flagged' : 'Failed' }}</span>
                @endif
            </td>
            <td class="small">
                @if($log->error)<div class="text-danger">{{ \Illuminate\Support\Str::limit($log->error, 300) }}</div>@endif
                @foreach($log->details['steps'] ?? [] as $step)
                    <div class="text-muted">• {{ $step }}</div>
                @endforeach
                @if($log->duration_ms)<div class="text-muted">{{ number_format($log->duration_ms) }} ms</div>@endif
            </td>
            <td class="small">{{ $log->user?->name ?? ($log->operation === 'webhook' ? 'Shopify' : '—') }}</td>
        </tr>
        @empty
        <tr><td colspan="6" class="text-center text-muted py-4">No sync activity yet.</td></tr>
        @endforelse
    </tbody>
</table>
