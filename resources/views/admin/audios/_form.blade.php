@csrf

<div class="mb-3">
    <label for="package_id" class="form-label">Package</label>
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

<div class="mb-3">
    <label for="title" class="form-label">Audio Title</label>
    <input type="text" name="title" id="title" class="form-control"
        value="{{ old('title', $audio->title ?? '') }}" required>
</div>

<div class="mb-3">
    <label for="file" class="form-label">Audio File</label>
    <input type="file" name="file" id="file" class="form-control" accept="audio/*"
        {{ isset($audio) && $audio->file_path ? '' : 'required' }}>

    @if (!empty($audio->file_path))
        <p class="mt-2 small text-muted">
            Current file: {{ basename($audio->file_path) }}
        </p>
        @if ($audio->id)
            <div class="mt-2">
                <audio controls style="width: 100%; max-width: 400px;">
                    <source src="{{ route('audio.stream', $audio->id) }}" type="audio/mpeg">
                    Your browser does not support the audio element.
                </audio>
            </div>
        @else
            <div class="text-muted mt-2">Audio file not found.</div>
        @endif
    @endif
</div>



<div class="mb-3">
    <label for="duration_seconds" class="form-label">Duration (seconds)</label>
    <input type="number" name="duration_seconds" id="duration_seconds" class="form-control"
        value="{{ old('duration_seconds', $audio->duration_seconds ?? '') }}">
</div>

<div class="mb-3">
    <label for="order_index" class="form-label">Order Index</label>
    <input type="number" name="order_index" id="order_index" class="form-control"
        value="{{ old('order_index', $audio->order_index ?? '0') }}">
</div>

<div class="mt-4">
    <button type="submit" class="btn btn-success">Save Audio</button>
    <a href="{{ route('audios.index') }}" class="btn btn-secondary">Cancel</a>
</div>
