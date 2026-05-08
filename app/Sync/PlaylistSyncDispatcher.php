<?php

namespace App\Sync;

use App\Enums\Status;
use App\Jobs\ProcessM3uImport;
use App\Models\Playlist;
use App\Models\SyncRun;
use App\Sync\Contracts\SyncPhase;
use App\Sync\Middleware\RecordsSyncPhaseCompletion;
use App\Sync\Phases\AbstractPhase;
use App\Sync\Plans\PlaylistPreSyncPlan;
use Illuminate\Contracts\Container\Container;

/**
 * Single entry point for dispatching playlist sync work.
 *
 * Opens a `SyncRun` ledger row (kind: `sync`) so every sync attempt is
 * traceable to the trigger that initiated it (UI action, API call, scheduled
 * command, model lifecycle event, ...) before handing off to
 * `ProcessM3uImport`.
 *
 * Pre-sync work (network/media-server guards, concurrency check, sync state
 * initialization) is run synchronously via {@see PlaylistPreSyncPlan} against
 * the same SyncRun. Each pre-sync phase records its own status on the run.
 * If any pre-sync phase signals `halt` in the shared context, the import job
 * is **not** dispatched — equivalent to the legacy in-job early returns.
 *
 * The `m3u_import` phase on the run is recorded by
 * {@see RecordsSyncPhaseCompletion} once the worker
 * picks the import job up — we attach the run + phase slug fluently via
 * `withSyncContext()` so the middleware has the context it needs. The
 * dispatcher passes `startsRun: true` (run transitions Pending → Running when
 * the worker picks the job up) but `closesRun: false`, because
 * `ProcessM3uImport` only dispatches downstream chains for Xtream playlists
 * with series/VOD — the actual sync work lives in those chains. The run is
 * instead closed by {@see SyncListener} when the `SyncCompleted` event fires,
 * which is the true end of the initial sync regardless of playlist type.
 *
 * If a pre-sync phase halts (network playlist, no integration, already
 * processing, ...), the run is marked Cancelled with the halt reason since
 * no import job will be dispatched.
 *
 * The post-sync flow that runs after `SyncCompleted` fires opens a *separate*
 * `SyncRun` (kind: `post_sync`) inside `SyncListener`, managed end-to-end by
 * the {@see SyncOrchestrator}.
 */
class PlaylistSyncDispatcher
{
    // -------------------------------------------------------------------------
    // Trigger identifiers — centralised for grep-ability and consistency.
    // -------------------------------------------------------------------------

    public const TRIGGER_PLAYLIST_CREATED = 'playlist.created';

    public const TRIGGER_API_REFRESH = 'api.refresh';

    public const TRIGGER_CONSOLE_REFRESH = 'console.refresh';

    public const TRIGGER_CONSOLE_REFRESH_SCHEDULED = 'console.refresh.scheduled';

    public const TRIGGER_CONSOLE_RESET_STUCK = 'console.reset_stuck';

    public const TRIGGER_FILAMENT_PROCESS = 'filament.process';

    public const TRIGGER_FILAMENT_BULK_PROCESS = 'filament.bulk_process';

    /**
     * Phase slug used for the `ProcessM3uImport` step on the sync-side
     * `SyncRun`. Recorded by the job's `RecordsSyncPhaseCompletion`
     * middleware when the worker actually executes the job.
     */
    public const PHASE_M3U_IMPORT = 'm3u_import';

    public function __construct(private readonly Container $container) {}

    /**
     * Open a SyncRun ledger row, run the pre-sync phase plan, and (if no
     * pre-sync phase halted) dispatch the M3U import job.
     *
     * @param  array<string, mixed>  $meta  Additional metadata to merge into the run's `meta` column.
     */
    public function dispatch(
        Playlist $playlist,
        string $trigger,
        bool $force = false,
        bool $isNew = false,
        array $meta = [],
    ): SyncRun {
        $run = SyncRun::openFor(
            playlist: $playlist,
            kind: 'sync',
            trigger: $trigger,
            meta: array_merge([
                'force' => $force,
                'is_new' => $isNew,
            ], $meta),
        );

        $context = $this->runPreSync($run, $playlist, [
            'force' => $force,
            'is_new' => $isNew,
        ]);

        if ($context['halt'] ?? false) {
            $reason = $context['halt_reason'] ?? 'unknown';
            $run->markCancelled("Pre-sync halted: {$reason}");

            return $run->fresh() ?? $run;
        }

        // Optimistically mark the playlist as Processing so the UI reflects the
        // new state immediately, without waiting for a queue worker to pick up
        // the import job. This is what activates the 3s progress-column polling
        // in the Filament table (columns poll when status is Processing/Pending).
        // ProcessM3uImport::handle() will overwrite this with the full reset
        // (errors, progress, processing flags) when it runs.
        //
        // Intentionally placed AFTER the pre-sync halt check: if a pre-sync
        // phase cancels the run (e.g. playlist already processing), we must
        // NOT set Processing here — the status would otherwise be stuck until
        // the user manually resets it.
        $playlist->update([
            'status' => Status::Processing,
            'progress' => 0,
            'vod_progress' => 0,
        ]);

        dispatch(
            (new ProcessM3uImport($playlist, $force, $isNew))
                ->withSyncContext($run, self::PHASE_M3U_IMPORT, closesRun: false, startsRun: true)
        );

        return $run->fresh() ?? $run;
    }

    /**
     * Walk the {@see PlaylistPreSyncPlan} steps inline, stopping as soon as a
     * phase sets `halt` on the shared context. Each phase records its own
     * Started/Completed/Failed transitions on the SyncRun via
     * {@see AbstractPhase}.
     *
     * Pre-sync phases are run synchronously here rather than through the
     * {@see SyncOrchestrator} so we can:
     *   - inspect halt context between phases without flipping the run-level
     *     status to Running/Completed (the run lifecycle is owned by the
     *     post-sync flow / the import job's middleware);
     *   - keep the dispatcher as the single owner of "should we dispatch the
     *     import job?" decision.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function runPreSync(SyncRun $run, Playlist $playlist, array $context): array
    {
        foreach (PlaylistPreSyncPlan::build()->steps() as $step) {
            /** @var SyncPhase $phase */
            $phase = $this->container->make($step->phaseClass);

            if (! $phase->shouldRun($playlist)) {
                $run->markPhaseSkipped($phase::slug(), reason: 'shouldRun returned false');

                continue;
            }

            $updates = $phase->run($run, $playlist, $context);
            $context = array_merge($context, $updates);

            if ($context['halt'] ?? false) {
                break;
            }
        }

        return $context;
    }
}
