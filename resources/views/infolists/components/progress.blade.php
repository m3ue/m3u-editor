@php
    $progress = (float) ($record->candidates_progress ?? 0);
    $clamped = min(max((int) $progress, 0), 100);
@endphp

<div class="flex flex-col gap-2 px-2 py-1">
    <div class="relative h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
        <div class="h-full rounded-full transition-[width] duration-300"
            style="width: {{ $clamped }}%; background-color: var(--color-primary-600)"></div>
    </div>
    <span class="text-xs text-gray-500 dark:text-gray-400">{{ $clamped }}%</span>
</div>
