@extends('admin.layouts.app')

@section('page-title', 'Availability Templates')

@section('content')
<div class="container-fluid">

    {{-- Page Header --}}
    @include('admin.components.page-header', [
        'title'    => 'Availability Templates',
        'subtitle' => 'Your configured recurring time slots for each weekday',
        'action'   => '
            <div style="display:flex;gap:0.625rem;flex-wrap:wrap;">
                <a href="' . route('admin.availability_templates.create') . '" class="btn btn-light">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                    </svg>
                    Edit Templates
                </a>
                <a href="' . route('admin.availability_templates.generate.form') . '" class="btn btn-success">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
                    </svg>
                    Generate Slots
                </a>
            </div>',
    ])

    {{-- ─── Weekly Accordion Grid ───────────────────────────────────────────
         x-data on the PARENT holds shared state: only one day open at a time.
         openDay: 'Monday'  → Monday is open by default, rest are closed.
         Click same day  → closes it (openDay = null).
         Click other day → closes current, opens clicked.
    ──────────────────────────────────────────────────────────────────────── --}}
    @php $days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday']; @endphp

    <div class="avail-grid"
         x-data="{ openDay: 'Monday' }">

        @foreach($days as $index => $day)
            @php
                $daySlots  = $templates[$day] ?? [];
                $hasSlots  = count($daySlots) > 0;
                $slotCount = count($daySlots);
            @endphp

            <div class="card avail-day-card"
                 style="animation: slideInUp 0.42s {{ $index * 0.06 }}s both;">

                {{-- Toggle button: entire header row ──────────────────── --}}
                <button class="avail-day-header"
                        @click="openDay = (openDay === '{{ $day }}') ? null : '{{ $day }}'"
                        :class="{ 'avail-day-header--open': openDay === '{{ $day }}' }"
                        :aria-expanded="(openDay === '{{ $day }}').toString()"
                        aria-label="Toggle {{ $day }} time slots">

                    <div class="avail-day-header__left">
                        <span class="avail-day-dot {{ $hasSlots ? 'avail-day-dot--active' : '' }}"></span>
                        <span class="avail-day-name">{{ $day }}</span>
                        @if($hasSlots)
                            <span class="badge badge-primary">
                                {{ $slotCount }}&nbsp;{{ $slotCount === 1 ? 'slot' : 'slots' }}
                            </span>
                        @else
                            <span class="avail-empty-badge">No slots</span>
                        @endif
                    </div>

                    {{-- Chevron: points down when open, right when closed --}}
                    <span class="avail-chevron"
                          :class="{ 'avail-chevron--closed': openDay !== '{{ $day }}' }"
                          aria-hidden="true">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2.5">
                            <polyline points="6 9 12 15 18 9"></polyline>
                        </svg>
                    </span>
                </button>

                {{-- ── Accordion body — CSS grid-template-rows animation ── --}}
                {{-- How it works:
                     • Outer div: display:grid; grid-template-rows: 1fr (open) or 0fr (closed)
                     • CSS transitions animate between these two states smoothly
                     • Inner div: overflow:hidden + min-height:0 allows compression to zero
                     • No JS height measurement needed — pure CSS + Alpine class toggle
                --}}
                <div class="avail-accordion-body"
                     :class="{ 'avail-accordion-body--closed': openDay !== '{{ $day }}' }">
                    <div class="avail-accordion-inner">

                        @if($hasSlots)
                            <div class="avail-slots-wrap">
                                @foreach($daySlots as $si => $slot)
                                    <div class="avail-slot-pill"
                                         style="animation-delay: {{ $si * 0.03 }}s;">
                                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none"
                                             stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                            <circle cx="12" cy="12" r="10"></circle>
                                            <polyline points="12 6 12 12 16 14"></polyline>
                                        </svg>
                                        <span>{{ substr($slot->start_time, 0, 5) }}&thinsp;—&thinsp;{{ substr($slot->end_time, 0, 5) }}</span>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="avail-empty-state">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                                     stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <line x1="12" y1="8" x2="12" y2="12"></line>
                                    <line x1="12" y1="16" x2="12.01" y2="16"></line>
                                </svg>
                                <span>No slots configured for {{ $day }}</span>
                            </div>
                        @endif

                    </div>
                </div>

            </div>
        @endforeach

    </div>

</div>
@endsection
