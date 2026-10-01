<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\MediaServerIntegration;
use App\Models\MediaSourceMatch;
use App\Models\Playlist;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Builds the media_source_matches table for one provider playlist: provider
 * VOD movies / series episodes keyed to same-user Emby/Jellyfin/local media
 * items by external IDs (TMDB, then IMDB/TVDB). Rows are fully rebuilt —
 * stale rows are deleted and the current set is rewritten — because both
 * sides of the index (provider ids after TMDB enrichment, media library
 * contents after a sync) change independently.
 */
class MediaSourceMatchService
{
    /**
     * Match-row insert chunk size.
     */
    private const INSERT_CHUNK_SIZE = 500;

    /**
     * Rebuild (or clear) the match rows for a playlist.
     *
     * @return array{movies: int, episodes: int}
     */
    public function rebuildForPlaylist(Playlist $playlist): array
    {
        if (! $playlist->prefer_media_server_sources) {
            $this->deleteMatches($playlist->id);

            return ['movies' => 0, 'episodes' => 0];
        }

        $integrationIdByPlaylistId = $this->eligibleIntegrationPlaylists($playlist);

        if ($integrationIdByPlaylistId === []) {
            $this->deleteMatches($playlist->id);

            return ['movies' => 0, 'episodes' => 0];
        }

        $movieIndex = $this->buildMovieIndex(array_keys($integrationIdByPlaylistId), $integrationIdByPlaylistId);
        $episodeIndex = $this->buildEpisodeIndex(array_keys($integrationIdByPlaylistId), $integrationIdByPlaylistId);

        $movieMatches = $this->matchProviderMovies($playlist, $movieIndex);
        $episodeMatches = $this->matchProviderEpisodes($playlist, $episodeIndex);

        $this->writeMatches($playlist->id, [...$movieMatches, ...$episodeMatches]);

        Log::info('MediaSourceMatchService: rebuilt matches', [
            'playlist_id' => $playlist->id,
            'movies' => count($movieMatches),
            'episodes' => count($episodeMatches),
        ]);

        return ['movies' => count($movieMatches), 'episodes' => count($episodeMatches)];
    }

    /**
     * Eligible integrations of the playlist owner: enabled, Emby/Jellyfin/
     * local only, with a synced playlist, excluding the provider playlist
     * itself. Returns [playlist_id => integration_id].
     *
     * @return array<int, int>
     */
    private function eligibleIntegrationPlaylists(Playlist $playlist): array
    {
        return MediaServerIntegration::query()
            ->where('user_id', $playlist->user_id)
            ->where('enabled', true)
            ->whereIn('type', ['emby', 'jellyfin', 'local'])
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
     * @param  array<int, int>  $playlistIds
     * @param  array<int, int>  $integrationIdByPlaylistId
     * @return array<string, array{int, int}>
     */
    private function buildMovieIndex(array $playlistIds, array $integrationIdByPlaylistId): array
    {
        $index = [];

        $channels = Channel::query()
            ->whereIn('playlist_id', $playlistIds)
            ->where('is_vod', true)
            ->where('enabled', true)
            ->select(['id', 'playlist_id', 'tmdb_id', 'imdb_id', 'info', 'movie_data']);

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
     * @param  array<int, int>  $playlistIds
     * @param  array<int, int>  $integrationIdByPlaylistId
     * @return array<string, array{int, int}>
     */
    private function buildEpisodeIndex(array $playlistIds, array $integrationIdByPlaylistId): array
    {
        $index = [];

        $rows = DB::table('episodes')
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

        foreach ($rows->lazyById(1000, 'episodes.id', 'id') as $row) {
            foreach ($this->episodeKeys($row) as $key) {
                $index[$key] ??= [$row->id, $integrationIdByPlaylistId[$row->playlist_id]];
            }
        }

        return $index;
    }

    /**
     * @return array<string, array<string, int>>
     */
    private function matchProviderMovies(Playlist $playlist, array $index): array
    {
        $matches = [];

        $channels = Channel::query()
            ->where('playlist_id', $playlist->id)
            ->where('is_vod', true)
            ->select(['id', 'playlist_id', 'tmdb_id', 'imdb_id', 'info', 'movie_data']);

        foreach ($channels->lazyById(1000) as $channel) {
            foreach ($this->movieKeys($channel) as $key) {
                if (isset($index[$key])) {
                    [$mediaChannelId, $integrationId] = $index[$key];
                    $matches[$channel->id] = [
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
     * @return array<string, array<string, int|null>>
     */
    private function matchProviderEpisodes(Playlist $playlist, array $index): array
    {
        $matches = [];

        $rows = DB::table('episodes')
            ->join('series', 'episodes.series_id', '=', 'series.id')
            ->where('episodes.playlist_id', $playlist->id)
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

        foreach ($rows->lazyById(1000, 'episodes.id', 'id') as $row) {
            foreach ($this->episodeKeys($row) as $key) {
                if (isset($index[$key])) {
                    [$mediaEpisodeId, $integrationId] = $index[$key];
                    $matches[$row->id] = [
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
     *
     * @param  list<array<string, int|null>>  $rows
     */
    private function writeMatches(int $playlistId, array $rows): void
    {
        DB::transaction(function () use ($playlistId, $rows): void {
            $this->deleteMatches($playlistId);

            if ($rows === []) {
                return;
            }

            $now = now();

            foreach (array_chunk($rows, self::INSERT_CHUNK_SIZE) as $chunk) {
                MediaSourceMatch::insert(array_map(fn (array $row): array => [
                    ...$row,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk));
            }
        });
    }

    private function deleteMatches(int $playlistId): void
    {
        MediaSourceMatch::where('playlist_id', $playlistId)->delete();
    }
}
