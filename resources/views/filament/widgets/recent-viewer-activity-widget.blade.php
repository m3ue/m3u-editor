<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-play-circle" icon-color="gray" :heading="__('Recent viewer activity')">
        <x-slot name="afterHeader">
            <x-filament::button
                tag="a"
                href="{{ $viewersUrl }}"
                color="gray"
                size="sm"
                icon="heroicon-m-arrow-top-right-on-square"
            >
                {{ __('Viewers') }}
            </x-filament::button>
        </x-slot>

        @if ($rows->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No watch activity recorded yet.') }}</p>
        @else
            <ul role="list" class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($rows as $row)
                    <li class="py-2">
                        <div class="flex items-center justify-between gap-3">
                            <span class="truncate text-sm font-medium text-gray-950 dark:text-white">{{ $row['title'] }}</span>
                            <span class="shrink-0 text-xs text-gray-400 dark:text-gray-500">{{ $row['when'] }}</span>
                        </div>
                        <div class="mt-1 flex items-center gap-2">
                            <span class="text-xs text-gray-500 dark:text-gray-400">{{ $row['viewer'] }}</span>
                            <x-filament::badge color="gray" size="sm">
                                {{ \Illuminate\Support\Str::headline($row['type']) }}
                            </x-filament::badge>
                            @if ($row['completed'])
                                <x-filament::badge color="success" size="sm">{{ __('Completed') }}</x-filament::badge>
                            @elseif ($row['percent'] !== null)
                                <x-filament::badge color="info" size="sm">{{ $row['percent'] }}%</x-filament::badge>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
