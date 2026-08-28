<x-filament-widgets::widget>
    <x-filament::section
        :icon="count($items) ? 'heroicon-o-bell-alert' : 'heroicon-o-check-badge'"
        :icon-color="count($items) ? 'warning' : 'success'"
        :heading="__('Needs attention')"
    >
        @if (count($items))
            <ul role="list" class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($items as $item)
                    <li class="flex items-center justify-between gap-4 py-2.5">
                        <div class="flex min-w-0 items-center gap-2.5">
                            <x-filament::icon
                                :icon="$item['icon']"
                                @class([
                                    'h-5 w-5 shrink-0',
                                    'text-danger-500' => $item['color'] === 'danger',
                                    'text-warning-500' => $item['color'] === 'warning',
                                    'text-info-500' => $item['color'] === 'info',
                                ])
                            />
                            <span class="truncate text-sm text-gray-950 dark:text-white">{{ $item['label'] }}</span>
                        </div>

                        <x-filament::button
                            tag="a"
                            href="{{ $item['url'] }}"
                            :color="$item['color'] === 'info' ? 'gray' : $item['color']"
                            size="sm"
                            icon="heroicon-m-arrow-right"
                            icon-position="after"
                            :target="$item['external'] ?? false ? '_blank' : null"
                        >
                            {{ __('Review') }}
                        </x-filament::button>
                    </li>
                @endforeach
            </ul>
        @else
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('Nothing needs your attention right now. Syncs, recordings, requests and storage all look healthy.') }}
            </p>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
