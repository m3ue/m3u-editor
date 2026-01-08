<?php

namespace App\Jobs;

use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\Job;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessEpgImportChunk implements ShouldQueue
{
    use Queueable;

    // Don't retry the job on failure
    public $tries = 1;

    public $deleteWhenMissingModels = true;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public array $jobs,
        public int $batchCount,
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Determine what percentage of the import this batch accounts for
        $totalJobsCount = $this->batchCount;
        $chunkSize = 10;

        // Process the jobs
        foreach (Job::whereIn('id', $this->jobs)->cursor() as $index => $job) {
            $bulk = [];
            if ($index % $chunkSize === 0) {
                $epg = Epg::find($job->variables['epgId']);
                $epg->update([
                    'progress' => min(99, $epg->progress + (($chunkSize / $totalJobsCount) * 100)),
                ]);
            }

            // Add the channel for insert/update
            foreach ($job->payload as $channel) {
                $bulk[] = [
                    ...$channel,
                ];
            }

            // Deduplicate the channels
            $bulk = collect($bulk)
                ->unique(function ($item) {
                    return $item['name'].$item['channel_id'].$item['epg_id'].$item['user_id'];
                })->toArray();

            // Upsert the channels
            EpgChannel::upsert($bulk, uniqueBy: ['name', 'channel_id', 'epg_id', 'user_id'], update: [
                // Don't update the following fields...
                // 'name_custom',
                // 'display_name_custom',
                // 'icon_custom',
                // 'epg_id',
                // 'user_id',
                // ...only update the following fields
                'lang',
                // 'name', // part of uniqueBy, so won't be updated
                'display_name',
                'icon',
                'channel_id',
                'import_batch_no',
                'additional_display_names',
            ]);
        }
    }
}
