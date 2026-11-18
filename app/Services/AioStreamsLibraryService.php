<?php

namespace App\Services;

use App\Jobs\FetchTmdbIds;
use App\Jobs\ResolveAioStreamsChannel;
use App\Jobs\ResolveAioStreamsEpisode;
use App\Models\AioStreamsMatchProfile;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\MediaServerIntegration;
use App\Models\Series;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Creates the library rows for AIOStreams titles: a custom VOD channel per
 * movie, a custom Series per show and Season/Episode rows on demand. Shared by
 * the browse page's single "Add to Library" buttons and the bulk add job
 * (AddAioStreamsItemsToLibrary), so both store titles the same way.
 */
class AioStreamsLibraryService
{
    /**
     * Add a movie as a custom VOD channel and queue its stream resolution.
     * Returns null when the movie is already in the library.
     *
     * @param  array<string, mixed>  $meta  Stremio meta object
     */
    public function addMovie(MediaServerIntegration $integration, string $itemId, array $meta, ?int $profileId = null): ?Channel
    {
        $exists = Channel::where('aio_integration_id', $integration->id)
            ->where('aio_item_id', $itemId)
            ->where('aio_type', 'movie')
            ->exists();

        if ($exists) {
            return null;
        }

        $playlist = $integration->getOrCreatePlaylist();
        $rating = is_numeric($meta['imdbRating'] ?? null) ? (float) $meta['imdbRating'] : null;
        $movieDbIds = AIOStreamsService::extractMovieDbIds($itemId, $meta);

        $channel = Channel::create([
            'user_id' => $integration->user_id,
            'playlist_id' => $playlist->id,
            'name' => $meta['name'] ?? 'Unknown',
            'title' => $meta['name'] ?? null,
            'logo' => $meta['poster'] ?? null,
            'is_custom' => true,
            'is_vod' => true,
            'enabled' => true,
            // AIOStreams VODs manage their own internal failover chain and can't be
            // probed (see VodResource's disabled probe_enabled/can_merge toggles for
            // aio_integration_id rows), so set both explicitly rather than relying on
            // column defaults, which default to true.
            'can_merge' => false,
            'probe_enabled' => false,
            'year' => is_numeric($meta['releaseInfo'] ?? null) ? (int) $meta['releaseInfo'] : null,
            'rating' => $rating,
            'rating_5based' => $rating ? round($rating / 2, 1) : null,
            'info' => $this->buildMetaInfo($meta),
            'imdb_id' => $movieDbIds['imdb'],
            'tmdb_id' => $movieDbIds['tmdb'],
            'aio_integration_id' => $integration->id,
            'aio_item_id' => $itemId,
            'aio_type' => 'movie',
            'aio_match_profile_id' => $this->ownedProfileId($integration, $profileId),
            'aio_resolution_status' => 'pending',
        ]);

        ResolveAioStreamsChannel::dispatch($channel->id);
        $this->queueTmdbEnrichment($integration, channel: $channel);

        return $channel;
    }

    /**
     * Find the library Series for a show, creating it when missing. The match
     * profile only applies to a newly created series; an existing one keeps its
     * own (change it from the series' rescan action instead).
     *
     * @param  array<string, mixed>  $meta  Stremio meta object
     */
    public function findOrCreateSeries(MediaServerIntegration $integration, string $itemId, array $meta, ?int $profileId = null): Series
    {
        $playlist = $integration->getOrCreatePlaylist();
        $castNames = $this->castNames($meta);
        $movieDbIds = AIOStreamsService::extractMovieDbIds($itemId, $meta);

        return Series::firstOrCreate(
            [
                'aio_integration_id' => $integration->id,
                'aio_item_id' => $itemId,
                'aio_type' => 'series',
            ],
            array_filter([
                'name' => $meta['name'] ?? 'Unknown',
                'plot' => $meta['description'] ?? null,
                'cover' => $meta['poster'] ?? null,
                'genre' => ! empty($meta['genres']) ? implode(', ', (array) $meta['genres']) : null,
                'director' => ! empty($meta['director']) ? implode(', ', (array) $meta['director']) : null,
                'cast' => $castNames ?: null,
                'rating' => $meta['imdbRating'] ?? null,
                'rating_5based' => is_numeric($meta['imdbRating'] ?? null) ? round(((float) $meta['imdbRating']) / 2, 1) : null,
                'release_date' => $meta['releaseInfo'] ?? null,
                'backdrop_path' => ! empty($meta['background']) ? [$meta['background']] : null,
                'imdb_id' => $movieDbIds['imdb'],
                'tmdb_id' => $movieDbIds['tmdb'],
                'aio_match_profile_id' => $this->ownedProfileId($integration, $profileId),
                'user_id' => $integration->user_id,
                'playlist_id' => $playlist->id,
                'import_batch_no' => Str::orderedUuid()->toString(),
                'is_custom' => true,
                'enabled' => true,
                'aio_resolution_status' => 'resolved',
            ], fn ($value) => $value !== null && $value !== '')
        );
    }

