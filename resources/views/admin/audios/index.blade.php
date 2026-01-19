@extends('admin.layouts.app')

@section('content')
    <div class="container-fluid">

        {{-- Page Header --}}
        @include('admin.components.page-header', [
            'title' => 'Audios',
            'subtitle' => 'Manage audio files and streaming content',
            'action' =>
                '<a href="' .
                route('audios.create') .
                '" class="btn btn-primary">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display: inline-block; vertical-align: middle; margin-right: 0.5rem;">
                                        <path d="M9 18V5l12-2v13"></path>
                                        <circle cx="6" cy="18" r="3"></circle>
                                        <circle cx="18" cy="16" r="3"></circle>
                                    </svg>
                                    Add Audio
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

        {{-- Audios Table --}}
        <div class="card p-4">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Audio Details</th>
                            <th style="min-width: 300px;">Player</th>
                            <th style="text-align: center;">Duration</th>
                            <th style="text-align: center;">Order</th>
                            <th>Created</th>
                            <th style="text-align: right; width: 180px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($audios as $audio)
                            <tr>
                                <td>
                                    <div style="font-weight: 600; color: #1e293b; font-size: 1rem; margin-bottom: 0.5rem;">
                                        {{ $audio->title }}
                                    </div>
                                    @if ($audio->package)
                                        <div style="display: flex; align-items: center; gap: 0.5rem; margin-top: 0.5rem;">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                                                stroke="currentColor" stroke-width="2" style="color: #94a3b8;">
                                                <rect x="3" y="3" width="18" height="18" rx="2"
                                                    ry="2"></rect>
                                            </svg>
                                            <span
                                                style="font-size: 0.875rem; color: #64748b;">{{ $audio->package->title }}</span>
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    @if ($audio->is_hls_ready && $audio->hls_path)
                                        <div
                                            style="background: rgba(102, 126, 234, 0.05); padding: 0.5rem; border-radius: 10px; border: 1px solid rgba(102, 126, 234, 0.1);">
                                            <video id="audio-player-{{ $audio->id }}" controls
                                                style="width: 100%; height: 40px; border-radius: 8px;"></video>
                                        </div>

                                        <script>
                                            document.addEventListener('DOMContentLoaded', function() {
                                                const audio{{ $audio->id }} = document.getElementById('audio-player-{{ $audio->id }}');
                                                const src = "{{ route('audio.stream', ['audio' => $audio->id, 'file' => 'playlist.m3u8']) }}";

                                                if (Hls.isSupported()) {
                                                    const hls = new Hls();
                                                    hls.loadSource(src);
                                                    hls.attachMedia(audio{{ $audio->id }});
                                                } else if (audio{{ $audio->id }}.canPlayType('application/vnd.apple.mpegurl')) {
                                                    audio{{ $audio->id }}.src = src;
                                                } else {
                                                    audio{{ $audio->id }}.outerHTML =
                                                        '<span class="text-danger" style="font-size: 0.875rem;">HLS not supported</span>';
                                                }
                                            });
                                        </script>
                                    @else
                                        <div
                                            style="text-align: center; padding: 1rem; background: rgba(245, 158, 11, 0.05); border-radius: 10px; border: 1px solid rgba(245, 158, 11, 0.2);">
                                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
                                                stroke="currentColor" stroke-width="2"
                                                style="color: #f59e0b; margin-bottom: 0.25rem;">
                                                <circle cx="12" cy="12" r="10"></circle>
                                                <line x1="12" y1="8" x2="12" y2="12"></line>
                                                <line x1="12" y1="16" x2="12.01" y2="16"></line>
                                            </svg>
                                            <div style="font-size: 0.75rem; color: #f59e0b; font-weight: 600;">Processing...
                                            </div>
                                        </div>
                                    @endif
                                </td>
                                <td style="text-align: center;">
                                    @if ($audio->duration_seconds)
                                        <span
                                            style="background: rgba(16, 185, 129, 0.1); color: #10b981; padding: 0.375rem 0.75rem; border-radius: 12px; font-weight: 700; font-size: 0.875rem;">
                                            {{ $audio->duration_seconds }}s
                                        </span>
                                    @else
                                        <span style="color: #94a3b8;">—</span>
                                    @endif
                                </td>
                                <td style="text-align: center;">
                                    <span
                                        style="background: rgba(102, 126, 234, 0.1); color: var(--color-primary); padding: 0.375rem 0.75rem; border-radius: 12px; font-weight: 700;">
                                        {{ $audio->order_index }}
                                    </span>
                                </td>
                                <td style="color: #64748b; font-weight: 500;">
                                    {{ $audio->created_at->format('d M Y') }}
                                </td>
                                <td style="text-align: right;">
                                    <div style="display: inline-flex; gap: 0.5rem;">
                                        <a href="{{ route('audios.edit', $audio) }}" class="btn btn-sm"
                                            style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: white; border: none; padding: 0.5rem 1rem; border-radius: 8px; font-weight: 600;">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                                                stroke="currentColor" stroke-width="2"
                                                style="display: inline-block; vertical-align: middle; margin-right: 0.25rem;">
                                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                            </svg>
                                            Edit
                                        </a>
                                        <form action="{{ route('audios.destroy', $audio) }}" method="POST"
                                            style="display: inline;"
                                            onsubmit="return confirm('Are you sure you want to delete this audio?');">
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
                                            'No audio files found. Upload your first audio to get started!',
                                        'icon' => '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin: 0 auto 1rem; opacity: 0.5;">
                                                                                        <path d="M9 18V5l12-2v13"></path>
                                                                                        <circle cx="6" cy="18" r="3"></circle>
                                                                                        <circle cx="18" cy="16" r="3"></circle>
                                                                                    </svg>',
                                    ])
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Pagination --}}
            @if ($audios->hasPages())
                <div style="margin-top: 1.5rem;">
                    {!! $audios->appends(request()->query())->links('pagination::bootstrap-5') !!}
                </div>
            @endif
        </div>

    </div>
@endsection
