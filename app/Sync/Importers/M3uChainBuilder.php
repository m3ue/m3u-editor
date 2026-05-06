<?php

namespace App\Sync\Importers;

use App\Jobs\CreateBackup;
use App\Jobs\ProcessM3uImportChunk;
use App\Jobs\ProcessM3uImportComplete;
use App\Models\Job;
use App\Models\Playlist;
use Carbon\Carbon;

/**
 * Assembles the ordered Bus chain for a standard M3U+ playlist sync.
 *
 * Simpler than its Xtream counterpart: a single (optional) backup job, the
 * channel chunks queued during parsing, then the completion job. Returned
 * array is intended to be fed to ChainDispatcher.
 */
final class M3uChainBuilder
{
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
    ): array {
        $playlistId = $playlist->id;
        $jobs = [];

        if (! $isNew && $playlist->backup_before_sync) {
            $jobs[] = new CreateBackup(includeFiles: false);
        }

        $where = [
            ['batch_no', '=', $batchNo],
            ['variables', '!=', null],
        ];
        $batchCount = Job::where($where)->count();
        Job::where($where)->select('id')->cursor()->chunk(100)->each(function ($chunk) use (&$jobs, $batchCount) {
            $jobs[] = new ProcessM3uImportChunk($chunk->pluck('id')->toArray(), $batchCount);
        });

        $jobs[] = new ProcessM3uImportComplete(
            userId: $userId,
            playlistId: $playlistId,
            batchNo: $batchNo,
            start: $start,
            maxHit: $maxItemsHit,
            isNew: $isNew,
            runningSeriesImport: false, // No series import for M3U imports
        );

        return $jobs;
    }
}
