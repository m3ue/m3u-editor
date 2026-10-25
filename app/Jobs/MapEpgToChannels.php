<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\EpgMap;
use App\Models\Job;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Arr;

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

        if (! $map) {
            return;
        }

        // Process the jobs
        foreach (Job::whereIn('id', $this->jobs)->cursor() as $index => $job) {
            if ($index % $chunkSize === 0) {
                $map->update([
                    'progress' => min(99, $map->progress + (($chunkSize / $totalJobsCount) * 100)),
                ]);
            }

            // Custom channels have no source_id/playlist_id. The upsert below can't
            // match them (NULLs never conflict on the unique index), so it would
            // insert orphan rows instead of linking them; update those by id.
            [$custom, $imported] = collect($job->payload)
                ->partition(fn (array $item): bool => blank($item['source_id'] ?? null) || blank($item['playlist_id'] ?? null));

            $this->updateById($custom->all());

            // Deduplicate the channels ("|" keeps e.g. 1|23 and 12|3 apart)
            $bulk = $imported
                ->map(fn (array $item): array => Arr::except($item, ['id']))
                ->unique(fn (array $item): string => $item['source_id'].'|'.$item['playlist_id'].'|'.(int) ($item['is_vod'] ?? false))
                ->values()
                ->all();

            if ($bulk === []) {
                continue;
            }

            // Upsert the channels
            Channel::upsert($bulk, uniqueBy: ['source_id', 'playlist_id', 'is_vod'], update: [
                'epg_channel_id',
                'logo_type',
            ]);
        }
    }

    /**
     * Link channels that can't be upserted (custom channels) by their own id.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function updateById(array $rows): void
    {
        // Group rows that get the same values into one UPDATE each.
        collect($rows)
            ->filter(fn (array $item): bool => filled($item['id'] ?? null)) // rows queued before ids were included
            ->groupBy(fn (array $item): string => $item['epg_channel_id'].'|'.$item['logo_type'])
            ->each(function ($group): void {
                $first = $group->first();

                Channel::query()
                    ->whereKey($group->pluck('id')->unique()->all())
                    ->update([
                        'epg_channel_id' => $first['epg_channel_id'],
                        'logo_type' => $first['logo_type'],
                    ]);
            });
    }
}
