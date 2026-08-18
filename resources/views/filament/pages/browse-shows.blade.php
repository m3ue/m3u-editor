<x-filament-panels::page>
    @if ($this->timezoneNotSet)
        <div class="mb-6 rounded-lg border border-amber-300 bg-amber-50 p-4 dark:border-amber-700 dark:bg-amber-900/20">
            <div class="flex items-start gap-3">
                <x-filament::icon
                    icon="heroicon-o-exclamation-triangle"
                    class="mt-0.5 h-5 w-5 flex-shrink-0 text-amber-600 dark:text-amber-400"
                />
                <div class="flex-1 text-sm">
                    <p class="font-medium text-amber-800 dark:text-amber-200">{{ __('Timezone not configured') }}</p>
                    <p class="mt-1 text-amber-700 dark:text-amber-300">
                        {{ __('Air times are shown in UTC. To see times in your local timezone,') }}
                        <a
                            href="{{ \App\Filament\Pages\Preferences::getUrl() }}"
                            class="font-medium underline hover:text-amber-900 dark:hover:text-amber-100"
                        >
                            {{ __('set your timezone in Preferences') }} </a
                        >.
                    </p>
                </div>
            </div>
        </div>
    @endif

    {{-- Page description --}}
    <x-filament::callout icon="heroicon-o-magnifying-glass" color="primary">
        <x-slot name="description">
            {{ __('Search your EPG guide to find shows and movies, then create recording rules to capture them automatically. Schedule a single airing or set up a series rule to record every episode as it airs.') }}
        </x-slot>
    </x-filament::callout>

    {{-- Filter Form --}}
    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
        <form wire:submit="search">
            {{ $this->filtersForm }}
            <div class="mt-4 flex justify-end">
                <x-filament::button type="submit" icon="heroicon-m-magnifying-glass">
                    {{ __('Search') }}
                </x-filament::button>
            </div>
        </form>
    </div>

    {{-- Loading indicator --}}
    <div wire:loading wire:target="search,gotoPage" class="py-12">
        <div class="flex items-center justify-center gap-2">
            <x-filament::loading-indicator class="h-5 w-5 text-indigo-500 dark:text-indigo-400" />
            <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('Loading results...') }}</span>
        </div>
    </div>

    {{-- Results --}}
    @if ($searched)
        @if (empty($shows))
            <div class="py-12 text-center text-sm text-gray-500 dark:text-gray-400">
                {{ __('No EPG programmes matched your search in the selected window.') }}
            </div>
        @else
            {{-- Result summary --}}
            <div class="mb-4 flex items-center justify-between">
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    @php
                        $pageFrom = ($currentPage - 1) * 20 + 1;
                        $pageTo = min($currentPage * 20, $totalShows);
                    @endphp
                    {{ __(':from-:to of :total shows', ['from' => $pageFrom, 'to' => $pageTo, 'total' => $totalShows]) }}
                </p>

                @if ($this->totalPages > 1)
                    <div class="flex items-center gap-2">
                        <x-filament::button
                            wire:click="gotoPage({{ $currentPage - 1 }})"
                            color="gray"
                            size="sm"
                            :disabled="$currentPage <= 1"
                            icon="heroicon-m-chevron-left"
                        >
                            {{ __('Prev') }}
                        </x-filament::button>
                        <span class="text-sm text-gray-500 dark:text-gray-400">
                            {{ __('Page :page of :total', ['page' => $currentPage, 'total' => $this->totalPages]) }}
                        </span>
                        <x-filament::button
                            wire:click="gotoPage({{ $currentPage + 1 }})"
                            color="gray"
                            size="sm"
                            :disabled="$currentPage >= $this->totalPages"
                            icon="heroicon-m-chevron-right"
                            icon-position="after"
                        >
                            {{ __('Next') }}
                        </x-filament::button>
                    </div>
                @endif
            </div>

            {{-- Card grid --}}
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                @foreach ($shows as $index => $show)
                    <div class="relative flex flex-col overflow-visible rounded-xl border border-gray-200 bg-gray-100 dark:border-white/10 dark:bg-gray-900">
                        {{-- Poster area --}}
                        <button
                            type="button"
                            class="focus:ring-primary-500 relative aspect-[2/3] w-full cursor-pointer overflow-hidden rounded-t-xl bg-gray-200 text-left focus:ring-2 focus:outline-none focus:ring-inset dark:bg-gray-800"
                            wire:click="openShowDetail({{ \Illuminate\Support\Js::from($show['title']) }})"
                        >
                            @if ($show['poster_url'])
                                <img
                                    src="{{ $show['poster_url'] }}"
                                    alt="{{ $show['title'] }}"
                                    class="absolute inset-0 h-full w-full object-cover"
                                    loading="lazy"
                                    decoding="async"
                                />
                            @elseif ($postersLoaded && $show['epg_icon'])
                                <img
                                    src="{{ $show['epg_icon'] }}"
                                    alt="{{ $show['title'] }}"
                                    class="absolute inset-0 h-full w-full object-contain p-6"
                                    loading="lazy"
                                    decoding="async"
                                />
                            @elseif ($postersLoaded)
                                <div class="absolute inset-0 flex flex-col items-center justify-center gap-2 px-3 text-center text-gray-400 dark:text-gray-600">
                                    <x-filament::icon icon="heroicon-o-film" class="h-10 w-10 opacity-40" />
                                    <span class="text-xs leading-tight opacity-60">{{ $show['title'] }}</span>
                                </div>
                            @else
                                <div class="absolute inset-0 animate-pulse bg-gradient-to-b from-gray-300 to-gray-200 dark:from-gray-700 dark:to-gray-800"></div>
                            @endif

                            @if ($show['has_series_rule'])
                                <div class="absolute top-2 right-2 inline-flex items-center rounded-md bg-gray-900/70 ring-1 ring-white/10 backdrop-blur-sm">
                                    <x-filament::badge color="success" icon="heroicon-s-queue-list" size="sm" class="!bg-emerald-600 !text-white">
                                        {{ __('Series') }}
                                    </x-filament::badge>
                                </div>
                            @elseif ($show['has_once_rule'])
                                <div class="absolute top-2 right-2 inline-flex items-center rounded-md bg-gray-900/70 ring-1 ring-white/10 backdrop-blur-sm">
                                    <x-filament::badge color="info" icon="heroicon-s-clock" size="sm">
                                        {{ __('Scheduled') }}
                                    </x-filament::badge>
                                </div>
                            @endif

                            <div class="absolute top-2 left-2 flex flex-col items-start gap-1">
                                @if ($show['flags']['is_new'])
                                    <div class="inline-flex items-center rounded-md bg-gray-900/70 ring-1 ring-white/10 backdrop-blur-sm">
                                        <x-filament::badge color="success" icon="heroicon-s-sparkles" size="sm" class="!bg-emerald-600 !text-white">
                                            {{ __('New') }}
                                        </x-filament::badge>
                                    </div>
                                @endif
                                @if ($show['flags']['premiere'])
                                    <div class="inline-flex items-center rounded-md bg-gray-900/70 ring-1 ring-white/10 backdrop-blur-sm">
                                        <x-filament::badge color="warning" icon="heroicon-s-star" size="sm">
                                            {{ __('Premiere') }}
                                        </x-filament::badge>
                                    </div>
                                @endif
                            </div>
                        </button>

                        {{-- Card footer --}}
                        <div class="flex items-start justify-between gap-2 p-3">
                            <button
                                class="min-w-0 flex-1 text-left"
                                wire:click="openShowDetail({{ \Illuminate\Support\Js::from($show['title']) }})"
                            >
                                <p class="truncate text-sm font-semibold text-gray-900 dark:text-white">
                                    {{ $show['title'] }}
                                </p>
                                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                    <span class="font-semibold text-gray-600 dark:text-gray-300">{{ __('Airing Next:') }}</span>
                                    {{ $show['next_air_date_human'] ?? '-' }}
                                </p>
                            </button>

                            {{-- Actions menu --}}
                            <x-filament::dropdown placement="top-end">
                                <x-slot name="trigger">
                                    <x-filament::icon-button
                                        icon="heroicon-o-ellipsis-vertical"
                                        color="primary"
                                        size="sm"
                                    />
                                </x-slot>
                                <x-filament::dropdown.list>
                                    <x-filament::dropdown.list.item
                                        wire:click="openShowDetail({{ \Illuminate\Support\Js::from($show['title']) }})"
                                        icon="heroicon-o-information-circle"
                                    >
                                        {{ __('View Details') }}
                                    </x-filament::dropdown.list.item>
                                    <x-filament::dropdown.list.item
                                        wire:click="quickRecordNextAiring({{ \Illuminate\Support\Js::from($show['title']) }})"
                                        icon="heroicon-o-play-circle"
                                    >
                                        {{ __('Quick Record Next Airing') }}
                                    </x-filament::dropdown.list.item>
                                    <x-filament::dropdown.list.item
                                        wire:click="recordSeriesDefaults({{ \Illuminate\Support\Js::from($show['title']) }})"
                                        icon="heroicon-o-queue-list"
                                    >
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
                <div class="mt-6 flex items-center justify-between border-t border-gray-200 pt-4 dark:border-white/10">
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ __('Page :page of :total', ['page' => $currentPage, 'total' => $this->totalPages]) }}
                    </p>
                    <div class="flex items-center gap-2">
                        <x-filament::button
                            wire:click="gotoPage({{ $currentPage - 1 }})"
                            color="gray"
                            size="sm"
                            :disabled="$currentPage <= 1"
                            icon="heroicon-m-chevron-left"
                        >
                            {{ __('Prev') }}
                        </x-filament::button>

                        {{-- Page number buttons (show up to 7 pages centred on current) --}}
                        @php
                            $total = $this->totalPages;
                            $current = $currentPage;
                            $window = 3; // pages each side
                            $start = max(1, $current - $window);
                            $end = min($total, $current + $window);
                        @endphp
                        @if ($start > 1)
                            <x-filament::button wire:click="gotoPage(1)" color="gray" size="sm">1</x-filament::button>
                            @if ($start > 2)
                                <span class="text-sm text-gray-400">…</span>
                            @endif
                        @endif
                        @for ($p = $start; $p <= $end; $p++)
                            <x-filament::button
                                wire:click="gotoPage({{ $p }})"
                                color="{{ $p === $current ? 'primary' : 'gray' }}"
                                size="sm"
                            >{{ $p }}</x-filament::button>
                        @endfor
                        @if ($end < $total)
                            @if ($end < $total - 1)
                                <span class="text-sm text-gray-400">…</span>
                            @endif
                            <x-filament::button
                                wire:click="gotoPage({{ $total }})"
                                color="gray"
                                size="sm"
                            >{{ $total }}</x-filament::button>
                        @endif

                        <x-filament::button
                            wire:click="gotoPage({{ $currentPage + 1 }})"
                            color="gray"
                            size="sm"
                            :disabled="$currentPage >= $this->totalPages"
                            icon="heroicon-m-chevron-right"
                            icon-position="after"
                        >
                            {{ __('Next') }}
                        </x-filament::button>
                    </div>
                </div>
            @endif
        @endif
    @endif
</x-filament-panels::page>
