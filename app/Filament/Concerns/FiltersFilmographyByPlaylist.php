<?php

namespace App\Filament\Concerns;

use App\Models\Channel;
use App\Models\Series;

/**
 * Shared logic for the admin + guest ActorFilmography pages when they are
 * navigated to with a playlist scope (e.g. from a series/VOD detail view).
 *
 * The using class must implement three abstract accessors that supply the
 * panel-specific context the trait cannot infer on its own:
 *  - filmographyPlaylistId() — the originating playlist id (0 = no scope)
 *  - filmographySeriesResource() — Filament resource class for Series URLs
 *  - filmographyVodResource() — Filament resource class for Vod URLs
 *
 * The filter and local-item resolver use cursor() + array_flip lookups so
 * memory stays flat regardless of playlist size.
 */
trait FiltersFilmographyByPlaylist
{
    /**
     * The originating playlist_id, or 0 to show global TMDB filmography.
     * Implementations should validate ownership for non-admin panels.
     */
    abstract protected function filmographyPlaylistId(): int;

    /**
     * Filament resource class used to build the Series view URL in the
     * current panel (admin vs guest variant).
     */
    abstract protected function filmographySeriesResource(): string;

    /**
     * Filament resource class used to build the Vod view URL in the
     * current panel (admin vs guest variant).
     */
    abstract protected function filmographyVodResource(): string;

    /**
     * Filter the actor's TMDB combined credits to only those that exist as
     * Series or VOD channels in the originating playlist. When the playlist
     * id is 0, returns $filmography unchanged (global view).
     *
     * @param  array<int, array<string, mixed>>  $filmography
     * @return array<int, array<string, mixed>>
     */
    protected function filterFilmographyToPlaylist(array $filmography): array
    {
        $playlistId = $this->filmographyPlaylistId();
        if ($playlistId <= 0) {
            return $filmography;
        }

        $seriesTmdbSet = [];
        Series::query()
            ->where('playlist_id', $playlistId)
            ->whereNotNull('tmdb_id')
            ->cursor()
            ->each(function (Series $series) use (&$seriesTmdbSet): void {
                $seriesTmdbSet[(int) $series->tmdb_id] = true;
            });

        $vodTmdbSet = [];
        Channel::query()
            ->where('playlist_id', $playlistId)
            ->where('is_vod', true)
            ->whereNotNull('tmdb_id')
            ->cursor()
            ->each(function (Channel $channel) use (&$vodTmdbSet): void {
                $vodTmdbSet[(int) $channel->tmdb_id] = true;
            });

        return collect($filmography)
            ->filter(function (array $item) use ($seriesTmdbSet, $vodTmdbSet): bool {
                $tmdbId = (int) ($item['tmdb_id'] ?? 0);
                if ($tmdbId <= 0) {
                    return false;
                }

                $isTv = ($item['media_type'] ?? 'movie') === 'tv';

                return $isTv
                    ? isset($seriesTmdbSet[$tmdbId])
                    : isset($vodTmdbSet[$tmdbId]);
            })
            ->values()
            ->all();
    }

    /**
     * Resolve a TMDB id + media_type to the local Series/Vod detail URL inside
     * the originating playlist. Returns null when no local record matches or
     * when there is no playlist scope.
     */
    protected function resolveLocalItemUrl(int $tmdbId, string $mediaType): ?string
    {
        $playlistId = $this->filmographyPlaylistId();
        if ($playlistId <= 0 || $tmdbId <= 0) {
            return null;
        }

        if ($mediaType === 'tv') {
            $series = Series::query()
                ->where('playlist_id', $playlistId)
                ->where('tmdb_id', $tmdbId)
                ->first();
            if ($series) {
                return $this->filmographySeriesResource()::getUrl('view', ['record' => $series->id]);
            }
        } else {
            $vod = Channel::query()
                ->where('playlist_id', $playlistId)
                ->where('is_vod', true)
                ->where('tmdb_id', $tmdbId)
                ->first();
            if ($vod) {
                return $this->filmographyVodResource()::getUrl('view', ['record' => $vod->id]);
            }
        }

        return null;
    }
}
