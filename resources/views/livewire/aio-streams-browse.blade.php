{{--
    Bulk selection lives in the browser, in a global Alpine store: the cards that
    render it sit in both this component (search results) and each
    AioStreamsCatalogRow child, and a store is reachable from all of them however
    Livewire nests the components. Toggling never costs a server round trip; the
    selection reaches the server only as the bulkAddToLibrary action's `items`.
--}}
<div
    x-data
    x-init="
        Alpine.store('aioSelection', {
            canSelect: @js(! $guestMode),
            max: @js(\App\Jobs\AddAioStreamsItemsToLibrary::MAX_ITEMS),
            items: {},
            count() {
                return Object.keys(this.items).length
            },
            any() {
                return this.count() > 0
            },
            has(key) {
                return key in this.items
            },
            toggle(item) {
                const key = item.type + ':' + item.id
                if (key in this.items) {
                    delete this.items[key]
                } else if (this.count() < this.max) {
                    this.items[key] = item
                }
            },
            clear() {
                this.items = {}
            },
            list() {
                return Object.values(this.items)
            },
        })
    "
    x-on:aio-selection-cleared.window="$store.aioSelection.clear()"
>
    @if (! $this->integration)
        <div class="flex flex-col items-center justify-center py-12 text-center">
            <x-heroicon-o-exclamation-triangle class="h-12 w-12 text-gray-300 dark:text-gray-600" />
            <h4 class="mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">
                {{ __('AIOStreams integration not found or disabled') }}
            </h4>
        </div>
    @else
        <div class="space-y-6">
            {{-- ── Search Bar & Type Filter ───────────────────────────────── --}}
            <div class="flex flex-col gap-2 sm:flex-row">
                <form wire:submit.prevent="search" class="flex flex-1 gap-2">
                    <x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass" class="flex-1">
                        <x-filament::input
                            type="text"
                            wire:model="searchTerm"
                            placeholder="{{ __('Search movies & TV series...') }}"
                        />
                    </x-filament::input.wrapper>
                    @if (mb_strlen(trim($searchTerm)) >= 2)
                        <x-filament::button wire:click="clearSearch" color="gray" icon="heroicon-o-x-mark">
                            {{ __('Clear') }}
                        </x-filament::button>
                    @endif
                    <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="search">
                        {{ __('Search') }}
                    </x-filament::button>
                </form>

                <div class="flex gap-1.5">
                    @foreach (['all' => __('All'), 'movie' => __('Movies'), 'series' => __('Series')] as $value => $label)
                        <x-filament::button
                            size="sm"
                            wire:click="$set('typeFilter', '{{ $value }}')"
                            :icon="$value === 'movie' ? 'heroicon-o-film' : ($value === 'series' ? 'heroicon-o-tv' : 'heroicon-o-rectangle-stack')"
                            :color="$typeFilter === $value ? 'primary' : 'gray'"
                            :outlined="$typeFilter !== $value"
                        >
                            {{ $label }}
                        </x-filament::button>
                    @endforeach
                </div>
            </div>

            {{-- ── Search Results ──────────────────────────────────────────── --}}
            @if (mb_strlen(trim($searchTerm)) >= 2 || $isSearching)
                <x-filament::section
                    :collapsible="true"
                    compact
                    heading="{{ __('Search Results') }}"
                    icon="heroicon-o-magnifying-glass"
                    icon-color="gray"
                >
                    @if (count($searchResults) > 0)
                        <x-slot name="afterHeader">
                            <x-filament::badge color="gray">{{ count($searchResults) }}</x-filament::badge>
                        </x-slot>
                    @endif

                    @if ($isSearching)
                        <div class="flex items-center justify-center py-10">
                            <x-filament::loading-indicator class="text-primary-500 h-5 w-5" />
                            <span class="ml-3 text-sm text-gray-500 dark:text-gray-400">{{ __('Searching...') }}</span>
                        </div>
                    @elseif (count($searchResults) > 0)
                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5">
                            @foreach ($searchResults as $item)
                                @include('livewire.partials.aiostreams-card', ['item' => $item])
                            @endforeach
                        </div>
                    @else
                        <div class="flex flex-col items-center justify-center py-10 text-center">
                            <x-heroicon-o-magnifying-glass class="h-10 w-10 text-gray-300 dark:text-gray-600" />
                            <h4 class="mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">
                                {{ __('No results found') }}
                            </h4>
                        </div>
                    @endif
                </x-filament::section>
            @else
                {{-- ── Continue Watching ───────────────────────────────────── --}}
                @if ($this->continueWatching->isNotEmpty())
                    <x-filament::section
                        :collapsible="true"
                        compact
                        heading="{{ __('Continue Watching') }}"
                        icon="heroicon-o-play-pause"
                        icon-color="gray"
                    >
                        <x-slot name="afterHeader">
                            <x-filament::badge color="gray">{{ $this->continueWatching->count() }}</x-filament::badge>
                        </x-slot>

                        <div class="flex gap-3 overflow-x-auto pb-1">
                            @foreach ($this->continueWatching as $progress)
                                @php
                                    $pct = $progress->duration_seconds
                                        ? min(
                                            100,
                                            (int) round(
                                                ($progress->position_seconds / $progress->duration_seconds) * 100,
                                            ),
                                        )
                                        : 0;
                                    $isEpisode = (bool) $progress->season_number;
                                    $durationLabel = null;
                                    if ($progress->duration_seconds) {
                                        $h = intdiv($progress->duration_seconds, 3600);
                                        $m = intdiv($progress->duration_seconds % 3600, 60);
                                        $durationLabel = $h > 0 ? "{$h}h {$m}m" : "{$m}m";
                                    }
                                @endphp
                                <div
                                    wire:key="continue-{{ $progress->id }}"
                                    wire:click="resumeWatch({{ $progress->id }})"
                                    class="group w-64 flex-shrink-0 cursor-pointer overflow-hidden rounded-lg bg-gray-200 shadow-sm transition-shadow duration-200 hover:shadow-xl dark:bg-gray-800"
                                >
                                    <div class="relative aspect-video">
                                        @if ($progress->backdrop_url || $progress->thumbnail_url)
                                            <img
                                                src="{{ $progress->backdrop_url ?: $progress->thumbnail_url }}"
                                                alt="{{ $progress->title }}"
                                                class="h-full w-full object-cover"
                                                loading="lazy"
                                            />
                                        @else
                                            <div class="flex h-full w-full items-center justify-center bg-gray-200 dark:bg-gray-700">
                                                <x-heroicon-o-film class="h-8 w-8 text-gray-400 dark:text-gray-500" />
                                            </div>
                                        @endif

                                        <span class="bg-primary-600 absolute top-2 right-2 rounded px-1.5 py-0.5 text-xs font-semibold text-white">
                                            {{ $pct }}%
                                        </span>

                                        <div class="absolute bottom-2 left-2">
                                            @if ($isEpisode)
                                                <span class="rounded bg-black/70 px-1.5 py-0.5 text-xs font-medium text-white">
                                                    {{ __('S:s E:e', ['s' => $progress->season_number, 'e' => $progress->episode_number]) }}
                                                </span>
                                            @elseif ($progress->year)
                                                <span class="rounded bg-black/70 px-1.5 py-0.5 text-xs font-medium text-white">
                                                    {{ $progress->year }}
                                                </span>
                                            @endif
                                        </div>

                                        <div class="absolute right-2 bottom-2 flex items-center gap-1">
                                            @if ($progress->rating)
                                                <span class="flex items-center gap-0.5 rounded bg-black/70 px-1.5 py-0.5 text-xs font-semibold text-yellow-400">
                                                    <x-heroicon-s-star class="h-3 w-3" />
                                                    {{ $progress->rating }}
                                                </span>
                                            @endif
                                            @if ($durationLabel)
                                                <span class="rounded bg-black/70 px-1.5 py-0.5 text-xs font-medium text-white">
                                                    {{ $durationLabel }}
                                                </span>
                                            @endif
                                        </div>

                                        <div class="absolute right-0 bottom-0 left-0 h-1 bg-black/40">
                                            <div class="bg-primary-500 h-full" style="width: {{ $pct }}%"></div>
                                        </div>
                                    </div>
                                    <div class="p-2">
                                        <p class="truncate text-sm font-medium text-gray-900 dark:text-gray-100">
                                            {{ $progress->title }}
                                        </p>
                                        @if ($progress->episode_title)
                                            <p class="truncate text-xs text-gray-500 dark:text-gray-400">
                                                {{ $progress->episode_title }}
                                            </p>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </x-filament::section>
                @endif

                {{-- ── Catalog Rows — each section's items load only once scrolled into view ──── --}}
                @if (empty($this->catalogs))
                    <div class="flex flex-col items-center justify-center py-12 text-center">
                        <x-heroicon-o-film class="h-10 w-10 text-gray-300 dark:text-gray-600" />
                        <h4 class="mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">
                            {{ __('No catalogs available') }}
                        </h4>
                    </div>
                @else
                    <div class="space-y-6">
                        @foreach ($this->catalogs as $catalog)
                            <livewire:aio-streams-catalog-row
                                :integration-id="$integrationId"
                                :catalog-id="$catalog['id']"
                                :catalog-type="$catalog['type']"
                                :catalog-name="$catalog['name']"
                                :key="'aiostreams-row-' .
                                    $integrationId .
                                    '-' .
                                    $catalog['id'] .
                                    '-' .
                                    $catalog['type'] .
                                    '-' .
                                    $typeFilter"
                            />
                        @endforeach
                    </div>
                @endif
            @endif
        </div>

        @if (! $guestMode)
            <div
                x-cloak
                x-show="$store.aioSelection.any()"
                x-transition
                class="fixed inset-x-4 bottom-4 z-30 mx-auto flex max-w-xl flex-wrap items-center justify-between gap-3 rounded-xl bg-white p-3 shadow-lg ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
            >
                <div class="text-sm font-medium text-gray-950 dark:text-white">
                    <span x-text="@js(__(':count selected')).replace(':count', $store.aioSelection.count())"></span>
                    <span
                        x-show="$store.aioSelection.count() >= $store.aioSelection.max"
                        class="ml-1 text-xs font-normal text-gray-500 dark:text-gray-400"
                        x-text="@js(__('(limit :max per add)')).replace(':max', $store.aioSelection.max)"
                    ></span>
                </div>
                <div class="flex gap-2">
                    <x-filament::button color="gray" size="sm" x-on:click="$store.aioSelection.clear()">
                        {{ __('Deselect all') }}
                    </x-filament::button>
                    <x-filament::button
                        size="sm"
                        icon="heroicon-o-plus-circle"
                        x-on:click="$wire.mountAction('bulkAddToLibrary', { items: $store.aioSelection.list() })"
                    >
                        {{ __('Add to Library') }}
                    </x-filament::button>
                </div>
            </div>
        @endif
    @endif

    <x-filament-actions::modals />
</div>
