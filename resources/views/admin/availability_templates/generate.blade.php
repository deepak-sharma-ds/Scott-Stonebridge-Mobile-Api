@extends('admin.layouts.app')

@section('page-title', 'Generate Availability Slots')

@section('content')
<div class="container-fluid">

    {{-- Page Header --}}
    @include('admin.components.page-header', [
        'title'    => 'Generate Availability Slots',
        'subtitle' => 'Auto-create availability slots from your weekly templates',
        'action'   => '
            <a href="' . route('admin.availability_templates.index') . '" class="btn btn-secondary">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="15 18 9 12 15 6"></polyline>
                </svg>
                Back to Templates
            </a>',
    ])

    <div class="card p-4" style="max-width:780px;" x-data="generateForm()">

        <div style="text-align:center;margin-bottom:1.75rem;">
            <div style="width:52px;height:52px;border-radius:14px;background:var(--gradient-success);
                        display:flex;align-items:center;justify-content:center;margin:0 auto 0.875rem;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2">
                    <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
                </svg>
            </div>
            <h2 style="font-size:1.25rem;font-weight:700;color:var(--text-primary);margin:0 0 0.375rem;">
                Generate From Templates
            </h2>
            <p style="color:var(--text-muted);margin:0;font-size:0.9375rem;">
                Choose a date range and we'll create your availability slots automatically
            </p>
        </div>

        {{-- Validation Errors --}}
        @if($errors->any())
            <div class="alert alert-danger" style="margin-bottom:1.25rem;">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" style="flex-shrink:0;margin-top:1px;">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="12" y1="8" x2="12" y2="12"></line>
                    <line x1="12" y1="16" x2="12.01" y2="16"></line>
                </svg>
                <ul style="margin:0;padding-left:1rem;">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('admin.availability_templates.generate') }}">
            @csrf

            {{-- Mode Select --}}
            <div class="form-group">
                <label class="form-label">Generation Mode</label>
                <select id="mode" name="mode" class="form-select"
                        x-model="mode" @change="updateMode($event.target.value)">
                    <option value="current_week"  {{ old('mode') === 'current_week'  ? 'selected' : '' }}>Current Week</option>
                    <option value="week_of_month" {{ old('mode') === 'week_of_month' ? 'selected' : '' }}>Week of Month</option>
                    <option value="entire_month"  {{ old('mode') === 'entire_month'  ? 'selected' : '' }}>Entire Month</option>
                    <option value="custom_range"  {{ old('mode') === 'custom_range'  ? 'selected' : '' }}>Custom Date Range</option>
                </select>
            </div>

            {{-- Week of Month --}}
            <div x-show="mode === 'week_of_month'"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 class="form-group">
                <div style="background:rgba(99,102,241,0.05);border:1px solid rgba(99,102,241,0.12);
                            border-radius:10px;padding:1rem;margin-bottom:1rem;">
                    <div style="font-size:0.75rem;font-weight:700;text-transform:uppercase;
                                letter-spacing:0.06em;color:var(--color-primary);margin-bottom:0.875rem;">
                        Week of Month Options
                    </div>
                    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:0.75rem;">
                        <div>
                            <label class="form-label">Month</label>
                            <select name="month" class="form-select">
                                @for($m = 1; $m <= 12; $m++)
                                    <option value="{{ $m }}" {{ old('month') == $m ? 'selected' : '' }}>
                                        {{ \Carbon\Carbon::create()->month($m)->format('F') }}
                                    </option>
                                @endfor
                            </select>
                        </div>
                        <div>
                            <label class="form-label">Year</label>
                            <select name="year" class="form-select">
                                @php $year = date('Y'); @endphp
                                @for($i = 0; $i <= 5; $i++)
                                    <option value="{{ $year + $i }}" {{ old('year') == $year + $i ? 'selected' : '' }}>
                                        {{ $year + $i }}
                                    </option>
                                @endfor
                            </select>
                        </div>
                        <div>
                            <label class="form-label">Week Number</label>
                            <select name="week_number" class="form-select">
                                <option value="1" {{ old('week_number') === '1' ? 'selected' : '' }}>1st Week</option>
                                <option value="2" {{ old('week_number') === '2' ? 'selected' : '' }}>2nd Week</option>
                                <option value="3" {{ old('week_number') === '3' ? 'selected' : '' }}>3rd Week</option>
                                <option value="4" {{ old('week_number') === '4' ? 'selected' : '' }}>4th Week</option>
                                <option value="5" {{ old('week_number') === '5' ? 'selected' : '' }}>5th Week</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Entire Month --}}
            <div x-show="mode === 'entire_month'"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 class="form-group">
                <div style="background:rgba(99,102,241,0.05);border:1px solid rgba(99,102,241,0.12);
                            border-radius:10px;padding:1rem;margin-bottom:1rem;">
                    <div style="font-size:0.75rem;font-weight:700;text-transform:uppercase;
                                letter-spacing:0.06em;color:var(--color-primary);margin-bottom:0.875rem;">
                        Month Options
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
                        <div>
                            <label class="form-label">Month</label>
                            <select name="month" class="form-select">
                                @for($m = 1; $m <= 12; $m++)
                                    <option value="{{ $m }}" {{ old('month') == $m ? 'selected' : '' }}>
                                        {{ \Carbon\Carbon::create()->month($m)->format('F') }}
                                    </option>
                                @endfor
                            </select>
                        </div>
                        <div>
                            <label class="form-label">Year</label>
                            <select name="year" class="form-select">
                                @for($i = 0; $i <= 5; $i++)
                                    <option value="{{ $year + $i }}" {{ old('year') == $year + $i ? 'selected' : '' }}>
                                        {{ $year + $i }}
                                    </option>
                                @endfor
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Custom Range --}}
            <div x-show="mode === 'custom_range'"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 class="form-group">
                <div style="background:rgba(99,102,241,0.05);border:1px solid rgba(99,102,241,0.12);
                            border-radius:10px;padding:1rem;margin-bottom:1rem;">
                    <div style="font-size:0.75rem;font-weight:700;text-transform:uppercase;
                                letter-spacing:0.06em;color:var(--color-primary);margin-bottom:0.875rem;">
                        Custom Date Range
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
                        <div>
                            <label class="form-label">Start Date</label>
                            <input type="date" name="custom_start"
                                   value="{{ old('custom_start') }}"
                                   class="form-control">
                        </div>
                        <div>
                            <label class="form-label">End Date</label>
                            <input type="date" name="custom_end"
                                   value="{{ old('custom_end') }}"
                                   class="form-control">
                        </div>
                    </div>
                </div>
            </div>

            {{-- Actions --}}
            <div style="display:flex;justify-content:flex-end;gap:0.625rem;margin-top:0.5rem;">
                <a href="{{ route('admin.availability_templates.index') }}"
                   class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-success">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
                    </svg>
                    Generate Slots
                </button>
            </div>

        </form>
    </div>

</div>
@endsection

@section('custom_js_scripts')
<script>
function generateForm() {
    return {
        mode: '{{ old('mode', 'current_week') }}',
        updateMode(val) {
            this.mode = val;
        }
    };
}
</script>
@endsection
