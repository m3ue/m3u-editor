<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Episode;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\ViewerWatchProgress;
use Illuminate\Database\Eloquent\Builder;

/**
 * Re-points a vod/episode ViewerWatchProgress row's stream_id at its current
 * Channel/Episode row when the original target no longer exists - e.g. a
 * media-server library flush deletes and recreates every row with new ids.
 * Matches on tmdb_id, which - unlike the internal id - is stable across a
 * resync, and is only available for rows recorded after tmdb_id started
 * being captured on write (see WatchProgressController::resolveTmdbId()).
 *
 * Shared between the live "get_recently_watched" API response (relink on
 * read, so the fix is visible immediately) and the offline prune command /
 * Playlist Viewers admin action (relink or, failing that, delete the row).
 *
 * Rows added by hand from the Playlist Viewer "Add Progress" action for a
 * title that isn't in the library yet are stored unlinked (stream_id NULL,
 * tmdb_id set). Those are kept, rather than pruned as unrecoverable, until
 * the content shows up and they can be relinked.
 *
 * A PlaylistViewer's `viewerable` can be any of these four playlist types
 * (see AppServiceProvider's morph map), so every method here accepts all four.
 */
class WatchProgressLinker
{
    /**
     * Attempt to relink $progress in place if its content is orphaned.
     * Mutates and persists $progress->stream_id, and updates the loaded
     * relation, on success. Returns true if the row now resolves (either it
     * already did, or relinking fixed it), false if it's still orphaned.
     */
    public function ensureLinked(ViewerWatchProgress $progress, Playlist|CustomPlaylist|MergedPlaylist|PlaylistAlias $playlist): bool
    {
        if ($progress->content_type === 'vod') {
            if ($progress->channel) {
                return true;
            }

            $replacement = $this->findChannel($progress, $playlist);
            if (! $replacement || $this->conflictsWithExistingRow($progress, $replacement->id)) {
                return false;
            }

            $progress->forceFill(['stream_id' => $replacement->id])->save();
            $progress->setRelation('channel', $replacement);

            return true;
        }

        if ($progress->content_type === 'episode') {
            if ($progress->episode) {
                return true;
            }

            $replacement = $this->findEpisode($progress, $playlist);
            if (! $replacement || $this->conflictsWithExistingRow($progress, $replacement->id)) {
                return false;
            }

            // Unlinked manual rows carry no series_id/season/episode yet - fill
            // them from the episode so get_series_progress can see the row.
            $progress->forceFill([
                'stream_id' => $replacement->id,
                'series_id' => $progress->series_id ?? $replacement->series_id,
                'season_number' => $progress->season_number ?? $replacement->season,
                'episode_number' => $progress->episode_number ?? $replacement->episode_num,
            ])->save();
            $progress->setRelation('episode', $replacement->loadMissing('series'));

            return true;
        }

        // Other content types (live, dvr_recording, aiostreams) aren't relinked -
        // live/dvr progress isn't shown in Continue Watching, and aiostreams
        // progress carries its own denormalised metadata with no id to go stale.
        return true;
    }

    /**
     * Backfill tmdb_id on a progress row whose content still resolves via its
     * current stream_id but was recorded before tmdb_id started being captured
     * (see WatchProgressController::resolveTmdbId()). Without this, a row that
     * looks healthy today is just as unrecoverable as the rows ensureLinked()
     * already can't fix, the next time its stream_id goes stale. Returns true
     * if a value was found and persisted.
     */
    public function backfillTmdbId(ViewerWatchProgress $progress): bool
    {
        if ($progress->tmdb_id) {
            return false;
        }

        $tmdbId = match ($progress->content_type) {
            'vod' => $progress->channel?->getTmdbId(),
            'episode' => $progress->episode?->tmdb_id,
            default => null,
        };

        if (! $tmdbId) {
            return false;
        }

        $progress->forceFill(['tmdb_id' => $tmdbId])->save();

        return true;
    }

    /**
     * Read-only preview of what pruneOrphaned() would do, scoped the same
     * way, without persisting anything. Shared by the `progress:prune-orphaned
     * --dry-run` command output and the Playlist Viewers "Preview" admin
     * action, so both report identical rows for identical scope.
     *
     * @return array{checked: int, items: list<array{id: int, content_type: string, stream_id: ?int, tmdb_id: ?int, action: string}>}
     */
    public function preview(Playlist|CustomPlaylist|MergedPlaylist|PlaylistAlias|null $scopeToPlaylist = null): array
    {
        $checked = 0;
        $items = [];

        foreach ($this->candidateQuery($scopeToPlaylist)->cursor() as $progress) {
            $checked++;
            $playlist = $progress->viewer?->viewerable;
            if (! $playlist) {
                continue;
            }

            $hasTarget = $progress->content_type === 'vod' ? (bool) $progress->channel : (bool) $progress->episode;

            if ($hasTarget) {
                if (! $progress->tmdb_id) {
                    $items[] = $this->describe($progress, 'backfill');
                }

                continue;
            }

            $action = $this->resolveOrphanAction($progress, $playlist);
            if ($action !== 'keep') {
                $items[] = $this->describe($progress, $action);
            }
        }

        return ['checked' => $checked, 'items' => $items];
    }

