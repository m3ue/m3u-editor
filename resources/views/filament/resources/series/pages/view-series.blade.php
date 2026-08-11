<x-filament-panels::page @class([
    'fi-resource-view-record-page',
    'fi-resource-'.str_replace('/', '-', $this->getResource()::getSlug()),
])>
    {{-- Hero Backdrop Section --}}
    @php
        $record = $this->record;
        $backdrops = $record->backdrop_path;
        if (is_string($backdrops)) {
            $backdrops = json_decode($backdrops, true) ?? [];
        }
        $backdropUrl = null;
        if (! empty($backdrops) && is_array($backdrops)) {
            $backdropUrl = is_array($backdrops[0] ?? null) ? $backdrops[0]['url'] ?? null : $backdrops[0] ?? null;
        }
    @endphp

    {{-- Check auth --}}
    @php
        $auth = $this->getAuth();
        $username = $auth['username'] ?? null;
        $password = $auth['password'] ?? null;
    @endphp

    @if ($backdropUrl)
        <div class="relative -mt-4 mb-6 overflow-hidden rounded-xl" style="min-height: 400px">
            {{-- Backdrop Image --}}
            <div class="absolute inset-0">
                <img src="{{ $backdropUrl }}" alt="{{ $record->name }}" class="h-full w-full object-cover" />
                <div class="absolute inset-0 bg-gradient-to-r from-gray-900 via-gray-900/80 to-transparent"></div>
                <div class="absolute inset-0 bg-gradient-to-t from-gray-900 via-transparent to-transparent"></div>
            </div>

            {{-- Content Overlay --}}
            <div class="relative z-10 flex flex-col gap-8 p-8 md:flex-row">
                {{-- Poster --}}
                <div class="flex-shrink-0">
                    @php
                        $coverUrl = \App\Facades\LogoFacade::getSeriesLogoUrl($record);
                    @endphp
                    @if ($coverUrl)
                        <img
                            src="{{ $coverUrl }}"
                            alt="{{ $record->name }}"
                            class="h-72 w-48 rounded-lg object-cover shadow-2xl ring-1 ring-white/20"
                        />
                    @else
                        <div class="flex h-72 w-48 items-center justify-center rounded-lg bg-gray-800 shadow-2xl">
                            <x-heroicon-o-film class="h-16 w-16 text-gray-600" />
                        </div>
                    @endif
                </div>

                {{-- Info --}}
                <div class="flex-1 space-y-4 text-white">
                    <h1 class="text-4xl font-bold">{{ $record->name }}</h1>

                    {{-- Metadata Badges --}}
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        @if ($record->release_date)
                            <span class="rounded-full bg-white/10 px-3 py-1">{{ $record->release_date }}</span>
                        @endif
                        @if ($record->genre)
                            <span class="rounded-full bg-white/10 px-3 py-1">{{ $record->genre }}</span>
                        @endif
                        @if ($record->rating)
                            <span class="flex items-center gap-1 rounded-full bg-yellow-500/20 px-3 py-1 text-yellow-300">
                                <x-heroicon-s-star class="h-4 w-4" />
                                {{ $record->rating }}
                            </span>
                        @endif
                        @php
                            $seasonsCount = $record->seasons()->count();
                            $episodesCount = $record->episodes()->count();
                        @endphp
                        @if ($seasonsCount > 0)
                            <span class="rounded-full bg-white/10 px-3 py-1">{{ $seasonsCount }} {{ Str::plural('Season', $seasonsCount) }}</span>
                        @endif
                        @if ($episodesCount > 0)
                            <span class="rounded-full bg-white/10 px-3 py-1">{{ $episodesCount }} {{ Str::plural('Episode', $episodesCount) }}</span>
                        @endif

                        {{-- Status Badge --}}
                        <span class="px-3 py-1 rounded-full {{ $record->enabled ? 'bg-green-500/20 text-green-300' : 'bg-red-500/20 text-red-300' }}">
                            {{ $record->enabled ? 'Enabled' : 'Disabled' }}
                        </span>
                    </div>

                    {{-- Plot --}}
                    @if ($record->plot)
                        <p class="max-w-2xl leading-relaxed text-gray-300">{{ Str::limit($record->plot, 500) }}</p>
                    @endif

                    {{-- External IDs --}}
                    @if ($record->tmdb_id || $record->tvdb_id || $record->imdb_id)
                        <div class="flex gap-3 pt-2">
                            @if ($record->tmdb_id)
                                <a
                                    href="https://www.themoviedb.org/tv/{{ $record->tmdb_id }}"
                                    target="_blank"
                                    class="rounded bg-blue-600/30 px-3 py-1 text-xs text-blue-300 transition-colors hover:bg-blue-600/50"
                                >
                                    TMDB: {{ $record->tmdb_id }}
                                </a>
                            @endif
                            @if ($record->tvdb_id)
                                <a
                                    href="https://thetvdb.com/?tab=series&id={{ $record->tvdb_id }}"
                                    target="_blank"
                                    class="rounded bg-green-600/30 px-3 py-1 text-xs text-green-300 transition-colors hover:bg-green-600/50"
                                >
                                    TVDB: {{ $record->tvdb_id }}
                                </a>
                            @endif
                            @if ($record->imdb_id)
                                <a
                                    href="https://www.imdb.com/title/{{ $record->imdb_id }}"
                                    target="_blank"
                                    class="rounded bg-yellow-600/30 px-3 py-1 text-xs text-yellow-300 transition-colors hover:bg-yellow-600/50"
                                >
                                    {{ $record->imdb_id }}
                                </a>
                            @endif
                        </div>
                    @endif

                    {{-- YouTube Trailer --}}
                    @if ($record->youtube_trailer)
                        <div class="pt-2">
                            <a
                                href="https://www.youtube.com/watch?v={{ $record->youtube_trailer }}"
                                target="_blank"
                                class="inline-flex items-center gap-2 rounded-lg bg-red-600 px-4 py-2 text-white transition-colors hover:bg-red-700"
                            >
                                <x-heroicon-s-play class="h-5 w-5" />
                                Watch Trailer
                            </a>
                        </div>
                    @endif

                    {{-- Cast & Director --}}
                    @if ($record->director || $record->cast)
                        <div class="space-y-2 border-t border-white/10 pt-4">
                            @if ($record->director)
                                <p class="text-sm">
                                    <span class="text-gray-400">Director:</span>
                                    <span class="text-white">{{ $record->director }}</span>
                                </p>
                            @endif
                            @if ($record->cast)
                                <p class="text-sm">
                                    <span class="text-gray-400">Cast:</span>
                                    <span class="text-white">{{ Str::limit($record->cast, 200) }}</span>
                                </p>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @else
        {{-- Fallback without backdrop --}}
        <div class="mb-6 rounded-xl bg-gray-100 p-6 dark:bg-gray-800">
            <div class="flex flex-col gap-6 md:flex-row">
                {{-- Poster --}}
                <div class="flex-shrink-0">
                    @php
                        $coverUrl = \App\Facades\LogoFacade::getSeriesLogoUrl($record);
                    @endphp
                    @if ($coverUrl)
                        <img
                            src="{{ $coverUrl }}"
                            alt="{{ $record->name }}"
                            class="h-60 w-40 rounded-lg object-cover shadow-lg"
                        />
                    @else
                        <div class="flex h-60 w-40 items-center justify-center rounded-lg bg-gray-300 dark:bg-gray-700">
                            <x-heroicon-o-film class="h-12 w-12 text-gray-400" />
                        </div>
                    @endif
                </div>

                {{-- Info --}}
                <div class="flex-1 space-y-3">
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        @if ($record->release_date)
                            <span class="rounded bg-gray-200 px-2 py-1 dark:bg-gray-700">{{ $record->release_date }}</span>
                        @endif
                        @if ($record->genre)
                            <span class="rounded bg-gray-200 px-2 py-1 dark:bg-gray-700">{{ $record->genre }}</span>
                        @endif
                        @if ($record->rating)
                            <span class="flex items-center gap-1 rounded bg-yellow-100 px-2 py-1 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-300">
                                <x-heroicon-s-star class="h-3 w-3" />
                                {{ $record->rating }}
                            </span>
                        @endif
                        <span class="px-2 py-1 rounded {{ $record->enabled ? 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300' : 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300' }}">
                            {{ $record->enabled ? 'Enabled' : 'Disabled' }}
                        </span>
                    </div>

                    @if ($record->plot)
                        <p class="text-gray-600 dark:text-gray-300">{{ Str::limit($record->plot, 300) }}</p>
                    @endif

                    @if ($record->director || $record->cast)
                        <div class="space-y-1 text-sm">
                            @if ($record->director)
                                <p><span class="text-gray-500">Director:</span> {{ $record->director }}</p>
                            @endif
                            @if ($record->cast)
                                <p><span class="text-gray-500">Cast:</span> {{ Str::limit($record->cast, 150) }}</p>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- Probed Stream Info (aggregated across episodes) --}}
    @include('filament.partials.probed-stream-info-series', ['record' => $record])

    {{-- Seasons Grid --}}
    @php
        // Fetch all episodes with season relationship, ordered by season and episode number
        $allEpisodes = $record->episodes()->with('season')->orderBy('season')->orderBy('episode_num')->get();

        // Group episodes by season number
        $episodesBySeason = $allEpisodes->groupBy('season');

        // Create a lookup of Season models by season_number for cover images
        $seasonsLookup = $record->seasons()->orderBy('season_number')->get()->keyBy('season_number');
    @endphp
    @if ($episodesBySeason->isNotEmpty())
        <div class="mb-6">
            <h3 class="mb-4 flex items-center gap-2 text-lg font-semibold">
                <x-heroicon-o-rectangle-stack class="h-5 w-5" />
                Seasons
            </h3>
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 xl:grid-cols-8">
                @foreach ($episodesBySeason as $seasonNumber => $episodes)
                    @php
                        $season = $seasonsLookup->get($seasonNumber);
                        $cover = $season?->display_cover;
                        $seasonName = $season?->name ?? 'Season '.str_pad($seasonNumber, 2, '0', STR_PAD_LEFT);
                        $totalEpisodes = $episodes->count();
                        $enabledEpisodes = $episodes->where('enabled', true)->count();
                    @endphp
                    <x-filament::modal width="5xl">
                        <x-slot name="trigger">
                            <div class="group hover:ring-primary-500 dark:hover:ring-primary-500 h-full w-60 cursor-pointer overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200 transition-all dark:bg-gray-800 dark:ring-gray-700">
                                @if ($cover)
                                    <div class="aspect-[2/3] overflow-hidden">
                                        <img
                                            src="{{ $cover }}"
                                            alt="{{ $seasonName }}"
                                            class="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105"
                                        />
                                    </div>
                                @else
                                    <div class="flex aspect-[2/3] items-center justify-center bg-gray-100 dark:bg-gray-700">
                                        <x-heroicon-o-tv class="h-8 w-8 text-gray-400" />
                                    </div>
                                @endif
                                <div class="p-3">
                                    <div class="truncate text-sm font-medium">{{ $seasonName }}</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ $enabledEpisodes }}/{{ $totalEpisodes }} episodes
                                    </div>
                                    @if ($totalEpisodes > 0)
                                        <div class="mt-2 h-1 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-600">
                                            <div
                                                class="bg-primary-500 h-full rounded-full"
                                                style="width: {{ ($enabledEpisodes / $totalEpisodes) * 100 }}%"
                                            ></div>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </x-slot>

                        <x-slot name="heading">{{ $seasonName }}</x-slot>

                        <x-slot name="description">{{ $enabledEpisodes }}/{{ $totalEpisodes }} episodes enabled</x-slot>

                        {{-- Episodes list --}}
                        <div class="grid max-h-[60vh] grid-cols-1 gap-4 overflow-y-auto p-1 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach ($episodes as $episode)
                                @php
                                    $episodeCover = \App\Facades\LogoFacade::getEpisodeLogoUrl($episode);
                                    $info = $episode->info ?? [];
                                @endphp
                                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-200 dark:ring-gray-700 flex flex-col {{ !$episode->enabled ? 'opacity-50' : '' }}">
                                    {{-- Episode Thumbnail --}}
                                    <div class="relative aspect-video overflow-hidden rounded-t-lg bg-gray-100 dark:bg-gray-700">
                                        @if ($episodeCover)
                                            <img
                                                src="{{ $episodeCover }}"
                                                alt="{{ $episode->title }}"
                                                class="h-full w-full object-cover"
                                            />
                                        @else
                                            <div class="flex h-full w-full items-center justify-center">
                                                <x-heroicon-o-film class="h-8 w-8 text-gray-400" />
                                            </div>
                                        @endif

                                        {{-- Play Button Overlay --}}
                                        @if ($episode->enabled)
                                            <button
                                                type="button"
                                                wire:click="$dispatch('openFloatingStream', {{ json_encode($episode->getFloatingPlayerAttributes($username, $password)) }})"
                                                class="absolute inset-0 flex cursor-pointer items-center justify-center bg-black/40 opacity-0 transition-opacity hover:opacity-100"
                                            >
                                                <div class="flex h-12 w-12 items-center justify-center rounded-full bg-white/90 shadow-lg">
                                                    <x-heroicon-s-play class="ml-1 h-6 w-6 text-gray-900" />
                                                </div>
                                            </button>
                                        @endif

                                        {{-- Episode Number Badge --}}
                                        <div class="absolute top-2 left-2 rounded bg-black/70 px-2 py-1 text-xs text-white">
                                            E{{ str_pad($episode->episode_num, 2, '0', STR_PAD_LEFT) }}
                                        </div>

                                        {{-- Duration Badge --}}
                                        @if (! empty($info['duration']))
                                            <div class="absolute right-2 bottom-2 rounded bg-black/70 px-2 py-1 text-xs text-white">
                                                {{ $info['duration'] }}
                                            </div>
                                        @endif
                                    </div>

                                    {{-- Episode Info --}}
                                    <div class="space-y-1 p-3">
                                        <div class="truncate text-sm font-medium" title="{{ $episode->title }}">
                                            {{ $episode->title }}
                                        </div>

                                        @if (! empty($info['plot']))
                                            <p
                                                class="line-clamp-2 text-xs text-gray-500 dark:text-gray-400"
                                                title="{{ $info['plot'] }}"
                                            >
                                                {{ $info['plot'] }}
                                            </p>
                                        @endif

                                        <div class="flex items-center gap-2 pt-1">
                                            @if (! empty($info['rating']))
                                                <span class="inline-flex items-center gap-1 text-xs text-yellow-600 dark:text-yellow-400">
                                                    <x-heroicon-s-star class="h-3 w-3" />
                                                    {{ $info['rating'] }}
                                                </span>
                                            @endif
                                            @if (! empty($info['release_date']))
                                                <span class="text-xs text-gray-500 dark:text-gray-400">
                                                    {{ $info['release_date'] }}
                                                </span>
                                            @endif
                                        </div>

                                        @if ($episode->stream_stats_probed_at)
                                            @php
                                                $epStats = \App\Services\StreamStatsService::normalize(
                                                    is_array($episode->stream_stats) ? $episode->stream_stats : [],
                                                );
                                                $epQuality = \App\Services\StreamStatsService::detectQuality($epStats);
                                                $epHdr = \App\Services\StreamStatsService::detectHdr($epStats);
                                                $epVideo = \App\Services\StreamStatsService::detectVideoCodec($epStats);
                                                $epAudio = \App\Services\StreamStatsService::detectAudio($epStats);
                                                $badges = array_filter([$epQuality, $epHdr, $epVideo, $epAudio]);
                                            @endphp
                                            @if (! empty($badges))
                                                <div class="flex flex-wrap gap-1 pt-1">
                                                    @foreach ($badges as $badge)
                                                        <span class="inline-block rounded bg-gray-200 px-1.5 py-0.5 text-[10px] dark:bg-gray-700">
                                                            {{ $badge }}
                                                        </span>
                                                    @endforeach
                                                </div>
                                            @endif
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </x-filament::modal>
                @endforeach
            </div>
        </div>
    @endif
</x-filament-panels::page>
