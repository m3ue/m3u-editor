@if ($show)
    @php($timezone = config('dev.timezone') ?? (app(\App\Settings\GeneralSettings::class)->app_timezone ?? 'UTC'))

    {{-- Flags --}}
    @if ($show['flags']['is_new'] || $show['flags']['premiere'] || $show['flags']['previously_shown'])
        <div class="flex flex-wrap gap-2">
            @if ($show['flags']['is_new'])
                <x-filament::badge color="success" size="sm">{{ __('New') }}</x-filament::badge>
            @endif
            @if ($show['flags']['premiere'])
                <x-filament::badge color="warning" size="sm">{{ __('Premiere') }}</x-filament::badge>
            @endif
            @if ($show['flags']['previously_shown'])
                <x-filament::badge color="gray" size="sm">{{ __('Previously Shown') }}</x-filament::badge>
            @endif
        </div>
    @endif

    {{-- Upcoming Airings --}}
    @if (! empty($show['airings']))
        <div class="mb-5">
            <h3 class="mb-3 text-sm font-semibold text-gray-900 dark:text-white">
                {{ __('Upcoming Airings') }}
                <span class="font-normal text-gray-400">({{ count($show['airings']) }})</span>
            </h3>
            <div class="space-y-2">
                @foreach ($show['airings'] as $airing)
                    <div class="overflow-hidden rounded-lg border border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-gray-800/50">
                        <div class="p-3 pb-2">
                            {{-- Season/Episode --}}
                            @if ($airing['season'] || $airing['episode'])
                                <x-filament::badge color="primary" class="mb-0.5 font-mono">
                                    S{{ str_pad($airing['season'] ?? '?', 2, '0', STR_PAD_LEFT) }}E{{ str_pad($airing['episode'] ?? '?', 2, '0', STR_PAD_LEFT) }}
                                </x-filament::badge>
                            @endif

                            {{-- Episode title --}}
                            @if ($airing['subtitle'])
                                <p class="text-sm font-semibold text-gray-900 dark:text-white leading-snug {{ $airing['season'] || $airing['episode'] || $airing['is_new'] || $airing['premiere'] || !empty($airing['description']) ? 'mt-0.5' : '' }}">
                                    {{ $airing['subtitle'] }}
                                </p>
                            @elseif ($show['title'] && ! ($airing['season'] || $airing['episode']))
                                <p class="text-sm leading-snug font-semibold text-gray-900 dark:text-white">
                                    {{ $show['title'] }}
                                </p>
                            @endif

                            {{-- Badges --}}
                            @if ($airing['is_new'] || $airing['premiere'])
                                <div class="mt-1 flex items-center gap-1.5">
                                    @if ($airing['is_new'])
                                        <x-filament::badge color="success">{{ __('New') }}</x-filament::badge>
                                    @endif
                                    @if ($airing['premiere'])
                                        <x-filament::badge color="warning">{{ __('Premiere') }}</x-filament::badge>
                                    @endif
                                </div>
                            @endif

                            {{-- Synopsis --}}
                            @if ($airing['description'])
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    {{ $airing['description'] }}
                                </p>
                            @endif
                        </div>

                        {{-- Footer: channel + time + record --}}
                        <div class="flex items-center justify-between gap-3 border-t border-gray-200 bg-white/50 px-3 py-2 dark:border-white/10 dark:bg-gray-900/50">
                            <div class="flex min-w-0 items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                                <span class="truncate font-medium text-gray-700 dark:text-gray-300">{{ $airing['channel_name'] }}</span>
                                <span aria-hidden="true">&middot;</span>
                                <span class="flex-shrink-0"
                                    >{{ $airing['start_time_human'] }}
                                    @if ($airing['end_time_human'] ?? null) -{{ $airing['end_time_human'] }}@endif
                                    @if ($airing['duration_human'] ?? null) ({{ $airing['duration_human'] }})@endif
                                </span>
                            </div>
                            <x-filament::button size="xs" color="gray" wire:click="recordOnce({{ $airing['id'] }})">
                                {{ __('Record') }}
                            </x-filament::button>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Record Series --}}
    <div class="border-t border-gray-200 pt-4 dark:border-white/10">
        <div class="mb-3 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Record Series') }}</h3>
        </div>

        @if (! $show['has_series_rule'])
            <x-filament::button
                wire:click="recordSeriesDefaults({{ \Illuminate\Support\Js::from($show['title']) }})"
                color="primary"
                class="mb-3 w-full"
            >
                {{ __('Record Series (defaults)') }}
            </x-filament::button>
        @else
            <div class="mb-3 flex items-center gap-2 rounded-lg border border-green-200 bg-green-50 p-3 dark:border-green-800 dark:bg-green-900/20">
                <x-filament::icon
                    icon="heroicon-o-check-circle"
                    class="h-4 w-4 flex-shrink-0 text-green-600 dark:text-green-400"
                />
                <span class="text-sm text-green-800 dark:text-green-200">{{ __('Series rule already exists for this show.') }}</span>
            </div>
        @endif

        {{-- Series options --}}
        {{ $this->seriesOptionsForm }}
    </div>
@else
    <div class="flex flex-col items-center justify-center py-12 text-center">
        <x-filament::icon
            icon="heroicon-o-information-circle"
            class="mb-3 h-12 w-12 text-gray-300 dark:text-gray-600"
        />
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Show details unavailable.') }}</p>
    </div>
@endif
