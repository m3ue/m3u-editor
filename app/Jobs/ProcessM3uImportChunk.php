<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Job;
use App\Models\Playlist;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessM3uImportChunk implements ShouldQueue
{
    use Queueable;

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
                $playlist = Playlist::find($job->variables['playlistId']);
                $playlist->update([
                    'progress' => min(99, $playlist->progress + (($chunkSize / $totalJobsCount) * 100)),
                ]);
            }

            // Add the channel for insert/update
            $groupId = $job->variables['groupId'];
            $groupName = $job->variables['groupName'];
            foreach ($job->payload as $channel) {
                // Make sure name is set
                if (!isset($channel['name'])) {
                    continue;
                }

                // Add the channel for insert/update
                $bulk[] = [
                    ...$channel,
                    'group' => $groupName ?? null,
                    'group_id' => $groupId ?? null,
                ];
            }

            // Deduplicate the channels
            $bulk = collect($bulk)
                ->unique(function ($item) {
                    return $item['title'] . $item['name'] . $item['group_internal'] . $item['playlist_id'];
                })->toArray();

            // Upsert the channels
            Channel::upsert($bulk, uniqueBy: ['title', 'name', 'group_internal', 'playlist_id'], update: [
                // Don't update the following fields...
                // 'title',
                // 'name',
                // 'group', // user override
                // 'group_internal',
                // 'playlist_id',
                // 'user_id',
                // 'logo', // user override
                // 'channel', // user override
                // 'enabled',
                // 'epg_channel_id',
                // 'new'
                // ...only update the following fields
                'sort',
                'url',
                'stream_id',
                'lang', // should we update this? Not sure it's set anywhere...
                'country', // should we update this? Not sure it's set anywhere...
                'import_batch_no',
                'extvlcopt',
                'kodidrop',
                'catchup',
                'catchup_source',
                'tvg_shift', // new field for TVG shift
                'is_vod', // new field for VOD
            ]);
        }
    }
}
