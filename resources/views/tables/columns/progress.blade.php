@php
    $color = $getColor();
    $progress = $getProgress();

    $colorMap = [
        'primary' => 'var(--color-primary-600)',
        'success' => 'var(--color-success-600)',
        'warning' => 'var(--color-warning-600)',
        'danger' => 'var(--color-danger-600)',
        'info' => 'var(--color-info-600)',
        'gray' => 'var(--color-gray-600)',
    ];

    $barColor = $colorMap[$color] ?? "var(--color-{$color}-600)";
    $clamped = min(max((int) $progress, 0), 100);
@endphp

<div class="flex w-full flex-col gap-1 px-2">
    <div class="relative h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
        <div
            class="h-full rounded-full transition-[width] duration-300"
            style="width: {{ $clamped }}%; background-color: {{ $barColor }}"
        ></div>
    </div>
    <span class="text-xs text-gray-500 dark:text-gray-400">{{ $clamped }}%</span>
</div>
