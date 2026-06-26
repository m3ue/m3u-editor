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
    @if (!empty($show['airings']))
        <div class="mb-5">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-3">
                {{ __('Upcoming Airings') }}
                <span class="text-gray-400 font-normal">({{ count($show['airings']) }})</span>
            </h3>
            <div class="space-y-2">
                @foreach ($show['airings'] as $airing)
                    <div
                        class="rounded-lg bg-gray-50 dark:bg-gray-800/50 border border-gray-200 dark:border-white/10 overflow-hidden">
                        <div class="p-3 pb-2">
                            {{-- Season/Episode --}}
                            @if ($airing['season'] || $airing['episode'])
                                <x-filament::badge color="primary" class="font-mono mb-0.5">
                                    S{{ str_pad($airing['season'] ?? '?', 2, '0', STR_PAD_LEFT) }}E{{ str_pad($airing['episode'] ?? '?', 2, '0', STR_PAD_LEFT) }}
                                </x-filament::badge>
                            @endif

                            {{-- Episode title --}}
                            @if ($airing['subtitle'])
                                <p
                                    class="text-sm font-semibold text-gray-900 dark:text-white leading-snug {{ $airing['season'] || $airing['episode'] || $airing['is_new'] || $airing['premiere'] || !empty($airing['description']) ? 'mt-0.5' : '' }}">
                                    {{ $airing['subtitle'] }}
                                </p>
                            @elseif($show['title'] && !($airing['season'] || $airing['episode']))
                                <p class="text-sm font-semibold text-gray-900 dark:text-white leading-snug">
                                    {{ $show['title'] }}
                                </p>
                            @endif

                            {{-- Badges --}}
                            @if ($airing['is_new'] || $airing['premiere'])
                                <div class="flex items-center gap-1.5 mt-1">
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
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ $airing['description'] }}
                                </p>
                            @endif
                        </div>

                        {{-- Footer: channel + time + record --}}
                        <div
                            class="flex items-center justify-between gap-3 px-3 py-2 border-t border-gray-200 dark:border-white/10 bg-white/50 dark:bg-gray-900/50">
                            <div class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400 min-w-0">
                                <span
                                    class="font-medium text-gray-700 dark:text-gray-300 truncate">{{ $airing['channel_name'] }}</span>
                                <span aria-hidden="true">&middot;</span>
                                <span class="flex-shrink-0">{{ $airing['start_time_human'] }}</span>
                            </div>
                            <x-filament::button size="xs" color="gray"
                                wire:click="recordOnce({{ $airing['id'] }})">
                                {{ __('Record') }}
                            </x-filament::button>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Record Series --}}
    <div class="border-t border-gray-200 dark:border-white/10 pt-4">
        <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Record Series') }}</h3>
        </div>

        @if (!$show['has_series_rule'])
            <x-filament::button wire:click="recordSeriesDefaults({{ \Illuminate\Support\Js::from($show['title']) }})"
                color="primary" class="w-full mb-3">
                {{ __('Record Series (defaults)') }}
            </x-filament::button>
        @else
            <div
                class="flex items-center mb-3 gap-2 p-3 rounded-lg bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800">
                <x-filament::icon icon="heroicon-o-check-circle"
                    class="w-4 h-4 text-green-600 dark:text-green-400 flex-shrink-0" />
                <span
                    class="text-sm text-green-800 dark:text-green-200">{{ __('Series rule already exists for this show.') }}</span>
            </div>
        @endif

        {{-- Series options --}}
        {{ $this->seriesOptionsForm }}
    </div>
@else
    <div class="flex flex-col items-center justify-center py-12 text-center">
        <x-filament::icon icon="heroicon-o-information-circle"
            class="w-12 h-12 text-gray-300 dark:text-gray-600 mb-3" />
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Show details unavailable.') }}</p>
    </div>
@endif
