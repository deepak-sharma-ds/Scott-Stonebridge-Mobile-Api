@csrf

{{-- Select Package --}}
<div class="mb-3">
    <label for="package_id" class="form-label">Package <span class="text-danger">*</span></label>
    <select name="package_id" id="package_id" class="form-select" required>
        <option value="">-- Select Package --</option>
        @foreach ($packages as $id => $title)
            <option value="{{ $id }}"
                {{ old('package_id', $audio->package_id ?? '') == $id ? 'selected' : '' }}>
                {{ $title }}
            </option>
        @endforeach
    </select>
</div>

{{-- Audio Title --}}
<div class="mb-3">
    <label for="title" class="form-label">Audio Title <span class="text-danger">*</span></label>
    <input type="text" name="title" id="title" class="form-control"
        value="{{ old('title', $audio->title ?? '') }}" required>
</div>

{{-- Audio File Upload --}}
<div class="mb-3">
    <label for="file" class="form-label">Audio File <span class="text-danger">*</span></label>

    <input type="file" name="file" id="file" class="form-control" accept="audio/*"
        {{ isset($audio) && $audio->file_path ? '' : 'required' }}>

    @if (!empty($audio->file_path))
        <p class="mt-2 small text-muted">
            Current file: {{ basename($audio->file_path) }}
        </p>

        @if ($audio->id)
            <div class="mt-2">
                {{-- <audio controls style="width: 100%; max-width: 400px;">
                    <source src="{{ route('audio.stream', $audio->id) }}" type="audio/mpeg">
                    Your browser does not support the audio element.
                </audio> --}}
                @if ($audio->is_hls_ready && $audio->hls_path)
                    {{-- ✅ Show current HLS conversion status --}}
                    <video id="audio-player-{{ $audio->id }}" controls style="width: 300px; height: 40px;"></video>

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
                                    '<span class="text-danger">HLS not supported in this browser.</span>';
                            }
                        });
                    </script>
                    <div class="mt-3">
                        <h6 class="text-muted">Conversion Status:</h6>
                        @if ($audio->is_hls_ready)
                            <span class="badge bg-success">Ready</span>
                        @else
                            <span class="badge bg-warning text-dark">Processing or Not Converted</span>
                        @endif
                    </div>
                @else
                    <span class="text-muted">Processing or not available</span>
                @endif
            </div>
        @else
            <div class="text-muted mt-2">Audio file not found.</div>
        @endif
    @else
        <p class="small text-muted mt-2">
            <em>Note: The uploaded audio file is stored securely in private storage and will be converted into a
                protected HLS format automatically.</em>
        </p>
        <div class="mt-3">
            <h6 class="text-muted mb-1">Conversion Status:</h6>
            <span class="badge bg-secondary">Pending Upload</span>
        </div>
    @endif
</div>

{{-- Duration (seconds) --}}
<div class="mb-3">
    <label for="duration_seconds" class="form-label">Duration (seconds)</label>
    <input type="number" name="duration_seconds" id="duration_seconds" class="form-control" min="0"
        value="{{ old('duration_seconds', $audio->duration_seconds ?? '') }}">
</div>

{{-- Order Index --}}
<div class="mb-3">
    <label for="order_index" class="form-label">Order Index</label>
    <input type="number" name="order_index" id="order_index" class="form-control" min="0"
        value="{{ old('order_index', $audio->order_index ?? '0') }}">
</div>

{{-- Action buttons --}}
<div class="mt-4">
    <button type="submit" class="btn btn-success">
        {{ isset($audio->id) ? 'Update Audio' : 'Save Audio' }}
    </button>
    <a href="{{ route('audios.index') }}" class="btn btn-secondary">Cancel</a>
</div>

@section('custom_js_scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const fileInput = document.getElementById('file');
            const durationInput = document.getElementById('duration_seconds');

            fileInput.addEventListener('change', function(event) {
                const file = event.target.files[0];
                if (!file) {
                    durationInput.value = '';
                    return;
                }

                // Create an audio element (not visible)
                const audio = document.createElement('audio');
                audio.preload = 'metadata';

                audio.onloadedmetadata = function() {
                    window.URL.revokeObjectURL(audio.src);
                    const duration = audio.duration;
                    durationInput.value = Math.round(duration);
                };

                audio.onerror = function() {
                    console.error('Error loading audio metadata.');
                    durationInput.value = '';
                };

                // Load the selected file as object URL
                audio.src = URL.createObjectURL(file);
            });
        });
    </script>
@endsection
