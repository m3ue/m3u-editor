<?php

namespace App\Jobs;

use App\Models\Series;
use App\Settings\GeneralSettings;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncSeriesStrmFiles implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Series $series,
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(GeneralSettings $settings): void
    {
        // Get all the series episodes
        $series = $this->series;
        try {
            // Get playlist sync settings
            $sync_settings = $series->sync_settings;

            // Get global sync settings
            $global_sync_settings = [
                'enabled' => $settings->stream_file_sync_enabled ?? false,
                'include_series' => $settings->stream_file_sync_include_series ?? true,
                'include_season' => $settings->stream_file_sync_include_season ?? true,
                'sync_location' => $series->sync_location ?? $settings->stream_file_sync_location ?? null,
            ];

            // Merge global settings with series specific settings
            $sync_settings = array_merge($global_sync_settings, $sync_settings ?? []);

            // Check if sync is enabled
            if (!$sync_settings['enabled'] ?? false) {
                Notification::make()
                    ->danger()
                    ->title("Error sync .strm files for series \"{$series->name}\"")
                    ->body("Sync is not enabled for this series.")
                    ->broadcast($series->user)
                    ->sendToDatabase($series->user);
                return;
            }

            // Get the series episodes
            $episodes = $series->episodes;

            // Check if there are any episodes
            if ($episodes->isEmpty()) {
                Notification::make()
                    ->danger()
                    ->title("Error sync .strm files for series \"{$series->name}\"")
                    ->body("No episodes found for this series. Try processing it first.")
                    ->broadcast($series->user)
                    ->sendToDatabase($series->user);
                return;
            }

            // Get the path info
            $path = rtrim($sync_settings['sync_location'], '/');
            if (!is_dir($path)) {
                Notification::make()
                    ->danger()
                    ->title("Error sync .strm files for series \"{$series->name}\"")
                    ->body("Sync location \"{$path}\" does not exist.")
                    ->broadcast($series->user)
                    ->sendToDatabase($series->user);
                return;
            }

            // See if the series is enabled, if not, skip, else create the folder
            if ($sync_settings['include_series'] ?? true) {
                // Create the series folder
                // Remove any special characters from the series name
                $cleanName = preg_replace('/[^a-zA-Z0-9_\-]/', ' ', $series->name);
                $path .= '/' . $cleanName;
                if (!is_dir($path)) {
                    mkdir($path, 0777, true);
                }
            }

            // Loop through each episode
            foreach ($episodes as $ep) {
                // Setup episode prefix
                $season = $ep->season;
                $num = str_pad($ep->episode_num, 2, '0', STR_PAD_LEFT);
                $prefx = "S" . str_pad($season, 2, '0', STR_PAD_LEFT) . "E{$num}";

                // Create the .strm file
                $fileName = "{$prefx} - {$ep->title}.strm";

                // Create the season folder
                if ($sync_settings['include_season'] ?? true) {
                    $seasonPath = $path . '/Season ' . str_pad($season, 2, '0', STR_PAD_LEFT);
                    if (!is_dir($seasonPath)) {
                        mkdir($seasonPath, 0777, true);
                    }
                    $filePath = $seasonPath . '/' . $fileName;
                } else {
                    $filePath = $path . '/' . $fileName;
                }

                // Check if the file already exists
                if (file_exists($filePath)) {
                    // If the file exists, check if the URL is the same
                    $currentUrl = file_get_contents($filePath);
                    if ($currentUrl === $ep->url) {
                        // Skip if the URL is the same
                        continue;
                    }
                }
                file_put_contents($filePath, $ep->url);
            }

            // Notify the user
            Notification::make()
                ->success()
                ->title("Sync .strm files for series \"{$series->name}\"")
                ->body("Sync completed for series \"{$series->name}\".")
                ->broadcast($series->user)
                ->sendToDatabase($series->user);
        } catch (\Exception $e) {
            Notification::make()
                ->danger()
                ->title("Error sync .strm files for series \"{$series->name}\"")
                ->body("Error: {$e->getMessage()}")
                ->broadcast($series->user)
                ->sendToDatabase($series->user);
        }
    }
}
