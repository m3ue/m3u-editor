<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\EpgMap;
use App\Models\Job;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class MapEpgToChannels implements ShouldQueue
{
    use Queueable;

    public $deleteWhenMissingModels = true;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public array $jobs,
        public int $batchCount,
        public string $batchNo,
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
        $map = EpgMap::where('uuid', $this->batchNo)->first();

        // Process the jobs
        foreach (Job::whereIn('id', $this->jobs)->cursor() as $index => $job) {
            $bulk = [];
            if ($index % $chunkSize === 0) {
                $map->update([
                    'progress' => min(99, $map->progress + (($chunkSize / $totalJobsCount) * 100)),
                ]);
            }

            // Add the channel for insert/update
            foreach ($job->payload as $channel) {
                // Add the channel for insert/update
                $bulk[] = [
                    ...$channel,
                ];
            }

            // Deduplicate the channels
            $bulk = collect($bulk)
                ->unique(fn ($item) => $item['source_id'].$item['playlist_id'])
                ->toArray();

            // Upsert the channels
            Channel::upsert($bulk, uniqueBy: ['source_id', 'playlist_id'], update: [
                'epg_channel_id', // this is the only field we want to update...
            ]);
        }
    }
}
