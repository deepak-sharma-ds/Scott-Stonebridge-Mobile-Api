{{-- @props([
    'title' => '',
    'canvas' => 'chart' . uniqid(),
    'height' => 260,
])

<div class="bg-white dark:bg-gray-800 p-5 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700">
    <h2 class="text-lg font-medium text-gray-900 dark:text-white mb-3">
        {{ $title }}
    </h2>

    <canvas id="{{ $canvas }}" height="{{ $height }}"></canvas>

    {{ $slot }}
</div> --}}

@props([
    'title' => 'Chart Title',
    'canvas' => 'chartCanvas',
])

<div class="glass-card p-6 shadow-sm">

    {{-- Title --}}
    <div class="flex justify-between items-center mb-3">
        <h2 class="text-lg font-bold text-slate-800">{{ $title }}</h2>
    </div>

    {{-- Canvas --}}
    <div class="relative h-72">
        <canvas id="{{ $canvas }}"></canvas>
    </div>

</div>