    /**
     * Create the custom Episode (+ Season, on demand) row for one episode of the
     * series and queue its stream resolution once it has aired. Returns null
     * without doing anything when the episode is already in the library.
     *
     * @param  array<string, mixed>|null  $episodeVideo  Stremio video object for the episode, when known
     */
    public function createEpisode(Series $series, int $season, int $episode, ?array $episodeVideo = null): ?Episode
    {
        $aioItemId = "{$series->aio_item_id}:{$season}:{$episode}";

        if ($series->episodes()->where('aio_item_id', $aioItemId)->exists()) {
            return null;
        }

        // Stremio meta doesn't expose per-season posters, so fall back to the
        // series' own poster rather than leaving the season card blank.
        $seasonCover = $series->cover_big ?? $series->cover ?? null;

        $seasonRow = $series->seasons()->firstOrCreate(
            ['season_number' => $season],
            array_filter([
                'name' => 'Season '.str_pad((string) $season, 2, '0', STR_PAD_LEFT),
                'user_id' => $series->user_id,
                'playlist_id' => $series->playlist_id,
                'import_batch_no' => Str::orderedUuid()->toString(),
                'is_custom' => true,
                'cover' => $seasonCover,
                'cover_big' => $seasonCover,
            ], fn ($value) => $value !== null)
        );

        $released = $episodeVideo['released'] ?? $episodeVideo['firstAired'] ?? null;
        $airDate = self::parseAirDate($episodeVideo ?? []);
        $hasAired = ! $airDate || $airDate->isPast();

        $plot = $episodeVideo['overview'] ?? $episodeVideo['description'] ?? null;
        $thumbnail = $episodeVideo['thumbnail'] ?? $episodeVideo['poster'] ?? null;

        $episodeRow = $series->episodes()->create([
            'title' => $episodeVideo['title'] ?? $episodeVideo['name'] ?? "Episode {$episode}",
            'user_id' => $series->user_id,
            'playlist_id' => $series->playlist_id,
            'season_id' => $seasonRow->id,
            'episode_num' => $episode,
            'season' => $season,
            'import_batch_no' => Str::orderedUuid()->toString(),
            'is_custom' => true,
            'aio_item_id' => $aioItemId,
            'aio_air_date' => $airDate,
            // Unaired episodes have no stream (and usually no plot/poster) yet, so
            // don't surface them as if they were playable until they've actually aired.
            'enabled' => $hasAired,
            // AIOStreams episodes manage their own internal failover chain and can't be
            // probed (see EpisodesRelationManager's disabled probe_enabled toggle), so set
            // explicitly rather than relying on the column default, which defaults to true.
            'probe_enabled' => false,
            'plot' => $plot,
            'cover' => $thumbnail,
            'info' => array_filter([
                'plot' => $plot,
                'movie_image' => $thumbnail,
                'cover_big' => $thumbnail,
                'release_date' => $released,
            ], fn ($value) => $value !== null && $value !== ''),
            // Streams can't exist for an episode that hasn't aired yet - resolving
            // now would just burn the empty-result retry budget and permanently
            // mark it 'failed'. Leave it 'scheduled' for the upcoming-episodes
            // command (routes/console.php) to pick up once aio_air_date passes.
            'aio_resolution_status' => $hasAired ? 'pending' : 'scheduled',
        ]);

        if ($hasAired) {
            ResolveAioStreamsEpisode::dispatch($episodeRow->id);
        }

        return $episodeRow;
    }

    /**
     * Add every episode of the show's latest season that has started airing
     * (specials, season 0, are skipped). Episodes of that season not yet aired
     * are added as 'scheduled' and resolve once they air.
     *
     * @param  array<int, array<string, mixed>>  $videos  Stremio meta `videos`
     * @return array<int, Episode> The newly created episodes
     */
    public function addLatestSeason(Series $series, array $videos): array
    {
        $latestSeason = collect($videos)
            ->filter(fn (array $video): bool => (int) ($video['season'] ?? 0) > 0 && self::hasEpisodeAired($video))
            ->max(fn (array $video): int => (int) $video['season']);

        if (! $latestSeason) {
            return [];
        }

        $created = [];

        foreach ($videos as $video) {
            if ((int) ($video['season'] ?? 0) !== $latestSeason || (int) ($video['episode'] ?? 0) <= 0) {
                continue;
            }

            $episodeRow = $this->createEpisode($series, $latestSeason, (int) $video['episode'], $video);

            if ($episodeRow) {
                $created[] = $episodeRow;
            }
        }

        return $created;
    }

