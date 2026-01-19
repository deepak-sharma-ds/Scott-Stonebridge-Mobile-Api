{{--
    Component: Search & Filter Card
    Usage: @include('admin.components.search-filter', ['fields' => ..., 'action' => ...])
--}}

<form method="GET" action="{{ $action ?? '' }}" class="card p-4 mb-4">
    <div class="row g-3">
        @if (isset($fields))
            @foreach ($fields as $field)
                <div class="col-md-{{ $field['col'] ?? 4 }}">
                    @if (isset($field['label']))
                        <label
                            style="font-size: 0.875rem; font-weight: 600; color: #475569; margin-bottom: 0.5rem; display: block;">
                            {{ $field['label'] }}
                        </label>
                    @endif

                    @if ($field['type'] === 'text')
                        <input type="text" name="{{ $field['name'] }}" value="{{ request($field['name']) }}"
                            placeholder="{{ $field['placeholder'] ?? '' }}" class="form-control">
                    @elseif($field['type'] === 'select')
                        <select name="{{ $field['name'] }}" class="form-select">
                            @foreach ($field['options'] as $value => $label)
                                <option value="{{ $value }}"
                                    {{ request($field['name']) == $value ? 'selected' : '' }}>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </select>
                    @endif
                </div>
            @endforeach
        @endif

        <!-- Action Buttons -->
        <div class="col-md-auto ms-auto">
            <label
                style="font-size: 0.875rem; font-weight: 600; color: transparent; margin-bottom: 0.5rem; display: block;">Action</label>
            <div style="display: flex; gap: 0.5rem;">
                <button type="submit" class="btn btn-primary">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" style="display: inline-block; vertical-align: middle; margin-right: 0.25rem;">
                        <circle cx="11" cy="11" r="8"></circle>
                        <path d="m21 21-4.35-4.35"></path>
                    </svg>
                    Search
                </button>
                @if (isset($clearUrl))
                    <a href="{{ $clearUrl }}" class="btn btn-light" style="border: 2px solid #e2e8f0;">
                        Clear
                    </a>
                @endif
            </div>
        </div>
    </div>
</form>
