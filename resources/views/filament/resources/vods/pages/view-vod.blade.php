<x-filament-panels::page @class([
    'fi-resource-view-record-page',
    'fi-resource-'.str_replace('/', '-', $this->getResource()::getSlug()),
])>
    {{-- Check auth --}}
    @php
        $auth = $this->getAuth();
        $username = $auth['username'] ?? null;
        $password = $auth['password'] ?? null;
    @endphp

    @php
        try {
            $record = $this->record;
            $info = is_array($record->info) ? $record->info : [];
            $movieData = is_array($record->movie_data) ? $record->movie_data : [];
            // movie_data might contain an 'info' key with additional data
            $movieInfo = is_array($movieData['info'] ?? null) ? $movieData['info'] : [];

            // Get metadata - check all possible locations
            $title = $record->title_custom ?? ($record->title ?? ($record->name ?? 'Unknown'));
            $plot =
                $info['plot'] ?? ($info['description'] ?? ($movieInfo['plot'] ?? ($movieInfo['description'] ?? null)));
            $genre = $info['genre'] ?? ($movieInfo['genre'] ?? null);
            $year = $record->year ?? null;
            if (! $year && isset($info['releasedate']) && is_string($info['releasedate'])) {
                $year = substr($info['releasedate'], 0, 4);
            }
            // Prefer TMDB-sourced info['rating'] over the raw provider column - mirrors
            // the precedence fix in XtreamApiController::get_vod_info. Suppress the
            // rating entirely when TMDB's own vote count is known and below the
            // configured minimum (issue #1436) - an absent vote count (e.g. movie_data
            // / DVR-integrated items, which don't carry a vote count at all) is treated
            // as unknown, not low, and is not suppressed.
            $rating = $info['rating'] ?? ($record->rating ?? ($movieInfo['rating'] ?? null));
            $rating = \App\Support\TmdbRating::suppressIfLowVotes($rating, $info['vote_count'] ?? null, null);
            $duration =
                $info['duration'] ??
                ($movieInfo['duration'] ?? ($info['duration_secs'] ?? ($movieInfo['duration_secs'] ?? null)));
            $director = $info['director'] ?? ($movieInfo['director'] ?? null);
            $cast = $info['cast'] ?? ($movieInfo['cast'] ?? ($info['actors'] ?? ($movieInfo['actors'] ?? null)));
            $country = $info['country'] ?? ($movieInfo['country'] ?? null);

            // Handle backdrop_path - can be array (Emby), string (Xtream), or nested array
            $backdropRaw =
                $info['backdrop_path'] ??
                ($movieInfo['backdrop_path'] ?? ($info['cover_big'] ?? ($movieInfo['cover_big'] ?? null)));
            if (is_array($backdropRaw)) {
                // Could be array of URLs or array of objects
                $first = $backdropRaw[0] ?? null;
                $backdrop = is_array($first) ? $first['url'] ?? null : $first;
            } else {
                $backdrop = is_string($backdropRaw) ? $backdropRaw : null;
            }

            $cover = \App\Facades\LogoFacade::getChannelLogoUrl($record);
            $tmdbId = $record->tmdb_id ?? ($info['tmdb_id'] ?? ($movieInfo['tmdb_id'] ?? null));
            $imdbId = $record->imdb_id ?? ($info['imdb_id'] ?? ($movieInfo['imdb_id'] ?? null));
            $youtubeTrailer = $info['youtube_trailer'] ?? ($movieInfo['youtube_trailer'] ?? null);

            // Format duration safely
            $formattedDuration = null;
            if ($duration) {
                if (is_string($duration) && str_contains($duration, ':')) {
                    $formattedDuration = $duration;
                } elseif (is_numeric($duration)) {
                    $hours = floor((int) $duration / 3600);
                    $minutes = floor(((int) $duration % 3600) / 60);
                    $formattedDuration = $hours > 0 ? "{$hours}h {$minutes}m" : "{$minutes}m";
                } elseif (is_string($duration)) {
                    $formattedDuration = $duration;
                }
            }

            $hasError = false;
        } catch (\Throwable $e) {
            $hasError = true;
            $errorMessage = $e->getMessage();
            // Set defaults to prevent further errors
            $title = $record->title_custom ?? ($record->title ?? ($record->name ?? 'Unknown'));
            $plot = $genre = $year = $rating = $formattedDuration = $director = $cast = $country = null;
            $backdrop = null;
            $cover = null;
            $tmdbId = $imdbId = $youtubeTrailer = null;
        }

        $playerArgs = json_encode($record->getFloatingPlayerAttributes(username: $username, password: $password));
    @endphp

    @if ($hasError ?? false)
        <div class="mb-4 rounded-lg bg-red-100 p-4 text-red-700 dark:bg-red-900/30 dark:text-red-300">
            <p class="font-medium">Error loading VOD metadata</p>
            <p class="text-sm">{{ $errorMessage ?? 'Unknown error' }}</p>
        </div>
    @endif

    {{-- Hero Section with Backdrop --}}
    @if ($backdrop)
        <div class="relative -mt-4 mb-6 overflow-hidden rounded-xl" style="min-height: 400px">
            {{-- Backdrop Image --}}
            <div class="absolute inset-0">
                <img src="{{ $backdrop }}" alt="{{ $title }}" class="h-full w-full object-cover" />
                <div class="absolute inset-0 bg-gradient-to-r from-gray-900 via-gray-900/80 to-transparent"></div>
                <div class="absolute inset-0 bg-gradient-to-t from-gray-900 via-transparent to-transparent"></div>
            </div>

            {{-- Content Overlay --}}
            <div class="relative z-10 flex flex-col gap-8 p-8 md:flex-row">
                {{-- Poster --}}
                <div class="flex-shrink-0">
                    @if ($cover)
                        <img
                            src="{{ $cover }}"
                            alt="{{ $title }}"
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
                    <h1 class="text-4xl font-bold">{{ $title }}</h1>

                    {{-- Metadata Badges --}}
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        @if ($year)
                            <span class="rounded-full bg-white/10 px-3 py-1">{{ $year }}</span>
                        @endif
                        @if ($genre)
                            <span class="rounded-full bg-white/10 px-3 py-1">{{ $genre }}</span>
                        @endif
                        @if ($rating)
                            <span class="flex items-center gap-1 rounded-full bg-yellow-500/20 px-3 py-1 text-yellow-300">
                                <x-heroicon-s-star class="h-4 w-4" />
                                {{ $rating }}
                            </span>
                        @endif
                        @if ($formattedDuration)
                            <span class="flex items-center gap-1 rounded-full bg-white/10 px-3 py-1">
                                <x-heroicon-o-clock class="h-4 w-4" />
                                {{ $formattedDuration }}
                            </span>
                        @endif

                        {{-- Status Badge --}}
                        <span class="px-3 py-1 rounded-full {{ $record->enabled ? 'bg-green-500/20 text-green-300' : 'bg-red-500/20 text-red-300' }}">
                            {{ $record->enabled ? 'Enabled' : 'Disabled' }}
                        </span>
                    </div>

                    {{-- Plot --}}
                    @if ($plot)
                        <p class="max-w-2xl leading-relaxed text-gray-300">{{ Str::limit($plot, 500) }}</p>
                    @endif

                    {{-- External IDs --}}
                    @if ($tmdbId || $imdbId)
                        <div class="flex gap-3 pt-2">
                            @if ($tmdbId)
                                <a
                                    href="https://www.themoviedb.org/movie/{{ $tmdbId }}"
                                    target="_blank"
                                    class="rounded bg-blue-600/30 px-3 py-1 text-xs text-blue-300 transition-colors hover:bg-blue-600/50"
                                >
                                    TMDB: {{ $tmdbId }}
                                </a>
                            @endif
                            @if ($imdbId)
                                <a
                                    href="https://www.imdb.com/title/{{ $imdbId }}"
                                    target="_blank"
                                    class="rounded bg-yellow-600/30 px-3 py-1 text-xs text-yellow-300 transition-colors hover:bg-yellow-600/50"
                                >
                                    {{ $imdbId }}
                                </a>
                            @endif
                        </div>
                    @endif

                    {{-- Actions Row --}}
                    <div class="flex gap-3 pt-4">
                        {{-- Play Button --}}
                        <button
                            type="button"
                            wire:click="$dispatch('openFloatingStream', [{{ $playerArgs }}])"
                            class="bg-primary-600 hover:bg-primary-700 inline-flex items-center gap-2 rounded-lg px-6 py-3 font-semibold text-white transition-colors"
                        >
                            <x-heroicon-s-play class="h-5 w-5" />
                            Play Movie
                        </button>

                        @if ($youtubeTrailer)
                            <a
                                href="https://www.youtube.com/watch?v={{ $youtubeTrailer }}"
                                target="_blank"
                                class="inline-flex items-center gap-2 rounded-lg bg-red-600 px-4 py-3 text-white transition-colors hover:bg-red-700"
                            >
                                <x-heroicon-s-play class="h-5 w-5" />
                                Watch Trailer
                            </a>
                        @endif
                    </div>

                    {{-- Cast & Director --}}
                    @if ($director || $cast)
                        <div class="space-y-2 border-t border-white/10 pt-4">
                            @if ($director)
                                <p class="text-sm">
                                    <span class="text-gray-400">Director:</span>
                                    <span class="text-white">{{ $director }}</span>
                                </p>
                            @endif
                            @if ($cast)
                                <p class="text-sm">
                                    <span class="text-gray-400">Cast:</span>
                                    <span class="text-white">{{ Str::limit($cast, 200) }}</span>
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
                    @if ($cover)
                        <img
                            src="{{ $cover }}"
                            alt="{{ $title }}"
                            class="h-72 w-48 rounded-lg object-cover shadow-lg"
                        />
                    @else
                        <div class="flex h-72 w-48 items-center justify-center rounded-lg bg-gray-300 dark:bg-gray-700">
                            <x-heroicon-o-film class="h-12 w-12 text-gray-400" />
                        </div>
                    @endif
                </div>

                {{-- Info --}}
                <div class="flex-1 space-y-3">
                    <h1 class="text-2xl font-bold">{{ $title }}</h1>

                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        @if ($year)
                            <span class="rounded bg-gray-200 px-2 py-1 dark:bg-gray-700">{{ $year }}</span>
                        @endif
                        @if ($genre)
                            <span class="rounded bg-gray-200 px-2 py-1 dark:bg-gray-700">{{ $genre }}</span>
                        @endif
                        @if ($rating)
                            <span class="flex items-center gap-1 rounded bg-yellow-100 px-2 py-1 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-300">
                                <x-heroicon-s-star class="h-3 w-3" />
                                {{ $rating }}
                            </span>
                        @endif
                        @if ($formattedDuration)
                            <span class="flex items-center gap-1 rounded bg-gray-200 px-2 py-1 dark:bg-gray-700">
                                <x-heroicon-o-clock class="h-3 w-3" />
                                {{ $formattedDuration }}
                            </span>
                        @endif
                        <span class="px-2 py-1 rounded {{ $record->enabled ? 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300' : 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300' }}">
                            {{ $record->enabled ? 'Enabled' : 'Disabled' }}
                        </span>
                    </div>

                    @if ($plot)
                        <p class="text-gray-600 dark:text-gray-300">{{ Str::limit($plot, 300) }}</p>
                    @endif

                    {{-- Play Button --}}
                    <div class="flex gap-3 pt-2">
                        <button
                            type="button"
                            wire:click="$dispatch('openFloatingStream', [{{ $playerArgs }}])"
                            class="bg-primary-600 hover:bg-primary-700 inline-flex items-center gap-2 rounded-lg px-4 py-2 font-semibold text-white transition-colors"
                        >
                            <x-heroicon-s-play class="h-4 w-4" />
                            Play Movie
                        </button>
                    </div>

                    @if ($director || $cast)
                        <div class="space-y-1 pt-2 text-sm">
                            @if ($director)
                                <p><span class="text-gray-500">Director:</span> {{ $director }}</p>
                            @endif
                            @if ($cast)
                                <p><span class="text-gray-500">Cast:</span> {{ Str::limit($cast, 150) }}</p>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- Technical Details --}}
    <div class="mb-6">
        <x-filament::section icon="heroicon-o-cog-6-tooth" :heading="__('Technical Details')" collapsible collapsed>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <div>
                    <span class="text-sm text-gray-500">Format</span>
                    <div class="font-medium">{{ $record->container_extension ?? 'Unknown' }}</div>
                </div>
                <div>
                    <span class="text-sm text-gray-500">Stream ID</span>
                    <div class="font-medium">{{ $record->stream_id ?? 'N/A' }}</div>
                </div>
                <div>
                    <span class="text-sm text-gray-500">Playlist</span>
                    <div class="font-medium">{{ $record->playlist?->name ?? 'N/A' }}</div>
                </div>
                <div class="col-span-full">
                    <span class="text-sm text-gray-500">Stream URL</span>
                    <div class="mt-1 overflow-x-auto rounded bg-gray-100 p-2 font-mono text-sm dark:bg-gray-800">
                        {{ rtrim($record->getProxyUrl(username: $username, password: $password), '?proxy=true') }}
                    </div>
                </div>
                <div class="col-span-full">
                    <span class="text-sm text-gray-500">Proxy URL</span>
                    <div class="mt-1 overflow-x-auto rounded bg-gray-100 p-2 font-mono text-sm dark:bg-gray-800">
                        {{ $record->getProxyUrl(username: $username, password: $password) }}
                    </div>
                </div>
            </div>

            {{-- Probed stream info (ffprobe) --}}
            <div class="mt-6 border-t border-gray-200 pt-4 dark:border-gray-700">
                <h3 class="mb-2 text-sm font-semibold">{{ __('Probed Stream Info') }}</h3>
                @include('filament.partials.probed-stream-info', ['record' => $record])
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
