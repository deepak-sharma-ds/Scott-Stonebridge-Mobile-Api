@extends('admin.layouts.app')

@section('page-title', 'Edit Availability Templates')

@section('content')
<div class="container-fluid">

    {{-- Page Header --}}
    @include('admin.components.page-header', [
        'title'    => 'Edit Availability Templates',
        'subtitle' => 'Add or modify your recurring weekly availability schedule',
        'action'   => '
            <a href="' . route('admin.availability_templates.index') . '" class="btn btn-secondary">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="15 18 9 12 15 6"></polyline>
                </svg>
                Back to Overview
            </a>',
    ])

    @php
        $days      = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
        $templates = App\Models\AvailabilityTemplate::where('user_id', auth()->id())->get();
    @endphp

    <form method="POST" action="{{ route('admin.availability_templates.store') }}">
        @csrf

        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:1rem;margin-bottom:1.5rem;">

            @foreach($days as $i => $day)
                @php $daySlots = $templates->where('day_of_week', $day); @endphp

                <div x-data="{ open: true }"
                     class="card"
                     style="animation:slideInUp 0.4s {{ ($i * 0.07) }}s both;overflow:hidden;">

                    {{-- Day header --}}
                    <div style="display:flex;justify-content:space-between;align-items:center;
                                padding:1rem 1.25rem;
                                background:linear-gradient(135deg,rgba(99,102,241,0.06),rgba(139,92,246,0.04));
                                border-bottom:1px solid var(--card-border);">
                        <div style="display:flex;align-items:center;gap:0.625rem;">
                            <div style="width:8px;height:8px;border-radius:50%;background:var(--color-primary);"></div>
                            <span style="font-weight:700;color:var(--color-primary);font-size:0.9375rem;">
                                {{ $day }}
                            </span>
                        </div>
                        <button type="button" @click="open = !open"
                                style="border:none;background:rgba(99,102,241,0.08);
                                       color:var(--color-primary);
                                       width:28px;height:28px;border-radius:7px;
                                       cursor:pointer;display:flex;align-items:center;justify-content:center;"
                                aria-label="Toggle">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="2.5"
                                 style="transition:transform 0.2s;"
                                 :style="open ? '' : 'transform:rotate(-90deg)'">
                                <polyline points="6 9 12 15 18 9"></polyline>
                            </svg>
                        </button>
                    </div>

                    {{-- Slots body --}}
                    <div x-show="open"
                         x-transition:enter="transition ease-out duration-200"
                         x-transition:enter-start="opacity-0"
                         x-transition:enter-end="opacity-100"
                         style="padding:1rem 1.25rem;">

                        <div id="slots-{{ $day }}">
                            @foreach($daySlots as $k => $slot)
                                <div class="slot-edit-row slot-row" data-slot-index="{{ $k }}">
                                    <input type="time"
                                           name="templates[{{ $day }}][{{ $k }}][start]"
                                           value="{{ $slot->start_time }}"
                                           class="form-control" style="flex:1;">
                                    <span style="color:var(--text-muted);font-weight:600;flex-shrink:0;">—</span>
                                    <input type="time"
                                           name="templates[{{ $day }}][{{ $k }}][end]"
                                           value="{{ $slot->end_time }}"
                                           class="form-control" style="flex:1;">
                                    <button type="button"
                                            class="remove-slot"
                                            style="border:none;background:var(--color-danger-muted);
                                                   color:var(--color-danger);
                                                   width:30px;height:30px;border-radius:7px;
                                                   cursor:pointer;display:flex;align-items:center;
                                                   justify-content:center;flex-shrink:0;
                                                   transition:all var(--t-fast);"
                                            onmouseover="this.style.background='var(--color-danger)';this.style.color='#fff'"
                                            onmouseout="this.style.background='var(--color-danger-muted)';this.style.color='var(--color-danger)'"
                                            aria-label="Remove slot">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                                             stroke="currentColor" stroke-width="2.5">
                                            <line x1="18" y1="6" x2="6" y2="18"></line>
                                            <line x1="6"  y1="6" x2="18" y2="18"></line>
                                        </svg>
                                    </button>
                                </div>
                            @endforeach
                        </div>

                        <button type="button"
                                class="add-slot"
                                data-day="{{ $day }}"
                                style="margin-top:0.625rem;display:inline-flex;align-items:center;gap:0.375rem;
                                       padding:0.4375rem 0.875rem;border-radius:8px;
                                       border:1.5px dashed var(--color-primary);
                                       background:var(--color-primary-muted);
                                       color:var(--color-primary);
                                       font-size:0.8125rem;font-weight:600;cursor:pointer;
                                       transition:all var(--t-fast);"
                                onmouseover="this.style.background='var(--color-primary)';this.style.color='#fff';this.style.borderStyle='solid'"
                                onmouseout="this.style.background='var(--color-primary-muted)';this.style.color='var(--color-primary)';this.style.borderStyle='dashed'">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="2.5">
                                <line x1="12" y1="5" x2="12" y2="19"></line>
                                <line x1="5" y1="12" x2="19" y2="12"></line>
                            </svg>
                            Add Slot
                        </button>

                    </div>
                </div>
            @endforeach

        </div>

        {{-- Save Button --}}
        <div class="card p-4" style="display:flex;justify-content:flex-end;gap:0.625rem;">
            <a href="{{ route('admin.availability_templates.index') }}" class="btn btn-secondary">
                Cancel
            </a>
            <button type="submit" class="btn btn-primary" style="padding:0.625rem 2rem;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
                    <polyline points="17 21 17 13 7 13 7 21"></polyline>
                    <polyline points="7 3 7 8 15 8"></polyline>
                </svg>
                Save Templates
            </button>
        </div>

    </form>

