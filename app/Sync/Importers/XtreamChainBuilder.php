<?php

namespace App\Sync\Importers;

use App\Jobs\ProcessM3uImportChunk;
use App\Jobs\ProcessM3uImportComplete;
use App\Jobs\ProcessM3uImportSeriesChunk;
use App\Jobs\ProcessM3uImportSeriesComplete;
use App\Jobs\ProcessM3uVodImportChunk;
use App\Models\Job;
use App\Models\Playlist;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Assembles the ordered Bus chain for an Xtream playlist sync.
 *
 * The chain is composed dynamically based on which feeds (live, vod, series)
 * are enabled and which jobs have already been queued in the batch table.
 * Returned array is intended to be fed to ChainDispatcher; this class does
 * not dispatch anything itself. Backup (when enabled) is handled upstream by
 * BackupPhase in the pre-sync plan.
 */
final class XtreamChainBuilder
{
    public function __construct(
        private readonly InclusionPolicy $inclusionPolicy,
    ) {}

    /**
     * @return array<int, object>
     */
    public function build(
        Playlist $playlist,
        string $batchNo,
        int $userId,
        Carbon $start,
        bool $isNew,
        bool $maxItemsHit,
        bool $liveStreamsEnabled,
        bool $vodStreamsEnabled,
        ?Collection $seriesCategories,
        bool $preprocess,
        Collection $enabledCategories,
        ?int $syncRunId = null,
    ): array {
        $playlistId = $playlist->id;
        $jobs = [];

        // Flag any previously marked new items as not new.
        $playlist->groups()->where('new', true)->update(['new' => false]);
        $playlist->channels()->where('new', true)->update(['new' => false]);

        if ($liveStreamsEnabled) {
            $this->appendBatchJobs(
                jobs: $jobs,
                batchNo: $batchNo,
                type: 'live',
                jobClass: ProcessM3uImportChunk::class,
            );
        }

        if ($vodStreamsEnabled) {
            $this->appendBatchJobs(
                jobs: $jobs,
                batchNo: $batchNo,
                type: 'vod',
                jobClass: ProcessM3uVodImportChunk::class,
            );
        }

        $jobs[] = new ProcessM3uImportComplete(
            userId: $userId,
            playlistId: $playlistId,
            batchNo: $batchNo,
            start: $start,
            maxHit: $maxItemsHit,
            isNew: $isNew,
            runningSeriesImport: $seriesCategories && $seriesCategories->count() > 0,
            runningLiveImport: $liveStreamsEnabled,
            runningVodImport: $vodStreamsEnabled,
            syncRunId: $syncRunId,
        );

        if ($seriesCategories) {
            $this->appendSeriesJobs(
                jobs: $jobs,
                seriesCategories: $seriesCategories,
                playlist: $playlist,
                playlistId: $playlistId,
                batchNo: $batchNo,
                preprocess: $preprocess,
                enabledCategories: $enabledCategories,
            );
        }

        return $jobs;
    }

    /**
     * @param  array<int, object>  $jobs
     * @param  class-string  $jobClass
     */
    private function appendBatchJobs(array &$jobs, string $batchNo, string $type, string $jobClass): void
    {
        $where = [
            ['batch_no', '=', $batchNo],
            ['variables', '!=', null],
            ['variables->type', '=', $type],
        ];
        $count = Job::where($where)->count();
        Job::where($where)->select('id')->cursor()->chunk(100)->each(function ($chunk) use (&$jobs, $count, $jobClass) {
            $jobs[] = new $jobClass($chunk->pluck('id')->toArray(), $count);
        });
    }

    /**
     * @param  array<int, object>  $jobs
     */
    private function appendSeriesJobs(
        array &$jobs,
        Collection $seriesCategories,
        Playlist $playlist,
        int $playlistId,
        string $batchNo,
        bool $preprocess,
        Collection $enabledCategories,
    ): void {
        $categoryCount = $seriesCategories->count();
        $seriesCategories->each(function ($category, $index) use (&$jobs, $playlist, $playlistId, $batchNo, $categoryCount, $preprocess, $enabledCategories) {
            $categoryName = (string) ($category['category_name'] ?? '');
            if ($preprocess && ! $this->inclusionPolicy->shouldIncludeSeries($categoryName)) {
                return;
            }

            $autoEnable = (bool) ($playlist->enable_series || $enabledCategories->contains($categoryName));

            $jobs[] = new ProcessM3uImportSeriesChunk(
                [
                    'categoryId' => $category['category_id'],
                    'categoryName' => $category['category_name'],
                    'playlistId' => $playlistId,
                ],
                $categoryCount,
                $batchNo,
                $index,
                $autoEnable,
            );
        });

        $jobs[] = new ProcessM3uImportSeriesComplete(
            playlist: $playlist,
            batchNo: $batchNo,
        );
    }
}
