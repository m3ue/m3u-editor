<div class="flex items-center gap-2">
    @if ($icon)
        <x-filament::icon :icon="$icon" class="h-5 w-5 shrink-0 text-gray-400 dark:text-gray-500" />
    @endif

    <span class="font-medium">{{ $label }}</span>
</div>
