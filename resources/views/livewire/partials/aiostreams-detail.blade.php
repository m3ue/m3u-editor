<div>
    @if ($detailResult)
        @php
            $isSeries = $detailType === 'series';
        @endphp

        <div class="relative flex-shrink-0">
            @if (!empty($detailResult['background']))
                <div class="relative h-44 overflow-hidden">
                    <img src="{{ $detailResult['background'] }}" alt="" class="w-full h-full object-cover">
                    <div class="absolute inset-0 bg-gradient-to-t from-gray-900 via-gray-900/60 to-gray-900/10"></div>
                    <div class="absolute bottom-0 left-0 right-0 px-4 pb-3 pr-12">
                        <h2 class="text-white font-bold text-base leading-snug line-clamp-2">
                            {{ $detailResult['name'] ?? '' }}</h2>
                        @if ($resumeEpisode)
                            <p class="text-white/70 text-xs mt-0.5 truncate">
                                {{ __('S:s E:e', ['s' => $resumeSeason, 'e' => $resumeEpisode]) }}
                                @if ($resumeEpisodeTitle)
                                    &middot; {{ $resumeEpisodeTitle }}
                                @endif
                            </p>
                        @endif
                        <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5 mt-1">
                            @if (!empty($detailResult['releaseInfo']))
                                <span class="text-white/60 text-xs">{{ $detailResult['releaseInfo'] }}</span>
                            @endif
                        </div>
                    </div>
                </div>
            @else
                <div class="flex items-center px-4 py-3 border-b border-gray-200 dark:border-gray-700 pr-12">
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100 truncate">
                            {{ $detailResult['name'] ?? '' }}</h2>
                        @if ($resumeEpisode)
                            <p class="text-xs text-gray-500 dark:text-gray-400 truncate">
                                {{ __('S:s E:e', ['s' => $resumeSeason, 'e' => $resumeEpisode]) }}
                                @if ($resumeEpisodeTitle)
                                    &middot; {{ $resumeEpisodeTitle }}
                                @endif
                            </p>
                        @endif
                    </div>
                </div>
            @endif
        </div>

        <div class="flex-1 overflow-y-auto">
            <div class="flex gap-3 px-4 py-4">
                @if (!empty($detailResult['poster']))
                    <img src="{{ $detailResult['poster'] }}" alt="{{ $detailResult['name'] ?? '' }}"
                        class="w-24 flex-shrink-0 rounded-md object-cover shadow-lg self-start">
                @else
                    <div
                        class="w-24 aspect-[2/3] flex-shrink-0 rounded-md bg-gray-200 dark:bg-gray-700 flex items-center justify-center self-start">
                        @if ($isSeries)
                            <x-heroicon-o-tv class="w-8 h-8 text-gray-400 dark:text-gray-500" />
                        @else
                            <x-heroicon-o-film class="w-8 h-8 text-gray-400 dark:text-gray-500" />
                        @endif
                    </div>
                @endif
                <div class="flex-1 min-w-0 space-y-1.5 pt-0.5">
                    @if (!empty($detailResult['imdbRating']))
                        <div class="flex items-center gap-1.5">
                            <x-filament::icon icon="heroicon-s-star" class="w-4 h-4 text-yellow-400 flex-shrink-0" />
                            <span
                                class="text-sm font-bold text-gray-900 dark:text-gray-100">{{ $detailResult['imdbRating'] }}</span>
                            <span class="text-xs font-semibold text-gray-400 uppercase tracking-wide">IMDB</span>
                        </div>
                    @endif
                    @if (!empty($detailResult['genres']))
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            {{ implode(' · ', array_slice($detailResult['genres'], 0, 4)) }}</p>
                    @endif
                    @if (!empty($detailResult['runtime']))
                        <p class="text-xs text-gray-500 dark:text-gray-400 flex items-center gap-1">
                            <x-filament::icon icon="heroicon-o-clock" class="w-3.5 h-3.5 flex-shrink-0" />
                            {{ $detailResult['runtime'] }}
                        </p>
                    @endif
                    @if (!empty($detailResult['director']))
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            {{ __('Director') }}: {{ implode(', ', (array) $detailResult['director']) }}</p>
                    @endif
                </div>
            </div>

            @if (!empty($detailResult['description']))
                <div class="px-4 pb-4 -mt-1">
                    <p class="text-sm text-gray-600 dark:text-gray-400 leading-relaxed">
                        {{ $detailResult['description'] }}</p>
                </div>
            @endif

            <div class="px-4 space-y-4 pb-4">

                {{-- ── Source picker (shown after choosing to watch, or while lazily loading a resumed item's streams) ──
                     Deliberately Alpine-driven rather than Livewire-rendered: loadResumeStreams()/retryLoadStreams()
                     are #[Renderless] (see AioStreamsBrowse.php) since re-rendering this component from a
                     wire:init call — with no originating click for Filament's focus-trap to anchor to — was
                     enough to disturb the whole page's DOM and force every lazy catalog row to reload. Instead,
                     the fetched streams arrive via a dispatched 'aio-streams-loaded' event and Alpine renders
                     them locally, never touching the rest of the page. --}}
                @if ($streamsLoading || $streamsFailed || !empty($streamChoices))
                    <div x-data="{
                            loading: @js($streamsLoading),
                            failed: @js($streamsFailed),
                            streams: @js($streamChoices),
                        }"
                        x-init="$wire.on('aio-streams-loaded', (payload) => {
                            loading = false;
                            failed = payload.failed;
                            streams = payload.streams;
                        })">
                        <x-filament::section compact heading="{{ __('Choose a Source') }}" icon="heroicon-o-play">
                            <x-slot name="afterHeader">
                                <x-filament::icon-button icon="heroicon-o-arrow-path" size="sm" color="gray"
                                    :label="__('Refresh sources')" x-bind:class="loading ? 'animate-spin' : ''"
                                    x-bind:disabled="loading"
                                    x-on:click="loading = true; failed = false; $wire.retryLoadStreams()" />
                            </x-slot>
                            <div wire:init="loadResumeStreams" x-show="loading"
                                class="flex items-center justify-center gap-2 py-4">
                                <x-filament::loading-indicator class="h-5 w-5" />
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('Loading sources...') }}</span>
                            </div>
                            <div x-show="!loading && failed" class="flex flex-col items-center justify-center gap-2 py-4">
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('Failed to load sources') }}</span>
                                <x-filament::button size="xs" color="gray"
                                    x-on:click="loading = true; failed = false; $wire.retryLoadStreams()">
                                    {{ __('Retry') }}
                                </x-filament::button>
                            </div>
                            <div x-show="!loading && !failed && streams.length > 0"
                                class="divide-y divide-gray-100 dark:divide-gray-800 max-h-72 overflow-y-auto">
                                {{-- Stremio addons often pack file name, size, resolution, seeders/cache
                                     status, etc. into a multi-line `title` (or `description`) field, with
                                     `name` reserved for a short provider/quality label. Show both — `name`
                                     as the primary line, `title`/`description` underneath with newlines
                                     preserved via `whitespace-pre-line` — matching the M3U TV app's picker. --}}
                                <template x-for="(stream, index) in streams" :key="index">
                                    <button type="button" x-on:click="$wire.playChosenStream(index)"
                                        class="w-full flex items-start gap-2 px-1 py-2 text-left hover:bg-gray-50 dark:hover:bg-gray-800/60 transition-colors">
                                        <x-filament::icon icon="heroicon-o-play-circle"
                                            class="w-5 h-5 flex-shrink-0 text-primary-500 mt-0.5" />
                                        <div class="flex-1 min-w-0">
                                            <p class="text-xs font-semibold text-gray-800 dark:text-gray-200 whitespace-pre-line break-words"
                                                x-text="stream.name || stream.title || stream.description || ('{{ __('Source') }} ' + (index + 1))">
                                            </p>
                                            <p x-show="stream.name && (stream.title || stream.description)"
                                                class="text-[11px] text-gray-500 dark:text-gray-400 whitespace-pre-line break-words mt-0.5"
                                                x-text="stream.title || stream.description"></p>
                                        </div>
                                    </button>
                                </template>
                            </div>
                        </x-filament::section>
                    </div>
                @endif

                @if (!empty($detailResult['cast']))
                    <x-filament::section :collapsible="true" compact :collapsed="true" heading="{{ __('Cast') }}">
                        <x-slot name="afterHeader">
                            <x-filament::badge color="gray">{{ count($detailResult['cast']) }}</x-filament::badge>
                        </x-slot>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach ((array) $detailResult['cast'] as $member)
                                <x-filament::badge color="gray">
                                    {{ is_array($member) ? $member['name'] ?? '' : $member }}
                                </x-filament::badge>
                            @endforeach
                        </div>
                    </x-filament::section>
                @endif

                @if ($isSeries && !empty($detailSeasons))
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('Episodes') }}
                            </h3>
                            <div class="flex flex-wrap gap-1.5">
                                @foreach ($detailSeasons as $seasonNum)
                                    <x-filament::button size="xs" wire:click="selectSeason({{ $seasonNum }})"
                                        wire:loading.attr="disabled" wire:target="selectSeason({{ $seasonNum }})"
                                        :color="$detailSelectedSeason === $seasonNum ? 'primary' : 'gray'" :outlined="$detailSelectedSeason !== $seasonNum">
                                        {{ $seasonNum === 0 ? __('Specials') : __('S:n', ['n' => $seasonNum]) }}
                                    </x-filament::button>
                                @endforeach
                            </div>
                        </div>

                        @if (!$guestMode)
                            @php
                                $seasonEpisodeNumbers = collect($detailEpisodesBySeason[$detailSelectedSeason] ?? [])
                                    ->map(fn (array $v) => (int) ($v['episode'] ?? 0));
                                $allSeasonSelected = $seasonEpisodeNumbers->isNotEmpty()
                                    && $seasonEpisodeNumbers->every(fn (int $ep) => isset($selectedEpisodes["{$detailSelectedSeason}:{$ep}"]));
                            @endphp
                            <div class="flex items-center justify-between mb-2 px-0.5">
                                <label class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-400 cursor-pointer">
                                    <x-filament::input.checkbox wire:click="toggleSelectAllForSeason({{ $detailSelectedSeason }})"
                                        :checked="$allSeasonSelected" />
                                    {{ __('Select all in this season') }}
                                </label>
                                <x-filament::button size="xs" wire:click="addSelectedEpisodesToLibrary"
                                    wire:loading.attr="disabled" wire:target="addSelectedEpisodesToLibrary"
                                    icon="heroicon-o-plus-circle" :disabled="count($selectedEpisodes) === 0">
                                    {{ __('Add Selected (:count)', ['count' => count($selectedEpisodes)]) }}
                                </x-filament::button>
                            </div>
                        @endif

                        <div wire:loading.class="opacity-50" wire:target="selectSeason"
                            class="rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($detailEpisodesBySeason[$detailSelectedSeason] ?? [] as $episode)
                                @php
                                    $episodeNum = (int) ($episode['episode'] ?? 0);
                                    $episodeKey = "{$detailSelectedSeason}:{$episodeNum}";
                                    $hasAired = \App\Livewire\AioStreamsBrowse::hasEpisodeAired($episode);
                                @endphp
                                <div class="w-full flex items-center gap-3 px-3 py-3 hover:bg-gray-50 dark:hover:bg-gray-800/60 transition-colors">
                                    @if (!$guestMode)
                                        <x-filament::input.checkbox
                                            wire:click="toggleEpisodeSelected({{ $detailSelectedSeason }}, {{ $episodeNum }})"
                                            :checked="isset($selectedEpisodes[$episodeKey])"
                                            class="flex-shrink-0" />
                                    @endif
                                    <button type="button"
                                        wire:click="playStream({{ $detailSelectedSeason }}, {{ $episodeNum }})"
                                        wire:loading.attr="disabled" wire:target="playStream"
                                        @disabled(!$hasAired)
                                        class="flex-1 flex items-start gap-3 text-left disabled:opacity-40 min-w-0">
                                        <div
                                            class="relative w-28 aspect-video flex-shrink-0 rounded-md overflow-hidden bg-gray-200 dark:bg-gray-700">
                                            @if (!empty($episode['thumbnail']))
                                                <img src="{{ $episode['thumbnail'] }}" alt=""
                                                    class="w-full h-full object-cover" loading="lazy">
                                            @else
                                                <div class="w-full h-full flex items-center justify-center">
                                                    <x-heroicon-o-tv class="w-6 h-6 text-gray-400 dark:text-gray-500" />
                                                </div>
                                            @endif
                                        </div>
                                        <div class="flex-1 min-w-0 pt-0.5">
                                            <p class="text-xs font-semibold text-gray-900 dark:text-gray-100 leading-snug">
                                                {{ __('E:n', ['n' => $episode['episode'] ?? 0]) }}
                                                @if (!empty($episode['title']))
                                                    · {{ $episode['title'] }}
                                                @endif
                                            </p>
                                            @if (!empty($episode['released']))
                                                <p class="text-[11px] mt-0.5 {{ $hasAired ? 'text-gray-400 dark:text-gray-500' : 'text-warning-600 dark:text-warning-400 font-medium' }}">
                                                    @if (!$hasAired)
                                                        {{ __('Airs :date', ['date' => \Illuminate\Support\Carbon::parse($episode['released'])->format('M j, Y')]) }}
                                                    @else
                                                        {{ \Illuminate\Support\Carbon::parse($episode['released'])->format('M j, Y') }}
                                                    @endif
                                                </p>
                                            @endif
                                            @if (!empty($episode['overview']))
                                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 line-clamp-2">
                                                    {{ $episode['overview'] }}
                                                </p>
                                            @endif
                                        </div>
                                        @if ($hasAired)
                                            <x-filament::icon icon="heroicon-o-play-circle"
                                                class="w-5 h-5 flex-shrink-0 text-primary-500 mt-0.5" />
                                        @else
                                            <x-filament::icon icon="heroicon-o-clock"
                                                class="w-5 h-5 flex-shrink-0 text-gray-400 dark:text-gray-500 mt-0.5" />
                                        @endif
                                    </button>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </div>

        @if (!$isSeries && ! $streamsLoading && ! $streamsFailed && empty($streamChoices))
            <div
                class="flex-shrink-0 flex gap-2 px-4 py-3 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/50">
                <x-filament::button wire:click="playStream" wire:loading.attr="disabled" wire:target="playStream"
                    icon="heroicon-o-play" class="flex-1">
                    {{ __('Get Sources') }}
                </x-filament::button>
                @if (!$guestMode)
                    <x-filament::button wire:click="addMovieToLibrary" wire:loading.attr="disabled"
                        wire:target="addMovieToLibrary" icon="heroicon-o-plus-circle" color="gray"
                        outlined>
                        {{ __('Add to Library') }}
                    </x-filament::button>
                @endif
            </div>
        @endif

        @if ($isSeries && !$guestMode)
            <div
                class="flex-shrink-0 px-4 py-3 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/50">
                <x-filament::button wire:click="addSeriesToLibrary" wire:loading.attr="disabled"
                    wire:target="addSeriesToLibrary" icon="heroicon-o-plus-circle" color="gray" outlined
                    class="w-full">
                    {{ __('Add Series to Library') }}
                </x-filament::button>
            </div>
        @endif
    @endif
</div>
