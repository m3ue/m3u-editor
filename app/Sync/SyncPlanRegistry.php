<?php

namespace App\Sync;

use App\Enums\Status;
use App\Models\SyncRun;
use App\Sync\Plans\PlaylistPostSyncPlan;
use App\Sync\Plans\PlaylistPreSyncPlan;

/**
 * Resolves the canonical {@see SyncPlan} that was (or will be) executed for a
 * given {@see SyncRun}. Used by the Filament SyncRun resource to render the
 * full set of planned phases up-front, before the orchestrator has had a
 * chance to record any phase transitions on the run.
 *
 * The registry is keyed by the run's `kind`. For runs whose plan choice
 * depends on runtime state (e.g. the post-sync plan reduces to
 * post-process-only when the playlist did not complete successfully), the
 * resolver inspects {@see SyncRun::$meta} to pick the right variant.
 */
final class SyncPlanRegistry
{
    public static function for(SyncRun $run): ?SyncPlan
    {
        return match ($run->kind) {
            'sync' => PlaylistPreSyncPlan::build(),
            'post_sync' => self::resolvePostSyncPlan($run),
            default => null,
        };
    }

    private static function resolvePostSyncPlan(SyncRun $run): SyncPlan
    {
        $playlistStatus = $run->meta['playlist_status'] ?? null;

        return $playlistStatus === Status::Completed->value
            ? PlaylistPostSyncPlan::build()
            : PlaylistPostSyncPlan::buildPostProcessOnly();
    }
}
