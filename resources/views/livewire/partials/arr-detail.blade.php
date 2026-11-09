<div x-init="$wire.call('loadDetailEpisodes')">
    @if ($detailResult && count($detailCandidates) > 1)
        <div class="border-b border-gray-200 bg-gray-50/60 px-4 py-2.5 dark:border-gray-700 dark:bg-white/[0.02]">
            <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">
                {{ __('Request Destination') }}
            </label>
            <x-filament::input.wrapper class="w-full">
                <x-filament::input.select wire:change="switchDetailIntegration($event.target.value)">
                    @foreach ($detailCandidates as $candidate)
                        <option
                            value="{{ $candidate['integrationId'] }}"
                            @selected((int) $candidate['integrationId'] === $detailIntegration?->id)
                        >
                            {{ $candidate['integrationName'] }}
                        </option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>
    @endif
    @if ($detailResult)
        @php
            $detailIsSonarr = ($detailResult['integrationType'] ?? '') === 'sonarr';
            $detailInLibrary = ! empty($detailResult['existsInLibrary']);

            // Use per-episode status from Sonarr /episode API when available —
            // this is authoritative; the lookup's statistics may be stale or absent.
            if ($detailIsSonarr && ! empty($detailSonarrEpisodeStatus)) {
                $sonarrFileCount = collect($detailSonarrEpisodeStatus)->flatMap(fn ($eps) => $eps)->filter()->count();
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
            @if (! empty($detailResult['fanart']))
                <div class="relative h-44 overflow-hidden">
                    <img src="{{ $detailResult['fanart'] }}" alt="" class="h-full w-full object-cover" />
                    <div class="absolute inset-0 bg-gradient-to-t from-gray-900 via-gray-900/60 to-gray-900/10"></div>
                    <div class="absolute right-0 bottom-0 left-0 px-4 pr-12 pb-3">
                        <h2 class="line-clamp-2 text-base leading-snug font-bold text-white">
                            {{ $detailResult['title'] ?? '' }}
                        </h2>
                        <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5">
                            @if (! empty($detailResult['year']))
                                <span class="text-xs text-white/60">{{ $detailResult['year'] }}</span>
                            @endif
                            @if (! empty($detailResult['certification']))
                                <span class="rounded border border-white/30 px-1.5 text-xs leading-5 text-white/70">{{ $detailResult['certification'] }}</span>
                            @endif
                            @if (! empty($detailResult['status']))
                                <span class="text-xs text-white/60">{{ ucfirst($detailResult['status']) }}</span>
                            @endif
                        </div>
                    </div>
                </div>
            @else
                <div class="flex items-center border-b border-gray-200 px-4 py-3 pr-12 dark:border-gray-700">
                    <div class="min-w-0 flex-1">
                        <h2 class="truncate text-base font-semibold text-gray-900 dark:text-gray-100">
                            {{ $detailResult['title'] ?? '' }}
                        </h2>
                        <div class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5">
                            @if (! empty($detailResult['year']))
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $detailResult['year'] }}</span>
                            @endif
                            @if (! empty($detailResult['certification']))
                                <span class="rounded border border-gray-300 px-1.5 text-xs leading-5 text-gray-500 dark:border-gray-600 dark:text-gray-400">{{ $detailResult['certification'] }}</span>
                            @endif
                            @if (! empty($detailResult['status']))
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ ucfirst($detailResult['status']) }}</span>
                            @endif
                        </div>
                    </div>
                </div>
            @endif
        </div>

        <div class="flex-1 overflow-y-auto">
            <div class="flex gap-3 px-4 py-4">
                @if (! empty($detailResult['poster']))
                    <img
                        src="{{ $detailResult['poster'] }}"
                        alt="{{ $detailResult['title'] ?? '' }}"
                        class="w-24 flex-shrink-0 self-start rounded-md object-cover shadow-lg"
                    />
                @else
                    <div class="flex aspect-[2/3] w-24 flex-shrink-0 items-center justify-center self-start rounded-md bg-gray-200 dark:bg-gray-700">
                        @if ($detailIntegration?->isSonarr())
                            <x-heroicon-o-tv class="h-8 w-8 text-gray-400 dark:text-gray-500" />
                        @else
                            <x-heroicon-o-film class="h-8 w-8 text-gray-400 dark:text-gray-500" />
                        @endif
                    </div>
                @endif
                <div class="min-w-0 flex-1 space-y-1.5 pt-0.5">
                    @if (! empty($detailResult['rating']))
                        <div class="flex items-center gap-1.5">
                            <x-filament::icon icon="heroicon-s-star" class="h-4 w-4 flex-shrink-0 text-yellow-400" />
                            <span class="text-sm font-bold text-gray-900 dark:text-gray-100">{{ $detailResult['rating']['value'] }}</span>
                            <span class="text-xs font-semibold tracking-wide text-gray-400 uppercase">{{ strtoupper($detailResult['rating']['source']) }}</span>
                            @if (! empty($detailResult['rating']['votes']))
                                <span class="text-xs text-gray-400">({{ number_format($detailResult['rating']['votes']) }})</span>
                            @endif
                        </div>
                    @endif
                    @if (! empty($detailResult['network']))
                        <p class="flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                            <x-filament::icon icon="heroicon-o-building-office-2" class="h-3.5 w-3.5 flex-shrink-0" />
                            {{ $detailResult['network'] }}
                        </p>
                    @endif
                    @if (! empty($detailResult['genres']))
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            {{ implode(' · ', array_slice($detailResult['genres'], 0, 4)) }}
                        </p>
                    @endif
                    @if (! empty($detailResult['runtime']))
                        <p class="flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                            <x-filament::icon icon="heroicon-o-clock" class="h-3.5 w-3.5 flex-shrink-0" />
                            {{ $detailResult['runtime'] }}m
                        </p>
                    @endif
                    @if ($detailIsDownloaded)
                        @php
                            if ($detailIsSonarr && ! empty($detailSonarrEpisodeStatus)) {
                                $epFileCount = collect($detailSonarrEpisodeStatus)
                                    ->flatMap(fn ($e) => $e)
                                    ->filter()
                                    ->count();
                                $epTotalCount = collect($detailSonarrEpisodeStatus)->flatMap(fn ($e) => $e)->count();
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
                    @if ($detailInLibrary && ($detailIsSonarr || ! $detailIsDownloaded))
                        <x-filament::badge color="warning" icon="heroicon-s-bookmark">
                            {{ __('Monitored') }}
                        </x-filament::badge>
                    @endif
                </div>
            </div>

            @if (! empty($detailResult['overview']))
                <div class="-mt-1 px-4 pb-4">
                    <p class="text-sm leading-relaxed text-gray-600 dark:text-gray-400">
                        {{ $detailResult['overview'] }}
                    </p>
                </div>
            @endif

            <div class="space-y-4 px-4 pb-4">
                {{-- Interactive Search (admin-only, library items only; or any Sonarr item when doing episode-level search) --}}
                @if (
                    ! $guestMode &&
                        ($detailInLibrary || ! empty($detailReleasesLabel)) &&
                        ($detailIntegration?->isSonarr() || $detailIntegration?->isRadarr()))
                    <div
                        x-data="{ open: @js(! empty($detailReleases) || $releasesLoading) }"
                        x-effect="
                            if ($wire.detailReleasesLabel) {
                                open = true;
                            }
                        "
                        class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700"
                    >
                        <button
                            @click="open = ! open; if (open && @js(empty($detailReleases)) && ! @js($releasesLoading)) $wire.call('loadDetailReleases')"
                            type="button"
                            class="flex w-full items-center gap-2 px-3 py-2.5 text-left transition-colors hover:bg-gray-50 focus:outline-none dark:hover:bg-gray-800/60"
                        >
                            <x-filament::icon
                                icon="heroicon-m-chevron-right"
                                class="h-4 w-4 flex-shrink-0 text-gray-400 transition-transform duration-200"
                                x-bind:class="{ 'rotate-90': open }"
                            />
                            <span class="flex-1 text-sm font-semibold text-gray-900 dark:text-gray-100">
                                {{ __('Interactive Search') }}{{ $detailReleasesLabel ? ' - ' . $detailReleasesLabel : '' }}
                            </span>
                            <span wire:loading wire:target="loadDetailReleases,loadEpisodeReleases"
                                ><x-filament::loading-indicator class="text-primary-500 h-3 w-3"
                            /></span>
                            @if (! empty($detailReleases))
                                <span
                                    wire:loading.remove
                                    wire:target="loadDetailReleases,loadEpisodeReleases"
                                    class="text-xs text-gray-400 dark:text-gray-500"
                                >{{ count($detailReleases) }}</span>
                            @endif
                        </button>
                        <div x-show="open" x-collapse>
                            <div class="border-t border-gray-200 dark:border-gray-700">
                                <div
                                    wire:loading
                                    wire:target="loadDetailReleases,loadEpisodeReleases"
                                    class="ml-2 flex justify-center py-4"
                                >
                                    <x-filament::loading-indicator class="text-primary-500 h-4 w-4" />
                                </div>
                                <div wire:loading.remove wire:target="loadDetailReleases,loadEpisodeReleases">
                                    @if (! empty($detailReleases))
                                        <div class="max-h-72 divide-y divide-gray-100 overflow-y-auto dark:divide-gray-800">
                                            @foreach ($detailReleases as $release)
                                                @php
                                                    $releaseBytes = (int) ($release['size'] ?? 0);
                                                    $releaseSize = match (true) {
                                                        $releaseBytes >= 1_073_741_824 => number_format(
                                                            $releaseBytes / 1_073_741_824,
                                                            2,
                                                        ).' GB',
                                                        $releaseBytes >= 1_048_576 => number_format(
                                                            $releaseBytes / 1_048_576,
                                                            2,
                                                        ).' MB',
                                                        $releaseBytes > 0 => number_format($releaseBytes / 1_024, 2).
                                                            ' KB',
                                                        default => '-',
                                                    };
                                                    $rejectionReasons = implode('; ', $release['rejections'] ?? []);
                                                @endphp
                                                <div class="flex items-start gap-2 px-3 py-2 {{ $release['approved'] ? '' : 'opacity-60' }}">
                                                    <div class="min-w-0 flex-1">
                                                        <p class="text-xs leading-snug break-words text-gray-800 dark:text-gray-200">
                                                            {{ $release['title'] }}
                                                        </p>
                                                        <div class="mt-1 flex flex-wrap items-center gap-1.5">
                                                            <x-filament::badge color="gray">{{ $release['quality'] }}</x-filament::badge>
                                                            <span class="text-xs text-gray-400 dark:text-gray-500">{{ $releaseSize }}</span>
                                                            <span class="text-xs text-gray-400 uppercase dark:text-gray-500">{{ $release['protocol'] }}</span>
                                                            @if (! $release['approved'])
                                                                <x-filament::badge color="danger">{{ __('Rejected') }}</x-filament::badge>
                                                            @endif
                                                        </div>
                                                        @if (! $release['approved'] && $rejectionReasons)
                                                            <p class="text-danger-500 dark:text-danger-400 mt-1 text-xs leading-snug">
                                                                {{ $rejectionReasons }}
                                                            </p>
                                                        @endif
                                                    </div>
                                                    @if ($release['approved'])
                                                        <button
                                                            wire:click="downloadDetailRelease('{{ $release['guid'] }}', {{ (int) ($release['indexerId'] ?? 0) }})"
                                                            wire:loading.attr="disabled"
                                                            wire:target="downloadDetailRelease"
                                                            title="{{ __('Download this release') }}"
                                                            class="hover:text-primary-600 dark:hover:text-primary-400 hover:bg-primary-50 dark:hover:bg-primary-900/20 mt-0.5 flex-shrink-0 rounded p-1.5 text-gray-400 transition-colors disabled:opacity-40"
                                                        >
                                                            <x-filament::icon
                                                                icon="heroicon-o-arrow-down-tray"
                                                                class="h-4 w-4"
                                                            />
                                                        </button>
                                                    @else
                                                        <x-filament::icon-button
                                                            wire:click="mountAction('confirmForceDownload', {{ \Illuminate\Support\Js::from(['guid' => $release['guid'], 'indexerId' => (int) ($release['indexerId'] ?? 0)]) }})"
                                                            icon="heroicon-o-arrow-down-tray"
                                                            color="danger"
                                                            size="sm"
                                                            :label="__('Force download (rejected release)')"
                                                            class="mt-0.5"
                                                        />
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                        <div class="flex justify-end border-t border-gray-100 px-3 py-2 dark:border-gray-800">
                                            <button
                                                wire:click="loadDetailReleases"
                                                wire:loading.attr="disabled"
                                                wire:target="loadDetailReleases,loadEpisodeReleases"
                                                class="text-primary-600 dark:text-primary-400 text-xs hover:underline disabled:opacity-40"
                                            >
                                                {{ __('Refresh') }}
                                            </button>
                                        </div>
                                    @elseif (! $releasesLoading)
                                        <p class="py-3 text-center text-xs text-gray-400 dark:text-gray-500">
                                            {{ __('No releases found.') }}
                                        </p>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                @endif

                @if ($detailIntegration?->isSonarr() || $detailIntegration?->isRadarr())
                    <x-filament::section
                        :collapsible="true"
                        compact
                        :collapsed="true"
                        heading="{{ __('Cast') }}"
                        compact
                    >
                        <x-slot name="afterHeader">
                            <span wire:loading wire:target="loadDetailEpisodes">
                                <x-filament::loading-indicator class="text-primary-500 h-3 w-3" />
                            </span>
                            @if ($detailCast)
                                <x-filament::badge color="gray" wire:loading.remove wire:target="loadDetailEpisodes">
                                    {{ count($detailCast) }}
                                </x-filament::badge>
                            @endif
                        </x-slot>

                        <div wire:loading wire:target="loadDetailEpisodes" class="flex justify-center py-2">
                            <x-filament::loading-indicator class="text-primary-500 h-4 w-4" />
                        </div>
                        <div wire:loading.remove wire:target="loadDetailEpisodes">
                            @if ($detailCast)
                                <div class="divide-y divide-gray-100 dark:divide-gray-800">
                                    @foreach ($detailCast as $member)
                                        @php
                                            $actorName = (string) ($member['actor'] ?? '');
                                            // TvMaze (Sonarr/TV) uses its own person ID namespace — passing it
                                            // as a TMDB personId resolves the wrong person. Use 0 so the
                                            // filmography page falls back to a name-based TMDB search instead.
                                            $personId = $detailIsSonarr ? 0 : (int) ($member['id'] ?? 0);
                                            $filmographyPage = $guestMode
                                                ? \App\Filament\GuestPanel\Pages\GuestActorFilmography::class
                                                : \App\Filament\Pages\ActorFilmography::class;
                                            $filmographyUrl = $filmographyPage::getUrl([
                                                'personId' => $personId,
                                                'name' => $actorName,
                                            ]);
                                        @endphp
                                        <a
                                            href="{{ $filmographyUrl }}"
                                            class="group -mx-2 flex items-center gap-3 rounded-md px-2 py-2.5 transition-colors first:pt-0 last:pb-0 hover:bg-gray-50 dark:hover:bg-gray-800/60"
                                        >
                                            <div class="h-9 w-9 flex-shrink-0 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                                                @if (! empty($member['photo']))
                                                    <img
                                                        src="{{ $member['photo'] }}"
                                                        alt="{{ $actorName }}"
                                                        class="h-full w-full object-cover"
                                                        loading="lazy"
                                                    />
                                                @else
                                                    <div class="flex h-full w-full items-center justify-center">
                                                        <x-heroicon-o-user class="h-4 w-4 text-gray-400" />
                                                    </div>
                                                @endif
                                            </div>
                                            <div class="min-w-0 flex-1">
                                                <p class="group-hover:text-primary-600 dark:group-hover:text-primary-400 truncate text-sm font-medium text-gray-900 dark:text-gray-100">
                                                    {{ $actorName }}
                                                </p>
                                                @if (! empty($member['character']))
                                                    <p class="truncate text-xs text-gray-500 dark:text-gray-400">
                                                        {{ $member['character'] }}
                                                    </p>
                                                @endif
                                            </div>
                                        </a>
                                    @endforeach
                                </div>
                            @else
                                <p class="py-2 text-center text-xs text-gray-400 dark:text-gray-500">
                                    {{ __('No cast information available.') }}
                                </p>
                            @endif
                        </div>
                    </x-filament::section>
                @endif

                @if ($detailIntegration?->isSonarr() && ! empty($detailResult['seasons']))
                    <div>
                        <div class="mb-2 flex items-center justify-between">
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('Seasons') }}</h3>
                            <div class="flex items-center gap-2">
                                <button
                                    wire:click="toggleAllSeasons(true)"
                                    class="text-primary-600 dark:text-primary-400 text-xs hover:underline"
                                >
                                    {{ __('All') }}
                                </button>
                                <span class="text-gray-300 dark:text-gray-600">·</span>
                                <button
                                    wire:click="toggleAllSeasons(false)"
                                    class="text-xs text-gray-500 hover:underline dark:text-gray-400"
                                >
                                    {{ __('None') }}
                                </button>
                            </div>
                        </div>
                        <div class="divide-y divide-gray-100 overflow-hidden rounded-lg border border-gray-200 dark:divide-gray-800 dark:border-gray-700">
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
                                    <div class="flex items-center transition-colors hover:bg-gray-50 dark:hover:bg-gray-800/60">
                                        <div class="py-2.5 pl-3" @click.stop>
                                            <input
                                                type="checkbox"
                                                wire:model.live="selectedSeasons.{{ $seasonNum }}"
                                                class="text-primary-600 focus:ring-primary-500 rounded border-gray-300 dark:border-gray-600 dark:bg-gray-800"
                                            />
                                        </div>
                                        <button
                                            @click="expanded = ! expanded"
                                            type="button"
                                            class="flex flex-1 items-center gap-2 px-2 py-2.5 pr-3 text-left"
                                        >
                                            <x-filament::icon
                                                icon="heroicon-m-chevron-right"
                                                class="h-3.5 w-3.5 flex-shrink-0 text-gray-400 transition-transform duration-200"
                                                x-bind:class="{ 'rotate-90': expanded }"
                                            />
                                            <span class="flex-1 text-sm text-gray-800 dark:text-gray-200">
                                                {{ $seasonNum === 0 ? __('Specials') : __('Season :n', ['n' => $seasonNum]) }}
                                            </span>
                                            <span wire:loading wire:target="loadDetailEpisodes"
                                                ><x-filament::loading-indicator class="h-3 w-3 text-gray-400"
                                            /></span>
                                            @if ($episodeCount !== null)
                                                <span
                                                    wire:loading.remove
                                                    wire:target="loadDetailEpisodes"
                                                    class="text-xs text-gray-400 dark:text-gray-500"
                                                >{{ __(':n ep', ['n' => $episodeCount]) }}</span>
                                            @endif
                                            @if ($seasonFileCount !== null && $seasonEpTotal !== null)
                                                @php
                                                    $seasonBadgeColor =
                                                        $seasonFileCount === $seasonEpTotal && $seasonEpTotal > 0
                                                            ? 'success'
                                                            : ($seasonFileCount > 0
                                                                ? 'warning'
                                                                : 'gray');
                                                @endphp
                                                <x-filament::badge
                                                    wire:loading.remove
                                                    wire:target="loadDetailEpisodes"
                                                    :color="$seasonBadgeColor"
                                                >{{ $seasonFileCount }}/{{ $seasonEpTotal }}</x-filament::badge>
                                            @endif
                                        </button>
                                    </div>
                                    <div
                                        x-show="expanded"
                                        x-collapse
                                        class="border-t border-gray-100 bg-gray-50/50 dark:border-gray-800 dark:bg-gray-800/30"
                                    >
                                        <div
                                            wire:loading
                                            wire:target="loadDetailEpisodes"
                                            class="flex justify-center py-4"
                                        >
                                            <x-filament::loading-indicator class="text-primary-500 h-4 w-4" />
                                        </div>
                                        <div wire:loading.remove wire:target="loadDetailEpisodes">
                                            @if ($seasonEpisodes !== null && count($seasonEpisodes) > 0)
                                                <div class="divide-y divide-gray-100 dark:divide-gray-700/50">
                                                    @foreach ($seasonEpisodes as $episode)
                                                        @php
                                                            $epHasFile = ! empty(
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
                                                                    ).' GB',
                                                                    $epBytes >= 1_048_576 => number_format(
                                                                        $epBytes / 1_048_576,
                                                                        2,
                                                                    ).' MB',
                                                                    $epBytes > 0 => number_format($epBytes / 1_024, 2).
                                                                        ' KB',
                                                                    default => null,
                                                                }
                                                            : null;
                                                        @endphp
                                                        <div class="flex items-center gap-2 px-3 py-1.5">
                                                            <span class="w-6 flex-shrink-0 text-right font-mono text-xs text-gray-400 dark:text-gray-500">{{ str_pad($episode['episodeNumber'], 2, '0', STR_PAD_LEFT) }}</span>
                                                            @if ($epHasFile)
                                                                <x-filament::icon
                                                                    icon="heroicon-s-check-circle"
                                                                    class="text-success-500 dark:text-success-400 h-3.5 w-3.5 flex-shrink-0"
                                                                />
                                                            @endif
                                                            <span class="min-w-0 flex-1 truncate text-xs text-gray-800 dark:text-gray-200">{{ $episode['title'] }}</span>
                                                            @if ($epHasFile && ($epQuality || $epSize))
                                                                <span class="flex-shrink-0 text-xs text-gray-400 tabular-nums dark:text-gray-500">{{ implode(' · ', array_filter([$epQuality, $epSize])) }}</span>
                                                            @elseif (! empty($episode['airDate']))
                                                                <span class="flex-shrink-0 text-xs text-gray-400 tabular-nums dark:text-gray-500">{{ \Carbon\Carbon::parse($episode['airDate'])->format('M j, Y') }}</span>
                                                            @endif
                                                            @if ($epHasFile)
                                                                <button
                                                                    wire:click="requestEpisode({{ $seasonNum }}, {{ $episode['episodeNumber'] }})"
                                                                    wire:loading.attr="disabled"
                                                                    wire:target="requestEpisode"
                                                                    title="{{ __('Re-download this episode') }}"
                                                                    class="text-success-500 dark:text-success-400 hover:text-primary-600 dark:hover:text-primary-400 hover:bg-primary-50 dark:hover:bg-primary-900/20 flex-shrink-0 rounded p-1 transition-colors disabled:opacity-40"
                                                                >
                                                                    <x-filament::icon
                                                                        icon="heroicon-o-arrow-path"
                                                                        class="h-3.5 w-3.5"
                                                                    />
                                                                </button>
                                                            @else
                                                                <button
                                                                    wire:click="requestEpisode({{ $seasonNum }}, {{ $episode['episodeNumber'] }})"
                                                                    wire:loading.attr="disabled"
                                                                    wire:target="requestEpisode"
                                                                    title="{{ __('Request episode') }}"
                                                                    class="hover:text-primary-600 dark:hover:text-primary-400 hover:bg-primary-50 dark:hover:bg-primary-900/20 flex-shrink-0 rounded p-1 text-gray-300 transition-colors disabled:opacity-40 dark:text-gray-600"
                                                                >
                                                                    <x-filament::icon
                                                                        icon="heroicon-o-arrow-down-tray"
                                                                        class="h-3.5 w-3.5"
                                                                    />
                                                                </button>
                                                            @endif
                                                            @if (! $guestMode)
                                                                <button
                                                                    wire:click="loadEpisodeReleases({{ $seasonNum }}, {{ $episode['episodeNumber'] }})"
                                                                    wire:loading.attr="disabled"
                                                                    wire:target="loadEpisodeReleases,requestEpisode"
                                                                    title="{{ __('Pick a specific release for this episode') }}"
                                                                    class="hover:text-primary-600 dark:hover:text-primary-400 hover:bg-primary-50 dark:hover:bg-primary-900/20 flex-shrink-0 rounded p-1 text-gray-300 transition-colors disabled:opacity-40 dark:text-gray-600"
                                                                >
                                                                    <x-filament::icon
                                                                        icon="heroicon-o-magnifying-glass"
                                                                        class="h-3.5 w-3.5"
                                                                    />
                                                                </button>
                                                            @endif
                                                        </div>
                                                    @endforeach
                                                </div>
                                            @elseif ($seasonEpisodes !== null)
                                                <p class="py-3 text-center text-xs text-gray-400 dark:text-gray-500">
                                                    {{ __('No episode details available.') }}
                                                </p>
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

        <div class="flex-shrink-0 border-t border-gray-200 bg-gray-50 px-4 py-3 dark:border-gray-700 dark:bg-gray-900/50">
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
                                ).' GB',
                                $sonarrSizeBytes >= 1_048_576 => number_format($sonarrSizeBytes / 1_048_576, 2).' MB',
                                $sonarrSizeBytes > 0 => number_format($sonarrSizeBytes / 1_024, 2).' KB',
                                default => null,
                            }
                    : null;
                @endphp
                <div class="space-y-2">
                    <div class="flex flex-col items-center justify-center gap-1 py-0.5">
                        <div class="flex items-center gap-2 text-sm">
                            @if ($detailIsDownloaded)
                                <x-filament::icon
                                    icon="heroicon-o-check-circle"
                                    class="text-success-600 dark:text-success-400 h-4 w-4 flex-shrink-0"
                                />
                                <span class="text-success-700 dark:text-success-400">{{ __('Available in library') }}</span>
                            @else
                                <x-filament::icon
                                    icon="heroicon-s-bookmark"
                                    class="h-4 w-4 flex-shrink-0 text-amber-500"
                                />
                                <span class="text-amber-600 dark:text-amber-400">{{ __('Monitored - searching for releases') }}</span>
                            @endif
                        </div>
                        @if ($sonarrSizeDisplay)
                            <div class="text-xs text-gray-400 tabular-nums dark:text-gray-500">
                                {{ $sonarrSizeDisplay }} {{ __('on disk') }}
                            </div>
                        @endif
                    </div>
                    @if (! $guestMode)
                        <div class="flex gap-2">
                            <x-filament::button
                                wire:click="triggerAutomaticSearch"
                                wire:loading.attr="disabled"
                                wire:target="triggerAutomaticSearch"
                                color="gray"
                                icon="heroicon-o-magnifying-glass"
                                class="flex-1"
                            >
                                {{ __('Auto Search') }}
                            </x-filament::button>
                            <x-filament::button
                                wire:click="requestDetail"
                                wire:loading.attr="disabled"
                                :disabled="$monitoredCount === 0"
                                icon="heroicon-o-arrow-path"
                                class="flex-1"
                            >
                                @if ($monitoredCount > 0)
                                    {{ __('Re-request :n Season(s)', ['n' => $monitoredCount]) }}
                                @else
                                    {{ __('Select Seasons') }}
                                @endif
                            </x-filament::button>
                        </div>
                    @endif
                </div>
            @elseif ($detailIsDownloaded)
                {{-- Radarr downloaded --}}
                @php
                    $radarrFileQuality = $detailResult['fileQuality'] ?? null;
                    $radarrFileBytes = $detailResult['fileSize'] ?? null;
                    $radarrFileSize = $radarrFileBytes
                        ? match (true) {
                            $radarrFileBytes >= 1_073_741_824 => number_format($radarrFileBytes / 1_073_741_824, 2).
                                ' GB',
                            $radarrFileBytes >= 1_048_576 => number_format($radarrFileBytes / 1_048_576, 2).' MB',
                            $radarrFileBytes > 0 => number_format($radarrFileBytes / 1_024, 2).' KB',
                            default => null,
                        }
                    : null;
                @endphp
                <div class="space-y-2">
                    <div class="flex flex-col items-center justify-center gap-1 py-1">
                        <div class="text-success-700 dark:text-success-400 flex items-center gap-2 text-sm">
                            <x-filament::icon icon="heroicon-o-check-circle" class="h-5 w-5" />
                            {{ __('This title is available in your library.') }}
                        </div>
                        @if ($radarrFileQuality || $radarrFileSize)
                            <div class="text-xs text-gray-400 tabular-nums dark:text-gray-500">
                                {{ implode(' · ', array_filter([$radarrFileQuality, $radarrFileSize])) }}
                            </div>
                        @endif
                    </div>
                    @if (! $guestMode)
                        <x-filament::button
                            wire:click="triggerAutomaticSearch"
                            wire:loading.attr="disabled"
                            wire:target="triggerAutomaticSearch"
                            color="gray"
                            icon="heroicon-o-magnifying-glass"
                            class="w-full"
                        >
                            {{ __('Trigger Automatic Search') }}
                        </x-filament::button>
                    @endif
                </div>
            @elseif ($detailInLibrary)
                {{-- Radarr monitored (not yet downloaded) --}}
                <div class="space-y-2">
                    <div class="flex items-center justify-center gap-2 py-1 text-sm text-amber-600 dark:text-amber-400">
                        <x-filament::icon icon="heroicon-s-bookmark" class="h-5 w-5" />
                        {{ __('This title is monitored and searching for releases.') }}
                    </div>
                    @if (! $guestMode)
                        <x-filament::button
                            wire:click="triggerAutomaticSearch"
                            wire:loading.attr="disabled"
                            wire:target="triggerAutomaticSearch"
                            color="gray"
                            icon="heroicon-o-magnifying-glass"
                            class="w-full"
                        >
                            {{ __('Trigger Automatic Search') }}
                        </x-filament::button>
                    @endif
                </div>
            @elseif ($detailIsSonarr)
                {{-- Sonarr not in library — initial request --}}
                @php $monitoredCount = collect($selectedSeasons)->filter()->count(); @endphp
                <x-filament::button
                    wire:click="requestDetail"
                    wire:loading.attr="disabled"
                    :disabled="$monitoredCount === 0"
                    icon="heroicon-o-plus"
                    class="w-full"
                >
                    @if ($monitoredCount > 0)
                        {{ __('Request :count Season(s)', ['count' => $monitoredCount]) }}
                    @else
                        {{ __('Select Seasons to Request') }}
                    @endif
                </x-filament::button>
            @else
                {{-- Radarr not in library --}}
                <div class="flex gap-2">
                    <x-filament::button
                        wire:click="request({{ $detailIndex }})"
                        wire:loading.attr="disabled"
                        wire:target="request,addForInteractiveSearch"
                        icon="heroicon-o-plus"
                        class="flex-1"
                    >
                        {{ __('Request') }}
                    </x-filament::button>
                    @if (! $guestMode)
                        <x-filament::button
                            wire:click="addForInteractiveSearch"
                            wire:loading.attr="disabled"
                            wire:target="request,addForInteractiveSearch"
                            color="gray"
                            icon="heroicon-o-list-bullet"
                            class="flex-1"
                        >
                            {{ __('Pick Release') }}
                        </x-filament::button>
                    @endif
                </div>
            @endif
        </div>
    @endif
</div>
