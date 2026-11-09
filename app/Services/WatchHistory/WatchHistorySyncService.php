<?php

namespace App\Services\WatchHistory;

use App\Enums\WatchHistoryProvider;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\PlaylistViewer;
use App\Models\Series;
use App\Models\ViewerWatchProgress;
use App\Models\WatchHistoryIntegration;
use App\Models\WatchHistorySyncState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * On-demand bulk sync between a Playlist Auth's watch progress and a Trakt /
 * SIMKL account. Only titles the library has an external id for are
 * matched; everything else is counted as unmatched and left alone.
 *
 * Modes:
 * - import: copy the account's watched titles and resume points in, keeping
 *   local progress that's newer.
 * - replace: clear the auth's movie and episode progress first, then import.
 * - export: mark local watched titles watched on the account (titles it
 *   already has are skipped, so plays aren't duplicated).
 * - sync: import, then export.
 */
class WatchHistorySyncService
{
    public const MODES = ['import', 'replace', 'export', 'sync'];

    private const HISTORY_BATCH_SIZE = 250;

    private const LOOKUP_CHUNK_SIZE = 1000;

    public function __construct(private WatchHistoryScrobbler $scrobbler) {}

    /**
     * @return array{imported: int, updated: int, unchanged: int, unmatched: int, exported: int, cleared: int}
     */
    public function run(WatchHistoryIntegration $integration, PlaylistAuth $playlistAuth, string $mode): array
    {
        $client = WatchHistoryClient::make($integration);
        $viewers = $this->viewersFor($playlistAuth);
        $stats = ['imported' => 0, 'updated' => 0, 'unchanged' => 0, 'unmatched' => 0, 'exported' => 0, 'cleared' => 0];
        $remoteWatched = null;

        if (in_array($mode, ['import', 'replace', 'sync'], true)) {
            $remoteWatched = $client->fetchWatched();
            $remotePlayback = $client->fetchPlayback();

            if ($mode === 'replace') {
                $stats['cleared'] = ViewerWatchProgress::query()
                    ->whereIn('playlist_viewer_id', $viewers->modelKeys())
                    ->whereIn('content_type', ['vod', 'episode'])
                    ->delete();
            }

            foreach ($viewers as $viewer) {
                $this->importInto($viewer, $integration, $remoteWatched, $remotePlayback, $stats);
            }
        }

        if (in_array($mode, ['export', 'sync'], true)) {
            $remoteWatched ??= $client->fetchWatched();
            $stats['exported'] = $this->export($client, $integration, $viewers, $remoteWatched);
        }

        $integration->forceFill(['last_synced_at' => now(), 'last_error' => null])->save();

        return $stats;
    }

    /**
     * Every viewer signed in with this auth. Before any device has used it,
     * create the viewer for its assigned playlist (as the Xtream API does on
     * first use) so there's somewhere to import into.
     *
     * @return Collection<int, PlaylistViewer>
     */
    private function viewersFor(PlaylistAuth $playlistAuth): Collection
    {
        $viewers = PlaylistViewer::query()
            ->where('playlist_auth_id', $playlistAuth->id)
            ->with('viewerable')
            ->get();

        $playlist = $playlistAuth->getAssignedModel();
        if ($viewers->isEmpty() && $playlist) {
            $viewers->push(PlaylistViewer::create([
                'ulid' => (string) Str::ulid(),
                'name' => $playlistAuth->name,
                'is_admin' => false,
                'playlist_auth_id' => $playlistAuth->id,
                'viewerable_type' => $playlist->getMorphClass(),
                'viewerable_id' => $playlist->id,
            ])->setRelation('viewerable', $playlist));
        }

        return $viewers->filter(fn (PlaylistViewer $viewer): bool => $viewer->viewerable !== null)->values();
    }

    /**
     * @param  list<WatchHistoryItem>  $watched
     * @param  list<WatchHistoryItem>  $playback
     * @param  array<string, int>  $stats
     */
    private function importInto(PlaylistViewer $viewer, WatchHistoryIntegration $integration, array $watched, array $playback, array &$stats): void
    {
        $library = $this->libraryLookup($viewer, [...$watched, ...$playback]);

        foreach ($watched as $item) {
            $this->importItem($viewer, $integration, $item, $library, true, $stats);
        }

        foreach ($playback as $item) {
            $this->importItem($viewer, $integration, $item, $library, false, $stats);
        }
    }

    /**
     * @param  array{movies: array<string, Channel>, episodes: array<string, Episode>}  $library
     * @param  array<string, int>  $stats
     */
    private function importItem(PlaylistViewer $viewer, WatchHistoryIntegration $integration, WatchHistoryItem $item, array $library, bool $isWatched, array &$stats): void
    {
        $content = $this->findInLibrary($item, $library);
        if (! $content) {
            $stats['unmatched']++;

            return;
        }

        $contentType = $item->isEpisode() ? 'episode' : 'vod';
        $remoteAt = $item->watchedAt ?? now();

        $existing = ViewerWatchProgress::query()
            ->where('playlist_viewer_id', $viewer->id)
            ->where('content_type', $contentType)
            ->where('stream_id', $content->id)
            ->first();

        // Local progress that's newer than the account's wins.
        if ($existing && $existing->last_watched_at && $existing->last_watched_at->gte($remoteAt)) {
            $stats['unchanged']++;

            return;
        }

        if ($isWatched && $existing?->completed) {
            $stats['unchanged']++;

            return;
        }

        $duration = $existing?->duration_seconds ?: $this->durationOf($content);
        if (! $isWatched && ! $duration) {
            // A resume point can't be placed without knowing the runtime.
            $stats['unmatched']++;

            return;
        }

        $attributes = [
            'completed' => $isWatched,
            'position_seconds' => $isWatched
                ? (int) ($duration ?? $existing?->position_seconds ?? 0)
                : (int) round(($item->progress ?? 0) / 100 * $duration),
            'duration_seconds' => $duration,
            'last_watched_at' => $remoteAt,
            'tmdb_id' => $existing?->tmdb_id ?? ($content instanceof Channel ? $content->getTmdbId() : $content->tmdb_id),
        ];

        if ($content instanceof Episode) {
            $attributes += [
                'series_id' => $content->series_id,
                'season_number' => $content->season,
                'episode_number' => $content->episode_num,
            ];
        }

        $progress = ViewerWatchProgress::updateOrCreate(
            ['playlist_viewer_id' => $viewer->id, 'content_type' => $contentType, 'stream_id' => $content->id],
            $attributes,
        );

        $stats[$existing ? 'updated' : 'imported']++;

        // Record it as already on the account so a later save doesn't send it back.
        WatchHistorySyncState::updateOrCreate(
            ['watch_history_integration_id' => $integration->id, 'viewer_watch_progress_id' => $progress->id],
            ['completed' => $isWatched, 'progress' => $isWatched ? 100 : round($item->progress ?? 0, 2), 'pushed_at' => now()],
        );
    }

    /**
     * @param  Collection<int, PlaylistViewer>  $viewers
     * @param  list<WatchHistoryItem>  $remoteWatched
     */
    private function export(WatchHistoryClient $client, WatchHistoryIntegration $integration, Collection $viewers, array $remoteWatched): int
    {
        $onAccount = [];
        foreach ($remoteWatched as $item) {
            foreach ($item->matchKeys() as $key) {
                $onAccount[$key] = true;
            }
        }

        $pending = [];
        $exported = 0;

        $rows = ViewerWatchProgress::query()
            ->whereIn('playlist_viewer_id', $viewers->modelKeys())
            ->whereIn('content_type', ['vod', 'episode'])
            ->where('completed', true)
            ->with(['channel', 'episode.series'])
            ->cursor();

        foreach ($rows as $row) {
            $item = $this->scrobbler->itemFor($row);
            if (! $item || array_intersect_key($onAccount, array_flip($item->matchKeys())) !== []) {
                continue;
            }

            foreach ($item->matchKeys() as $key) {
                $onAccount[$key] = true;
            }

            $pending[$row->id] = $item;

            if (count($pending) >= self::HISTORY_BATCH_SIZE) {
                $exported += $this->sendHistoryBatch($client, $integration, $pending);
                $pending = [];
            }
        }

        if ($pending !== []) {
            $exported += $this->sendHistoryBatch($client, $integration, $pending);
        }

        return $exported;
    }

    /**
     * @param  array<int, WatchHistoryItem>  $items  Keyed by progress row id
     */
    private function sendHistoryBatch(WatchHistoryClient $client, WatchHistoryIntegration $integration, array $items): int
    {
        try {
            $added = $client->addToHistory(array_values($items));
        } catch (WatchHistoryException $exception) {
            if ($exception->retryAfter === null || $exception->retryAfter > 120) {
                throw $exception;
            }
            sleep($exception->retryAfter);
            $added = $client->addToHistory(array_values($items));
        }

        foreach (array_keys($items) as $progressId) {
            WatchHistorySyncState::updateOrCreate(
                ['watch_history_integration_id' => $integration->id, 'viewer_watch_progress_id' => $progressId],
                ['completed' => true, 'progress' => 100, 'pushed_at' => now()],
            );
        }

        // SIMKL holds a 20 second write lock after each history write.
        if ($integration->provider === WatchHistoryProvider::Simkl) {
            sleep(SimklClient::WRITE_LOCK_SECONDS);
        }

        return $added;
    }

    /**
     * Load the viewer's library entries the remote items could refer to,
     * keyed by every external id they carry.
     *
     * @param  list<WatchHistoryItem>  $items
     * @return array{movies: array<string, Channel>, episodes: array<string, Episode>}
     */
    private function libraryLookup(PlaylistViewer $viewer, array $items): array
    {
        $playlist = $viewer->viewerable;
        $movieIds = ['tmdb' => [], 'imdb' => [], 'tvdb' => []];
        $showIds = ['tmdb' => [], 'imdb' => [], 'tvdb' => []];

        foreach ($items as $item) {
            foreach ($item->ids as $source => $id) {
                if ($item->isEpisode()) {
                    $showIds[$source][(string) $id] = true;
                } else {
                    $movieIds[$source][(string) $id] = true;
                }
            }
        }

        $scope = function (Builder $query) use ($playlist): Builder {
            $query->where('user_id', $playlist->user_id);

            return $playlist instanceof Playlist ? $query->where('playlist_id', $playlist->id) : $query;
        };

        $movies = [];
        foreach (['tmdb', 'imdb'] as $source) {
            foreach (array_chunk(array_keys($movieIds[$source]), self::LOOKUP_CHUNK_SIZE) as $chunk) {
                $channels = $scope(Channel::query())
                    ->where('is_vod', true)
                    ->whereIn("{$source}_id", $chunk)
                    ->get();
                foreach ($channels as $channel) {
                    $movies["movie:{$source}:".$channel->{"{$source}_id"}] ??= $channel;
                }
            }
        }

        $seriesKeys = [];
        foreach (['tmdb', 'imdb', 'tvdb'] as $source) {
            foreach (array_chunk(array_keys($showIds[$source]), self::LOOKUP_CHUNK_SIZE) as $chunk) {
                $series = $scope(Series::query())->whereIn("{$source}_id", $chunk)->get(['id', "{$source}_id"]);
                foreach ($series as $show) {
                    $seriesKeys[$show->id][] = "{$source}:".$show->{"{$source}_id"};
                }
            }
        }

        $episodes = [];
        foreach (array_chunk(array_keys($seriesKeys), self::LOOKUP_CHUNK_SIZE) as $chunk) {
            $rows = Episode::query()
                ->whereIn('series_id', $chunk)
                ->get(['id', 'series_id', 'season', 'episode_num', 'tmdb_id', 'info']);
            foreach ($rows as $episode) {
                foreach ($seriesKeys[$episode->series_id] as $showKey) {
                    $episodes["episode:{$showKey}:{$episode->season}:{$episode->episode_num}"] ??= $episode;
                }
            }
        }

        return ['movies' => $movies, 'episodes' => $episodes];
    }

    /**
     * @param  array{movies: array<string, Channel>, episodes: array<string, Episode>}  $library
     */
    private function findInLibrary(WatchHistoryItem $item, array $library): Channel|Episode|null
    {
        $bucket = $item->isEpisode() ? $library['episodes'] : $library['movies'];

        foreach ($item->matchKeys() as $key) {
            if (isset($bucket[$key])) {
                return $bucket[$key];
            }
        }

        return null;
    }

    private function durationOf(Channel|Episode $content): ?int
    {
        $seconds = (int) ($content->info['duration_secs'] ?? 0);

        return $seconds > 0 ? $seconds : null;
    }
}
