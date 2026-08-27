<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-heart" icon-color="gray" :heading="__('System health')">
        <ul role="list" class="divide-y divide-gray-100 dark:divide-white/5">
            @foreach ($checks as $check)
                <li class="flex items-center justify-between gap-3 py-1.5">
                    <div class="flex items-center gap-2">
                        <x-filament::icon
                            :icon="$check['ok'] ? 'heroicon-s-check-circle' : 'heroicon-s-exclamation-triangle'"
                            @class([
                                'h-4 w-4',
                                'text-success-500' => $check['ok'],
                                'text-danger-500' => ! $check['ok'],
                            ])
                        />
                        <span class="text-sm text-gray-950 dark:text-white">{{ $check['label'] }}</span>
                    </div>

                    @if (filled($check['detail']))
                        <span @class([
                            'text-xs tabular-nums',
                            'text-gray-500 dark:text-gray-400' => $check['ok'],
                            'font-medium text-danger-600 dark:text-danger-400' => ! $check['ok'],
                        ])>
                            {{ $check['detail'] }}
                        </span>
                    @endif
                </li>
            @endforeach
        </ul>
    </x-filament::section>
</x-filament-widgets::widget>
