<?php

namespace App\Sync\Phases;

use App\Jobs\AutoSyncGroupsToCustomPlaylist;
use App\Models\Playlist;
use App\Models\SyncRun;
use App\Sync\Contracts\BatchablePhase;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Auto-sync configured source groups (or series categories) into one or more
 * custom playlists. Driven by the `auto_sync_to_custom_config` JSON column on
 * the playlist; each enabled rule produces an {@see AutoSyncGroupsToCustomPlaylist}
 * dispatch.
 *
 * Implements {@see BatchablePhase} so the orchestrator can collect the jobs
 * across sibling parallel-group phases and dispatch them as a single
 * `Bus::batch([...])`. The standalone `execute()` path (used by direct
 * `$phase->run()` callers and tests) still dispatches each job inline.
 */
class AutoSyncToCustomPhase extends AbstractPhase implements BatchablePhase
{
    public static function slug(): string
    {
        return 'auto_sync_to_custom';
    }

    public function shouldRun(Playlist $playlist): bool
    {
        return collect($playlist->auto_sync_to_custom_config ?? [])
            ->contains(fn (array $rule): bool => $rule['enabled'] ?? false);
    }

    protected function execute(SyncRun $run, Playlist $playlist, array $context): ?array
    {
        $jobs = $this->batchJobs($run, $playlist, $context);

        foreach ($jobs as $job) {
            dispatch($job);
        }

        return ['auto_sync_rules_dispatched' => count($jobs)];
    }

    /**
     * @return array<int, ShouldQueue>
     */
    public function batchJobs(SyncRun $run, Playlist $playlist, array $context = []): array
    {
        $jobs = [];

        foreach ($playlist->auto_sync_to_custom_config ?? [] as $rule) {
            if (! ($rule['enabled'] ?? false)) {
                continue;
            }

            $customPlaylistId = (int) ($rule['custom_playlist_id'] ?? 0);
            $groupIds = array_map('intval', (array) ($rule['groups'] ?? []));

            if (! $customPlaylistId || empty($groupIds)) {
                continue;
            }

            $jobs[] = new AutoSyncGroupsToCustomPlaylist(
                userId: $playlist->user_id,
                playlistId: $playlist->id,
                groupIds: $groupIds,
                customPlaylistId: $customPlaylistId,
                data: [
                    'mode' => $rule['mode'] ?? 'original',
                    'category' => $rule['category'] ?? null,
                    'new_category' => $rule['new_category'] ?? null,
                ],
                type: ($rule['type'] ?? '') === 'series_categories' ? 'series' : 'channel',
                syncMode: $rule['sync_mode'] ?? 'full_sync',
            );
        }

        return $jobs;
    }
}
