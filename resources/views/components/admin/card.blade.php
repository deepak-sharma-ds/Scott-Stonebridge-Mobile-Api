{{-- @props([
    'title' => '',
    'id' => null,
])

<div class="bg-white dark:bg-gray-800 p-5 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700">
    <div class="text-sm text-gray-500 dark:text-gray-300">
        {{ $title }}
    </div>

    <div class="mt-2 text-3xl font-semibold text-gray-900 dark:text-white" id="{{ $id }}">
        {{ $slot ?: '—' }}
    </div>
</div> --}}

@props([
    'title' => 'Title',
    'id' => null,
    'icon' => 'ph-trend-up',
])

<div
    class="glass-card p-4 shadow-sm flex items-center gap-4 transition-all duration-200 hover:-translate-y-1 cursor-pointer">

    {{-- Icon --}}
    <div class="w-12 h-12 rounded-xl bg-indigo-50 flex items-center justify-center text-indigo-600 text-2xl">
        <i class="{{ $icon }}"></i>
    </div>

    {{-- Text --}}
    <div>
        <p class="text-sm text-slate-500">{{ $title }}</p>
        <div id="{{ $id }}" class="kpi-value">0</div>
    </div>

</div>
