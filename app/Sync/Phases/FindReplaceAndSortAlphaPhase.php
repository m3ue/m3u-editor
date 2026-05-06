<?php

namespace App\Sync\Phases;

use App\Jobs\RunPlaylistFindReplaceRules;
use App\Jobs\RunPlaylistSortAlpha;
use App\Models\Playlist;
use App\Models\SyncRun;
use App\Sync\Contracts\ChainablePhase;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

/**
 * Phase 1 of the post-sync pipeline: apply Find/Replace rules and the
 * Sort Alpha sort order to channel names.
 *
 * Both steps must run in sequence (sort_alpha keys off names that
 * find_replace may have rewritten).
 *
 * This phase is {@see ChainablePhase}: when invoked inside a `chain()` block
 * the orchestrator collects the F/R and Sort Alpha jobs alongside the
 * subsequent chained phases (e.g. {@see StrmSyncPhase}) and dispatches them
 * as a single `Bus::chain([...])` so STRM sync is guaranteed to observe
 * processed `title_custom` values written by F/R.
 *
 * Outside a chain block (the legacy path retained for callers that build a
 * non-chained plan), the phase falls back to dispatching its own jobs.
 *
 * Honours the `playlist:{id}:find_replace_ran` cache marker that
 * {@see RunPlaylistFindReplaceRules} writes when F/R already ran earlier in
 * this sync window. The marker is consumed via Cache::pull so it cannot
 * bleed into a subsequent sync.
 */
class FindReplaceAndSortAlphaPhase extends AbstractPhase implements ChainablePhase
{
    public static function slug(): string
    {
        return 'find_replace_and_sort_alpha';
    }

    public function shouldRun(Playlist $playlist): bool
    {
        return $this->hasFindReplaceRules($playlist) || $this->hasSortAlphaRules($playlist);
    }

    protected function execute(SyncRun $run, Playlist $playlist, array $context): ?array
    {
        $jobs = $this->resolveJobs($playlist);

        if ($jobs === []) {
            return ['name_processing_dispatched' => []];
        }

        if (count($jobs) > 1) {
            Bus::chain($jobs)->dispatch();
        } else {
            dispatch($jobs[0]);
        }

        return ['name_processing_dispatched' => $this->describeJobs($jobs)];
    }

    /**
     * @return array<int, ShouldQueue>
     */
    public function chainJobs(SyncRun $run, Playlist $playlist, array $context = []): array
    {
        return $this->resolveJobs($playlist);
    }

    /**
     * Build the ordered list of jobs this phase contributes (F/R first, then
     * Sort Alpha). Honours the dedup marker so an eager F/R earlier in the
     * sync window is not re-run.
     *
     * @return array<int, ShouldQueue>
     */
    private function resolveJobs(Playlist $playlist): array
    {
        $hasFindReplace = $this->hasFindReplaceRules($playlist);
        $hasSortAlpha = $this->hasSortAlphaRules($playlist);

        if ($hasFindReplace && Cache::pull(RunPlaylistFindReplaceRules::ranMarkerKey($playlist))) {
            $hasFindReplace = false;
        }

        $jobs = [];

        if ($hasFindReplace) {
            $jobs[] = new RunPlaylistFindReplaceRules($playlist);
        }

        if ($hasSortAlpha) {
            $jobs[] = new RunPlaylistSortAlpha($playlist);
        }

        return $jobs;
    }

    /**
     * @param  array<int, ShouldQueue>  $jobs
     * @return array<int, string>
     */
    private function describeJobs(array $jobs): array
    {
        return array_map(
            fn (ShouldQueue $job): string => $job instanceof RunPlaylistFindReplaceRules ? 'find_replace' : 'sort_alpha',
            $jobs,
        );
    }

    private function hasFindReplaceRules(Playlist $playlist): bool
    {
        return collect($playlist->find_replace_rules ?? [])
            ->contains(fn ($r) => $r['enabled'] ?? false);
    }

    private function hasSortAlphaRules(Playlist $playlist): bool
    {
        return collect($playlist->sort_alpha_config ?? [])
            ->contains(fn ($r) => $r['enabled'] ?? false);
    }
}
