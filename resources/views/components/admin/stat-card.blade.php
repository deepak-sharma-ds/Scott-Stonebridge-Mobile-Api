{{--
    Blade Component: x-admin.stat-card
    Usage:
        <x-admin.stat-card
            id="kpi-downloads"
            label="Downloads"
            gradient="var(--gradient-primary)"
            :delay="0.1"
        >
            <x-slot:icon>
                <svg>...</svg>
            </x-slot:icon>
        </x-admin.stat-card>

    Props:
        id        — element ID for JS counter animation
        label     — metric label text
        gradient  — CSS gradient string for icon bg and value colour
        delay     — animation-delay in seconds (default 0)
--}}

@props([
    'id'       => '',
    'label'    => 'Metric',
    'gradient' => 'var(--gradient-primary)',
    'delay'    => 0,
])

<div class="card stat-card card-hover"
     style="animation:slideInUp 0.5s {{ $delay }}s both;">

    {{-- Icon --}}
    <div class="stat-card-icon"
         style="background: {{ $gradient }};">
        @if(isset($icon))
            <div style="color:#fff;">{{ $icon }}</div>
        @endif
    </div>

    {{-- Label --}}
    <div class="stat-card-label">{{ $label }}</div>

    {{-- Value (animated by JS) --}}
    <div class="stat-card-value"
         id="{{ $id }}"
         style="background: {{ $gradient }};
                -webkit-background-clip: text;
                -webkit-text-fill-color: transparent;
                background-clip: text;">
        0
    </div>

    {{-- Decorative circle --}}
    <div style="position:absolute;bottom:-24px;right:-24px;
                width:96px;height:96px;border-radius:50%;
                background: {{ $gradient }};opacity:0.06;
                pointer-events:none;">
    </div>
</div>
