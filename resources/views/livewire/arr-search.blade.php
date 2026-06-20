<div wire:init="loadDiscover"
    @if ($guestMode && $queuePolling) wire:poll.{{ $this->queuePollInterval }}s="loadQueue" @endif>

    @if ($this->integrationsForSearch->isNotEmpty())

        <div class="space-y-6">

            {{-- ── Search Bar ─────────────────────────────────────────────── --}}
            <form wire:submit.prevent="search" class="relative">
                <input type="text" wire:model.live.debounce.300ms="searchTerm"
                    placeholder="{{ __('Search movies & TV series...') }}"
                    class="w-full pl-10 pr-4 py-3 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 focus:border-primary-500 focus:ring-primary-500 text-sm">
                <x-heroicon-o-magnifying-glass class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" />
                <div wire:loading wire:target="search" class="absolute right-3 top-1/2 -translate-y-1/2">
                    <x-filament::loading-indicator class="h-5 w-5 text-primary-500" />
                </div>
            </form>

            {{-- ── Search Results ──────────────────────────────────────────── --}}
            @if (strlen(trim($searchTerm)) >= 2 || $isSearching)

                <x-filament::section :collapsible="true" compact heading="{{ __('Search Results') }}"
                    icon="heroicon-o-magnifying-glass" icon-color="gray">
                    @if (count($results) > 0)
                        <x-slot name="afterHeader">
                            <x-filament::badge color="gray">{{ count($results) }}</x-filament::badge>
                        </x-slot>
                    @endif

                    <div>
                        @if ($isSearching)
                            <div class="flex items-center justify-center py-10">
                                <x-filament::loading-indicator class="h-7 w-7 text-primary-500" />
                                <span
                                    class="ml-3 text-sm text-gray-500 dark:text-gray-400">{{ __('Searching...') }}</span>
                            </div>
                        @elseif(count($results) > 0)
                            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">
                                @foreach ($results as $index => $result)
                                    @php
                                        $isSonarr = ($result['integrationType'] ?? '') === 'sonarr';
                                        $inLibrary = !empty($result['existsInLibrary']);
                                        $isDownloaded =
                                            $inLibrary &&
                                            ($isSonarr
                                                ? ($result['episodeFileCount'] ?? 0) > 0
                                                : $result['hasFile'] ?? false);
                                    @endphp
                                    <div wire:click="openDetail({{ $index }})"
                                        class="group relative cursor-pointer rounded-lg overflow-hidden shadow-sm hover:shadow-xl transition-shadow duration-200 bg-gray-200 dark:bg-gray-800">
                                        <div class="relative aspect-[2/3]">
                                            @if (!empty($result['poster']))
                                                <img src="{{ $result['poster'] }}" alt="{{ $result['title'] ?? '' }}"
                                                    class="w-full h-full object-cover" loading="lazy">
                                            @else
                                                <div
                                                    class="w-full h-full bg-gray-200 dark:bg-gray-700 flex items-center justify-center">
                                                    @if ($isSonarr)
                                                        <x-heroicon-o-tv
                                                            class="w-10 h-10 text-gray-400 dark:text-gray-500" />
                                                    @else
                                                        <x-heroicon-o-film
                                                            class="w-10 h-10 text-gray-400 dark:text-gray-500" />
                                                    @endif
                                                </div>
                                            @endif
                                            <span
                                                class="absolute top-2 left-2 px-1.5 py-0.5 text-xs font-semibold rounded bg-black/60 text-white">
                                                {{ $isSonarr ? __('TV') : __('Movie') }}
                                            </span>
                                            @if ($isDownloaded)
                                                <span
                                                    class="absolute top-2 right-2 w-6 h-6 rounded-full bg-green-500 flex items-center justify-center shadow-sm">
                                                    <x-heroicon-s-check class="w-3.5 h-3.5 text-white" />
                                                </span>
                                            @elseif($inLibrary)
                                                <span
                                                    class="absolute top-2 right-2 w-6 h-6 rounded-full bg-amber-500 flex items-center justify-center shadow-sm">
                                                    <x-heroicon-s-bookmark class="w-3.5 h-3.5 text-white" />
                                                </span>
                                            @endif
                                            @if (!empty($result['rating']['value']))
                                                <span
                                                    class="absolute bottom-2 right-2 flex items-center gap-0.5 px-1.5 py-0.5 rounded bg-black/70 text-yellow-400 text-xs font-semibold">
                                                    <x-heroicon-s-star class="w-3 h-3" />
                                                    {{ $result['rating']['value'] }}
                                                </span>
                                            @endif
                                            @if (!empty($result['integrationName']))
                                                <span
                                                    class="absolute bottom-2 left-2 px-1.5 py-0.5 text-xs font-medium rounded text-white shadow-sm"
                                                    style="background:{{ $isSonarr ? 'oklch(0.588 0.158 241.966)' : 'oklch(0.558 0.288 302.321)' }}">{{ $result['integrationName'] }}</span>
                                            @endif
                                            <div
                                                class="absolute inset-0 opacity-0 group-hover:opacity-100 transition-opacity duration-200 bg-gradient-to-t from-black/95 via-black/65 to-black/20 flex flex-col justify-end p-3 gap-1">
                                                <h3 class="text-white font-semibold text-sm leading-tight line-clamp-2">
                                                    {{ $result['title'] ?? '' }}
                                                    @if (!empty($result['year']))
                                                        <span
                                                            class="text-white/60 font-normal">({{ $result['year'] }})</span>
                                                    @endif
                                                </h3>
                                                @if (!empty($result['overview']))
                                                    <p class="text-white/55 text-xs line-clamp-2">
                                                        {{ $result['overview'] }}</p>
                                                @endif
                                                <div class="mt-1.5">
                                                    @if ($isDownloaded)
                                                        <span
                                                            class="flex items-center gap-1 text-green-400 text-xs font-medium">
                                                            <x-heroicon-s-check class="w-3.5 h-3.5" />
                                                            @if ($isSonarr && ($result['totalEpisodeCount'] ?? 0) > 0)
                                                                {{ $result['episodeFileCount'] }}/{{ $result['totalEpisodeCount'] }}
                                                                {{ __('eps') }}
                                                            @else
                                                                {{ __('Downloaded') }}
                                                            @endif
                                                        </span>
                                                    @elseif($inLibrary)
                                                        <span
                                                            class="flex items-center gap-1 text-amber-400 text-xs font-medium">
                                                            <x-heroicon-s-bookmark class="w-3.5 h-3.5" />
                                                            {{ __('Monitored') }}
                                                        </span>
                                                    @elseif($isSonarr)
                                                        <span
                                                            class="block w-full text-center px-2 py-1.5 rounded-md bg-primary-600 text-white text-xs font-medium">
                                                            {{ __('Select Seasons') }}
                                                        </span>
                                                    @else
                                                        <button wire:click.stop="request({{ $index }})"
                                                            wire:loading.attr="disabled" wire:target="request"
                                                            class="block w-full text-center px-2 py-1.5 rounded-md bg-primary-600 hover:bg-primary-700 disabled:opacity-60 text-white text-xs font-medium transition-colors">
                                                            {{ __('Request Movie') }}
                                                        </button>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="flex flex-col items-center justify-center py-10 text-center">
                                <x-heroicon-o-magnifying-glass class="w-10 h-10 text-gray-300 dark:text-gray-600" />
                                <h4 class="mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">
                                    {{ __('No results found') }}</h4>
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    {{ __('Try a different search term.') }}</p>
                            </div>
                        @endif
                    </div>
                </x-filament::section>

            @endif

            {{-- ── Discover Sections ────────────────────────────────────────── --}}
            @if ($tmdbConfigured && strlen(trim($searchTerm)) < 2)

                @if (!$discoverLoaded)
                    <div class="flex items-center justify-center py-16">
                        <x-filament::loading-indicator class="h-8 w-8 text-primary-500" />
                        <span
                            class="ml-3 text-sm text-gray-500 dark:text-gray-400">{{ __('Loading discover...') }}</span>
                    </div>
                @else
                    {{-- ── Genre Sections (always under search bar) ──────── --}}

                    @if (count($movieGenres) > 0 || count($tvGenres) > 0)
                        <x-filament::section :collapsible="true" compact :id="'genres'"
                            heading="{{ __('Browse by Genre') }}" icon="heroicon-o-tag" icon-color="primary">

                            <div x-data="{ tab: '{{ $browseGenreType ?? 'movie' }}' }" x-init="$watch(() => $wire.browseGenreType, v => { if (v) tab = v })">
                                @if (count($movieGenres) > 0 && count($tvGenres) > 0)
                                    <div class="flex gap-1 mb-4 bg-gray-100 dark:bg-gray-800 rounded-lg p-1 w-fit">
                                        <button @click="tab = 'movie'"
                                            :class="tab === 'movie' ?
                                                'bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 shadow-sm' :
                                                'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200'"
                                            class="flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-medium transition-all">
                                            <x-heroicon-o-film class="w-3.5 h-3.5" />
                                            {{ __('Movies') }}
                                        </button>
                                        <button @click="tab = 'tv'"
                                            :class="tab === 'tv' ?
                                                'bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 shadow-sm' :
                                                'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200'"
                                            class="flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-medium transition-all">
                                            <x-heroicon-o-tv class="w-3.5 h-3.5" />
                                            {{ __('TV') }}
                                        </button>
                                    </div>
                                @endif

                                @if (count($movieGenres) > 0)
                                    <div x-show="tab === 'movie'" x-transition:enter="transition ease-out duration-150"
                                        x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100">
                                        <div class="flex flex-wrap gap-1.5">
                                            @foreach ($movieGenres as $genre)
                                                @php $isActive = $browseGenreId === $genre['id'] && $browseGenreType === 'movie'; @endphp
                                                <button
                                                    wire:click="{{ $isActive ? 'clearBrowse' : 'browseGenre(' . $genre['id'] . ", 'movie')" }}"
                                                    @class([
                                                        'inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium transition-colors border',
                                                        'bg-primary-600 text-white border-primary-600' => $isActive,
                                                        'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 border-transparent hover:bg-primary-50 dark:hover:bg-primary-900/30 hover:text-primary-700 dark:hover:text-primary-300 hover:border-primary-200 dark:hover:border-primary-800' => !$isActive,
                                                    ])>
                                                    {{ $genre['name'] }}
                                                    @if ($isActive)
                                                        <x-heroicon-o-x-mark class="w-3 h-3 opacity-80" />
                                                    @endif
                                                </button>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                @if (count($tvGenres) > 0)
                                    <div x-show="tab === 'tv'" x-transition:enter="transition ease-out duration-150"
                                        x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100">
                                        <div class="flex flex-wrap gap-1.5">
                                            @foreach ($tvGenres as $genre)
                                                @php $isActive = $browseGenreId === $genre['id'] && $browseGenreType === 'tv'; @endphp
                                                <button
                                                    wire:click="{{ $isActive ? 'clearBrowse' : 'browseGenre(' . $genre['id'] . ", 'tv')" }}"
                                                    @class([
                                                        'inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium transition-colors border',
                                                        'bg-primary-600 text-white border-primary-600' => $isActive,
                                                        'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 border-transparent hover:bg-primary-50 dark:hover:bg-primary-900/30 hover:text-primary-700 dark:hover:text-primary-300 hover:border-primary-200 dark:hover:border-primary-800' => !$isActive,
                                                    ])>
                                                    {{ $genre['name'] }}
                                                    @if ($isActive)
                                                        <x-heroicon-o-x-mark class="w-3 h-3 opacity-80" />
                                                    @endif
                                                </button>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </x-filament::section>
                    @endif

                    {{-- ── Browse Results (genre selected) ──────────────── --}}
                    @if ($browseGenreId !== null)

                        @php
                            $browseName =
                                collect($browseGenreType === 'tv' ? $tvGenres : $movieGenres)->firstWhere(
                                    'id',
                                    $browseGenreId,
                                )['name'] ?? '';
                        @endphp

                        @php
                            $activeFilterCount =
                                (int) ($sortBy !== 'popularity') +
                                (int) ($yearFrom !== null || $yearTo !== null) +
                                (int) ($minRating > 0) +
                                (int) ($minVoteCount > 0) +
                                (int) ($minRuntime !== null || $maxRuntime !== null) +
                                (int) ($originalLanguage !== '') +
                                (int) !empty($selectedProviders) +
                                (int) !empty($tvStatuses);
                        @endphp

                        <div x-data="{ filtersOpen: @js($activeFilterCount > 0) }"
                            class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm overflow-hidden">
                            {{-- Header --}}
                            <div
                                class="flex items-center gap-2.5 px-4 py-3.5 border-b border-gray-200 dark:border-gray-700 bg-gray-50/80 dark:bg-white/5">
                                <button wire:click="clearBrowse"
                                    class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-md text-xs font-medium text-gray-600 dark:text-gray-300 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 border border-gray-200 dark:border-gray-700 transition-colors">
                                    <x-heroicon-o-arrow-left class="w-3.5 h-3.5" />
                                    {{ __('All Genres') }}
                                </button>
                                <span class="w-px h-4 bg-gray-200 dark:bg-gray-700"></span>
                                <h3 class="flex-1 text-sm font-semibold text-gray-900 dark:text-gray-100">
                                    {{ $browseName }}
                                    <span class="font-normal text-gray-400 dark:text-gray-500 ml-1">
                                        — {{ $browseGenreType === 'tv' ? __('TV Series') : __('Movies') }}
                                    </span>
                                </h3>
                                @if (count($browseResults) > 0)
                                    <span
                                        class="text-xs font-medium text-gray-400 dark:text-gray-500 mr-1">{{ count($browseResults) }}</span>
                                @endif
                                <button @click="filtersOpen = !filtersOpen"
                                    :class="filtersOpen ?
                                        'bg-primary-50 dark:bg-primary-900/30 border-primary-200 dark:border-primary-800 text-primary-700 dark:text-primary-300' :
                                        'bg-gray-100 dark:bg-gray-800 border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700'"
                                    class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-md text-xs font-medium border transition-colors">
                                    <x-heroicon-o-adjustments-horizontal class="w-3.5 h-3.5" />
                                    {{ __('Filters') }}
                                    @if ($activeFilterCount > 0)
                                        <span
                                            class="inline-flex items-center justify-center w-4 h-4 rounded-full bg-primary-600 text-white text-[10px] font-bold leading-none">{{ $activeFilterCount }}</span>
                                    @endif
                                </button>
                            </div>

                            {{-- Filter panel --}}
                            <div x-show="filtersOpen" x-collapse
                                class="border-b border-gray-200 dark:border-gray-700">
                                <div class="p-4 space-y-4 bg-gray-50/60 dark:bg-white/[0.02]">

                                    {{-- Row 1: Sort + Language --}}
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                        <div>
                                            <label
                                                class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">{{ __('Sort By') }}</label>
                                            <select wire:model.live="sortBy"
                                                class="w-full rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-xs py-1.5 px-2.5 focus:border-primary-500 focus:ring-primary-500">
                                                <option value="popularity">{{ __('Most Popular') }}</option>
                                                <option value="rating">{{ __('Highest Rated') }}</option>
                                                <option value="votes">{{ __('Most Voted') }}</option>
                                                <option value="newest">
                                                    {{ $browseGenreType === 'tv' ? __('Latest Air Date') : __('Newest Release') }}
                                                </option>
                                                <option value="oldest">
                                                    {{ $browseGenreType === 'tv' ? __('Earliest Air Date') : __('Oldest Release') }}
                                                </option>
                                                @if ($browseGenreType !== 'tv')
                                                    <option value="revenue">{{ __('Box Office') }}</option>
                                                @endif
                                            </select>
                                        </div>
                                        <div>
                                            <label
                                                class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">{{ __('Original Language') }}</label>
                                            <select wire:model.live="originalLanguage"
                                                class="w-full rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-xs py-1.5 px-2.5 focus:border-primary-500 focus:ring-primary-500">
                                                <option value="">{{ __('Any Language') }}</option>
                                                <option value="en">{{ __('English') }}</option>
                                                <option value="fr">{{ __('French') }}</option>
                                                <option value="de">{{ __('German') }}</option>
                                                <option value="es">{{ __('Spanish') }}</option>
                                                <option value="pt">{{ __('Portuguese') }}</option>
                                                <option value="it">{{ __('Italian') }}</option>
                                                <option value="ja">{{ __('Japanese') }}</option>
                                                <option value="ko">{{ __('Korean') }}</option>
                                                <option value="zh">{{ __('Chinese') }}</option>
                                                <option value="ru">{{ __('Russian') }}</option>
                                                <option value="ar">{{ __('Arabic') }}</option>
                                                <option value="hi">{{ __('Hindi') }}</option>
                                                <option value="nl">{{ __('Dutch') }}</option>
                                                <option value="sv">{{ __('Swedish') }}</option>
                                                <option value="pl">{{ __('Polish') }}</option>
                                                <option value="tr">{{ __('Turkish') }}</option>
                                                <option value="th">{{ __('Thai') }}</option>
                                            </select>
                                        </div>
                                    </div>

                                    {{-- Row 2: Year + Rating --}}
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                        <div>
                                            <label
                                                class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">
                                                {{ $browseGenreType === 'tv' ? __('First Air Year') : __('Release Year') }}
                                            </label>
                                            <div class="flex items-center gap-2">
                                                <input type="number" wire:model="yearFrom" min="1900"
                                                    max="{{ date('Y') + 1 }}" placeholder="{{ __('From') }}"
                                                    class="w-full rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-xs py-1.5 px-2.5 focus:border-primary-500 focus:ring-primary-500">
                                                <span class="text-gray-400 text-xs flex-shrink-0">—</span>
                                                <input type="number" wire:model="yearTo" min="1900"
                                                    max="{{ date('Y') + 1 }}" placeholder="{{ __('To') }}"
                                                    class="w-full rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-xs py-1.5 px-2.5 focus:border-primary-500 focus:ring-primary-500">
                                            </div>
                                        </div>
                                        <div x-data="{ r: @js($minRating) }" x-init="$watch(() => $wire.minRating, v => r = v ?? 0)">
                                            <div class="flex items-center justify-between mb-1.5">
                                                <label
                                                    class="text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('Min Rating') }}</label>
                                                <span
                                                    x-text="r > 0 ? '★ ' + parseFloat(r).toFixed(1) + '+' : '{{ __('Any') }}'"
                                                    :class="r > 0 ? 'text-yellow-500 dark:text-yellow-400 font-semibold' :
                                                        'text-gray-400'"
                                                    class="text-xs"></span>
                                            </div>
                                            <input type="range" x-model="r"
                                                @change="$wire.minRating = parseFloat(r)" min="0"
                                                max="10" step="0.5"
                                                class="w-full h-1.5 rounded-lg appearance-none cursor-pointer accent-primary-600 bg-gray-200 dark:bg-gray-700">
                                            <div class="flex justify-between text-[10px] text-gray-400 mt-0.5">
                                                <span>0</span><span>5</span><span>10</span>
                                            </div>
                                        </div>
                                    </div>

                                    {{-- Row 3: Runtime + Vote Count --}}
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                        <div>
                                            <label
                                                class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">{{ __('Runtime (minutes)') }}</label>
                                            <div class="flex items-center gap-2">
                                                <input type="number" wire:model="minRuntime" min="0"
                                                    max="400" placeholder="{{ __('Min') }}"
                                                    class="w-full rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-xs py-1.5 px-2.5 focus:border-primary-500 focus:ring-primary-500">
                                                <span class="text-gray-400 text-xs flex-shrink-0">—</span>
                                                <input type="number" wire:model="maxRuntime" min="0"
                                                    max="400" placeholder="{{ __('Max') }}"
                                                    class="w-full rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-xs py-1.5 px-2.5 focus:border-primary-500 focus:ring-primary-500">
                                            </div>
                                        </div>
                                        <div>
                                            <div class="flex items-center justify-between mb-1.5">
                                                <label
                                                    class="text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('Min Vote Count') }}</label>
                                                @if ($minVoteCount > 0)
                                                    <span
                                                        class="text-xs font-semibold text-primary-600 dark:text-primary-400">{{ number_format($minVoteCount) }}+</span>
                                                @else
                                                    <span class="text-xs text-gray-400">{{ __('Any') }}</span>
                                                @endif
                                            </div>
                                            <input type="range" wire:model="minVoteCount" min="0"
                                                max="5000" step="50"
                                                class="w-full h-1.5 rounded-lg appearance-none cursor-pointer accent-primary-600 bg-gray-200 dark:bg-gray-700">
                                            <div class="flex justify-between text-[10px] text-gray-400 mt-0.5">
                                                <span>0</span><span>2,500</span><span>5,000</span>
                                            </div>
                                        </div>
                                    </div>

                                    {{-- Row 4 (TV only): Show Status --}}
                                    @if ($browseGenreType === 'tv')
                                        <div>
                                            <label
                                                class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-2">{{ __('Show Status') }}</label>
                                            <div class="flex flex-wrap gap-1.5">
                                                @foreach ([0 => __('Returning'), 2 => __('In Production'), 3 => __('Ended'), 4 => __('Canceled'), 1 => __('Planned'), 5 => __('Pilot')] as $value => $label)
                                                    <button wire:click="toggleTvStatus({{ $value }})"
                                                        @class([
                                                            'inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium transition-colors border',
                                                            'bg-primary-600 text-white border-primary-600' => in_array(
                                                                $value,
                                                                $tvStatuses),
                                                            'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 border-gray-300 dark:border-gray-600 hover:border-primary-400 dark:hover:border-primary-600 hover:text-primary-600 dark:hover:text-primary-400' => !in_array(
                                                                $value,
                                                                $tvStatuses),
                                                        ])>{{ $label }}</button>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif

                                    {{-- Row 5: Watch Providers --}}
                                    @if (count($availableProviders) > 0)
                                        <div>
                                            <div class="flex items-center justify-between mb-2">
                                                <label
                                                    class="text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('Streaming On') }}</label>
                                                <select wire:model.live="watchRegion"
                                                    class="rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-xs py-1 px-2 focus:border-primary-500 focus:ring-primary-500">
                                                    <option value="US">{{ __('US') }}</option>
                                                    <option value="GB">{{ __('UK') }}</option>
                                                    <option value="CA">{{ __('Canada') }}</option>
                                                    <option value="AU">{{ __('Australia') }}</option>
                                                    <option value="DE">{{ __('Germany') }}</option>
                                                    <option value="FR">{{ __('France') }}</option>
                                                    <option value="ES">{{ __('Spain') }}</option>
                                                    <option value="IT">{{ __('Italy') }}</option>
                                                    <option value="JP">{{ __('Japan') }}</option>
                                                    <option value="KR">{{ __('South Korea') }}</option>
                                                    <option value="BR">{{ __('Brazil') }}</option>
                                                    <option value="MX">{{ __('Mexico') }}</option>
                                                    <option value="NL">{{ __('Netherlands') }}</option>
                                                    <option value="SE">{{ __('Sweden') }}</option>
                                                    <option value="NO">{{ __('Norway') }}</option>
                                                    <option value="IN">{{ __('India') }}</option>
                                                </select>
                                            </div>
                                            <div class="flex flex-wrap gap-2">
                                                @foreach (array_slice($availableProviders, 0, 24) as $provider)
                                                    @php $isSelected = in_array($provider['id'], $selectedProviders); @endphp
                                                    <button wire:click="toggleProvider({{ $provider['id'] }})"
                                                        title="{{ $provider['name'] }}" @class([
                                                            'relative w-10 h-10 rounded-lg overflow-hidden transition-all border-2 flex-shrink-0',
                                                            'border-primary-500 ring-2 ring-primary-500/30 shadow-md' => $isSelected,
                                                            'border-transparent hover:border-gray-300 dark:hover:border-gray-500 opacity-70 hover:opacity-100' => !$isSelected,
                                                        ])>
                                                        @if ($provider['logo'])
                                                            <img src="{{ $provider['logo'] }}"
                                                                alt="{{ $provider['name'] }}"
                                                                class="w-full h-full object-cover" loading="lazy">
                                                        @else
                                                            <div
                                                                class="w-full h-full bg-gray-200 dark:bg-gray-700 flex items-center justify-center text-[9px] font-bold text-gray-500 dark:text-gray-400 text-center leading-tight px-0.5">
                                                                {{ Str::limit($provider['name'], 8) }}
                                                            </div>
                                                        @endif
                                                        @if ($isSelected)
                                                            <div
                                                                class="absolute inset-0 bg-primary-600/20 flex items-center justify-center">
                                                                <x-heroicon-s-check
                                                                    class="w-4 h-4 text-white drop-shadow" />
                                                            </div>
                                                        @endif
                                                    </button>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif

                                </div>

                                {{-- Apply / Reset row --}}
                                <div
                                    class="flex items-center justify-end gap-2 px-4 pb-4 bg-gray-50/60 dark:bg-white/[0.02]">
                                    @if ($activeFilterCount > 0)
                                        <button wire:click="resetFilters"
                                            class="inline-flex items-center gap-1 px-3 py-1.5 rounded-md text-xs font-medium text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-gray-100 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors">
                                            <x-heroicon-o-x-mark class="w-3.5 h-3.5" />
                                            {{ __('Reset All') }}
                                        </button>
                                    @endif
                                    <button wire:click="reloadBrowse"
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-medium bg-primary-600 hover:bg-primary-700 text-white transition-colors">
                                        <x-heroicon-o-magnifying-glass class="w-3.5 h-3.5" />
                                        {{ __('Apply') }}
                                    </button>
                                </div>
                            </div>

                            <div class="p-4">
                                @if ($browseLoading)
                                    <div class="flex items-center justify-center py-10">
                                        <x-filament::loading-indicator class="h-6 w-6 text-primary-500" />
                                    </div>
                                @elseif(count($browseResults) > 0)
                                    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">
                                        @foreach($browseResults as $item)
                                            @include('livewire.partials.discover-card', ['item' => $item])
                                        @endforeach
                                    </div>
                                    @if($browseTotalPages > 1)
                                        <div class="mt-4 pt-3 border-t border-gray-100 dark:border-gray-800 flex items-center justify-center gap-3">
                                            <button
                                                wire:click="goToBrowsePage({{ $browsePage - 1 }})"
                                                wire:loading.attr="disabled"
                                                wire:target="goToBrowsePage"
                                                @disabled($browsePage <= 1)
                                                class="p-1 rounded text-gray-500 dark:text-gray-400 hover:text-primary-600 dark:hover:text-primary-400 disabled:opacity-30 disabled:cursor-not-allowed transition-colors"
                                            >
                                                <x-heroicon-o-chevron-left class="w-4 h-4" />
                                            </button>
                                            <span class="text-xs text-gray-500 dark:text-gray-400" wire:loading.class="opacity-50" wire:target="goToBrowsePage">
                                                {{ __('Page :current of :total', ['current' => $browsePage, 'total' => $browseTotalPages]) }}
                                            </span>
                                            <button
                                                wire:click="goToBrowsePage({{ $browsePage + 1 }})"
                                                wire:loading.attr="disabled"
                                                wire:target="goToBrowsePage"
                                                @disabled($browsePage >= $browseTotalPages)
                                                class="p-1 rounded text-gray-500 dark:text-gray-400 hover:text-primary-600 dark:hover:text-primary-400 disabled:opacity-30 disabled:cursor-not-allowed transition-colors"
                                            >
                                                <x-heroicon-o-chevron-right class="w-4 h-4" />
                                            </button>
                                        </div>
                                    @endif
                                @else
                                    <div class="flex flex-col items-center justify-center py-10 text-center">
                                        <x-heroicon-o-film class="w-10 h-10 text-gray-300 dark:text-gray-600" />
                                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                            {{ __('No results found for this genre.') }}</p>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @else
                        {{-- ── Discovery Content Sections ───────────────── --}}
                        @php
                            $discoverSections = [
                                [
                                    'id' => 'trending',
                                    'label' => __('Trending This Week'),
                                    'icon' => 'heroicon-o-fire',
                                    'iconColor' => 'warning',
                                    'items' => $trendingItems,
                                ],
                                [
                                    'id' => 'popular-movies',
                                    'label' => __('Popular Movies'),
                                    'icon' => 'heroicon-o-film',
                                    'iconColor' => 'info',
                                    'items' => $popularMovies,
                                ],
                                [
                                    'id' => 'popular-tv',
                                    'label' => __('Popular TV'),
                                    'icon' => 'heroicon-o-tv',
                                    'iconColor' => 'primary',
                                    'items' => $popularTv,
                                ],
                                [
                                    'id' => 'upcoming-movies',
                                    'label' => __('Upcoming Movies'),
                                    'icon' => 'heroicon-o-calendar-days',
                                    'iconColor' => 'success',
                                    'items' => $upcomingMovies,
                                ],
                            ];
                        @endphp

                        @foreach ($discoverSections as $section)
                            @if (count($section['items']) > 0)
                                @php
                                    $initialItems = array_slice($section['items'], 0, 10);
                                    $remainingItems = array_slice($section['items'], 10);
                                @endphp

                                <x-filament::section :collapsible="true" compact heading="{{ $section['label'] }}"
                                    icon="{{ $section['icon'] }}" icon-color="{{ $section['iconColor'] }}">
                                    <x-slot name="afterHeader">
                                        <x-filament::badge
                                            color="gray">{{ count($section['items']) }}</x-filament::badge>
                                    </x-slot>

                                    <div x-data="{ expanded: false }">
                                        <div
                                            class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">
                                            @foreach ($initialItems as $item)
                                                <div wire:click="requestFromDiscover({{ $item['tmdb_id'] }}, '{{ $item['media_type'] }}')"
                                                    wire:loading.class="opacity-50 pointer-events-none"
                                                    wire:target="requestFromDiscover"
                                                    class="group relative cursor-pointer rounded-lg overflow-hidden shadow-sm hover:shadow-xl transition-shadow duration-200 bg-gray-200 dark:bg-gray-800">
                                                    <div class="relative aspect-[2/3]">
                                                        @if (!empty($item['poster_url']))
                                                            <img src="{{ $item['poster_url'] }}"
                                                                alt="{{ $item['title'] }}"
                                                                class="w-full h-full object-cover" loading="lazy">
                                                        @else
                                                            <div
                                                                class="w-full h-full bg-gray-200 dark:bg-gray-700 flex items-center justify-center">
                                                                @if ($item['media_type'] === 'tv')
                                                                    <x-heroicon-o-tv
                                                                        class="w-10 h-10 text-gray-400 dark:text-gray-500" />
                                                                @else
                                                                    <x-heroicon-o-film
                                                                        class="w-10 h-10 text-gray-400 dark:text-gray-500" />
                                                                @endif
                                                            </div>
                                                        @endif
                                                        <span
                                                            class="absolute top-2 left-2 px-1.5 py-0.5 text-xs font-semibold rounded bg-black/60 text-white">
                                                            {{ $item['media_type'] === 'tv' ? __('TV') : __('Movie') }}
                                                        </span>
                                                        @if (!empty($item['isDownloaded']))
                                                            <span
                                                                class="absolute top-2 right-2 w-6 h-6 rounded-full bg-green-500 flex items-center justify-center shadow-sm">
                                                                <x-heroicon-s-check class="w-3.5 h-3.5 text-white" />
                                                            </span>
                                                        @elseif(!empty($item['existsInLibrary']))
                                                            <span
                                                                class="absolute top-2 right-2 w-6 h-6 rounded-full bg-amber-500 flex items-center justify-center shadow-sm">
                                                                <x-heroicon-s-bookmark
                                                                    class="w-3.5 h-3.5 text-white" />
                                                            </span>
                                                        @endif
                                                        @if (!empty($item['vote_average']))
                                                            <span
                                                                class="absolute bottom-2 right-2 flex items-center gap-0.5 px-1.5 py-0.5 rounded bg-black/70 text-yellow-400 text-xs font-semibold">
                                                                <x-heroicon-s-star class="w-3 h-3" />
                                                                {{ $item['vote_average'] }}
                                                            </span>
                                                        @endif
                                                        <div
                                                            class="absolute inset-0 opacity-0 group-hover:opacity-100 transition-opacity duration-200 bg-gradient-to-t from-black/95 via-black/65 to-black/20 flex flex-col justify-end p-3 gap-1">
                                                            <h3
                                                                class="text-white font-semibold text-sm leading-tight line-clamp-2">
                                                                {{ $item['title'] }}
                                                                @if (!empty($item['year']))
                                                                    <span
                                                                        class="text-white/60 font-normal">({{ $item['year'] }})</span>
                                                                @endif
                                                            </h3>
                                                            @if (!empty($item['overview']))
                                                                <p class="text-white/55 text-xs line-clamp-2">
                                                                    {{ $item['overview'] }}</p>
                                                            @endif
                                                            <div class="mt-1.5">
                                                                @if (!empty($item['isDownloaded']))
                                                                    <span
                                                                        class="flex items-center gap-1 text-green-400 text-xs font-medium">
                                                                        <x-heroicon-s-check class="w-3.5 h-3.5" />
                                                                        {{ __('Downloaded') }}
                                                                    </span>
                                                                @elseif(!empty($item['existsInLibrary']))
                                                                    <span
                                                                        class="flex items-center gap-1 text-amber-400 text-xs font-medium">
                                                                        <x-heroicon-s-bookmark class="w-3.5 h-3.5" />
                                                                        {{ __('Monitored') }}
                                                                    </span>
                                                                @else
                                                                    <span
                                                                        class="block w-full text-center px-2 py-1.5 rounded-md bg-primary-600 text-white text-xs font-medium">
                                                                        {{ $item['media_type'] === 'tv' ? __('Select Seasons') : __('Request Movie') }}
                                                                    </span>
                                                                @endif
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>

                                        {{-- Expandable remaining items --}}
                                        @if (count($remainingItems) > 0)
                                            <div x-show="expanded" x-collapse>
                                                <div
                                                    class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3 mt-3">
                                                    @foreach ($remainingItems as $item)
                                                        <div wire:click="requestFromDiscover({{ $item['tmdb_id'] }}, '{{ $item['media_type'] }}')"
                                                            wire:loading.class="opacity-50 pointer-events-none"
                                                            wire:target="requestFromDiscover"
                                                            class="group relative cursor-pointer rounded-lg overflow-hidden shadow-sm hover:shadow-xl transition-shadow duration-200 bg-gray-200 dark:bg-gray-800">
                                                            <div class="relative aspect-[2/3]">
                                                                @if (!empty($item['poster_url']))
                                                                    <img src="{{ $item['poster_url'] }}"
                                                                        alt="{{ $item['title'] }}"
                                                                        class="w-full h-full object-cover"
                                                                        loading="lazy">
                                                                @else
                                                                    <div
                                                                        class="w-full h-full bg-gray-200 dark:bg-gray-700 flex items-center justify-center">
                                                                        @if ($item['media_type'] === 'tv')
                                                                            <x-heroicon-o-tv
                                                                                class="w-10 h-10 text-gray-400 dark:text-gray-500" />
                                                                        @else
                                                                            <x-heroicon-o-film
                                                                                class="w-10 h-10 text-gray-400 dark:text-gray-500" />
                                                                        @endif
                                                                    </div>
                                                                @endif
                                                                <span
                                                                    class="absolute top-2 left-2 px-1.5 py-0.5 text-xs font-semibold rounded bg-black/60 text-white">
                                                                    {{ $item['media_type'] === 'tv' ? __('TV') : __('Movie') }}
                                                                </span>
                                                                @if (!empty($item['isDownloaded']))
                                                                    <span
                                                                        class="absolute top-2 right-2 w-6 h-6 rounded-full bg-green-500 flex items-center justify-center shadow-sm">
                                                                        <x-heroicon-s-check
                                                                            class="w-3.5 h-3.5 text-white" />
                                                                    </span>
                                                                @elseif(!empty($item['existsInLibrary']))
                                                                    <span
                                                                        class="absolute top-2 right-2 w-6 h-6 rounded-full bg-amber-500 flex items-center justify-center shadow-sm">
                                                                        <x-heroicon-s-bookmark
                                                                            class="w-3.5 h-3.5 text-white" />
                                                                    </span>
                                                                @endif
                                                                @if (!empty($item['vote_average']))
                                                                    <span
                                                                        class="absolute bottom-2 right-2 flex items-center gap-0.5 px-1.5 py-0.5 rounded bg-black/70 text-yellow-400 text-xs font-semibold">
                                                                        <x-heroicon-s-star class="w-3 h-3" />
                                                                        {{ $item['vote_average'] }}
                                                                    </span>
                                                                @endif
                                                                <div
                                                                    class="absolute inset-0 opacity-0 group-hover:opacity-100 transition-opacity duration-200 bg-gradient-to-t from-black/95 via-black/65 to-black/20 flex flex-col justify-end p-3 gap-1">
                                                                    <h3
                                                                        class="text-white font-semibold text-sm leading-tight line-clamp-2">
                                                                        {{ $item['title'] }}
                                                                        @if (!empty($item['year']))
                                                                            <span
                                                                                class="text-white/60 font-normal">({{ $item['year'] }})</span>
                                                                        @endif
                                                                    </h3>
                                                                    @if (!empty($item['overview']))
                                                                        <p class="text-white/55 text-xs line-clamp-2">
                                                                            {{ $item['overview'] }}</p>
                                                                    @endif
                                                                    <div class="mt-1.5">
                                                                        @if (!empty($item['isDownloaded']))
                                                                            <span
                                                                                class="flex items-center gap-1 text-green-400 text-xs font-medium">
                                                                                <x-heroicon-s-check
                                                                                    class="w-3.5 h-3.5" />
                                                                                {{ __('Downloaded') }}
                                                                            </span>
                                                                        @elseif(!empty($item['existsInLibrary']))
                                                                            <span
                                                                                class="flex items-center gap-1 text-amber-400 text-xs font-medium">
                                                                                <x-heroicon-s-bookmark
                                                                                    class="w-3.5 h-3.5" />
                                                                                {{ __('Monitored') }}
                                                                            </span>
                                                                        @else
                                                                            <span
                                                                                class="block w-full text-center px-2 py-1.5 rounded-md bg-primary-600 text-white text-xs font-medium">
                                                                                {{ $item['media_type'] === 'tv' ? __('Select Seasons') : __('Request Movie') }}
                                                                            </span>
                                                                        @endif
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            </div>

                                            {{-- Show more / Show less footer --}}
                                            <div
                                                class="mt-4 pt-3 border-t border-gray-100 dark:border-gray-800 text-center">
                                                <button @click="expanded = !expanded"
                                                    class="inline-flex items-center gap-1.5 text-xs font-medium text-primary-600 dark:text-primary-400 hover:text-primary-700 dark:hover:text-primary-300 transition-colors">
                                                    <span
                                                        x-show="!expanded">{{ __('Show :n more', ['n' => count($remainingItems)]) }}</span>
                                                    <span x-show="expanded"
                                                        style="display:none">{{ __('Show less') }}</span>
                                                    <x-heroicon-o-chevron-down
                                                        class="w-3.5 h-3.5 transition-transform duration-200"
                                                        x-bind:class="{ 'rotate-180': expanded }" />
                                                </button>
                                            </div>
                                        @endif
                                    </div>
                                </x-filament::section>
                            @endif
                        @endforeach

                    @endif

                @endif
            @elseif(!$tmdbConfigured && strlen(trim($searchTerm)) < 2)
                {{-- No TMDB: search prompt --}}
                <div class="flex flex-col items-center justify-center py-12 text-center">
                    <div class="flex gap-3 text-gray-300 dark:text-gray-600">
                        <x-heroicon-o-tv class="w-10 h-10" />
                        <x-heroicon-o-film class="w-10 h-10" />
                    </div>
                    <h4 class="mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">
                        {{ __('Search movies & TV series') }}</h4>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('Enter a title to search across your Sonarr and Radarr servers.') }}
                    </p>
                </div>

            @endif

        </div>

        {{-- ── Guest Download Queue ─────────────────────────────────────── --}}
        @if ($queue && $guestMode)
            <div class="mt-6">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-3 flex items-center gap-2">
                    <x-heroicon-o-arrow-down-tray class="w-4 h-4" />
                    {{ __('Download Queue') }}
                </h3>
                <div class="space-y-2">
                    @foreach ($queue as $item)
                        <div
                            class="bg-white dark:bg-gray-800 rounded-md border border-gray-200 dark:border-gray-700 p-3">
                            <div class="flex items-center justify-between">
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">
                                        {{ $item['title'] ?? __('Unknown') }}
                                    </p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ $item['status'] ?? '' }}
                                        @if ($item['timeLeft'] ?? null)
                                            · {{ __(':time left', ['time' => $item['timeLeft']]) }}
                                        @endif
                                        @if (!empty($item['server']))
                                            · {{ $item['server'] }}
                                        @endif
                                    </p>
                                </div>
                                <span class="text-xs font-medium text-primary-600 dark:text-primary-400 ml-3">
                                    {{ $item['progress'] ?? 0 }}%
                                </span>
                            </div>
                            <div class="mt-2 w-full bg-gray-200 dark:bg-gray-700 rounded-full h-1.5 overflow-hidden">
                                <div class="bg-primary-600 h-1.5 rounded-full transition-all duration-300"
                                    style="width: {{ $item['progress'] ?? 0 }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    @else
        <div class="flex flex-col items-center justify-center py-12 text-center">
            <x-heroicon-o-magnifying-glass-circle class="w-12 h-12 text-gray-300 dark:text-gray-600" />
            <h4 class="mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">
                {{ $guestMode ? __('No integrations available') : __('No Sonarr or Radarr integrations configured') }}
            </h4>
            @unless ($guestMode)
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Add a Sonarr or Radarr integration in Settings to start requesting content.') }}
                </p>
            @endunless
        </div>

    @endif

    {{-- ── Series / Movie Detail Slide-over ──────────────────────────────── --}}
    <div x-data="{ open: @js($showDetail) }" x-init="$watch('$wire.showDetail', v => { open = v; if (v) $wire.call('loadDetailEpisodes') })" @keydown.escape.window="if (open) $wire.call('closeDetail')"
        class="fixed inset-0 z-50 pointer-events-none" x-cloak>
        <div x-show="open" x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0" @click="$wire.call('closeDetail')"
            class="absolute inset-0 bg-black/50 pointer-events-auto" style="display: none;"></div>

        <div x-show="open" x-transition:enter="transition ease-out duration-300 transform"
            x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
            x-transition:leave="transition ease-in duration-200 transform" x-transition:leave-start="translate-x-0"
            x-transition:leave-end="translate-x-full"
            class="absolute right-0 inset-y-0 w-full sm:max-w-lg bg-white dark:bg-gray-900 shadow-2xl flex flex-col pointer-events-auto"
            style="display: none;">
            @if ($detailResult)
                @php
                    $detailIsSonarr = ($detailResult['integrationType'] ?? '') === 'sonarr';
                    $detailInLibrary = !empty($detailResult['existsInLibrary']);

                    // Use per-episode status from Sonarr /episode API when available —
                    // this is authoritative; the lookup's statistics may be stale or absent.
