<?php

namespace App\Sync\Phases;

use App\Jobs\AutoSyncGroupsToCustomPlaylist;
use App\Models\Playlist;
use App\Models\SyncRun;

/**
 * Auto-sync configured source groups (or series categories) into one or more
 * custom playlists. Driven by the `auto_sync_to_custom_config` JSON column on
 * the playlist; each enabled rule produces an {@see AutoSyncGroupsToCustomPlaylist}
 * dispatch.
 */
class AutoSyncToCustomPhase extends AbstractPhase
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
        $rules = collect($playlist->auto_sync_to_custom_config ?? [])
            ->filter(fn (array $rule): bool => $rule['enabled'] ?? false);

        $dispatched = 0;

        foreach ($rules as $rule) {
            $customPlaylistId = (int) ($rule['custom_playlist_id'] ?? 0);
            $groupIds = array_map('intval', (array) ($rule['groups'] ?? []));

            if (! $customPlaylistId || empty($groupIds)) {
                continue;
            }

            dispatch(new AutoSyncGroupsToCustomPlaylist(
                userId: $playlist->user_id,
                playlistId: $playlist->id,
                groupIds: $groupIds,
                customPlaylistId: $customPlaylistId,
                data: [
                    'mode' => $rule['mode'] ?? 'original',
                    'category' => $rule['category'] ?? null,
                    'new_category' => $rule['new_category'] ?? null,
                ],
                type: $rule['type'] === 'series_categories' ? 'series' : 'channel',
                syncMode: $rule['sync_mode'] ?? 'full_sync',
            ));

            $dispatched++;
        }

        return ['auto_sync_rules_dispatched' => $dispatched];
    }
}
