<?php

namespace App\Services;

use App\Enums\SyncRunPhase;
use App\Enums\SyncRunStatus;
use App\Events\SyncCompleted;
use App\Jobs\AutoSyncGroupsToCustomPlaylist;
use App\Jobs\CompleteSyncPhase;
use App\Jobs\FetchTmdbIds;
use App\Jobs\ProbeVodStreams;
use App\Jobs\ProcessM3uImportSeries;
use App\Jobs\ProcessVodChannels;
use App\Jobs\RunPlaylistFindReplaceRules;
use App\Jobs\RunPlaylistSortAlpha;
use App\Jobs\SyncSeriesStrmFiles;
use App\Jobs\SyncVodStrmFiles;
use App\Models\Playlist;
use App\Models\SyncRun;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncPipelineService
{
    /**
     * Build and persist a SyncRun for a full post-import pipeline.
     *
     * Called from ProcessM3uImportComplete after channels/series are in the DB
     * so existence checks reflect the actual import result.
     */
    public function buildPipeline(
        Playlist $playlist,
        GeneralSettings $settings,
        string $trigger = 'full_sync',
        bool $skipSeriesMetadata = false,
    ): SyncRun {
        $phases = $this->resolvePipeline($playlist, $settings, $skipSeriesMetadata);
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
            'started_at' => now(),
        ]);
    }

    /**
     * Build a SyncRun for a standalone/manual trigger (e.g. UI button press).
     *
     * @param  SyncRunPhase[]  $requestedPhases
     */
    public function buildStandalonePipeline(
        Playlist $playlist,
        array $requestedPhases,
        string $trigger,
    ): SyncRun {
        $phases = array_merge(
            array_map(
                fn (SyncRunPhase $p) => $p->value,
                array_filter($requestedPhases, fn (SyncRunPhase $p) => $p !== SyncRunPhase::SyncCompleted),
            ),
            [SyncRunPhase::SyncCompleted->value],
        );

        return SyncRun::create([
            'playlist_id' => $playlist->id,
            'user_id' => $playlist->user_id,
            'trigger' => $trigger,
            'status' => SyncRunStatus::Pending->value,
            'phases' => $phases,
            'phase_statuses' => (object) [],
            'context' => [
                'playlist_id' => $playlist->id,
                'user_id' => $playlist->user_id,
            ],
            'started_at' => now(),
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
        dispatch(new ProbeVodStreams(
            playlistId: $playlist->id,
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
            user: $playlist->user,
            sendCompletionNotification: false,
            syncRunId: $run->id,
            completionPhase: SyncRunPhase::SeriesTmdb,
        );
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

        // Series-discovery mini-pipelines track post-processing phases only.
        // The SyncCompleted event was already fired by ProcessM3uImportSeriesComplete
        // before this pipeline ran, so we must not fire it again — doing so would
        // re-trigger find-replace, custom-playlist-sync, and post-processes.
        if ($run->trigger === 'series_discovery_complete') {
            return;
        }

        $playlist = Playlist::find($run->context['playlist_id']);
        if ($playlist) {
            event(new SyncCompleted($playlist, 'playlist', $run->id));
        }
    }

    public function fail(SyncRun $run, string $reason): void
    {
        $run->update([
            'status' => SyncRunStatus::Failed->value,
            'finished_at' => now(),
            'progress_message' => $reason,
        ]);

        Log::error("SyncPipeline: Run {$run->id} failed — {$reason}");
    }

    // ── Pipeline builder ─────────────────────────────────────────────────────

    /**
     * Resolve the ordered list of series post-discovery phases for a playlist.
     *
     * Exposed for callers (e.g. ProcessM3uImportSeriesComplete) that need to
     * build a standalone series-only mini-pipeline after the discovery chunks
     * have populated the DB.
     *
     * @return SyncRunPhase[]
     */
    public function resolveSeriesPhases(Playlist $playlist, GeneralSettings $settings): array
    {
        return $this->resolveMediaPhases(
            metadataEnabled: (bool) $playlist->auto_fetch_series_metadata,
            tmdbEnabled: (bool) $settings->tmdb_auto_lookup_on_import,
            strmEnabled: (bool) $playlist->auto_sync_series_stream_files,
            probeEnabled: (bool) $playlist->auto_probe_vod_streams,
            metadataPhase: SyncRunPhase::SeriesMetadata,
            tmdbPhase: SyncRunPhase::SeriesTmdb,
            strmPhase: SyncRunPhase::SeriesStrm,
            probePhase: SyncRunPhase::SeriesProbe,
            strmPostProbePhase: SyncRunPhase::SeriesStrmPostProbe,
        );
    }

    /** @return SyncRunPhase[] */
    private function resolvePipeline(Playlist $playlist, GeneralSettings $settings, bool $skipSeriesMetadata = false): array
    {
        $phases = [];

        $hasVod = $playlist->channels()
            ->where([['enabled', true], ['is_vod', true]])
            ->exists();

        $hasSeries = ! $skipSeriesMetadata
            && $playlist->series()->where('enabled', true)->exists();

        // Group 1: metadata + TMDB + probe (must complete before find-replace and STRM)
        if ($hasVod) {
            $phases = array_merge($phases, $this->resolvePreStrmPhases(
                metadataEnabled: (bool) $playlist->auto_fetch_vod_metadata,
                tmdbEnabled: (bool) $settings->tmdb_auto_lookup_on_import,
                probeEnabled: (bool) $playlist->auto_probe_vod_streams,
                metadataPhase: SyncRunPhase::VodMetadata,
                tmdbPhase: SyncRunPhase::VodTmdb,
                probePhase: SyncRunPhase::VodProbe,
            ));
        }

        if ($hasSeries) {
            $phases = array_merge($phases, $this->resolvePreStrmPhases(
                metadataEnabled: (bool) $playlist->auto_fetch_series_metadata,
                tmdbEnabled: (bool) $settings->tmdb_auto_lookup_on_import,
                probeEnabled: (bool) $playlist->auto_probe_vod_streams,
                metadataPhase: SyncRunPhase::SeriesMetadata,
                tmdbPhase: SyncRunPhase::SeriesTmdb,
                probePhase: SyncRunPhase::SeriesProbe,
            ));
        }

        // Group 2: find-replace (after metadata/probe so stream_stats are populated;
        // before STRM so title_custom is already corrected when filenames are written)
        $hasFindReplaceWork = $this->hasEnabledRule($playlist->find_replace_rules)
            || $this->hasEnabledRule($playlist->sort_alpha_config);

        if ($hasFindReplaceWork) {
            $phases[] = SyncRunPhase::FindReplace;
        }

        // Group 3: STRM generation (runs after find-replace so filenames embed corrected titles)
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
     * Metadata + TMDB + probe phases for a single media type — no STRM.
     *
     * Used by resolvePipeline() so FindReplace can be inserted between this
     * group and the STRM group.
     *
     * @return SyncRunPhase[]
     */
    private function resolvePreStrmPhases(
        bool $metadataEnabled,
        bool $tmdbEnabled,
        bool $probeEnabled,
        SyncRunPhase $metadataPhase,
        SyncRunPhase $tmdbPhase,
        SyncRunPhase $probePhase,
    ): array {
        $phases = [];

        if ($metadataEnabled) {
            $phases[] = $metadataPhase;
        }

        if ($tmdbEnabled) {
            $phases[] = $tmdbPhase;
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
     * Build the ordered phase list for a single media type (VOD or Series).
     *
     * Used by resolveSeriesPhases() for the series-discovery mini-pipeline,
     * where FindReplace is not included (it runs via the main sync event).
     *
     * @return SyncRunPhase[]
     */
    private function resolveMediaPhases(
        bool $metadataEnabled,
        bool $tmdbEnabled,
        bool $strmEnabled,
        bool $probeEnabled,
        SyncRunPhase $metadataPhase,
        SyncRunPhase $tmdbPhase,
        SyncRunPhase $strmPhase,
        SyncRunPhase $probePhase,
        SyncRunPhase $strmPostProbePhase,
    ): array {
        return array_merge(
            $this->resolvePreStrmPhases($metadataEnabled, $tmdbEnabled, $probeEnabled, $metadataPhase, $tmdbPhase, $probePhase),
            $this->resolveStrmPhases($strmEnabled, $probeEnabled, $strmPhase, $strmPostProbePhase),
        );
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
