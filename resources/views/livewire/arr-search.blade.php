<div @if ($guestMode && $queuePolling) wire:poll.{{ $this->queuePollInterval }}s="loadQueue" @endif>

    @if (!$detailOnly)

        @if ($this->integrationsForSearch->isNotEmpty())

            <div class="space-y-6">

                {{-- ── Search Bar ─────────────────────────────────────────────── --}}
                <form wire:submit.prevent="search" class="flex gap-2">
                    <x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass" class="flex-1">
                        <x-filament::input type="text" wire:model="searchTerm"
                            placeholder="{{ __('Search movies & TV series...') }}" />
                    </x-filament::input.wrapper>
                    @if (strlen(trim($searchTerm)) >= 2)
                        <x-filament::button wire:click="clearSearch" color="gray" icon="heroicon-o-x-mark">
                            {{ __('Clear') }}
                        </x-filament::button>
                    @endif
                    <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="search">
                        {{ __('Search') }}
                    </x-filament::button>
                </form>

                {{-- ── Search Results ──────────────────────────────────────────── --}}
                @if (strlen(trim($searchTerm)) >= 2 || $isSearching)

                    <x-filament::section :collapsible="true" compact heading="{{ __('Search Results') }}"
                        icon="heroicon-o-magnifying-glass" icon-color="gray">
                        @if (count($results) > 0)
                            <x-slot name="afterHeader">
                                @if (!empty($selectedGenres))
                                    <x-filament::badge color="primary">{{ count($this->filteredResults) }} /
                                        {{ count($results) }}</x-filament::badge>
                                @else
                                    <x-filament::badge color="gray">{{ count($results) }}</x-filament::badge>
                                @endif
                            </x-slot>
                        @endif

                        <div>
                            @if ($isSearching)
                                <div class="flex items-center justify-center py-10">
                                    <x-filament::loading-indicator class="h-5 w-5 text-primary-500" />
                                    <span
                                        class="ml-3 text-sm text-gray-500 dark:text-gray-400">{{ __('Searching...') }}</span>
                                </div>
                            @elseif(count($results) > 0)
                                {{-- Availability filter tabs (mirrors release-logs.blade.php tab strip) --}}
                                @php
                                    $availTabs = [
                                        null         => ['label' => __('All'),        'color' => 'gray'],
                                        'available'  => ['label' => __('Available'),  'color' => 'success'],
                                        'in_library' => ['label' => __('In Library'), 'color' => 'warning'],
                                        'missing'    => ['label' => __('Missing'),    'color' => 'gray'],
                                    ];
                                    $availCounts = $this->availabilityCounts;
                                @endphp
                                <div class="flex flex-wrap gap-2 mb-3">
                                    @foreach ($availTabs as $value => $tab)
                                        @php $current = ($availability ?? '') === ($value ?? ''); @endphp
                                        <x-filament::button
                                            wire:click="setAvailability('{{ $value ?? '' }}')"
                                            color="{{ $tab['color'] }}"
                                            icon="{{ $current ? 'heroicon-s-check-circle' : '' }}"
                                            size="sm"
                                            class="flex items-center gap-1">
                                            {{ $tab['label'] }}
                                            <x-filament::badge size="sm" color="{{ $tab['color'] }}">
                                                {{ $availCounts[$value ?? 'all'] ?? 0 }}
                                            </x-filament::badge>
                                        </x-filament::button>
                                    @endforeach
                                </div>

                                {{-- Genre filter chips --}}
                                @if (count($this->availableGenres) > 0)
                                    <div class="flex flex-wrap gap-1.5 mb-4">
                                        @foreach ($this->availableGenres as $genre)
                                            <button type="button" wire:key="genre-{{ $genre }}"
                                                wire:click="toggleGenre('{{ $genre }}')"
                                                class="px-2.5 py-1 text-xs font-medium rounded-full border transition-colors
                                                {{ in_array($genre, $selectedGenres, true)
                                                    ? 'bg-primary-600 border-primary-600 text-white'
                                                    : 'bg-white dark:bg-gray-800 border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:border-primary-400 dark:hover:border-primary-500' }}">
                                                {{ $genre }}
                                            </button>
                                        @endforeach
                                        @if (!empty($selectedGenres))
                                            <button type="button" wire:click="$set('selectedGenres', [])"
                                                class="px-2.5 py-1 text-xs font-medium rounded-full border border-gray-300 dark:border-gray-500 text-gray-500 dark:text-gray-400 hover:border-gray-400 bg-transparent transition-colors">
                                                {{ __('Clear') }}
                                            </button>
                                        @endif
                                    </div>
                                @endif
                                @php $filteredResults = $this->filteredResults; @endphp
                                @if (count($filteredResults) === 0)
                                    <div class="flex flex-col items-center justify-center py-10 text-center">
                                        <x-heroicon-o-funnel class="w-10 h-10 text-gray-300 dark:text-gray-600" />
                                        <h4 class="mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">
                                            {{ __('No results match the selected genres') }}</h4>
                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                            {{ __('Try removing some genre filters.') }}</p>
                                    </div>
                                @else
                                    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">
                                        @foreach ($filteredResults as $index => $result)
                                            @php
                                                $isSonarr = ($result['integrationType'] ?? '') === 'sonarr';
                                                $inLibrary = !empty($result['existsInLibrary']);
                                                $isDownloaded =
                                                    $inLibrary &&
                                                    ($isSonarr
                                                        ? ($result['episodeFileCount'] ?? 0) > 0
                                                        : $result['hasFile'] ?? false);
                                            @endphp
                                            <div wire:key="result-{{ $index }}"
                                                wire:click="openDetail({{ $index }})"
                                                class="group relative cursor-pointer rounded-lg overflow-hidden shadow-sm hover:shadow-xl transition-shadow duration-200 bg-gray-200 dark:bg-gray-800">
                                                <div class="relative aspect-[2/3]">
                                                    @if (!empty($result['poster']))
                                                        <img src="{{ $result['poster'] }}"
                                                            alt="{{ $result['title'] ?? '' }}"
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
                                                        <h3
                                                            class="text-white font-semibold text-sm leading-tight line-clamp-2">
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
                                @endif
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
                    <livewire:arr-discover :guestMode="$guestMode" :guestIntegrationIds="$guestIntegrationIds" />
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
                                <div
                                    class="mt-2 w-full bg-gray-200 dark:bg-gray-700 rounded-full h-1.5 overflow-hidden">
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

    @endif {{-- /detailOnly --}}

    <x-filament-actions::modals />

</div>
