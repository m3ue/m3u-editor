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
                if (! isset($channel['name'])) {
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
                ->unique(fn ($item) => $item['source_id'].$item['playlist_id'])
                ->toArray();

            // Upsert the channels
            Channel::upsert($bulk, uniqueBy: ['source_id', 'playlist_id'], update: [
                // Don't update the following fields...
                // 'title_custom',
                // 'name_custom',
                // 'group', // user overridable value
                // 'logo', // user overridable value
                // 'channel', // user overridable value
                // 'stream_id_custom', // user overridable value
                // 'playlist_id', // won't change
                // 'user_id', // won't change
                // 'enabled',
                // 'epg_channel_id',
                // 'new',
                // 'sort',
                // 'station_id', // Gracenote station ID
                // 'source_id', // won't change - for Xtream API this will be the `stream_id`, for M3U it will be a hash of the title, name, group and playlist ID
                // ...only update the following fields
                'url',
                'title', // provider title, update this if it changes
                'name', // provider name, update this if it changes
                'stream_id', // provider stream ID or tvg-id, update this if it changes (for Xtream API this could be `epg_channel_id`)
                'logo_internal', // provider logo path fallback
                'group_internal', // provider group, update if it changes
                'lang', // should we update this? Not sure it's set anywhere...
                'country', // should we update this? Not sure it's set anywhere...
                'import_batch_no',
                'extvlcopt',
                'kodidrop',
                'catchup',
                'catchup_source',
                // 'tvg_shift', // new field for TVG shift (don't update user overridden value)
                'is_vod', // new field for VOD
                'container_extension', // new field for container extension
                'year', // new field for year
                'rating', // new field for rating
                'rating_5based', // new field for 5-based rating
            ]);
        }
    }
}