if ($detailIsSonarr && !empty($detailSonarrEpisodeStatus)) {
    $sonarrFileCount = collect($detailSonarrEpisodeStatus)
        ->flatMap(fn($eps) => $eps)
        ->filter()
        ->count();
    $detailIsDownloaded = $detailInLibrary && $sonarrFileCount > 0;
} else {
    $detailIsDownloaded =
        $detailInLibrary &&
        ($detailIsSonarr
            ? ($detailResult['episodeFileCount'] ?? 0) > 0
            : $detailResult['hasFile'] ?? false);
                    }
                @endphp
                <div class="relative flex-shrink-0">
                    @if (!empty($detailResult['fanart']))
                        <div class="relative h-44 overflow-hidden">
                            <img src="{{ $detailResult['fanart'] }}" alt=""
                                class="w-full h-full object-cover">
                            <div
                                class="absolute inset-0 bg-gradient-to-t from-gray-900 via-gray-900/60 to-gray-900/10">
                            </div>
                            <div class="absolute bottom-0 left-0 right-0 px-4 pb-3 pr-12">
                                <h2 class="text-white font-bold text-base leading-snug line-clamp-2">
                                    {{ $detailResult['title'] ?? '' }}</h2>
                                <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5 mt-1">
                                    @if (!empty($detailResult['year']))
                                        <span class="text-white/60 text-xs">{{ $detailResult['year'] }}</span>
                                    @endif
                                    @if (!empty($detailResult['certification']))
                                        <span
                                            class="px-1.5 text-xs border border-white/30 text-white/70 rounded leading-5">{{ $detailResult['certification'] }}</span>
                                    @endif
                                    @if (!empty($detailResult['status']))
                                        <span
                                            class="text-white/60 text-xs">{{ ucfirst($detailResult['status']) }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @else
                        <div class="flex items-center px-4 py-3 border-b border-gray-200 dark:border-gray-700 pr-12">
                            <div class="min-w-0 flex-1">
                                <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100 truncate">
                                    {{ $detailResult['title'] ?? '' }}</h2>
                                <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5 mt-0.5">
                                    @if (!empty($detailResult['year']))
                                        <span
                                            class="text-xs text-gray-500 dark:text-gray-400">{{ $detailResult['year'] }}</span>
                                    @endif
                                    @if (!empty($detailResult['certification']))
                                        <span
                                            class="px-1.5 text-xs border border-gray-300 dark:border-gray-600 text-gray-500 dark:text-gray-400 rounded leading-5">{{ $detailResult['certification'] }}</span>
                                    @endif
                                    @if (!empty($detailResult['status']))
                                        <span
                                            class="text-xs text-gray-500 dark:text-gray-400">{{ ucfirst($detailResult['status']) }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endif
                    <button wire:click="closeDetail"
                        class="absolute top-2.5 right-3 z-10 p-1.5 rounded-full bg-black/40 hover:bg-black/60 text-white focus:outline-none focus:ring-2 focus:ring-primary-500 transition-colors"
                        aria-label="{{ __('Close') }}">
                        <x-heroicon-o-x-mark class="w-4 h-4" />
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto">
                    <div class="flex gap-3 px-4 py-4">
                        @if (!empty($detailResult['poster']))
                            <img src="{{ $detailResult['poster'] }}" alt="{{ $detailResult['title'] ?? '' }}"
                                class="w-24 flex-shrink-0 rounded-md object-cover shadow-lg self-start">
                        @else
                            <div
                                class="w-24 aspect-[2/3] flex-shrink-0 rounded-md bg-gray-200 dark:bg-gray-700 flex items-center justify-center self-start">
                                @if ($this->detailIntegration?->isSonarr())
                                    <x-heroicon-o-tv class="w-8 h-8 text-gray-400 dark:text-gray-500" />
                                @else
                                    <x-heroicon-o-film class="w-8 h-8 text-gray-400 dark:text-gray-500" />
                                @endif
                            </div>
                        @endif
                        <div class="flex-1 min-w-0 space-y-1.5 pt-0.5">
                            @if (!empty($detailResult['rating']))
                                <div class="flex items-center gap-1.5">
                                    <x-heroicon-s-star class="w-4 h-4 text-yellow-400 flex-shrink-0" />
                                    <span
                                        class="text-sm font-bold text-gray-900 dark:text-gray-100">{{ $detailResult['rating']['value'] }}</span>
                                    <span
                                        class="text-xs font-semibold text-gray-400 uppercase tracking-wide">{{ strtoupper($detailResult['rating']['source']) }}</span>
                                    @if (!empty($detailResult['rating']['votes']))
                                        <span
                                            class="text-xs text-gray-400">({{ number_format($detailResult['rating']['votes']) }})</span>
                                    @endif
                                </div>
                            @endif
                            @if (!empty($detailResult['network']))
                                <p class="text-xs text-gray-500 dark:text-gray-400 flex items-center gap-1">
                                    <x-heroicon-o-building-office-2 class="w-3.5 h-3.5 flex-shrink-0" />
                                    {{ $detailResult['network'] }}
                                </p>
                            @endif
                            @if (!empty($detailResult['genres']))
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ implode(' · ', array_slice($detailResult['genres'], 0, 4)) }}</p>
                            @endif
                            @if (!empty($detailResult['runtime']))
                                <p class="text-xs text-gray-500 dark:text-gray-400 flex items-center gap-1">
                                    <x-heroicon-o-clock class="w-3.5 h-3.5 flex-shrink-0" />
                                    {{ $detailResult['runtime'] }}m
                                </p>
                            @endif
                            @if ($detailIsDownloaded)
                                @php
                                    if ($detailIsSonarr && !empty($detailSonarrEpisodeStatus)) {
                                        $epFileCount = collect($detailSonarrEpisodeStatus)
                                            ->flatMap(fn($e) => $e)
                                            ->filter()
                                            ->count();
                                        $epTotalCount = collect($detailSonarrEpisodeStatus)
                                            ->flatMap(fn($e) => $e)
                                            ->count();
                                    } else {
                                        $epFileCount = (int) ($detailResult['episodeFileCount'] ?? 0);
                                        $epTotalCount = (int) ($detailResult['totalEpisodeCount'] ?? 0);
                                    }
                                @endphp
                                <x-filament::badge color="success" icon="heroicon-o-check">
                                    @if ($detailIsSonarr && $epTotalCount > 0)
                                        {{ $epFileCount }}/{{ $epTotalCount }} {{ __('eps downloaded') }}
                                    @else
                                        {{ __('Downloaded') }}
                                    @endif
                                </x-filament::badge>
                            @endif
                            @if ($detailInLibrary && ($detailIsSonarr || !$detailIsDownloaded))
                                <x-filament::badge color="warning" icon="heroicon-s-bookmark">
                                    {{ __('Monitored') }}
                                </x-filament::badge>
                            @endif
                        </div>
                    </div>

                    @if (!empty($detailResult['overview']))
                        <div class="px-4 pb-4 -mt-1">
                            <p class="text-sm text-gray-600 dark:text-gray-400 leading-relaxed">
                                {{ $detailResult['overview'] }}</p>
                        </div>
                    @endif

                    <div class="px-4 space-y-4 pb-4">
                        {{-- Interactive Search (admin-only, library items only; or any Sonarr item when doing episode-level search) --}}
                        @if (
                            !$guestMode &&
                                ($detailInLibrary || !empty($detailReleasesLabel)) &&
                                ($this->detailIntegration?->isSonarr() || $this->detailIntegration?->isRadarr()))
                            <div x-data="{ open: @js(!empty($detailReleases) || $releasesLoading) }" x-effect="if ($wire.detailReleasesLabel) { open = true; }"
                                class="rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
                                <button
                                    @click="open = !open; if (open && @js(empty($detailReleases)) && !@js($releasesLoading)) $wire.call('loadDetailReleases')"
                                    type="button"
                                    class="w-full flex items-center gap-2 px-3 py-2.5 text-left hover:bg-gray-50 dark:hover:bg-gray-800/60 transition-colors">
                                    <x-heroicon-m-chevron-right
                                        class="w-4 h-4 flex-shrink-0 text-gray-400 transition-transform duration-200"
                                        x-bind:class="{ 'rotate-90': open }" />
                                    <span class="flex-1 text-sm font-semibold text-gray-900 dark:text-gray-100">
                                        {{ __('Interactive Search') }}{{ $detailReleasesLabel ? ' — ' . $detailReleasesLabel : '' }}
                                    </span>
                                    <span wire:loading
                                        wire:target="loadDetailReleases,loadEpisodeReleases"><x-filament::loading-indicator
                                            class="h-3 w-3 text-primary-500" /></span>
                                    @if (!empty($detailReleases))
                                        <span wire:loading.remove wire:target="loadDetailReleases,loadEpisodeReleases"
                                            class="text-xs text-gray-400 dark:text-gray-500">{{ count($detailReleases) }}</span>
                                    @endif
                                </button>
                                <div x-show="open" x-collapse>
                                    <div class="border-t border-gray-200 dark:border-gray-700">
                                        <div wire:loading wire:target="loadDetailReleases,loadEpisodeReleases"
                                            class="py-4 ml-2 flex justify-center">
                                            <x-filament::loading-indicator class="h-4 w-4 text-primary-500" />
                                        </div>
                                        <div wire:loading.remove wire:target="loadDetailReleases,loadEpisodeReleases">
                                            @if (!empty($detailReleases))
                                                <div
                                                    class="divide-y divide-gray-100 dark:divide-gray-800 max-h-72 overflow-y-auto">
                                                    @foreach ($detailReleases as $release)
                                                        @php
                                                            $releaseBytes = (int) ($release['size'] ?? 0);
                                                            $releaseSize = match (true) {
                                                                $releaseBytes >= 1_073_741_824 => number_format(
                                                                    $releaseBytes / 1_073_741_824,
                                                                    2,
                                                                ) . ' GB',
                                                                $releaseBytes >= 1_048_576 => number_format(
                                                                    $releaseBytes / 1_048_576,
                                                                    2,
                                                                ) . ' MB',
                                                                $releaseBytes > 0 => number_format(
                                                                    $releaseBytes / 1_024,
                                                                    2,
                                                                ) . ' KB',
                                                                default => '–',
                                                            };
                                                            $rejectionReasons = implode(
                                                                '; ',
                                                                $release['rejections'] ?? [],
                                                            );
                                                        @endphp
                                                        <div
                                                            class="flex items-start gap-2 px-3 py-2 {{ $release['approved'] ? '' : 'opacity-60' }}">
                                                            <div class="flex-1 min-w-0">
                                                                <p
                                                                    class="text-xs text-gray-800 dark:text-gray-200 leading-snug break-words">
                                                                    {{ $release['title'] }}</p>
                                                                <div class="flex flex-wrap items-center gap-1.5 mt-1">
                                                                    <x-filament::badge
                                                                        color="gray">{{ $release['quality'] }}</x-filament::badge>
                                                                    <span
                                                                        class="text-xs text-gray-400 dark:text-gray-500">{{ $releaseSize }}</span>
                                                                    <span
                                                                        class="text-xs text-gray-400 dark:text-gray-500 uppercase">{{ $release['protocol'] }}</span>
                                                                    @if (!$release['approved'])
                                                                        <x-filament::badge
                                                                            color="danger">{{ __('Rejected') }}</x-filament::badge>
                                                                    @endif
                                                                </div>
                                                                @if (!$release['approved'] && $rejectionReasons)
                                                                    <p
                                                                        class="text-xs text-danger-500 dark:text-danger-400 mt-1 leading-snug">
                                                                        {{ $rejectionReasons }}</p>
                                                                @endif
                                                            </div>
                                                            @if ($release['approved'])
                                                                <button
                                                                    wire:click="downloadDetailRelease('{{ $release['guid'] }}', {{ (int) ($release['indexerId'] ?? 0) }})"
                                                                    wire:loading.attr="disabled"
                                                                    wire:target="downloadDetailRelease"
                                                                    title="{{ __('Download this release') }}"
                                                                    class="flex-shrink-0 p-1.5 rounded text-gray-400 hover:text-primary-600 dark:hover:text-primary-400 hover:bg-primary-50 dark:hover:bg-primary-900/20 disabled:opacity-40 transition-colors mt-0.5">
                                                                    <x-heroicon-o-arrow-down-tray class="w-4 h-4" />
                                                                </button>
                                                            @else
                                                                <button
                                                                    x-on:click="if (confirm('{{ __('This release was rejected by your quality profile. Download anyway?') }}')) $wire.call('downloadDetailRelease', '{{ $release['guid'] }}', {{ (int) ($release['indexerId'] ?? 0) }})"
                                                                    title="{{ __('Force download (rejected release)') }}"
                                                                    class="flex-shrink-0 p-1.5 rounded text-gray-300 dark:text-gray-600 hover:text-danger-500 dark:hover:text-danger-400 hover:bg-danger-50 dark:hover:bg-danger-900/20 transition-colors mt-0.5">
                                                                    <x-heroicon-o-arrow-down-tray class="w-4 h-4" />
                                                                </button>
                                                            @endif
                                                        </div>
                                                    @endforeach
                                                </div>
                                                <div
                                                    class="px-3 py-2 border-t border-gray-100 dark:border-gray-800 flex justify-end">
                                                    <button wire:click="loadDetailReleases"
                                                        wire:loading.attr="disabled"
                                                        wire:target="loadDetailReleases,loadEpisodeReleases"
                                                        class="text-xs text-primary-600 dark:text-primary-400 hover:underline disabled:opacity-40">{{ __('Refresh') }}</button>
                                                </div>
                                            @elseif(!$releasesLoading)
                                                <p class="text-xs text-gray-400 dark:text-gray-500 text-center py-3">
                                                    {{ __('No releases found.') }}</p>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endif

                        @if ($this->detailIntegration?->isSonarr() || $this->detailIntegration?->isRadarr())
                            <x-filament::section :collapsible="true" compact :collapsed="true"
                                heading="{{ __('Cast') }}" compact>
                                <x-slot name="afterHeader">
                                    <span wire:loading wire:target="loadDetailEpisodes">
                                        <x-filament::loading-indicator class="h-3 w-3 text-primary-500" />
                                    </span>
                                    @if ($detailCast)
                                        <x-filament::badge color="gray" wire:loading.remove
                                            wire:target="loadDetailEpisodes">
                                            {{ count($detailCast) }}
                                        </x-filament::badge>
                                    @endif
                                </x-slot>

                                <div wire:loading wire:target="loadDetailEpisodes" class="py-2 flex justify-center">
                                    <x-filament::loading-indicator class="h-4 w-4 text-primary-500" />
                                </div>
                                <div wire:loading.remove wire:target="loadDetailEpisodes">
                                    @if ($detailCast)
                                        <div class="divide-y divide-gray-100 dark:divide-gray-800">
                                            @foreach ($detailCast as $member)
                                                <div class="flex items-center gap-3 py-2.5 first:pt-0 last:pb-0">
                                                    <div
                                                        class="w-9 h-9 rounded-full overflow-hidden flex-shrink-0 bg-gray-200 dark:bg-gray-700">
                                                        @if (!empty($member['photo']))
                                                            <img src="{{ $member['photo'] }}"
                                                                alt="{{ $member['actor'] }}"
                                                                class="w-full h-full object-cover" loading="lazy">
                                                        @else
                                                            <div
                                                                class="w-full h-full flex items-center justify-center">
                                                                <x-heroicon-o-user class="w-4 h-4 text-gray-400" />
                                                            </div>
                                                        @endif
                                                    </div>
                                                    <div class="flex-1 min-w-0">
                                                        <p
                                                            class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">
                                                            {{ $member['actor'] }}</p>
                                                        @if (!empty($member['character']))
                                                            <p
                                                                class="text-xs text-gray-500 dark:text-gray-400 truncate">
                                                                {{ $member['character'] }}</p>
                                                        @endif
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    @else
                                        <p class="text-xs text-gray-400 dark:text-gray-500 text-center py-2">
                                            {{ __('No cast information available.') }}</p>
                                    @endif
                                </div>
                            </x-filament::section>
                        @endif

                        @if ($this->detailIntegration?->isSonarr() && !empty($detailResult['seasons']))
                            <div>
                                <div class="flex items-center justify-between mb-2">
                                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                        {{ __('Seasons') }}</h3>
                                    <div class="flex items-center gap-2">
                                        <button wire:click="toggleAllSeasons(true)"
                                            class="text-xs text-primary-600 dark:text-primary-400 hover:underline">{{ __('All') }}</button>
                                        <span class="text-gray-300 dark:text-gray-600">·</span>
                                        <button wire:click="toggleAllSeasons(false)"
                                            class="text-xs text-gray-500 dark:text-gray-400 hover:underline">{{ __('None') }}</button>
                                    </div>
                                </div>
                                <div
                                    class="rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden divide-y divide-gray-100 dark:divide-gray-800">
                                    @foreach (collect($detailResult['seasons'])->sortBy('seasonNumber') as $season)
                                        @php
                                            $seasonNum = (int) $season['seasonNumber'];
                                            $seasonEpisodes = $detailEpisodes[$seasonNum] ?? null;
                                            $episodeCount =
                                                $seasonEpisodes !== null
                                                    ? count($seasonEpisodes)
                                                    : $season['statistics']['totalEpisodeCount'] ?? null;

                                            // Per-episode download counts from Sonarr /episode — authoritative
                                            $seasonEpStatus = $detailSonarrEpisodeStatus[$seasonNum] ?? null;
                                            $seasonFileCount =
                                                $seasonEpStatus !== null ? count(array_filter($seasonEpStatus)) : null;
                                            $seasonEpTotal = $seasonEpStatus !== null ? count($seasonEpStatus) : null;
                                        @endphp
                                        <div x-data="{ expanded: false }">
                                            <div
                                                class="flex items-center hover:bg-gray-50 dark:hover:bg-gray-800/60 transition-colors">
                                                <div class="pl-3 py-2.5" @click.stop>
                                                    <input type="checkbox"
                                                        wire:model.live="selectedSeasons.{{ $seasonNum }}"
                                                        class="rounded border-gray-300 dark:border-gray-600 text-primary-600 focus:ring-primary-500 dark:bg-gray-800">
                                                </div>
                                                <button @click="expanded = !expanded" type="button"
                                                    class="flex-1 flex items-center gap-2 px-2 py-2.5 pr-3 text-left">
                                                    <x-heroicon-m-chevron-right
                                                        class="w-3.5 h-3.5 flex-shrink-0 text-gray-400 transition-transform duration-200"
                                                        x-bind:class="{ 'rotate-90': expanded }" />
                                                    <span class="flex-1 text-sm text-gray-800 dark:text-gray-200">
                                                        {{ $seasonNum === 0 ? __('Specials') : __('Season :n', ['n' => $seasonNum]) }}
                                                    </span>
                                                    <span wire:loading
                                                        wire:target="loadDetailEpisodes"><x-filament::loading-indicator
                                                            class="h-3 w-3 text-gray-400" /></span>
                                                    @if ($episodeCount !== null)
                                                        <span wire:loading.remove wire:target="loadDetailEpisodes"
                                                            class="text-xs text-gray-400 dark:text-gray-500">{{ __(':n ep', ['n' => $episodeCount]) }}</span>
                                                    @endif
                                                    @if ($seasonFileCount !== null && $seasonEpTotal !== null)
                                                        @php
                                                            $seasonBadgeColor =
                                                                $seasonFileCount === $seasonEpTotal &&
                                                                $seasonEpTotal > 0
                                                                    ? 'success'
                                                                    : ($seasonFileCount > 0
                                                                        ? 'warning'
                                                                        : 'gray');
                                                        @endphp
                                                        <x-filament::badge wire:loading.remove
                                                            wire:target="loadDetailEpisodes"
                                                            :color="$seasonBadgeColor">{{ $seasonFileCount }}/{{ $seasonEpTotal }}</x-filament::badge>
                                                    @endif
                                                </button>
                                            </div>
                                            <div x-show="expanded" x-collapse
                                                class="border-t border-gray-100 dark:border-gray-800 bg-gray-50/50 dark:bg-gray-800/30">
                                                <div wire:loading wire:target="loadDetailEpisodes"
                                                    class="py-4 flex justify-center">
                                                    <x-filament::loading-indicator class="h-4 w-4 text-primary-500" />
                                                </div>
                                                <div wire:loading.remove wire:target="loadDetailEpisodes">
                                                    @if ($seasonEpisodes !== null && count($seasonEpisodes) > 0)
                                                        <div class="divide-y divide-gray-100 dark:divide-gray-700/50">
                                                            @foreach ($seasonEpisodes as $episode)
                                                                @php
                                                                    $epHasFile = !empty(
                                                                        $detailSonarrEpisodeStatus[$seasonNum][
                                                                            $episode['episodeNumber']
                                                                        ]
                                                                    );
                                                                    $epFileInfo =
                                                                        $detailSonarrEpisodeFileInfo[$seasonNum][
                                                                            $episode['episodeNumber']
                                                                        ] ?? null;
                                                                    $epQuality = $epFileInfo['quality'] ?? null;
                                                                    $epBytes = $epFileInfo['size'] ?? null;
                                                                    $epSize = $epBytes
                                                                        ? match (true) {
                                                                            $epBytes >= 1_073_741_824 => number_format(
                                                                                $epBytes / 1_073_741_824,
                                                                                2,
                                                                            ) . ' GB',
                                                                            $epBytes >= 1_048_576 => number_format(
                                                                                $epBytes / 1_048_576,
                                                                                2,
                                                                            ) . ' MB',
                                                                            $epBytes > 0 => number_format(
                                                                                $epBytes / 1_024,
                                                                                2,
                                                                            ) . ' KB',
                                                                            default => null,
                                                                        }
                                                                        : null;
                                                                @endphp
                                                                <div class="flex items-center gap-2 px-3 py-1.5">
                                                                    <span
                                                                        class="text-xs font-mono text-gray-400 dark:text-gray-500 w-6 flex-shrink-0 text-right">{{ str_pad($episode['episodeNumber'], 2, '0', STR_PAD_LEFT) }}</span>
                                                                    @if ($epHasFile)
                                                                        <x-heroicon-s-check-circle
                                                                            class="w-3.5 h-3.5 flex-shrink-0 text-success-500 dark:text-success-400" />
                                                                    @endif
                                                                    <span
                                                                        class="flex-1 text-xs text-gray-800 dark:text-gray-200 min-w-0 truncate">{{ $episode['title'] }}</span>
                                                                    @if ($epHasFile && ($epQuality || $epSize))
                                                                        <span
                                                                            class="text-xs text-gray-400 dark:text-gray-500 flex-shrink-0 tabular-nums">{{ implode(' · ', array_filter([$epQuality, $epSize])) }}</span>
                                                                    @elseif(!empty($episode['airDate']))
                                                                        <span
                                                                            class="text-xs text-gray-400 dark:text-gray-500 flex-shrink-0 tabular-nums">{{ \Carbon\Carbon::parse($episode['airDate'])->format('M j, Y') }}</span>
                                                                    @endif
                                                                    @if ($epHasFile)
                                                                        <button
                                                                            wire:click="requestEpisode({{ $seasonNum }}, {{ $episode['episodeNumber'] }})"
                                                                            wire:loading.attr="disabled"
                                                                            wire:target="requestEpisode"
                                                                            title="{{ __('Re-download this episode') }}"
                                                                            class="flex-shrink-0 p-1 rounded text-success-500 dark:text-success-400 hover:text-primary-600 dark:hover:text-primary-400 hover:bg-primary-50 dark:hover:bg-primary-900/20 disabled:opacity-40 transition-colors">
                                                                            <x-heroicon-o-arrow-path
                                                                                class="w-3.5 h-3.5" />
                                                                        </button>
                                                                    @else
                                                                        <button
                                                                            wire:click="requestEpisode({{ $seasonNum }}, {{ $episode['episodeNumber'] }})"
                                                                            wire:loading.attr="disabled"
                                                                            wire:target="requestEpisode"
                                                                            title="{{ __('Request episode') }}"
                                                                            class="flex-shrink-0 p-1 rounded text-gray-300 dark:text-gray-600 hover:text-primary-600 dark:hover:text-primary-400 hover:bg-primary-50 dark:hover:bg-primary-900/20 disabled:opacity-40 transition-colors">
                                                                            <x-heroicon-o-arrow-down-tray
                                                                                class="w-3.5 h-3.5" />
                                                                        </button>
                                                                    @endif
                                                                    @if (!$guestMode)
                                                                        <button
                                                                            wire:click="loadEpisodeReleases({{ $seasonNum }}, {{ $episode['episodeNumber'] }})"
                                                                            wire:loading.attr="disabled"
                                                                            wire:target="loadEpisodeReleases,requestEpisode"
                                                                            title="{{ __('Pick a specific release for this episode') }}"
                                                                            class="flex-shrink-0 p-1 rounded text-gray-300 dark:text-gray-600 hover:text-primary-600 dark:hover:text-primary-400 hover:bg-primary-50 dark:hover:bg-primary-900/20 disabled:opacity-40 transition-colors">
                                                                            <x-heroicon-o-magnifying-glass
                                                                                class="w-3.5 h-3.5" />
                                                                        </button>
                                                                    @endif
                                                                </div>
                                                            @endforeach
                                                        </div>
                                                    @elseif($seasonEpisodes !== null)
                                                        <p
                                                            class="text-xs text-gray-400 dark:text-gray-500 text-center py-3">
                                                            {{ __('No episode details available.') }}</p>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                </div>

                <div
                    class="flex-shrink-0 px-4 py-3 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/50">
                    @if ($detailInLibrary && $detailIsSonarr)
                        {{-- Sonarr in-library: show status + always allow re-requesting seasons --}}
                        @php
                            $monitoredCount = collect($selectedSeasons)->filter()->count();
                            $sonarrSizeBytes = $detailResult['sizeOnDisk'] ?? 0;
                            $sonarrSizeDisplay =
                                $sonarrSizeBytes > 0
                                    ? match (true) {
                                        $sonarrSizeBytes >= 1_073_741_824 => number_format(
                                            $sonarrSizeBytes / 1_073_741_824,
                                            2,
                                        ) . ' GB',
                                        $sonarrSizeBytes >= 1_048_576 => number_format(
                                            $sonarrSizeBytes / 1_048_576,
                                            2,
                                        ) . ' MB',
                                        $sonarrSizeBytes > 0 => number_format($sonarrSizeBytes / 1_024, 2) . ' KB',
                                        default => null,
                                    }
                                    : null;
                        @endphp
                        <div class="space-y-2">
                            <div class="flex flex-col items-center justify-center gap-1 py-0.5">
                                <div class="flex items-center gap-2 text-sm">
                                    @if ($detailIsDownloaded)
                                        <x-heroicon-o-check-circle
                                            class="w-4 h-4 text-success-600 dark:text-success-400 flex-shrink-0" />
                                        <span
                                            class="text-success-700 dark:text-success-400">{{ __('Available in library') }}</span>
                                    @else
                                        <x-heroicon-s-bookmark class="w-4 h-4 text-amber-500 flex-shrink-0" />
                                        <span
                                            class="text-amber-600 dark:text-amber-400">{{ __('Monitored — searching for releases') }}</span>
                                    @endif
                                </div>
                                @if ($sonarrSizeDisplay)
                                    <div class="text-xs text-gray-400 dark:text-gray-500 tabular-nums">
                                        {{ $sonarrSizeDisplay }} {{ __('on disk') }}</div>
                                @endif
                            </div>
                            @if (!$guestMode)
                                <div class="flex gap-2">
                                    <button wire:click="triggerAutomaticSearch" wire:loading.attr="disabled"
                                        wire:target="triggerAutomaticSearch"
                                        class="flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-50 rounded-lg transition-colors">
                                        <x-heroicon-o-magnifying-glass class="w-4 h-4" />
                                        {{ __('Auto Search') }}
                                    </button>
                                    <button wire:click="requestDetail" wire:loading.attr="disabled"
                                        @disabled($monitoredCount === 0)
                                        class="flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-2 text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 disabled:opacity-50 disabled:cursor-not-allowed rounded-lg transition-colors">
                                        <x-heroicon-o-arrow-path class="w-4 h-4" />
                                        @if ($monitoredCount > 0)
                                            {{ __('Re-request :n Season(s)', ['n' => $monitoredCount]) }}
                                        @else
                                            {{ __('Select Seasons') }}
                                        @endif
                                    </button>
                                </div>
                            @endif
                        </div>
                    @elseif($detailIsDownloaded)
                        {{-- Radarr downloaded --}}
                        @php
                            $radarrFileQuality = $detailResult['fileQuality'] ?? null;
                            $radarrFileBytes = $detailResult['fileSize'] ?? null;
                            $radarrFileSize = $radarrFileBytes
                                ? match (true) {
                                    $radarrFileBytes >= 1_073_741_824 => number_format(
                                        $radarrFileBytes / 1_073_741_824,
                                        2,
                                    ) . ' GB',
                                    $radarrFileBytes >= 1_048_576 => number_format($radarrFileBytes / 1_048_576, 2) .
                                        ' MB',
                                    $radarrFileBytes > 0 => number_format($radarrFileBytes / 1_024, 2) . ' KB',
                                    default => null,
                                }
                                : null;
                        @endphp
                        <div class="space-y-2">
                            <div class="flex flex-col items-center justify-center gap-1 py-1">
                                <div class="flex items-center gap-2 text-sm text-success-700 dark:text-success-400">
                                    <x-heroicon-o-check-circle class="w-5 h-5" />
                                    {{ __('This title is available in your library.') }}
                                </div>
                                @if ($radarrFileQuality || $radarrFileSize)
                                    <div class="text-xs text-gray-400 dark:text-gray-500 tabular-nums">
                                        {{ implode(' · ', array_filter([$radarrFileQuality, $radarrFileSize])) }}
                                    </div>
                                @endif
                            </div>
                            @if (!$guestMode)
                                <button wire:click="triggerAutomaticSearch" wire:loading.attr="disabled"
                                    wire:target="triggerAutomaticSearch"
                                    class="w-full inline-flex items-center justify-center gap-2 px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-50 rounded-lg transition-colors">
                                    <x-heroicon-o-magnifying-glass class="w-4 h-4" />
                                    {{ __('Trigger Automatic Search') }}
                                </button>
                            @endif
                        </div>
                    @elseif($detailInLibrary)
                        {{-- Radarr monitored (not yet downloaded) --}}
                        <div class="space-y-2">
                            <div
                                class="flex items-center justify-center gap-2 py-1 text-sm text-amber-600 dark:text-amber-400">
                                <x-heroicon-s-bookmark class="w-5 h-5" />
                                {{ __('This title is monitored and searching for releases.') }}
                            </div>
                            @if (!$guestMode)
                                <button wire:click="triggerAutomaticSearch" wire:loading.attr="disabled"
                                    wire:target="triggerAutomaticSearch"
                                    class="w-full inline-flex items-center justify-center gap-2 px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-50 rounded-lg transition-colors">
                                    <x-heroicon-o-magnifying-glass class="w-4 h-4" />
                                    {{ __('Trigger Automatic Search') }}
                                </button>
                            @endif
                        </div>
                    @elseif($detailIsSonarr)
                        {{-- Sonarr not in library — initial request --}}
                        @php $monitoredCount = collect($selectedSeasons)->filter()->count(); @endphp
                        <button wire:click="requestDetail" wire:loading.attr="disabled" @disabled($monitoredCount === 0)
                            class="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 disabled:opacity-50 disabled:cursor-not-allowed rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2">
                            <x-heroicon-o-plus class="w-4 h-4" />
                            @if ($monitoredCount > 0)
                                {{ __('Request :count Season(s)', ['count' => $monitoredCount]) }}
                            @else
                                {{ __('Select Seasons to Request') }}
                            @endif
                        </button>
                    @else
                        {{-- Radarr not in library --}}
                        <div class="flex gap-2">
                            <button wire:click="request({{ $detailIndex }})" wire:click.stop
                                wire:loading.attr="disabled" wire:target="request,addForInteractiveSearch"
                                title="{{ __('Add to Radarr and let it auto-select the best release') }}"
                                class="flex-1 inline-flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 disabled:opacity-50 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 transition-colors">
                                <x-heroicon-o-plus class="w-4 h-4" />
                                {{ __('Request') }}
                            </button>
                            @if (!$guestMode)
                                <button wire:click="addForInteractiveSearch" wire:loading.attr="disabled"
                                    wire:target="request,addForInteractiveSearch"
                                    title="{{ __('Add to Radarr and pick a specific release') }}"
                                    class="flex-1 inline-flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-50 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 transition-colors">
                                    <x-heroicon-o-list-bullet class="w-4 h-4" />
                                    {{ __('Pick Release') }}
                                </button>
                            @endif
                        </div>
                    @endif
                </div>
            @endif
        </div>
    </div>

</div>