    /**
     * An episode with no known release date is treated as already aired - we
     * can't tell otherwise, and AIOStreams addons won't have streams for it
     * either way once it's actually requested.
     *
     * @param  array<string, mixed>  $episodeVideo
     */
    public static function hasEpisodeAired(array $episodeVideo): bool
    {
        $airDate = self::parseAirDate($episodeVideo);

        return ! $airDate || $airDate->isPast();
    }

    /**
     * The episode's release date, or null when missing or unparseable.
     *
     * @param  array<string, mixed>  $episodeVideo
     */
    private static function parseAirDate(array $episodeVideo): ?Carbon
    {
        $released = $episodeVideo['released'] ?? $episodeVideo['firstAired'] ?? null;

        if (! $released) {
            return null;
        }

        try {
            return Carbon::parse($released);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Queue the same TMDB enrichment the rest of the library gets (rich cast with
     * photos, clearlogo, trailers, certification, season posters, episode stills,
     * ...) for an item just added from AIOStreams. The browse view shows most of
     * that because AIOStreamsService::enrichMetaWithTmdb() merges it into the meta
     * on the fly, but the stored library row only keeps the plain Stremio fields,
     * so Xtream clients (M3U TV) saw it missing once the item was in the library.
     *
     * backfillEnrichment: the Stremio meta already gave the row a plot + poster,
     * which the bulk job would otherwise treat as "already enriched" and skip. On
     * a series that's already enriched, a repeat call only looks up the episodes
     * added since, so calling this on every episode add stays cheap.
     */
    public function queueTmdbEnrichment(MediaServerIntegration $integration, ?Channel $channel = null, ?Series $series = null): void
    {
        if (! ($integration->aiostreams_tmdb_enrich ?? true) || ! app(TmdbService::class)->isConfigured()) {
            return;
        }

        dispatch(new FetchTmdbIds(
            vodChannelIds: $channel ? [$channel->id] : null,
            seriesIds: $series ? [$series->id] : null,
            user: $integration->user,
            sendCompletionNotification: false,
            backfillEnrichment: true,
        ));
    }

    /**
     * Maps a Stremio meta object onto the Xtream-style `info` json shape
     * VodResource's form/infolist reads (genre, cast, director, rating, runtime,
     * images), not just the plot.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function buildMetaInfo(array $meta): array
    {
        $castNames = $this->castNames($meta);

        $runtimeMinutes = null;
        if (! empty($meta['runtime']) && preg_match('/(\d+)/', (string) $meta['runtime'], $matches)) {
            $runtimeMinutes = (int) $matches[1];
        }

        return array_filter([
            'name' => $meta['name'] ?? null,
            'plot' => $meta['description'] ?? null,
            'description' => $meta['description'] ?? null,
            'genre' => ! empty($meta['genres']) ? implode(', ', (array) $meta['genres']) : null,
            'director' => ! empty($meta['director']) ? implode(', ', (array) $meta['director']) : null,
            'actors' => $castNames ?: null,
            'cast' => $castNames ?: null,
            'episode_run_time' => $runtimeMinutes,
            'release_date' => $meta['releaseInfo'] ?? null,
            'movie_image' => $meta['poster'] ?? null,
            'cover_big' => $meta['poster'] ?? null,
            'backdrop_path' => ! empty($meta['background']) ? [$meta['background']] : null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * Stremio `cast` entries are either plain names or `{name, ...}` objects.
     *
     * @param  array<string, mixed>  $meta
     */
    private function castNames(array $meta): string
    {
        return collect((array) ($meta['cast'] ?? []))
            ->map(fn ($member) => is_array($member) ? ($member['name'] ?? null) : $member)
            ->filter()
            ->implode(', ');
    }

    /**
     * The profile id when it belongs to the integration's owner, else null
     * (integration default). The id arrives from a form or a queued job's
     * payload, so it's checked here rather than trusted.
     */
    public function ownedProfileId(MediaServerIntegration $integration, ?int $profileId): ?int
    {
        if (! $profileId) {
            return null;
        }

        return AioStreamsMatchProfile::query()
            ->where('id', $profileId)
            ->where('user_id', $integration->user_id)
            ->exists() ? $profileId : null;
    }
}
