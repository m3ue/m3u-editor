<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ChannelFindAndReplaceReset implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $user_id, // The ID of the user who owns the playlist
        public string $column,
        public ?Collection $channels = null,
        public ?bool $all_playlists = false,
        public ?int $playlist_id = null,
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

        // Need to treat some columns differently
        switch ($this->column) {
            case 'logo':
                // Resetting the logo column, `logo_internal` is the default, `logo` is the override
                $customColumn = 'logo';
                break;
            default:
                // Most will use the same name appended with `_custom`
                // e.g. `name_custom` for `name`
                // or `title_custom` for `title`
                $customColumn = $this->column.'_custom';
        }
        $totalUpdated = 0;

        // Process channels in chunks for better performance
        if (! $this->channels) {
            // Use chunking to process large datasets efficiently
            Channel::query()
                ->where('user_id', $this->user_id)
                ->when(! $this->all_playlists && $this->playlist_id, fn ($query) => $query->where('playlist_id', $this->playlist_id))
                ->whereNotNull($customColumn) // Only get channels that have custom values to reset
                ->chunkById(1000, function ($channels) use ($customColumn, &$totalUpdated) {
                    // Get IDs of channels to update
                    $channelIds = $channels->pluck('id')->toArray();

                    if (count($channelIds) > 0) {
                        // Batch update all channels in this chunk
                        $updated = DB::table('channels')
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
                        $updated = DB::table('channels')
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
            ->body("Channel find & replace reset has completed successfully. {$totalUpdated} channels updated.")
            ->broadcast($user);
        Notification::make()
            ->success()
            ->title('Find & Replace reset completed')
            ->body("Channel find & replace reset has completed successfully. {$totalUpdated} channels updated in {$completedInRounded} seconds")
            ->sendToDatabase($user);
    }
}