    /**
     * Sweep every vod/episode progress row (optionally scoped to one
     * playlist): backfill tmdb_id where it's still missing but the row
     * resolves, relink rows whose stream_id has already gone stale, and
     * delete the ones with no tmdb_id to relink from. Shared by the
     * `progress:prune-orphaned` command and the Playlist Viewers admin
     * action, so both surfaces report identical counts for identical work.
     *
     * @return array{checked: int, backfilled: int, relinked: int, deleted: int}
     */
    public function pruneOrphaned(Playlist|CustomPlaylist|MergedPlaylist|PlaylistAlias|null $scopeToPlaylist = null): array
    {
        $stats = ['checked' => 0, 'backfilled' => 0, 'relinked' => 0, 'deleted' => 0];

        foreach ($this->candidateQuery($scopeToPlaylist)->cursor() as $progress) {
            $stats['checked']++;
            $playlist = $progress->viewer?->viewerable;
            if (! $playlist) {
                continue;
            }

            $hasTarget = $progress->content_type === 'vod' ? (bool) $progress->channel : (bool) $progress->episode;

            if ($hasTarget) {
                if (! $progress->tmdb_id && $this->backfillTmdbId($progress)) {
                    $stats['backfilled']++;
                }

                continue;
            }

            if ($this->ensureLinked($progress, $playlist)) {
                $stats['relinked']++;
            } elseif ($this->resolveOrphanAction($progress, $playlist) !== 'keep') {
                $progress->delete();
                $stats['deleted']++;
            }
        }

        return $stats;
    }

    /**
     * Find the library content a TMDB id points at - a VOD Channel for 'vod',
     * an Episode (by its own per-episode TMDB id) for 'episode'. Scoped to the
     * playlist owner, the same way stale rows are relinked.
     */
    public function findContentByTmdbId(string $contentType, int $tmdbId, Playlist|CustomPlaylist|MergedPlaylist|PlaylistAlias $playlist): Channel|Episode|null
    {
        return match ($contentType) {
            'vod' => Channel::where('user_id', $playlist->user_id)
                ->where('is_vod', true)
                ->where('tmdb_id', $tmdbId)
                ->first(),
            'episode' => Episode::where('user_id', $playlist->user_id)
                ->where('tmdb_id', $tmdbId)
                ->first(),
            default => null,
        };
    }

    /**
     * What a sweep should do with a row ensureLinked() couldn't fix: 'relink'
     * (preview only - a match exists), 'keep' for a manually added row whose
     * content isn't in the library yet, or 'delete' (content gone, or already
     * tracked by another row for the same viewer).
     */
    private function resolveOrphanAction(ViewerWatchProgress $progress, Playlist|CustomPlaylist|MergedPlaylist|PlaylistAlias $playlist): string
    {
        $replacement = $progress->tmdb_id
            ? $this->findContentByTmdbId($progress->content_type, $progress->tmdb_id, $playlist)
            : null;

        if ($replacement) {
            return $this->conflictsWithExistingRow($progress, $replacement->id) ? 'delete' : 'relink';
        }

        return $progress->stream_id === null ? 'keep' : 'delete';
    }

    /**
     * Whether the viewer already has a progress row for $streamId - relinking
     * onto it would violate the (viewer, content_type, stream_id) unique index.
     */
    private function conflictsWithExistingRow(ViewerWatchProgress $progress, int $streamId): bool
    {
        return ViewerWatchProgress::where('playlist_viewer_id', $progress->playlist_viewer_id)
            ->where('content_type', $progress->content_type)
            ->where('stream_id', $streamId)
            ->whereKeyNot($progress->getKey())
            ->exists();
    }

    /**
     * @return array{id: int, content_type: string, stream_id: ?int, tmdb_id: ?int, action: string}
     */
    private function describe(ViewerWatchProgress $progress, string $action): array
    {
        return [
            'id' => $progress->id,
            'content_type' => $progress->content_type,
            'stream_id' => $progress->stream_id,
            'tmdb_id' => $progress->tmdb_id,
            'action' => $action,
        ];
    }

    private function candidateQuery(Playlist|CustomPlaylist|MergedPlaylist|PlaylistAlias|null $scopeToPlaylist): Builder
    {
        return ViewerWatchProgress::query()
            ->whereIn('content_type', ['vod', 'episode'])
            ->with(['channel', 'episode', 'viewer.viewerable'])
            ->when(
                $scopeToPlaylist,
                fn (Builder $query) => $query->whereHas(
                    'viewer',
                    fn ($viewerQuery) => $viewerQuery
                        ->where('viewerable_type', $scopeToPlaylist->getMorphClass())
                        ->where('viewerable_id', $scopeToPlaylist->id)
                )
            );
    }

    private function findChannel(ViewerWatchProgress $progress, Playlist|CustomPlaylist|MergedPlaylist|PlaylistAlias $playlist): ?Channel
    {
        return $progress->tmdb_id ? $this->findContentByTmdbId('vod', $progress->tmdb_id, $playlist) : null;
    }

    private function findEpisode(ViewerWatchProgress $progress, Playlist|CustomPlaylist|MergedPlaylist|PlaylistAlias $playlist): ?Episode
    {
        return $progress->tmdb_id ? $this->findContentByTmdbId('episode', $progress->tmdb_id, $playlist) : null;
    }
}
