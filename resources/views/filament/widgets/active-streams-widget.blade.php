<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-arrows-right-left" icon-color="gray" :heading="__('Active streams')">
        <x-slot name="afterHeader">
            <x-filament::button
                tag="a"
                href="{{ $this->getMonitorUrl() }}"
                color="gray"
                size="sm"
                icon="heroicon-m-arrow-top-right-on-square"
            >
                {{ __('Monitor') }}
            </x-filament::button>
        </x-slot>

        @if (! $connected)
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('The proxy service is not reachable right now.') }}
            </p>
        @elseif ($total === 0)
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No streams are active.') }}</p>
        @else
            <div class="space-y-3">
                <div class="flex gap-6">
                    <div>
                        <p class="text-2xl font-bold text-gray-950 tabular-nums dark:text-white">
                            {{ number_format($total) }}
                        </p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Streams') }}</p>
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-gray-950 tabular-nums dark:text-white">
                            {{ number_format($clients) }}
                        </p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Clients') }}</p>
                    </div>
                </div>

                <ul role="list" class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($streams as $stream)
                        <li class="flex items-center justify-between gap-3 py-1.5">
                            <span class="truncate text-sm text-gray-950 dark:text-white">{{ $stream['name'] }}</span>
                            <x-filament::badge color="gray" size="sm">
                                {{ trans_choice(':count client|:count clients', $stream['clients'], ['count' => $stream['clients']]) }}
                            </x-filament::badge>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
