<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\MediaServerIntegration;
use App\Models\MediaSourceMatch;
use App\Models\Playlist;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Builds the media_source_matches table for one provider playlist: provider
 * VOD movies / series episodes keyed to same-user Emby/Jellyfin/Plex/local
 * media items by external IDs (TMDB, then IMDB/TVDB). Rows are fully
 * rebuilt (stale rows are deleted and the current set is rewritten) because
 * both sides of the index (provider ids after TMDB enrichment, media library
 * contents after a sync) change independently.
 */
class MediaSourceMatchService
{
    /**
     * Integration types whose items can replace a provider stream.
     */
    public const SUPPORTED_INTEGRATION_TYPES = ['emby', 'jellyfin', 'plex', 'local'];

    /**
     * Match-row insert chunk size.
     */
    private const INSERT_CHUNK_SIZE = 500;

    /**
     * Rebuild (or clear) the match rows for a playlist.
     *
     * `changed` is true when the stored provider -> media mapping differs from
     * what was there before, so callers can decide whether derived output
     * (STRM files) needs regenerating.
     *
     * @return array{movies: int, episodes: int, changed: bool}
     */
    public function rebuildForPlaylist(Playlist $playlist): array
    {
        $integrationIdByPlaylistId = $playlist->prefer_media_server_sources
            ? $this->eligibleIntegrationPlaylists($playlist)
            : [];

        if ($integrationIdByPlaylistId === []) {
            $deleted = $this->deleteMatches($playlist->id);

            return ['movies' => 0, 'episodes' => 0, 'changed' => $deleted > 0];
        }

        $movieIndex = $this->buildMovieIndex($integrationIdByPlaylistId);
        $episodeIndex = $this->buildEpisodeIndex($integrationIdByPlaylistId);

        $movieMatches = $this->matchProviderMovies($playlist, $movieIndex);
        $episodeMatches = $this->matchProviderEpisodes($playlist, $episodeIndex);

        $changed = $this->writeMatches($playlist->id, [...$movieMatches, ...$episodeMatches]);

        Log::info('MediaSourceMatchService: rebuilt matches', [
            'playlist_id' => $playlist->id,
            'movies' => count($movieMatches),
            'episodes' => count($episodeMatches),
            'changed' => $changed,
        ]);

        return ['movies' => count($movieMatches), 'episodes' => count($episodeMatches), 'changed' => $changed];
    }

    /**
     * Eligible integrations of the playlist owner: enabled, supported type,
     * with a synced playlist, excluding the provider playlist itself.
     * Returns [playlist_id => integration_id].
     *
     * @return array<int, int>
     */
    private function eligibleIntegrationPlaylists(Playlist $playlist): array
    {
        return MediaServerIntegration::query()
            ->where('user_id', $playlist->user_id)
            ->where('enabled', true)
            ->whereIn('type', self::SUPPORTED_INTEGRATION_TYPES)
            ->whereNotNull('playlist_id')
            ->where('playlist_id', '!=', $playlist->id)
            ->orderBy('id')
            ->pluck('id', 'playlist_id')
            ->map(fn ($integrationId) => (int) $integrationId)
            ->all();
    }

    /**
     * Index the eligible playlists' movie channels by their external IDs.
     * First channel seen wins (ordered by id, so deterministic).
     *
     * @param  array<int, int>  $integrationIdByPlaylistId
     * @return array<string, array{int, int}>
     */
    private function buildMovieIndex(array $integrationIdByPlaylistId): array
    {
        $index = [];

        $channels = $this->movieChannelsQuery(array_keys($integrationIdByPlaylistId))
            ->where('enabled', true);

        // lazyById chunks by primary key. Postgres cursor() still buffers the
        // whole result (including the info/movie_data JSON) client-side.
        foreach ($channels->lazyById(1000) as $channel) {
            foreach ($this->movieKeys($channel) as $key) {
                $index[$key] ??= [$channel->id, $integrationIdByPlaylistId[$channel->playlist_id]];
            }
        }

        return $index;
    }

    /**
     * Index the eligible playlists' episodes by series external ID +
     * season/episode number.
     *
     * @param  array<int, int>  $integrationIdByPlaylistId
     * @return array<string, array{int, int}>
     */
    private function buildEpisodeIndex(array $integrationIdByPlaylistId): array
    {
        $index = [];

        $rows = $this->episodeRowsQuery(array_keys($integrationIdByPlaylistId));

        foreach ($rows->lazyById(1000, 'episodes.id', 'id') as $row) {
            foreach ($this->episodeKeys($row) as $key) {
                $index[$key] ??= [$row->id, $integrationIdByPlaylistId[$row->playlist_id]];
            }
        }

        return $index;
    }

    /**
     * @param  array<string, array{int, int}>  $index
     * @return array<int, array<string, int|string|null>>
     */
    private function matchProviderMovies(Playlist $playlist, array $index): array
    {
        $matches = [];

        foreach ($this->movieChannelsQuery([$playlist->id])->lazyById(1000) as $channel) {
            foreach ($this->movieKeys($channel) as $key) {
                if (isset($index[$key])) {
                    [$mediaChannelId, $integrationId] = $index[$key];
                    $matches[] = [
                        'playlist_id' => $playlist->id,
                        'media_server_integration_id' => $integrationId,
                        'channel_id' => $channel->id,
                        'episode_id' => null,
                        'media_channel_id' => $mediaChannelId,
                        'media_episode_id' => null,
                        'match_key' => $key,
                    ];

                    break;
                }
            }
        }

        return $matches;
    }

