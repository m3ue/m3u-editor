<?php

namespace App\Jobs;

use App\Models\EpgChannel;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class EpgChannelFindAndReplaceReset implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $user_id, // The ID of the user who owns the EPG
        public string $column,
        public ?Collection $channels = null,
        public ?bool $all_epgs = false,
        public ?int $epg_id = null,
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Clock the time
        $start = now();
        $customColumn = $this->column.'_custom';
        $totalUpdated = 0;

        // Process channels in chunks for better performance
        if (! $this->channels) {
            // Use chunking to process large datasets efficiently
            EpgChannel::query()
                ->when(! $this->all_epgs && $this->epg_id, fn ($query) => $query->where('epg_id', $this->epg_id))
                ->whereNotNull($customColumn) // Only get channels that have custom values to reset
                ->chunkById(1000, function ($channels) use ($customColumn, &$totalUpdated) {
                    // Get IDs of channels to update
                    $channelIds = $channels->pluck('id')->toArray();

                    if (count($channelIds) > 0) {
                        // Batch update all channels in this chunk
                        $updated = DB::table('epg_channels')
                            ->whereIn('id', $channelIds)
                            ->update([
                                $customColumn => null,
                                'updated_at' => now(),
                            ]);

                        $totalUpdated += $updated;
                    }
                });
        } else {
            // Process the provided collection in chunks
            $this->channels
                ->filter(fn ($channel) => $channel->{$customColumn} !== null) // Only channels with custom values
                ->chunk(1000)
                ->each(function ($chunk) use ($customColumn, &$totalUpdated) {
                    $channelIds = $chunk->pluck('id')->toArray();

                    if (count($channelIds) > 0) {
                        // Batch update all channels in this chunk
                        $updated = DB::table('epg_channels')
                            ->whereIn('id', $channelIds)
                            ->update([
                                $customColumn => null,
                                'updated_at' => now(),
                            ]);

                        $totalUpdated += $updated;
                    }
                });
        }

        // Notify the user we're done!
        $completedIn = $start->diffInSeconds(now());
        $completedInRounded = round($completedIn, 2);
        $user = User::find($this->user_id);

        Notification::make()
            ->success()
            ->title('Find & Replace reset')
            ->body("Epg Channel find & replace reset has completed successfully. {$totalUpdated} epg channels updated.")
            ->broadcast($user);
        Notification::make()
            ->success()
            ->title('Find & Replace reset completed')
            ->body("Epg Channel find & replace reset has completed successfully. {$totalUpdated} epg channels updated in {$completedInRounded} seconds")
            ->sendToDatabase($user);
    }
}
