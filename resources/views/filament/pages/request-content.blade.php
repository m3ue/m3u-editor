<x-filament-panels::page>
    {{-- Download Queue — collapsed by default, auto-expands when items are present --}}
    <div
        x-data="{ open: false, count: 0 }"
        x-on:queue-status.window="count = $event.detail.count; if (count > 0 && !open) open = true"
        class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm overflow-hidden"
    >
        <button
            @click="open = !open"
            type="button"
            class="w-full flex items-center gap-2.5 px-4 py-3.5 text-left hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors"
        >
            <x-heroicon-o-arrow-down-tray class="w-4 h-4 text-primary-500 flex-shrink-0" />
            <span class="flex-1 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('Download Queue') }}</span>
            <span
                x-show="count > 0"
                x-text="count"
                style="display: none"
                class="inline-flex items-center justify-center min-w-5 h-5 px-1.5 rounded-full bg-primary-600 text-white text-[10px] font-bold leading-none"
            ></span>
            <x-heroicon-o-chevron-down
                class="w-4 h-4 text-gray-400 transition-transform duration-200"
                x-bind:class="{ 'rotate-180': open }"
            />
        </button>
        <div x-show="open" x-collapse style="display: none">
            <div class="border-t border-gray-200 dark:border-gray-700 p-4">
                <livewire:arr-queue-monitor />
            </div>
        </div>
    </div>

    <livewire:arr-search />
</x-filament-panels::page>
