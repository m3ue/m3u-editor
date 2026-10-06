<?php

namespace App\Services;

use App\Enums\CacheDispatchResult;
use App\Models\ArrCacheMovie;
use App\Models\ArrIntegration;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\Series;
use App\Services\Arr\ArrService;
use App\Services\Arr\SonarrService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends cache requests to the owner's Radarr (movies) or Sonarr (series)
 * instead of the provider, on playlists that prefer media server sources:
 * the arr downloads the title and playback picks it up from the media
 * server. Only integrations with "Use for caching" on are used.
 *
 * Only titles the arr doesn't have yet are sent, and nothing already in its
 * library is changed or removed. A title it already has counts as cached
 * when the file is there. Without a file, Cache Now downloads it from the
 * provider (so running Cache Now again gets past an arr that can't find
 * it), while dynamic-group auto-cache leaves it to the arr.
 *
 * Movies dynamic-group auto-cache adds to a Radarr with "Remove after
 * leaving dynamic groups" on are recorded (ArrCacheMovie) for
 * ArrCacheCleanupService to remove later. Cache Now on one stops that.
 *
 * Methods return null (or false) when the provider should be used instead,
 * including when the arr is unreachable or rejects the title.
 */
class CachedContentArrService
{
    /** @var array<string, ArrIntegration|null> keyed by "userId:type" */
    private array $integrations = [];

    /** @var array<int, true> integration ids that failed during this run */
    private array $unreachable = [];

    /** @var array<int, Playlist> playlists reloaded with the columns routing reads */
    private array $routablePlaylists = [];

    /**
     * Sonarr state per series id, so a series is looked up once per run.
     * Null when Sonarr can't be used for it.
     *
     * @var array<int, array{integration: ArrIntegration, tvdb_id: int, library_id: ?int, added: bool, files: ?array<int, array<int, bool>>}|null>
     */
    private array $series = [];

    /**
     * Send one movie (Radarr) or episode (Sonarr). An automatic episode adds
     * its series with only that season monitored; a manual one asks Sonarr
     * for that episode alone.
     */
    public function request(Channel|Episode $item, bool $automatic): ?CacheDispatchResult
    {
        return $item instanceof Channel
            ? $this->requestMovie($item, $automatic)
            : $this->requestEpisode($item, $automatic);
    }

    /**
     * Cache all episodes: add a series Sonarr doesn't have yet, with
     * `$seasons` monitored. False means the episodes are handled one by one
     * instead, and `request()` reuses this lookup.
     *
     * @param  array<int, int>  $seasons
     */
    public function requestSeries(Series $series, array $seasons): bool
    {
        return $this->seriesState($series, $series->playlist, $seasons)['added'] ?? false;
    }

    private function requestMovie(Channel $channel, bool $automatic): ?CacheDispatchResult
    {
        $radarr = $this->integration($channel->playlist, 'radarr');
        $tmdbId = (int) $channel->getTmdbId();
        if (! $radarr || $tmdbId <= 0) {
            return null;
        }

        if (! $automatic) {
            ArrCacheMovie::keep($radarr->user_id, $tmdbId);
        }

        $movie = $this->lookup($radarr, $tmdbId);
        if ($movie === null) {
            return null;
        }

        if ($movie['existsInLibrary']) {
            return $movie['hasFile'] || $automatic ? CacheDispatchResult::InArrLibrary : null;
        }

        $added = $this->add($radarr, $tmdbId, [
            'tmdbId' => $tmdbId,
            'title' => $movie['title'],
            'titleSlug' => $movie['titleSlug'],
            'images' => $movie['images'],
        ]);
        if ($added === null) {
            return null;
        }

        if ($automatic && $radarr->cache_cleanup && isset($added['id'])) {
            ArrCacheMovie::query()->updateOrCreate(
                ['arr_integration_id' => $radarr->id, 'tmdb_id' => $tmdbId],
                ['arr_movie_id' => (int) $added['id'], 'left_at' => null],
            );
        }

        return CacheDispatchResult::SentToArr;
    }

    private function requestEpisode(Episode $episode, bool $automatic): ?CacheDispatchResult
    {
        $series = $episode->series;
        $season = (int) $episode->season;
        $state = $series ? $this->seriesState($series, $episode->playlist, $automatic ? [$season] : null) : null;
        if ($state === null) {
            return null;
        }

        if ($state['added']) {
            return CacheDispatchResult::SentToArr;
        }

        if ($state['library_id'] === null) {
            /** @var SonarrService $sonarr */
            $sonarr = ArrService::make($state['integration']);

            return $sonarr->requestEpisode($state['tvdb_id'], $season, (int) $episode->episode_num)['ok']
                ? CacheDispatchResult::SentToArr
                : null;
        }

        if ($automatic) {
            return CacheDispatchResult::InArrLibrary;
        }

        $files = $this->series[$series->id]['files'] ??= $this->episodeFiles($state['integration'], $state['library_id']);

        return ($files[$season][(int) $episode->episode_num] ?? false) ? CacheDispatchResult::InArrLibrary : null;
    }

