<x-filament-panels::page>
    {{-- Download Queue — collapsed by default, auto-expands when items arrive --}}
    <div
        x-data
        x-on:queue-status.window="if ($event.detail.count > 0) $dispatch('expand-section', { id: 'download-queue' })"
    >
        <x-filament::section
            collapsible
            :collapsed="true"
            collapse-id="download-queue"
            icon="heroicon-o-arrow-down-tray"
            icon-color="primary"
            heading="{{ __('Download Queue') }}"
        >
            <x-slot name="afterHeader">
                <span
                    x-data="{ count: 0 }"
                    x-on:queue-status.window="count = $event.detail.count"
                    x-show="count > 0"
                    style="display: none"
                >
                    <x-filament::badge color="primary">
                        <span x-text="count"></span>
                    </x-filament::badge>
                </span>
            </x-slot>

            <livewire:arr-queue-monitor />
        </x-filament::section>
    </div>

    <livewire:arr-search />
</x-filament-panels::page>