    /**
     * @param  array<string, array{int, int}>  $index
     * @return array<int, array<string, int|string|null>>
     */
    private function matchProviderEpisodes(Playlist $playlist, array $index): array
    {
        $matches = [];

        foreach ($this->episodeRowsQuery([$playlist->id])->lazyById(1000, 'episodes.id', 'id') as $row) {
            foreach ($this->episodeKeys($row) as $key) {
                if (isset($index[$key])) {
                    [$mediaEpisodeId, $integrationId] = $index[$key];
                    $matches[] = [
                        'playlist_id' => $playlist->id,
                        'media_server_integration_id' => $integrationId,
                        'channel_id' => null,
                        'episode_id' => $row->id,
                        'media_channel_id' => null,
                        'media_episode_id' => $mediaEpisodeId,
                        'match_key' => $key,
                    ];

                    break;
                }
            }
        }

        return $matches;
    }

    /**
     * VOD movie channels of the given playlists, selecting only what
     * movieKeys() reads.
     *
     * @param  array<int, int>  $playlistIds
     * @return Builder<Channel>
     */
    private function movieChannelsQuery(array $playlistIds): Builder
    {
        return Channel::query()
            ->whereIn('playlist_id', $playlistIds)
            ->where('is_vod', true)
            ->select(['id', 'playlist_id', 'tmdb_id', 'imdb_id', 'info', 'movie_data']);
    }

    /**
     * Enabled, numbered episodes of the given playlists joined to their
     * series' external IDs, selecting only what episodeKeys() reads.
     *
     * @param  array<int, int>  $playlistIds
     */
    private function episodeRowsQuery(array $playlistIds): QueryBuilder
    {
        return DB::table('episodes')
            ->join('series', 'episodes.series_id', '=', 'series.id')
            ->whereIn('episodes.playlist_id', $playlistIds)
            ->where('episodes.enabled', true)
            ->whereNotNull('episodes.season')
            ->whereNotNull('episodes.episode_num')
            ->select([
                'episodes.id',
                'episodes.playlist_id',
                'episodes.season',
                'episodes.episode_num',
                'series.tmdb_id',
                'series.tvdb_id',
                'series.imdb_id',
            ]);
    }

    /**
     * Match keys for a movie channel, TMDB first then IMDB.
     *
     * @param  Channel  $channel  Hydrated with only id/tmdb_id/imdb_id/info/movie_data.
     * @return list<string>
     */
    private function movieKeys(Channel $channel): array
    {
        $keys = [];

        $tmdbId = $channel->getTmdbId();
        if ($tmdbId !== null) {
            $keys[] = 'tmdb:'.$tmdbId;
        }

        $imdbId = $channel->getImdbId();
        if ($imdbId !== null && $imdbId !== '') {
            $keys[] = 'imdb:'.strtolower($imdbId);
        }

        return $keys;
    }

    /**
     * Match keys for an episode row joined to its series, each built only
     * when its external ID is present.
     *
     * @param  object  $row  episodes.id/season/episode_num + series.tmdb_id/tvdb_id/imdb_id.
     * @return list<string>
     */
    private function episodeKeys(object $row): array
    {
        $keys = [];

        $season = 's'.$row->season;
        $episode = 'e'.$row->episode_num;

        if (! empty($row->tmdb_id)) {
            $keys[] = 'series-tmdb:'.$row->tmdb_id.':'.$season.':'.$episode;
        }

        if (! empty($row->tvdb_id)) {
            $keys[] = 'series-tvdb:'.$row->tvdb_id.':'.$season.':'.$episode;
        }

        if (! empty($row->imdb_id)) {
            $keys[] = 'series-imdb:'.strtolower($row->imdb_id).':'.$season.':'.$episode;
        }

        return $keys;
    }

    /**
     * Replace the playlist's match rows with the current set, in chunks.
     * Returns whether the provider -> media mapping changed.
     *
     * @param  list<array<string, int|string|null>>  $rows
     */
    private function writeMatches(int $playlistId, array $rows): bool
    {
        $before = $this->mappingFor(
            MediaSourceMatch::query()
                ->where('playlist_id', $playlistId)
                ->toBase()
                ->select(['channel_id', 'episode_id', 'media_channel_id', 'media_episode_id'])
                ->cursor()
        );

        if ($before == $this->mappingFor($rows)) {
            return false;
        }

        DB::transaction(function () use ($playlistId, $rows): void {
            $this->deleteMatches($playlistId);

            $now = now();

            foreach (array_chunk($rows, self::INSERT_CHUNK_SIZE) as $chunk) {
                MediaSourceMatch::insert(array_map(fn (array $row): array => [
                    ...$row,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk));
            }
        });

        return true;
    }

    /**
     * Normalize match rows to a [provider item => media item] map for
     * order-insensitive comparison.
     *
     * @param  iterable<array<string, mixed>|object>  $rows
     * @return array<string, int>
     */
    private function mappingFor(iterable $rows): array
    {
        $mapping = [];

        foreach ($rows as $row) {
            $row = (array) $row;

            if ($row['channel_id'] !== null) {
                $mapping['c'.$row['channel_id']] = (int) $row['media_channel_id'];
            } else {
                $mapping['e'.$row['episode_id']] = (int) $row['media_episode_id'];
            }
        }

        return $mapping;
    }

    private function deleteMatches(int $playlistId): int
    {
        return MediaSourceMatch::where('playlist_id', $playlistId)->delete();
    }
}