    /**
     * Look the series up in Sonarr once, adding it when it's missing and
     * `$addSeasons` is given. A manual single episode passes null and is
     * requested on its own.
     *
     * @param  array<int, int>|null  $addSeasons
     * @return array{integration: ArrIntegration, tvdb_id: int, library_id: ?int, added: bool, files: ?array<int, array<int, bool>>}|null
     */
    private function seriesState(Series $series, ?Playlist $playlist, ?array $addSeasons): ?array
    {
        if (array_key_exists($series->id, $this->series)) {
            return $this->series[$series->id];
        }

        $sonarr = $this->integration($playlist, 'sonarr');
        $tvdbId = (int) ($series->getMovieDbIds()['tvdb'] ?? 0);
        $lookup = $sonarr && $tvdbId > 0 ? $this->lookup($sonarr, $tvdbId) : null;
        if ($lookup === null) {
            return $this->series[$series->id] = null;
        }

        $state = [
            'integration' => $sonarr,
            'tvdb_id' => $tvdbId,
            'library_id' => $lookup['libraryId'],
            'added' => false,
            'files' => null,
        ];

        if ($state['library_id'] === null && $addSeasons !== null) {
            $seasons = collect($lookup['seasons'])
                ->map(fn (array $season): array => [
                    'seasonNumber' => (int) $season['seasonNumber'],
                    'monitored' => in_array((int) $season['seasonNumber'], $addSeasons, true),
                ]);

            $added = $seasons->contains('monitored', true) && $this->add($sonarr, $tvdbId, [
                'tvdbId' => $tvdbId,
                'title' => $lookup['title'],
                'titleSlug' => $lookup['titleSlug'],
                'seasons' => $seasons->values()->all(),
            ]) !== null;

            $state = $added ? [...$state, 'added' => true] : null;
        }

        return $this->series[$series->id] = $state;
    }

    /**
     * The owner's enabled caching Radarr or Sonarr, when the playlist prefers
     * media server sources and the integration hasn't failed this run.
     */
    private function integration(?Playlist $playlist, string $type): ?ArrIntegration
    {
        $playlist = $playlist ? $this->routablePlaylist($playlist) : null;
        if (! $playlist?->prefer_media_server_sources) {
            return null;
        }

        $key = $playlist->user_id.':'.$type;
        if (! array_key_exists($key, $this->integrations)) {
            $this->integrations[$key] = ArrIntegration::query()
                ->where('user_id', $playlist->user_id)
                ->where('type', $type)
                ->enabled()
                ->cacheEnabled()
                ->orderBy('id')
                ->first();
        }

        $integration = $this->integrations[$key];

        return $integration && ! isset($this->unreachable[$integration->id]) ? $integration : null;
    }

    /**
     * The playlist with the columns routing reads. Tables eager load the
     * playlist with a narrow select, and a column left out reads as null,
     * which would quietly send every Cache Now to the provider.
     */
    private function routablePlaylist(Playlist $playlist): Playlist
    {
        $attributes = $playlist->getAttributes();
        if (array_key_exists('prefer_media_server_sources', $attributes) && array_key_exists('user_id', $attributes)) {
            return $playlist;
        }

        return $this->routablePlaylists[$playlist->getKey()] ??= Playlist::query()
            ->select(['id', 'user_id', 'prefer_media_server_sources'])
            ->findOrFail($playlist->getKey());
    }

    /**
     * The arr's lookup entry for a TMDB (Radarr) or TVDB (Sonarr) id, or
     * null when it doesn't know the title. An unreachable arr is skipped for
     * the rest of the run.
     *
     * @return array<string, mixed>|null
     */
    private function lookup(ArrIntegration $integration, int $externalId): ?array
    {
        $key = $integration->isRadarr() ? 'tmdbId' : 'tvdbId';

        try {
            $results = ArrService::make($integration)->search(($integration->isRadarr() ? 'tmdb:' : 'tvdb:').$externalId);
        } catch (Throwable $e) {
            $this->unreachable[$integration->id] = true;
            Log::warning('Cache request lookup failed, using the provider', [
                'integration_id' => $integration->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return collect($results)->first(fn (array $result): bool => (int) ($result[$key] ?? 0) === $externalId);
    }

    /**
     * Add a title, returning what the arr created, or null when it couldn't
     * be added. A rejected add still counts (as an empty array) when the
     * title is in the library now: two dynamic groups sharing a member can
     * race to add it.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function add(ArrIntegration $integration, int $externalId, array $payload): ?array
    {
        $result = ArrService::make($integration)->add($payload);
        if ($result['ok']) {
            return (array) ($result['data'] ?? []);
        }

        if ($this->lookup($integration, $externalId)['existsInLibrary'] ?? false) {
            return [];
        }

        Log::warning('Cache request could not be added, using the provider', [
            'integration_id' => $integration->id,
            'error' => $result['error'] ?? null,
        ]);

        return null;
    }

    /**
     * Which episodes of a library series have a file, as season => [episode => bool].
     *
     * @return array<int, array<int, bool>>
     */
    private function episodeFiles(ArrIntegration $integration, int $libraryId): array
    {
        try {
            /** @var SonarrService $sonarr */
            $sonarr = ArrService::make($integration);

            return $sonarr->fetchEpisodeData($libraryId)['status'];
        } catch (Throwable) {
            return [];
        }
    }
}
