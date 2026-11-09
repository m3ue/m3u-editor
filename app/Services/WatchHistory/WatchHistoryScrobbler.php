<?php

namespace App\Services\WatchHistory;

use App\Jobs\PushWatchHistoryProgress;
use App\Models\ViewerWatchProgress;
use App\Models\WatchHistoryIntegration;
use App\Models\WatchHistorySyncState;

/**
 * Sends Playlist Auth watch progress to the Trakt / SIMKL accounts attached
 * to the auth. Clients save progress every few seconds, but SIMKL forbids
 * timer-based events, so each save only (re)arms a delayed job and the push
 * happens once the row has been quiet for QUIET_SECONDS: one "pause" with
 * the position when someone stops part-way, one "stop" when a title is
 * finished. A client that scrobbles to a provider itself (M3U TV signed in
 * to Trakt on the device) names it in `client_scrobblers` and is skipped
 * for that provider.
 */
class WatchHistoryScrobbler
{
    /**
     * How long a progress row must go without a save before it's pushed.
     */
    public const QUIET_SECONDS = 60;

    /**
     * Progress changes smaller than this (in percent) aren't re-sent.
     */
    private const MIN_PROGRESS_CHANGE = 1.0;

    /**
     * Queue a push of $progress to every pushing account attached to the
     * viewer's Playlist Auth, except the providers the client handles.
     *
     * @param  list<string>  $clientProviders
     */
    public function queue(ViewerWatchProgress $progress, ?int $playlistAuthId, array $clientProviders = []): void
    {
        if (! $playlistAuthId || ! in_array($progress->content_type, ['vod', 'episode'], true)) {
            return;
        }

        $integrations = WatchHistoryIntegration::query()
            ->pushing()
            ->whereHas('playlistAuths', fn ($query) => $query->whereKey($playlistAuthId))
            ->get(['id', 'provider']);

        foreach ($integrations as $integration) {
            if (in_array($integration->provider->value, $clientProviders, true)) {
                continue;
            }

            PushWatchHistoryProgress::dispatch($integration->id, $progress->id)
                ->delay(now()->addSeconds(self::QUIET_SECONDS));
        }
    }

    /**
     * Read the `client_scrobblers` request value: a comma separated string
     * (how M3U TV sends form fields) or an array of provider names.
     *
     * @return list<string>
     */
    public static function parseClientProviders(mixed $value): array
    {
        $values = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_filter(array_map(
            fn ($provider): string => strtolower(trim((string) $provider)),
            $values,
        )));
    }

    /**
     * Send what changed since the last push. Returns the action sent
     * ('stop', 'pause') or why nothing was ('unidentified', 'unchanged').
     */
    public function push(WatchHistoryIntegration $integration, ViewerWatchProgress $progress): string
    {
        $item = $this->itemFor($progress);
        if (! $item) {
            return 'unidentified';
        }

        $state = WatchHistorySyncState::firstOrNew([
            'watch_history_integration_id' => $integration->id,
            'viewer_watch_progress_id' => $progress->id,
        ]);

        $client = WatchHistoryClient::make($integration);

        if ($progress->completed) {
            if ($state->exists && $state->completed) {
                return 'unchanged';
            }

            $percent = max($item->progress ?? 100.0, 90.0);
            $client->scrobble('stop', $item->withProgress($percent));
            $action = 'stop';
        } else {
            $percent = $item->progress;
            if ($percent === null || $percent < 1) {
                return 'unchanged';
            }

            if ($state->exists && ! $state->completed && abs($state->progress - $percent) < self::MIN_PROGRESS_CHANGE) {
                return 'unchanged';
            }

            $client->scrobble('pause', $item);
            $action = 'pause';
        }

        $state->fill([
            'completed' => (bool) $progress->completed,
            'progress' => round($percent, 2),
            'pushed_at' => now(),
        ])->save();

        $integration->forceFill(['last_pushed_at' => now(), 'last_error' => null])->save();

        return $action;
    }

    /**
     * How Trakt / SIMKL identify the movie or episode a progress row is for,
     * or null when the library has no external id for it.
     */
    public function itemFor(ViewerWatchProgress $progress): ?WatchHistoryItem
    {
        $percent = $progress->duration_seconds > 0
            ? min(100.0, $progress->position_seconds / $progress->duration_seconds * 100)
            : ($progress->completed ? 100.0 : null);

        if ($progress->content_type === 'vod') {
            $channel = $progress->channel;
            if (! $channel) {
                return null;
            }

            $ids = WatchHistoryItem::normalizeIds([
                'tmdb' => $progress->tmdb_id ?: $channel->getTmdbId(),
                'imdb' => $channel->getImdbId(),
            ]);

            return $ids === [] ? null : new WatchHistoryItem(
                type: 'movie',
                ids: $ids,
                title: $channel->title ?: $channel->name,
                year: $channel->year ?: null,
                progress: $percent,
                watchedAt: $progress->last_watched_at,
            );
        }

        if ($progress->content_type === 'episode') {
            $episode = $progress->episode;
            $series = $episode?->series;
            if (! $episode || ! $series) {
                return null;
            }

            $ids = WatchHistoryItem::normalizeIds($series->getMovieDbIds());
            $season = $progress->season_number ?? $episode->season;
            $number = $progress->episode_number ?? $episode->episode_num;

            return ($ids === [] || $season === null || ! $number) ? null : new WatchHistoryItem(
                type: 'episode',
                ids: $ids,
                title: $series->name,
                season: (int) $season,
                episode: (int) $number,
                progress: $percent,
                watchedAt: $progress->last_watched_at,
            );
        }

        return null;
    }
}
