<div x-init="$wire.call('loadDetailEpisodes')">
    @if ($detailResult)
        @php
            $detailIsSonarr = ($detailResult['integrationType'] ?? '') === 'sonarr';
            $detailInLibrary = !empty($detailResult['existsInLibrary']);

            // Use per-episode status from Sonarr /episode API when available —
            // this is authoritative; the lookup's statistics may be stale or absent.
if ($detailIsSonarr && !empty($detailSonarrEpisodeStatus)) {
    $sonarrFileCount = collect($detailSonarrEpisodeStatus)->flatMap(fn($eps) => $eps)->filter()->count();
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
                    <img src="{{ $detailResult['fanart'] }}" alt="" class="w-full h-full object-cover">
                    <div class="absolute inset-0 bg-gradient-to-t from-gray-900 via-gray-900/60 to-gray-900/10">
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
                                <span class="text-white/60 text-xs">{{ ucfirst($detailResult['status']) }}</span>
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
        </div>

        <div class="flex-1 overflow-y-auto">
            <div class="flex gap-3 px-4 py-4">
                @if (!empty($detailResult['poster']))
                    <img src="{{ $detailResult['poster'] }}" alt="{{ $detailResult['title'] ?? '' }}"
                        class="w-24 flex-shrink-0 rounded-md object-cover shadow-lg self-start">
                @else
                    <div
                        class="w-24 aspect-[2/3] flex-shrink-0 rounded-md bg-gray-200 dark:bg-gray-700 flex items-center justify-center self-start">
                        @if ($detailIntegration?->isSonarr())
                            <x-heroicon-o-tv class="w-8 h-8 text-gray-400 dark:text-gray-500" />
                        @else
                            <x-heroicon-o-film class="w-8 h-8 text-gray-400 dark:text-gray-500" />
                        @endif
                    </div>
                @endif
                <div class="flex-1 min-w-0 space-y-1.5 pt-0.5">
                    @if (!empty($detailResult['rating']))
                        <div class="flex items-center gap-1.5">
                            <x-filament::icon icon="heroicon-s-star" class="w-4 h-4 text-yellow-400 flex-shrink-0" />
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
                            <x-filament::icon icon="heroicon-o-building-office-2" class="w-3.5 h-3.5 flex-shrink-0" />
                            {{ $detailResult['network'] }}
                        </p>
                    @endif
                    @if (!empty($detailResult['genres']))
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            {{ implode(' · ', array_slice($detailResult['genres'], 0, 4)) }}</p>
                    @endif
                    @if (!empty($detailResult['runtime']))
                        <p class="text-xs text-gray-500 dark:text-gray-400 flex items-center gap-1">
                            <x-filament::icon icon="heroicon-o-clock" class="w-3.5 h-3.5 flex-shrink-0" />
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
                                $epTotalCount = collect($detailSonarrEpisodeStatus)->flatMap(fn($e) => $e)->count();
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
                        ($detailIntegration?->isSonarr() || $detailIntegration?->isRadarr()))
                    <div x-data="{ open: @js(!empty($detailReleases) || $releasesLoading) }" x-effect="if ($wire.detailReleasesLabel) { open = true; }"
                        class="rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
                        <button
                            @click="open = !open; if (open && @js(empty($detailReleases)) && !@js($releasesLoading)) $wire.call('loadDetailReleases')"
                            type="button"
                            class="w-full flex items-center gap-2 px-3 py-2.5 text-left hover:bg-gray-50 dark:hover:bg-gray-800/60 transition-colors focus:outline-none">
                            <x-filament::icon icon="heroicon-m-chevron-right"
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
                                                        $releaseBytes > 0 => number_format($releaseBytes / 1_024, 2) .
                                                            ' KB',
                                                        default => '–',
                                                    };
                                                    $rejectionReasons = implode('; ', $release['rejections'] ?? []);
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
                                                            <x-filament::icon icon="heroicon-o-arrow-down-tray"
                                                                class="w-4 h-4" />
                                                        </button>
                                                    @else
                                                        <x-filament::icon-button
                                                            wire:click="mountAction('confirmForceDownload', {{ \Illuminate\Support\Js::from(['guid' => $release['guid'], 'indexerId' => (int) ($release['indexerId'] ?? 0)]) }})"
                                                            icon="heroicon-o-arrow-down-tray" color="danger"
                                                            size="sm" :label="__('Force download (rejected release)')" class="mt-0.5" />
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                        <div
                                            class="px-3 py-2 border-t border-gray-100 dark:border-gray-800 flex justify-end">
                                            <button wire:click="loadDetailReleases" wire:loading.attr="disabled"
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

                @if ($detailIntegration?->isSonarr() || $detailIntegration?->isRadarr())
                    <x-filament::section :collapsible="true" compact :collapsed="true" heading="{{ __('Cast') }}"
                        compact>
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
                                        <a href="{{ $filmographyUrl }}"
                                            class="group flex items-center gap-3 py-2.5 first:pt-0 last:pb-0 rounded-md hover:bg-gray-50 dark:hover:bg-gray-800/60 -mx-2 px-2 transition-colors">
                                            <div
                                                class="w-9 h-9 rounded-full overflow-hidden flex-shrink-0 bg-gray-200 dark:bg-gray-700">
                                                @if (!empty($member['photo']))
                                                    <img src="{{ $member['photo'] }}" alt="{{ $actorName }}"
                                                        class="w-full h-full object-cover" loading="lazy">
                                                @else
                                                    <div class="w-full h-full flex items-center justify-center">
                                                        <x-heroicon-o-user class="w-4 h-4 text-gray-400" />
                                                    </div>
                                                @endif
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <p
                                                    class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate group-hover:text-primary-600 dark:group-hover:text-primary-400">
                                                    {{ $actorName }}
                                                </p>
                                                @if (!empty($member['character']))
                                                    <p class="text-xs text-gray-500 dark:text-gray-400 truncate">
                                                        {{ $member['character'] }}
                                                    </p>
                                                @endif
                                            </div>
                                        </a>
                                    @endforeach
                                </div>
                            @else
                                <p class="text-xs text-gray-400 dark:text-gray-500 text-center py-2">
                                    {{ __('No cast information available.') }}</p>
                            @endif
                        </div>
                    </x-filament::section>
                @endif

                @if ($detailIntegration?->isSonarr() && !empty($detailResult['seasons']))
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
                                            <x-filament::icon icon="heroicon-m-chevron-right"
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
                                                        $seasonFileCount === $seasonEpTotal && $seasonEpTotal > 0
                                                            ? 'success'
                                                            : ($seasonFileCount > 0
                                                                ? 'warning'
                                                                : 'gray');
                                                @endphp
                                                <x-filament::badge wire:loading.remove wire:target="loadDetailEpisodes"
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
                                                                    $epBytes > 0 => number_format($epBytes / 1_024, 2) .
                                                                        ' KB',
                                                                    default => null,
                                                                }
                                                                : null;
                                                        @endphp
                                                        <div class="flex items-center gap-2 px-3 py-1.5">
                                                            <span
                                                                class="text-xs font-mono text-gray-400 dark:text-gray-500 w-6 flex-shrink-0 text-right">{{ str_pad($episode['episodeNumber'], 2, '0', STR_PAD_LEFT) }}</span>
                                                            @if ($epHasFile)
                                                                <x-filament::icon icon="heroicon-s-check-circle"
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
                                                                    <x-filament::icon icon="heroicon-o-arrow-path"
                                                                        class="w-3.5 h-3.5" />
                                                                </button>
                                                            @else
                                                                <button
                                                                    wire:click="requestEpisode({{ $seasonNum }}, {{ $episode['episodeNumber'] }})"
                                                                    wire:loading.attr="disabled"
                                                                    wire:target="requestEpisode"
                                                                    title="{{ __('Request episode') }}"
                                                                    class="flex-shrink-0 p-1 rounded text-gray-300 dark:text-gray-600 hover:text-primary-600 dark:hover:text-primary-400 hover:bg-primary-50 dark:hover:bg-primary-900/20 disabled:opacity-40 transition-colors">
                                                                    <x-filament::icon icon="heroicon-o-arrow-down-tray"
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
                                                                    <x-filament::icon
                                                                        icon="heroicon-o-magnifying-glass"
                                                                        class="w-3.5 h-3.5" />
                                                                </button>
                                                            @endif
                                                        </div>
                                                    @endforeach
                                                </div>
                                            @elseif($seasonEpisodes !== null)
                                                <p class="text-xs text-gray-400 dark:text-gray-500 text-center py-3">
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
                                $sonarrSizeBytes >= 1_048_576 => number_format($sonarrSizeBytes / 1_048_576, 2) . ' MB',
                                $sonarrSizeBytes > 0 => number_format($sonarrSizeBytes / 1_024, 2) . ' KB',
                                default => null,
                            }
                            : null;
                @endphp
                <div class="space-y-2">
                    <div class="flex flex-col items-center justify-center gap-1 py-0.5">
                        <div class="flex items-center gap-2 text-sm">
                            @if ($detailIsDownloaded)
                                <x-filament::icon icon="heroicon-o-check-circle"
                                    class="w-4 h-4 text-success-600 dark:text-success-400 flex-shrink-0" />
                                <span
                                    class="text-success-700 dark:text-success-400">{{ __('Available in library') }}</span>
                            @else
                                <x-filament::icon icon="heroicon-s-bookmark"
                                    class="w-4 h-4 text-amber-500 flex-shrink-0" />
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
                            <x-filament::button wire:click="triggerAutomaticSearch" wire:loading.attr="disabled"
                                wire:target="triggerAutomaticSearch" color="gray"
                                icon="heroicon-o-magnifying-glass" class="flex-1">
                                {{ __('Auto Search') }}
                            </x-filament::button>
                            <x-filament::button wire:click="requestDetail" wire:loading.attr="disabled"
                                :disabled="$monitoredCount === 0" icon="heroicon-o-arrow-path" class="flex-1">
                                @if ($monitoredCount > 0)
                                    {{ __('Re-request :n Season(s)', ['n' => $monitoredCount]) }}
                                @else
                                    {{ __('Select Seasons') }}
                                @endif
                            </x-filament::button>
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
                            $radarrFileBytes >= 1_073_741_824 => number_format($radarrFileBytes / 1_073_741_824, 2) .
                                ' GB',
                            $radarrFileBytes >= 1_048_576 => number_format($radarrFileBytes / 1_048_576, 2) . ' MB',
                            $radarrFileBytes > 0 => number_format($radarrFileBytes / 1_024, 2) . ' KB',
                            default => null,
                        }
                        : null;
                @endphp
                <div class="space-y-2">
                    <div class="flex flex-col items-center justify-center gap-1 py-1">
                        <div class="flex items-center gap-2 text-sm text-success-700 dark:text-success-400">
                            <x-filament::icon icon="heroicon-o-check-circle" class="w-5 h-5" />
                            {{ __('This title is available in your library.') }}
                        </div>
                        @if ($radarrFileQuality || $radarrFileSize)
                            <div class="text-xs text-gray-400 dark:text-gray-500 tabular-nums">
                                {{ implode(' · ', array_filter([$radarrFileQuality, $radarrFileSize])) }}
                            </div>
                        @endif
                    </div>
                    @if (!$guestMode)
                        <x-filament::button wire:click="triggerAutomaticSearch" wire:loading.attr="disabled"
                            wire:target="triggerAutomaticSearch" color="gray" icon="heroicon-o-magnifying-glass"
                            class="w-full">
                            {{ __('Trigger Automatic Search') }}
                        </x-filament::button>
                    @endif
                </div>
            @elseif($detailInLibrary)
                {{-- Radarr monitored (not yet downloaded) --}}
                <div class="space-y-2">
                    <div
                        class="flex items-center justify-center gap-2 py-1 text-sm text-amber-600 dark:text-amber-400">
                        <x-filament::icon icon="heroicon-s-bookmark" class="w-5 h-5" />
                        {{ __('This title is monitored and searching for releases.') }}
                    </div>
                    @if (!$guestMode)
                        <x-filament::button wire:click="triggerAutomaticSearch" wire:loading.attr="disabled"
                            wire:target="triggerAutomaticSearch" color="gray" icon="heroicon-o-magnifying-glass"
                            class="w-full">
                            {{ __('Trigger Automatic Search') }}
                        </x-filament::button>
                    @endif
                </div>
            @elseif($detailIsSonarr)
                {{-- Sonarr not in library — initial request --}}
                @php $monitoredCount = collect($selectedSeasons)->filter()->count(); @endphp
                <x-filament::button wire:click="requestDetail" wire:loading.attr="disabled" :disabled="$monitoredCount === 0"
                    icon="heroicon-o-plus" class="w-full">
                    @if ($monitoredCount > 0)
                        {{ __('Request :count Season(s)', ['count' => $monitoredCount]) }}
                    @else
                        {{ __('Select Seasons to Request') }}
                    @endif
                </x-filament::button>
            @else
                {{-- Radarr not in library --}}
                <div class="flex gap-2">
                    <x-filament::button wire:click="request({{ $detailIndex }})" wire:loading.attr="disabled"
                        wire:target="request,addForInteractiveSearch" icon="heroicon-o-plus" class="flex-1">
                        {{ __('Request') }}
                    </x-filament::button>
                    @if (!$guestMode)
                        <x-filament::button wire:click="addForInteractiveSearch" wire:loading.attr="disabled"
                            wire:target="request,addForInteractiveSearch" color="gray"
                            icon="heroicon-o-list-bullet" class="flex-1">
                            {{ __('Pick Release') }}
                        </x-filament::button>
                    @endif
                </div>
            @endif
        </div>
    @endif
</div>
