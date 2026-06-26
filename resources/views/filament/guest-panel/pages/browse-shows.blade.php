<x-filament-panels::page>
    {{-- Page description --}}
    <x-filament::callout icon="heroicon-o-magnifying-glass" color="primary">
        <x-slot name="description">
            {{ __('Search your EPG guide to find shows and movies, then create recording rules to capture them automatically. Schedule a single airing or set up a series rule to record every episode as it airs.') }}
        </x-slot>
    </x-filament::callout>

    {{-- Filter Form --}}
    <div class="rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 p-4">
        <form wire:submit="search">
            {{ $this->filtersForm }}
            <div class="flex justify-end mt-4">
                <x-filament::button type="submit" icon="heroicon-m-magnifying-glass">
                    {{ __('Search') }}
                </x-filament::button>
            </div>
        </form>
    </div>

    {{-- Results --}}
    @if ($searched)
        @if (empty($groupedShows))
            <div class="py-12 text-center text-sm text-gray-500 dark:text-gray-400">
                {{ __('No EPG programmes matched your search in the selected window.') }}
            </div>
        @else
            {{-- Result summary + top pagination --}}
            <div class="flex items-center justify-between mb-4">
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    @php
                        $pageFrom = ($currentPage - 1) * 20 + 1;
                        $pageTo = min($currentPage * 20, $totalShows);
                    @endphp
                    {{ __(':from–:to of :total shows', ['from' => $pageFrom, 'to' => $pageTo, 'total' => $totalShows]) }}
                </p>

                @if ($this->totalPages > 1)
                    <div class="flex items-center gap-2">
                        <x-filament::button wire:click="gotoPage({{ $currentPage - 1 }})" color="gray" size="sm"
                            :disabled="$currentPage <= 1" icon="heroicon-m-chevron-left">
                            {{ __('Prev') }}
                        </x-filament::button>
                        <span class="text-sm text-gray-500 dark:text-gray-400">
                            {{ __('Page :page of :total', ['page' => $currentPage, 'total' => $this->totalPages]) }}
                        </span>
                        <x-filament::button wire:click="gotoPage({{ $currentPage + 1 }})" color="gray" size="sm"
                            :disabled="$currentPage >= $this->totalPages" icon="heroicon-m-chevron-right" icon-position="after">
                            {{ __('Next') }}
                        </x-filament::button>
                    </div>
                @endif
            </div>

            {{-- Poster Card Grid --}}
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
                @foreach ($groupedShows as $show)
                    <div class="relative flex flex-col rounded-xl overflow-visible bg-gray-100 dark:bg-gray-900 border border-gray-200 dark:border-white/10 shadow">

                        {{-- Poster area --}}
                        <button type="button"
                            class="relative aspect-[2/3] rounded-t-xl overflow-hidden bg-gray-200 dark:bg-gray-800 cursor-pointer w-full text-left focus:outline-none focus:ring-2 focus:ring-inset focus:ring-primary-500"
                            wire:click="openShowDetail({{ \Illuminate\Support\Js::from($show['title']) }})">

                            @if ($show['poster_url'])
                                <img src="{{ $show['poster_url'] }}" alt="{{ $show['title'] }}"
                                    class="absolute inset-0 w-full h-full object-cover" loading="lazy"
                                    decoding="async" />
                            @elseif($postersLoaded && $show['epg_icon'])
                                <img src="{{ $show['epg_icon'] }}" alt="{{ $show['title'] }}"
                                    class="absolute inset-0 w-full h-full object-contain p-6" loading="lazy"
                                    decoding="async" />
                            @elseif($postersLoaded)
                                <div
                                    class="absolute inset-0 flex flex-col items-center justify-center text-gray-400 dark:text-gray-600 px-3 text-center gap-2">
                                    <x-filament::icon icon="heroicon-o-film" class="w-10 h-10 opacity-40" />
                                    <span class="text-xs leading-tight opacity-60">{{ $show['title'] }}</span>
                                </div>
                            @else
                                <div
                                    class="absolute inset-0 animate-pulse bg-gradient-to-b from-gray-300 to-gray-200 dark:from-gray-700 dark:to-gray-800">
                                </div>
                            @endif

                            @if ($show['has_series_rule'])
                                <div class="absolute top-2 right-2">
                                    <x-filament::badge color="success">{{ __('Series') }}</x-filament::badge>
                                </div>
                            @elseif($show['has_once_rule'])
                                <div class="absolute top-2 right-2">
                                    <x-filament::badge color="info">{{ __('Scheduled') }}</x-filament::badge>
                                </div>
                            @endif

                            <div class="absolute top-2 left-2 flex flex-col gap-1">
                                @if ($show['flags']['is_new'])
                                    <x-filament::badge color="success">{{ __('New') }}</x-filament::badge>
                                @endif
                                @if ($show['flags']['premiere'])
                                    <x-filament::badge color="warning">{{ __('Premiere') }}</x-filament::badge>
                                @endif
                            </div>
                        </button>

                        {{-- Card footer --}}
                        <div class="p-3 flex items-start justify-between gap-2">
                            <button class="flex-1 min-w-0 text-left"
                                wire:click="openShowDetail({{ \Illuminate\Support\Js::from($show['title']) }})">
                                <p class="text-sm font-semibold text-gray-900 dark:text-white truncate">
                                    {{ $show['title'] }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                    <span
                                        class="font-semibold text-gray-600 dark:text-gray-300">{{ __('Airing Next:') }}</span>
                                    {{ $show['next_air_date_human'] ?? '—' }}
                                </p>
                            </button>

                            {{-- Actions menu --}}
                            <x-filament::dropdown placement="top-end">
                                <x-slot name="trigger">
                                    <x-filament::icon-button icon="heroicon-o-ellipsis-vertical"
                                        color="primary" size="sm" />
                                </x-slot>
                                <x-filament::dropdown.list>
                                    <x-filament::dropdown.list.item
                                        wire:click="openShowDetail({{ \Illuminate\Support\Js::from($show['title']) }})"
                                        icon="heroicon-o-information-circle">
                                        {{ __('View Details') }}
                                    </x-filament::dropdown.list.item>
                                    <x-filament::dropdown.list.item
                                        wire:click="quickRecordNextAiring({{ \Illuminate\Support\Js::from($show['title']) }})"
                                        icon="heroicon-o-play-circle">
                                        {{ __('Quick Record Next Airing') }}
                                    </x-filament::dropdown.list.item>
                                    <x-filament::dropdown.list.item
                                        wire:click="recordSeriesDefaults({{ \Illuminate\Support\Js::from($show['title']) }})"
                                        icon="heroicon-o-queue-list">
                                        {{ __('Record Series (defaults)') }}
                                    </x-filament::dropdown.list.item>
                                </x-filament::dropdown.list>
                            </x-filament::dropdown>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Pagination (bottom) --}}
            @if ($this->totalPages > 1)
                <div class="flex items-center justify-between mt-6 pt-4 border-t border-gray-200 dark:border-white/10">
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ __('Page :page of :total', ['page' => $currentPage, 'total' => $this->totalPages]) }}
                    </p>
                    <div class="flex items-center gap-2">
                        <x-filament::button wire:click="gotoPage({{ $currentPage - 1 }})" color="gray" size="sm"
                            :disabled="$currentPage <= 1" icon="heroicon-m-chevron-left">
                            {{ __('Prev') }}
                        </x-filament::button>

                        @php
                            $total = $this->totalPages;
                            $current = $currentPage;
                            $window = 3;
                            $start = max(1, $current - $window);
                            $end = min($total, $current + $window);
                        @endphp
                        @if ($start > 1)
                            <x-filament::button wire:click="gotoPage(1)" color="gray"
                                size="sm">1</x-filament::button>
                            @if ($start > 2)
                                <span class="text-gray-400 text-sm">…</span>
                            @endif
                        @endif
                        @for ($p = $start; $p <= $end; $p++)
                            <x-filament::button wire:click="gotoPage({{ $p }})"
                                color="{{ $p === $current ? 'primary' : 'gray' }}"
                                size="sm">{{ $p }}</x-filament::button>
                        @endfor
                        @if ($end < $total)
                            @if ($end < $total - 1)
                                <span class="text-gray-400 text-sm">…</span>
                            @endif
                            <x-filament::button wire:click="gotoPage({{ $total }})" color="gray"
                                size="sm">{{ $total }}</x-filament::button>
                        @endif

                        <x-filament::button wire:click="gotoPage({{ $currentPage + 1 }})" color="gray"
                            size="sm" :disabled="$currentPage >= $this->totalPages" icon="heroicon-m-chevron-right" icon-position="after">
                            {{ __('Next') }}
                        </x-filament::button>
                    </div>
                </div>
            @endif
        @endif
    @endif
</x-filament-panels::page>
