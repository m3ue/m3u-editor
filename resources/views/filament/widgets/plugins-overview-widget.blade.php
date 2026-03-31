<x-filament-widgets::widget class="fi-filament-info-widget">
    <x-filament::section>
        <div class="w-full space-y-4">
            {{-- Header --}}
            <div class="flex items-center justify-between">
                <h2 class="flex items-center gap-2 text-base font-semibold leading-6 text-gray-950 dark:text-white">
                    <x-filament::icon icon="heroicon-o-puzzle-piece" class="h-5 w-5 text-gray-400 dark:text-gray-500" />
                    {{ __('Plugins') }}
                </h2>

                <x-filament::button
                    color="gray"
                    tag="a"
                    href="{{ route('filament.admin.pages.plugins-dashboard') }}"
                    icon="heroicon-m-arrow-top-right-on-square"
                >
                    {{ __('Manage') }}
                </x-filament::button>
            </div>

            {{-- Stats --}}
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                @foreach ([
                    ['label' => __('Installed'), 'value' => $installed, 'warn' => false],
                    ['label' => __('Enabled'), 'value' => $enabled, 'warn' => false],
                    ['label' => __('Trusted'), 'value' => $trusted, 'warn' => false],
                    ['label' => __('Pending Trust'), 'value' => $pending, 'warn' => $pending > 0],
                ] as $stat)
                    <div class="rounded-xl bg-gray-50 px-4 py-3 text-center ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                        <p class="text-2xl font-bold tabular-nums {{ $stat['warn'] ? 'text-warning-600 dark:text-warning-400' : 'text-gray-950 dark:text-white' }}">
                            {{ $stat['value'] }}
                        </p>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $stat['label'] }}</p>
                    </div>
                @endforeach
            </div>

            {{-- Recent Runs --}}
            @if ($recentRuns->isNotEmpty())
                <div>
                    <p class="mb-2 text-xs font-medium uppercase tracking-wide text-gray-400 dark:text-gray-500">
                        {{ __('Recent Runs') }}
                    </p>

                    <div class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($recentRuns as $run)
                            @php
                                $runUrl = $run->plugin
                                    ? \App\Filament\Resources\Plugins\PluginResource::getUrl('run', ['record' => $run->plugin, 'run' => $run])
                                    : null;
                            @endphp
                            <div class="flex items-center justify-between gap-4 py-2">
                                <div class="flex min-w-0 items-center gap-2">
                                    <span class="truncate text-sm font-medium text-gray-950 dark:text-white">
                                        {{ $run->plugin?->name ?? '—' }}
                                    </span>
                                    <span class="truncate text-xs text-gray-400 dark:text-gray-500">
                                        {{ \Illuminate\Support\Str::headline($run->action ?? $run->hook ?? $run->trigger ?? '') }}
                                    </span>
                                </div>

                                <div class="flex shrink-0 items-center gap-3">
                                    <x-filament::badge :color="match ($run->status) {
                                        'completed' => 'success',
                                        'running'   => 'info',
                                        'failed'    => 'danger',
                                        'cancelled' => 'warning',
                                        default     => 'gray',
                                    }">
                                        {{ \Illuminate\Support\Str::headline($run->status) }}
                                    </x-filament::badge>

                                    <span class="text-xs text-gray-400 dark:text-gray-500">
                                        {{ $run->created_at->diffForHumans() }}
                                    </span>

                                    @if ($runUrl)
                                        <x-filament::button tag="a" href="{{ $runUrl }}" color="gray" size="xs">
                                            {{ __('View') }}
                                        </x-filament::button>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @else
                <p class="text-sm text-gray-400 dark:text-gray-500">{{ __('No plugin runs recorded yet.') }}</p>
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
