@if($show)
@php($timezone = config('dev.timezone') ?? app(\App\Settings\GeneralSettings::class)->app_timezone ?? 'UTC')

    {{-- Flags --}}
    @if($show['flags']['is_new'] || $show['flags']['premiere'] || $show['flags']['previously_shown'])
        <div class="flex flex-wrap gap-2 mb-5">
            @if($show['flags']['is_new'])
                <span
                    class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400">
                    {{ __('New') }}
                </span>
            @endif
            @if($show['flags']['premiere'])
                <span
                    class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400">
                    {{ __('Premiere') }}
                </span>
            @endif
            @if($show['flags']['previously_shown'])
                <span
                    class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400">
                    {{ __('Previously Shown') }}
                </span>
            @endif
        </div>
    @endif

    {{-- Upcoming Airings --}}
    @if(!empty($show['airings']))
        <div class="mb-5">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-3">
                {{ __('Upcoming Airings') }}
                <span class="text-gray-400 font-normal">({{ count($show['airings']) }})</span>
            </h3>
            <div class="space-y-2">
                @foreach($show['airings'] as $airing)
                    <div
                        class="rounded-lg bg-gray-50 dark:bg-gray-800/50 border border-gray-200 dark:border-white/10 overflow-hidden">
                        <div class="p-3 pb-2">
                            {{-- Season/Episode --}}
                            @if($airing['season'] || $airing['episode'])
                                <p class="text-xs font-mono font-semibold text-primary-600 dark:text-primary-400">
                                    S{{ str_pad($airing['season'] ?? '?', 2, '0', STR_PAD_LEFT) }}E{{ str_pad($airing['episode'] ?? '?', 2, '0', STR_PAD_LEFT) }}
                                </p>
                            @endif

                            {{-- Episode title --}}
                            @if($airing['subtitle'])
                                <p
                                    class="text-sm font-semibold text-gray-900 dark:text-white leading-snug {{ ($airing['season'] || $airing['episode'] || $airing['is_new'] || $airing['premiere'] || !empty($airing['description'])) ? 'mt-0.5' : '' }}">
                                    {{ $airing['subtitle'] }}
                                </p>
                            @elseif($show['title'] && !($airing['season'] || $airing['episode']))
                                <p class="text-sm font-semibold text-gray-900 dark:text-white leading-snug">
                                    {{ $show['title'] }}
                                </p>
                            @endif

                            {{-- Badges --}}
                            @if($airing['is_new'] || $airing['premiere'])
                                <div class="flex items-center gap-1.5 mt-1">
                                    @if($airing['is_new'])
                                        <span
                                            class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-emerald-500/90 text-white">New</span>
                                    @endif
                                    @if($airing['premiere'])
                                        <span
                                            class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-purple-500/90 text-white">Premiere</span>
                                    @endif
                                </div>
                            @endif

                            {{-- Synopsis --}}
                            @if($airing['description'])
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ $airing['description'] }}</p>
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
    <div class="border-t border-gray-200 dark:border-white/10 pt-4">
        <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Record Series') }}</h3>
        </div>

        @if(!$show['has_series_rule'])
            <x-filament::button wire:click="recordSeriesDefaults({{ \Illuminate\Support\Js::from($show['title']) }})"
                color="primary" class="w-full mb-3">
                {{ __('Record Series (defaults)') }}
            </x-filament::button>
        @else
            <div
                class="flex items-center gap-2 p-3 rounded-lg bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800">
                <svg class="w-4 h-4 text-green-600 dark:text-green-400 flex-shrink-0" fill="none" viewBox="0 0 24 24"
                    stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
                <span
                    class="text-sm text-green-800 dark:text-green-200">{{ __('Series rule already exists for this show.') }}</span>
            </div>
        @endif

        {{-- Series options collapsible --}}
        <div x-data="{ showOptions: false }" class="mt-2">
            <button type="button" @click="showOptions = !showOptions"
                class="flex items-center gap-1 text-sm text-primary-600 dark:text-primary-400 hover:text-primary-500 transition">
                <svg class="w-4 h-4 transition-transform" :class="showOptions ? 'rotate-90' : ''" fill="none"
                    viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                </svg>
                {{ __('Advanced options') }}
            </button>

            <div x-show="showOptions" x-collapse>
                <div class="mt-3 space-y-3">
                    <x-filament::input.wrapper label="{{ __('New episodes only') }}">
                        <x-filament::input.select wire:model.live="seriesNewOnly">
                            <option :value="false">{{ __('No') }}</option>
                            <option :value="true">{{ __('Yes') }}</option>
                        </x-filament::input.select>
                    </x-filament::input.wrapper>

                    <x-filament::input.wrapper label="{{ __('Channel (contains)') }}">
                        <x-filament::input type="text" wire:model.live="seriesChannelName"
                            placeholder="{{ __('Any channel') }}" />
                    </x-filament::input.wrapper>

                    <x-filament::input.wrapper label="{{ __('Priority') }}">
                        <x-filament::input type="number" wire:model.live="seriesPriority" min="1" max="99" />
                    </x-filament::input.wrapper>

                    <div class="grid grid-cols-2 gap-3">
                        <x-filament::input.wrapper label="{{ __('Start early (seconds)') }}">
                            <x-filament::input type="number" wire:model.live="seriesStartEarly" min="0" />
                        </x-filament::input.wrapper>
                        <x-filament::input.wrapper label="{{ __('End late (seconds)') }}">
                            <x-filament::input type="number" wire:model.live="seriesEndLate" min="0" />
                        </x-filament::input.wrapper>
                    </div>

                    <x-filament::input.wrapper label="{{ __('Keep last N recordings') }}">
                        <x-filament::input type="number" wire:model.live="seriesKeepLast" min="1"
                            placeholder="{{ __('All recordings') }}" />
                    </x-filament::input.wrapper>

                    <x-filament::button
                        wire:click="recordSeriesWithOptions({{ \Illuminate\Support\Js::from($show['title']) }})"
                        color="primary" class="w-full">
                        {{ __('Save Series Rule') }}
                    </x-filament::button>
                </div>
            </div>
        </div>
    </div>
@else
<div class="flex flex-col items-center justify-center py-12 text-center">
    <svg class="w-12 h-12 text-gray-300 dark:text-gray-600 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
            d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
    </svg>
    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Show details unavailable.') }}</p>
</div>
@endif