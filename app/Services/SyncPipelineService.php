<?php

namespace App\Services;

use App\Enums\SyncRunPhase;
use App\Enums\SyncRunStatus;
use App\Events\SyncCompleted;
use App\Jobs\AutoSyncGroupsToCustomPlaylist;
use App\Jobs\CompleteSyncPhase;
use App\Jobs\FetchTmdbIds;
use App\Jobs\MergeChannels;
use App\Jobs\ProbeChannelStreams;
use App\Jobs\ProbeStreams;
use App\Jobs\ProcessChannelScrubber;
use App\Jobs\ProcessM3uImportSeries;
use App\Jobs\ProcessVodChannels;
use App\Jobs\RunPlaylistFindReplaceRules;
use App\Jobs\RunPlaylistSortAlpha;
use App\Jobs\SyncSeriesStrmFiles;
use App\Jobs\SyncVodStrmFiles;
use App\Listeners\SyncListener;
use App\Models\Playlist;
use App\Models\SyncRun;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncPipelineService
{
    /**
     * Create a SyncRun at the very start of an import so the UI has visibility
     * into the sync from kickoff. The pipeline starts with only the [Import]
     * phase; once the import job chain completes and we know which post-import
     * phases will actually run, expandPipelineAfterImport() replaces the
     * phases array with the full resolved plan.
     *
     * If a run is already active for this playlist, the existing run is returned
     * and no new run is created. A cache-backed lock prevents concurrent callers
     * from slipping through the guard simultaneously.
     */
    public function startImport(
        Playlist $playlist,
        string $trigger = 'full_sync',
    ): SyncRun {
        $lockKey = "sync_pipeline_start:{$playlist->id}";

        return Cache::lock($lockKey, 10)->block(5, function () use ($playlist, $trigger): SyncRun {
            $existing = SyncRun::where('playlist_id', $playlist->id)
                ->where('status', SyncRunStatus::Running->value)
                ->latest('started_at')
                ->first();

            if ($existing) {
                // If the import phase is already complete the pipeline has moved past
                // Import. This run is stale — the pipeline died mid-way (queue clear,
                // worker crash, etc.). Fail it so we can start a fresh run below.
                if ($existing->isPhaseComplete(SyncRunPhase::Import)) {
                    $existing->update([
                        'status' => SyncRunStatus::Failed->value,
                        'finished_at' => now(),
                    ]);

                    Log::info("SyncPipeline: startImport — stale run {$existing->id} detected (import complete, pipeline dead). Failing and creating fresh run.", [
                        'playlist_id' => $playlist->id,
                        'trigger' => $trigger,
                        'stale_run_id' => $existing->id,
                        'stale_current_phase' => $existing->current_phase,
                    ]);
                } else {
                    // Import is not yet complete — a true concurrent dispatch. Return the
                    // existing run to prevent a duplicate import.
                    Log::info("SyncPipeline: startImport skipped — run {$existing->id} already active for playlist {$playlist->id}.", [
                        'existing_run_id' => $existing->id,
                        'trigger' => $trigger,
                        'playlist_id' => $playlist->id,
                    ]);

                    return $existing;
                }
            }

            return SyncRun::create([
                'playlist_id' => $playlist->id,
                'user_id' => $playlist->user_id,
                'trigger' => $trigger,
                'status' => SyncRunStatus::Running->value,
                'current_phase' => SyncRunPhase::Import->value,
                'phases' => [SyncRunPhase::Import->value],
                'phase_statuses' => (object) [],
                'context' => [
                    'playlist_id' => $playlist->id,
                    'user_id' => $playlist->user_id,
                ],
                'started_at' => now(),
            ]);
        });
    }

    /**
     * After the import job chain finishes, resolve the real post-import
     * pipeline (now that channel/series rows exist in the DB) and replace
     * the run's phases with [Import, ...resolved phases..., SyncCompleted].
     *
     * The Import phase itself is NOT yet marked completed here — the caller
     * marks it via completePhase() so dispatch of the next phase happens
     * through the normal pipeline progression path.
     */
    public function expandPipelineAfterImport(
        SyncRun $run,
        Playlist $playlist,
        GeneralSettings $settings,
    ): void {
        $resolved = $this->resolvePipeline($playlist, $settings);

        $phaseValues = array_merge(
            [SyncRunPhase::Import->value],
            array_map(fn (SyncRunPhase $p) => $p->value, $resolved),
        );

        $run->update([
            'phases' => $phaseValues,
        ]);
    }

    /**
     * Build and persist a SyncRun for a full post-import pipeline.
     *
     * Legacy path: used when an import dispatches ProcessM3uImportComplete
     * without a pre-existing SyncRun (e.g. one-off partial actions). Modern
     * full-sync entry points should call startImport() first and pass the
     * resulting SyncRun id through the chain.
     */
    public function buildPipeline(
        Playlist $playlist,
        GeneralSettings $settings,
        string $trigger = 'full_sync',
    ): SyncRun {
        $phases = $this->resolvePipeline($playlist, $settings);
        $phaseValues = array_map(fn (SyncRunPhase $p) => $p->value, $phases);

        return SyncRun::create([
            'playlist_id' => $playlist->id,
            'user_id' => $playlist->user_id,
            'trigger' => $trigger,
            'status' => SyncRunStatus::Pending->value,
            'phases' => $phaseValues,
            'phase_statuses' => (object) [],
            'context' => [
                'playlist_id' => $playlist->id,
                'user_id' => $playlist->user_id,
            ],
        ]);
    }

    /**
     * Mark a run as running and dispatch the first phase.
     */
    public function startRun(SyncRun $run): void
    {
        $first = $run->getNextPendingPhase();

        if ($first === null) {
            $this->finish($run);

            return;
        }

        $run->update([
            'status' => SyncRunStatus::Running->value,
            'current_phase' => $first->value,
            'started_at' => now(),
        ]);

        $this->dispatchPhase($run, $first);
    }

    /**
     * Called by gateway jobs when a phase completes.
     * Marks the phase done and dispatches the next one (or finishes the run).
     */
    public function completePhase(int $syncRunId, SyncRunPhase $phase): void
    {
        // Wrap the read-check-write sequence in a transaction with a row lock so that
        // two workers calling completePhase() simultaneously for the same run cannot
        // both pass the guards and double-dispatch the next phase.
        // Dispatching happens after the transaction commits to avoid sending jobs based
        // on state that could be rolled back.
        $run = null;
        $next = null;
        $shouldFinish = false;

        DB::transaction(function () use ($syncRunId, $phase, &$run, &$next, &$shouldFinish): void {
            $candidate = SyncRun::lockForUpdate()->find($syncRunId);

            // Only allow progression while the run is actively running.
            if (! $candidate || $candidate->status !== SyncRunStatus::Running->value) {
                return;
            }

            // Ignore phases not in this run's plan.
            if (! in_array($phase->value, $candidate->phases ?? [])) {
                Log::warning("SyncPipeline: Phase {$phase->value} not in run {$syncRunId} plan. Ignoring.");

                return;
            }

            // Idempotency: handles retries and any duplicate completion signals.
            if ($candidate->isPhaseComplete($phase)) {
                Log::warning("SyncPipeline: Phase {$phase->value} already completed in run {$syncRunId}. Ignoring duplicate.");

                return;
            }

            $candidate->markPhase($phase, 'completed');

            Log::info("SyncPipeline: Phase completed. run={$syncRunId}, phase={$phase->value}");

            $nextPhase = $candidate->getNextPendingPhase();

            if ($nextPhase === null || $nextPhase === SyncRunPhase::SyncCompleted) {
                $run = $candidate;
                $shouldFinish = true;

                return;
            }

            $candidate->update(['current_phase' => $nextPhase->value]);
            $run = $candidate;
            $next = $nextPhase;
        });

        if ($run === null) {
            return;
        }

        if ($shouldFinish) {
            $this->finish($run);

            return;
        }

        $this->dispatchPhase($run, $next);
    }

    /**
     * Dispatch the job(s) for a given phase.
     */
    public function dispatchPhase(SyncRun $run, SyncRunPhase $phase): void
    {
        $playlistId = $run->context['playlist_id'];
        $playlist = Playlist::find($playlistId);

        if (! $playlist) {
            Log::error("SyncPipeline: Playlist {$playlistId} not found. Failing run {$run->id}.");
            $this->fail($run, "Playlist {$playlistId} not found");

            return;
        }

        Log::info("SyncPipeline: Dispatching phase. run={$run->id}, phase={$phase->value}");

        match ($phase) {
            SyncRunPhase::Import => null, // No-op: Import is driven externally by the ProcessM3uImport job chain.
            SyncRunPhase::VodMetadata => $this->dispatchVodMetadata($run, $playlist),
            SyncRunPhase::VodTmdb => $this->dispatchVodTmdb($run, $playlist),
            SyncRunPhase::VodStrm => $this->dispatchVodStrm($run, $playlist),
            SyncRunPhase::VodProbe => $this->dispatchProbe($run, $playlist, isSeriesProbe: false),
            SyncRunPhase::VodStrmPostProbe => $this->dispatchVodStrmPostProbe($run, $playlist),
            SyncRunPhase::SeriesMetadata => $this->dispatchSeriesMetadata($run, $playlist),
            SyncRunPhase::SeriesTmdb => $this->dispatchSeriesTmdb($run, $playlist),
            SyncRunPhase::SeriesStrm => $this->dispatchSeriesStrm($run, $playlist),
            SyncRunPhase::SeriesProbe => $this->dispatchProbe($run, $playlist, isSeriesProbe: true),
            SyncRunPhase::SeriesStrmPostProbe => $this->dispatchSeriesStrmPostProbe($run, $playlist),
            SyncRunPhase::FindReplace => $this->dispatchFindReplace($run, $playlist),
            SyncRunPhase::ChannelMerge => $this->dispatchChannelMerge($run, $playlist),
            SyncRunPhase::LiveProbe => $this->dispatchLiveProbe($run, $playlist),
            SyncRunPhase::CustomPlaylistSync => $this->dispatchCustomPlaylistSync($run, $playlist),
            SyncRunPhase::SyncCompleted => $this->finish($run),
        };
    }

    // ── Phase dispatchers ────────────────────────────────────────────────────

    private function dispatchVodMetadata(SyncRun $run, Playlist $playlist): void
    {
        dispatch(new ProcessVodChannels(
            playlist: $playlist,
            syncRunId: $run->id,
        ));
    }

    private function dispatchVodTmdb(SyncRun $run, Playlist $playlist): void
    {
        FetchTmdbIds::dispatch(
            vodPlaylistId: $playlist->id,
            lookupScope: $this->resolveLookupScope(),
            user: $playlist->user,
            sendCompletionNotification: false,
            syncRunId: $run->id,
            completionPhase: SyncRunPhase::VodTmdb,
        );
    }

    private function dispatchVodStrm(SyncRun $run, Playlist $playlist): void
    {
        dispatch(new SyncVodStrmFiles(
            playlist: $playlist,
            syncRunId: $run->id,
            completionPhase: SyncRunPhase::VodStrm,
        ));
    }

    private function dispatchVodStrmPostProbe(SyncRun $run, Playlist $playlist): void
    {
        dispatch(new SyncVodStrmFiles(
            playlist: $playlist,
            notify: false,
            syncRunId: $run->id,
            completionPhase: SyncRunPhase::VodStrmPostProbe,
        ));
    }

    private function dispatchProbe(SyncRun $run, Playlist $playlist, bool $isSeriesProbe): void
    {
        dispatch(new ProbeStreams(
            playlistId: $playlist->id,
            onlyUnprobed: (bool) ($playlist->auto_probe_vod_streams_only_unprobed ?? true),
            includeDisabled: (bool) ($playlist->auto_probe_vod_streams_include_disabled ?? false),
            syncRunId: $run->id,
            isSeriesProbe: $isSeriesProbe,
        ));
    }

    private function dispatchSeriesMetadata(SyncRun $run, Playlist $playlist): void
    {
        dispatch(new ProcessM3uImportSeries(
            playlist: $playlist,
            force: true,
            syncRunId: $run->id,
        ));
    }

    private function dispatchSeriesTmdb(SyncRun $run, Playlist $playlist): void
    {
        FetchTmdbIds::dispatch(
            seriesPlaylistId: $playlist->id,
            lookupScope: $this->resolveLookupScope(),
            user: $playlist->user,
            sendCompletionNotification: false,
            syncRunId: $run->id,
            completionPhase: SyncRunPhase::SeriesTmdb,
        );
    }

    private function resolveLookupScope(): string
    {
        return app(GeneralSettings::class)->tmdb_auto_lookup_all_new ?? 'enabled';
    }

    private function dispatchSeriesStrm(SyncRun $run, Playlist $playlist): void
    {
        dispatch(new SyncSeriesStrmFiles(
            series: null,
            notify: true,
            playlist_id: $playlist->id,
            user_id: $playlist->user_id,
            syncRunId: $run->id,
            completionPhase: SyncRunPhase::SeriesStrm,
        ));
    }

    private function dispatchSeriesStrmPostProbe(SyncRun $run, Playlist $playlist): void
    {
        dispatch(new SyncSeriesStrmFiles(
            series: null,
            notify: false,
            playlist_id: $playlist->id,
            user_id: $playlist->user_id,
            syncRunId: $run->id,
            completionPhase: SyncRunPhase::SeriesStrmPostProbe,
        ));
    }

    private function dispatchFindReplace(SyncRun $run, Playlist $playlist): void
    {
        $jobs = [];
        if ($this->hasEnabledRule($playlist->find_replace_rules)) {
            $jobs[] = new RunPlaylistFindReplaceRules($playlist);
        }
        if ($this->hasEnabledRule($playlist->sort_alpha_config)) {
            $jobs[] = new RunPlaylistSortAlpha($playlist);
        }
        $jobs[] = new CompleteSyncPhase($run->id, SyncRunPhase::FindReplace);

        $this->chainOrDispatch($jobs, $run);
    }

    /**
     * Merge live channels across failover playlists. MergeChannels is a single
     * synchronous job, so we chain it with CompleteSyncPhase to mark the phase
     * done once the merge work returns.
     */
    private function dispatchChannelMerge(SyncRun $run, Playlist $playlist): void
    {
        $mergeJob = SyncListener::getMergeJob($playlist);

        // No merge work to do — complete the phase immediately and progress.
        if (! $mergeJob) {
            $this->completePhase($run->id, SyncRunPhase::ChannelMerge);

            return;
        }

        $this->chainOrDispatch([
            $mergeJob,
            new CompleteSyncPhase($run->id, SyncRunPhase::ChannelMerge),
        ], $run);
    }

    /**
     * Run channel scrubbers (fire-and-forget; they run in parallel) and then
     * the live stream probe. ProbeChannelStreamsComplete completes the
     * LiveProbe phase via SyncPipelineService::completePhase() once probing
     * actually finishes.
     *
     * Scrubbers are dispatched here (not chained before the probe) because
     * each scrubber is an orchestrator that returns synchronously after
     * dispatching its own async sub-chain. Chaining wouldn't actually wait
     * for scrubber sub-chunks. This matches the legacy SyncListener semantics.
     */
    private function dispatchLiveProbe(SyncRun $run, Playlist $playlist): void
    {
        // Dispatch any recurring scrubbers in parallel — they don't block the probe.
        $playlist->channelScrubbers()
            ->where('recurring', true)
            ->get()
            ->each(fn ($scrubber) => dispatch(new ProcessChannelScrubber($scrubber->id)));

        // Live probe completes the phase via ProbeChannelStreamsComplete -> completePhase.
        // If auto_probe_streams was toggled off after the phase was queued, short-circuit.
        if (! ($playlist->auto_probe_streams ?? false)) {
            $this->completePhase($run->id, SyncRunPhase::LiveProbe);

            return;
        }

        dispatch(new ProbeChannelStreams(
            playlistId: $playlist->id,
            onlyUnprobed: (bool) ($playlist->auto_probe_streams_only_unprobed ?? true),
            includeDisabled: (bool) ($playlist->auto_probe_streams_include_disabled ?? false),
            syncRunId: $run->id,
        ));
    }

    private function dispatchCustomPlaylistSync(SyncRun $run, Playlist $playlist): void
    {
        $rules = collect($playlist->auto_sync_to_custom_config ?? [])
            ->filter(fn (array $rule): bool => $rule['enabled'] ?? false)
            ->filter(fn (array $rule): bool => (int) ($rule['custom_playlist_id'] ?? 0) > 0 && ! empty($rule['groups'] ?? []));

        $jobs = $rules->map(fn (array $rule): AutoSyncGroupsToCustomPlaylist => new AutoSyncGroupsToCustomPlaylist(
            userId: $playlist->user_id,
            playlistId: $playlist->id,
            groupIds: array_map('intval', (array) ($rule['groups'] ?? [])),
            customPlaylistId: (int) ($rule['custom_playlist_id'] ?? 0),
            data: [
                'mode' => $rule['mode'] ?? 'original',
                'category' => $rule['category'] ?? null,
                'new_category' => $rule['new_category'] ?? null,
            ],
            type: $rule['type'] === 'series_categories' ? 'series' : 'channel',
            syncMode: $rule['sync_mode'] ?? 'full_sync',
        ))->values()->all();

        $jobs[] = new CompleteSyncPhase($run->id, SyncRunPhase::CustomPlaylistSync);

        $this->chainOrDispatch($jobs, $run);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function chainOrDispatch(array $jobs, ?SyncRun $run = null): void
    {
        if (count($jobs) === 1) {
            dispatch($jobs[0]);
        } else {
            $chain = Bus::chain($jobs);

            if ($run) {
                $runId = $run->id;
                $chain->catch(function (Throwable $e) use ($runId): void {
                    $stuckRun = SyncRun::find($runId);
                    if ($stuckRun && $stuckRun->status === SyncRunStatus::Running->value) {
                        $this->fail($stuckRun, "Chain job failed: {$e->getMessage()}");
                    }
                });
            }

            $chain->dispatch();
        }
    }

    private function finish(SyncRun $run): void
    {
        $run->markPhase(SyncRunPhase::SyncCompleted, 'completed');

        $run->update([
            'status' => SyncRunStatus::Completed->value,
            'finished_at' => now(),
            'current_phase' => SyncRunPhase::SyncCompleted->value,
        ]);

        Log::info("SyncPipeline: Run {$run->id} completed.");

        $playlist = Playlist::find($run->context['playlist_id']);
        if ($playlist) {
            event(new SyncCompleted($playlist, 'playlist', $run->id));
        }
    }

    public function fail(SyncRun $run, string $reason): void
    {
        // Mark the current phase as failed in phase_statuses so the timeline
        // shows the failure point rather than leaving the phase as 'pending'.
        $currentPhase = $run->current_phase;
        if ($currentPhase) {
            $run->markPhase(SyncRunPhase::from($currentPhase), 'failed');
        }

        $run->update([
            'status' => SyncRunStatus::Failed->value,
            'finished_at' => now(),
            'progress_message' => $reason,
        ]);

        Log::error("SyncPipeline: Run {$run->id} failed — {$reason}");
    }

    // ── Pipeline builder ─────────────────────────────────────────────────────

    /** @return SyncRunPhase[] */
    private function resolvePipeline(Playlist $playlist, GeneralSettings $settings): array
    {
        $phases = [];

        $hasVod = $playlist->channels()
            ->where([['enabled', true], ['is_vod', true]])
            ->exists();

        $hasSeries = $playlist->series()->where('enabled', true)->exists();

        $tmdbEnabled = (bool) $settings->tmdb_auto_lookup_on_import;
        $lookupScope = $settings->tmdb_auto_lookup_all_new ?? 'enabled';

        $hasNewVod = $lookupScope !== 'enabled' && $playlist->channels()
            ->where([['new', true], ['is_vod', true]])
            ->exists();

        $hasNewSeries = $lookupScope !== 'enabled' && $playlist->series()
            ->where('new', true)
            ->exists();

        // Group 1: metadata + probe (TMDB intentionally excluded here).
        //
        // TMDB runs in Group 3, after FindReplace, so that any title-cleaning
        // rules (e.g. stripping "|FR " or "|DE " provider prefixes from series
        // names) are applied before the TMDB search query is constructed.
        //
        // Two paths per content type:
        // 1. Enabled items exist → metadata + probe via resolvePreStrmPhases (tmdbEnabled: false).
        // 2. No enabled items, but new disabled items were imported → no metadata/probe needed;
        //    TMDB-only path is handled in Group 3 below.
        if ($hasVod) {
            $phases = array_merge($phases, $this->resolvePreStrmPhases(
                metadataEnabled: (bool) $playlist->auto_fetch_vod_metadata,
                probeEnabled: (bool) $playlist->auto_probe_vod_streams,
                metadataPhase: SyncRunPhase::VodMetadata,
                probePhase: SyncRunPhase::VodProbe,
            ));
        }

        if ($hasSeries) {
            $phases = array_merge($phases, $this->resolvePreStrmPhases(
                metadataEnabled: (bool) $playlist->auto_fetch_series_metadata,
                probeEnabled: (bool) $playlist->auto_probe_vod_streams,
                metadataPhase: SyncRunPhase::SeriesMetadata,
                probePhase: SyncRunPhase::SeriesProbe,
            ));
        }

        // Group 2: find-replace + live housekeeping.
        // Runs after metadata/probe so stream stats are populated, and before
        // TMDB so the search query uses already-cleaned titles.
        $hasFindReplaceWork = $this->hasEnabledRule($playlist->find_replace_rules)
            || $this->hasEnabledRule($playlist->sort_alpha_config);

        if ($hasFindReplaceWork) {
            $phases[] = SyncRunPhase::FindReplace;
        }

        // ChannelMerge and LiveProbe operate on live channels and are independent
        // of TMDB; keep them here alongside FindReplace.
        if ($playlist->auto_merge_channels_enabled ?? false) {
            $phases[] = SyncRunPhase::ChannelMerge;
        }

        if ($playlist->auto_probe_streams ?? false) {
            $phases[] = SyncRunPhase::LiveProbe;
        }

        // Group 3: TMDB (after find-replace so cleaned titles are searched).
        //
        // The lookupScope ('enabled', 'new', 'both') is applied at dispatch time
        // inside dispatchVodTmdb/dispatchSeriesTmdb.
        if ($tmdbEnabled) {
            if ($hasVod || $hasNewVod) {
                $phases[] = SyncRunPhase::VodTmdb;
            }

            if ($hasSeries || $hasNewSeries) {
                $phases[] = SyncRunPhase::SeriesTmdb;
            }
        }

        // Group 4: STRM generation (runs after find-replace so filenames embed corrected titles)
        if ($hasVod) {
            $phases = array_merge($phases, $this->resolveStrmPhases(
                strmEnabled: (bool) $playlist->auto_sync_vod_stream_files,
                probeEnabled: (bool) $playlist->auto_probe_vod_streams,
                strmPhase: SyncRunPhase::VodStrm,
                strmPostProbePhase: SyncRunPhase::VodStrmPostProbe,
            ));
        }

        if ($hasSeries) {
            $phases = array_merge($phases, $this->resolveStrmPhases(
                strmEnabled: (bool) $playlist->auto_sync_series_stream_files,
                probeEnabled: (bool) $playlist->auto_probe_vod_streams,
                strmPhase: SyncRunPhase::SeriesStrm,
                strmPostProbePhase: SyncRunPhase::SeriesStrmPostProbe,
            ));
        }

        if ($this->hasEnabledRule($playlist->auto_sync_to_custom_config)) {
            $phases[] = SyncRunPhase::CustomPlaylistSync;
        }

        $phases[] = SyncRunPhase::SyncCompleted;

        return $phases;
    }

    /**
     * Metadata + probe phases for a single media type — no STRM, no TMDB.
     * TMDB is added separately in resolvePipeline() after FindReplace.
     *
     * @return SyncRunPhase[]
     */
    private function resolvePreStrmPhases(
        bool $metadataEnabled,
        bool $probeEnabled,
        SyncRunPhase $metadataPhase,
        SyncRunPhase $probePhase,
    ): array {
        $phases = [];

        if ($metadataEnabled) {
            $phases[] = $metadataPhase;
        }

        if ($probeEnabled) {
            $phases[] = $probePhase;
        }

        return $phases;
    }

    /**
     * STRM phases for a single media type — no metadata/probe.
     *
     * Kept separate from resolvePreStrmPhases() so resolvePipeline() can place
     * FindReplace between the two groups.
     *
     * @return SyncRunPhase[]
     */
    private function resolveStrmPhases(
        bool $strmEnabled,
        bool $probeEnabled,
        SyncRunPhase $strmPhase,
        SyncRunPhase $strmPostProbePhase,
    ): array {
        if (! $strmEnabled) {
            return [];
        }

        return $probeEnabled
            ? [$strmPostProbePhase]
            : [$strmPhase];
    }

    /**
     * True when the given rules array contains at least one entry with `enabled => true`.
     */
    private function hasEnabledRule(?array $rules): bool
    {
        return collect($rules ?? [])
            ->contains(fn (array $rule): bool => $rule['enabled'] ?? false);
    }
}
