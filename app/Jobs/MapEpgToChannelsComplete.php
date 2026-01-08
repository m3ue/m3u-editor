<?php

namespace App\Jobs;

use App\Enums\Status;
use App\Models\Epg;
use App\Models\EpgMap;
use App\Models\Job;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class MapEpgToChannelsComplete implements ShouldQueue
{
    use Queueable;

    public $deleteWhenMissingModels = true;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Epg $epg,
        public int $batchCount,
        public int $channelCount,
        public int $mappedCount,
        public string $batchNo,
        public Carbon $start,
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Calculate the time taken to complete the import
        $completedIn = $this->start->diffInSeconds(now());
        $completedInRounded = round($completedIn, 2);

        // Calculate the actual mapped count from Job records before deletion
        $actualMappedCount = 0;
        if ($this->mappedCount === 0) {
            // Count was not provided, calculate from Job records
            $jobs = Job::where('batch_no', $this->batchNo)->get();
            foreach ($jobs as $job) {
                if (isset($job->payload) && is_array($job->payload)) {
                    $actualMappedCount += count($job->payload);
                }
            }
        } else {
            $actualMappedCount = $this->mappedCount;
        }

        // Clear out the jobs
        Job::where('batch_no', $this->batchNo)->delete();

        // Get the map
        $map = EpgMap::where('uuid', $this->batchNo)->first();

        // Update the map
        if ($map) {
            $map->update([
                'status' => Status::Completed,
                'errors' => null,
                'sync_time' => $completedIn,
                'channel_count' => $this->channelCount,
                'mapped_count' => $actualMappedCount,
                'progress' => 100,
                'processing' => false,
            ]);
        }

        // Notify the user
        $epg = $this->epg;
        $title = 'Completed processing EPG channel mapping';
        $body = "EPG \"{$epg->name}\" channel mapping completed. Mapped {$actualMappedCount} of {$this->channelCount} channels. Mapping took {$completedInRounded} seconds.";
        Notification::make()
            ->success()
            ->title($title)->body($body)
            ->broadcast($epg->user)
            ->sendToDatabase($epg->user);
    }
}
