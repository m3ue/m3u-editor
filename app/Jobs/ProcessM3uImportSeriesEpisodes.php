<?php

namespace App\Jobs;

use App\Models\Series;
use App\Models\User;
use App\Settings\GeneralSettings;
use App\Traits\ProviderRequestDelay;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessM3uImportSeriesEpisodes implements ShouldQueue
{
    use Queueable;
    use ProviderRequestDelay;

    // Don't retry the job on failure
    public $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public ?Series $playlistSeries = null,
        public bool $notify = true,
        public bool $all_playlists = false,
        public ?int $playlist_id = null,
        public bool $overwrite_existing = false,
        public ?int $user_id = null,
        public ?bool $sync_stream_files = true,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(GeneralSettings $settings): void
    {
        // Get the series
        $series = $this->playlistSeries;

        // Get global sync settings
        $global_sync_settings = [
            'enabled' => $settings->stream_file_sync_enabled ?? false,
        ];

        if ($series) {
            $this->fetchMetadataForSeries($series, $global_sync_settings);
        } else {
            // Disable notifications for bulk processing
            $this->notify = false;

            // Process all series in chunks
            Series::query()
                ->where([
                    ['enabled', true],
                    ['user_id', $this->user_id],
                ])
                ->when($this->playlist_id, function ($query) {
                    $query->where('playlist_id', $this->playlist_id);
                })
                ->with(['playlist'])
                ->chunkById(100, function ($seriesChunk) use ($global_sync_settings) {
                    foreach ($seriesChunk as $series) {
                        $this->fetchMetadataForSeries($series, $global_sync_settings);
                    }
                });

            // Notify the user we're done!
            if ($this->user_id) {
                $user = User::find($this->user_id);
                if ($user) {
                    Notification::make()
                        ->success()
                        ->title("Series Sync Completed")
                        ->body("Series sync completed successfully for all series.")
                        ->broadcast($user)
                        ->sendToDatabase($user);
                }
            }
        }
    }

    private function fetchMetadataForSeries($series, $settings)
    {
        // Get the playlist
        $playlist = $series->playlist;

        // Use provider throttling to limit concurrent requests and apply delay
        $results = $this->withProviderThrottling(function () use ($series) {
            return $series->fetchMetadata(
                refresh: $this->overwrite_existing,
                sync: $this->sync_stream_files
            );
        });

        if ($this->notify && $results !== false) {
            // Check if the playlist has .strm file sync enabled
            $sync_settings = $series->sync_settings;
            $syncStrmFiles = $settings['enabled'] ?? $sync_settings['enabled'] ?? false;
            $body = "Series sync completed successfully for \"{$series->name}\". Imported {$results} episodes.";
            if ($syncStrmFiles) {
                $body .= " .strm file sync is enabled, syncing now.";
            } else {
                $body .= " .strm file sync is not enabled.";
            }
            Notification::make()
                ->success()
                ->title('Series Sync Completed')
                ->body($body)
                ->broadcast($playlist->user)
                ->sendToDatabase($playlist->user);
        }
    }
}
