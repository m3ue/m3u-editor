<?php

namespace App\Jobs;

use App\Models\Epg;
use App\Models\Job;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class MapEpgToChannelsBatch implements ShouldQueue
{
    use Queueable;

    public $deleteWhenMissingModels = true;

    // Timeout of 5 minutes
    public $timeout = 60 * 5;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $batchNo,
        public int $epgId,
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Fetch the EPG
        $epg = Epg::find($this->epgId);
        if (! $epg) {
            Log::error("EPG not found: {$this->epgId}");

            return;
        }

        // Get all Job records for this batch
        $batchCount = Job::where('batch_no', $this->batchNo)->count();

        if ($batchCount === 0) {
            // No jobs to process (no channels were mapped)
            Log::info("No mapped channels found for batch: {$this->batchNo}");

            return;
        }

        // Create processing jobs for the Job records in chunks
        $jobs = [];
        $jobsBatch = Job::where('batch_no', $this->batchNo)->select('id')->cursor();
        $jobsBatch->chunk(50)->each(function ($chunk) use (&$jobs, $batchCount) {
            $jobs[] = new MapEpgToChannels($chunk->pluck('id')->toArray(), $batchCount, $this->batchNo);
        });

        // Dispatch the jobs to actually update the channels
        // These will be processed before the completion job in the main chain
        foreach ($jobs as $job) {
            dispatch($job)->onConnection('redis')->onQueue('import');
        }
    }
}