</div>
@endsection

@section('custom_css_links')
<style>
    .slot-edit-row {
        display: flex;
        align-items: center;
        gap: 0.625rem;
        margin-bottom: 0.5rem;
        animation: slideInUp 0.25s ease both;
    }
    @keyframes fadeOut {
        from { opacity:1; transform:scale(1); }
        to   { opacity:0; transform:scale(0.9); }
    }
    .slot-row.removing {
        animation: fadeOut 0.2s forwards;
    }
</style>
@endsection

@section('custom_js_scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    // ── Add Slot ──
    document.querySelectorAll('.add-slot').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var day       = btn.dataset.day;
            var container = document.getElementById('slots-' + day);
            var index     = container.querySelectorAll('.slot-row').length;

            var row = document.createElement('div');
            row.className = 'slot-edit-row slot-row';

            row.innerHTML =
                '<input type="time" name="templates[' + day + '][' + index + '][start]" class="form-control" style="flex:1;" required>' +
                '<span style="color:var(--text-muted);font-weight:600;flex-shrink:0;">—</span>' +
                '<input type="time" name="templates[' + day + '][' + index + '][end]" class="form-control" style="flex:1;" required>' +
                '<button type="button" class="remove-slot"' +
                '        style="border:none;background:var(--color-danger-muted);color:var(--color-danger);' +
                '               width:30px;height:30px;border-radius:7px;cursor:pointer;' +
                '               display:flex;align-items:center;justify-content:center;flex-shrink:0;"' +
                '        onmouseover="this.style.background=\'var(--color-danger)\';this.style.color=\'#fff\'"' +
                '        onmouseout="this.style.background=\'var(--color-danger-muted)\';this.style.color=\'var(--color-danger)\'"' +
                '        aria-label="Remove slot">' +
                '    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">' +
                '        <line x1="18" y1="6" x2="6" y2="18"></line>' +
                '        <line x1="6"  y1="6" x2="18" y2="18"></line>' +
                '    </svg>' +
                '</button>';

            container.appendChild(row);

            // Focus first input of new row
            row.querySelector('input[type="time"]').focus();
        });
    });

    // ── Remove Slot (event delegation) ──
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.remove-slot');
        if (!btn) return;
        var row = btn.closest('.slot-row');
        if (!row) return;
        row.classList.add('removing');
        setTimeout(function () { row.remove(); }, 220);
    });
});
</script>
@endsection
