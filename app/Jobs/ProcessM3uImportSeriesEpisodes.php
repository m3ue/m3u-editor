<?php

namespace App\Jobs;

use App\Models\Episode;
use App\Models\Playlist;
use App\Models\Series;
use App\Services\XtreamService;
use Illuminate\Support\Str;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessM3uImportSeriesEpisodes implements ShouldQueue
{
    use Queueable;

    // Don't retry the job on failure
    public $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Series $playlistSeries,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(XtreamService $xtream): void
    {
        // Initialize the Xtream API
        if (!$this->playlistSeries) {
            return;
        }

        $playlist = $this->playlistSeries->playlist;
        $xtream = $xtream->init($playlist);
        if (!$xtream) {
            Notification::make()
                ->danger()
                ->title('Series Sync Failed')
                ->body('Series has been deleted and no longer exists, unable to sync.')
                ->broadcast($playlist->user)
                ->sendToDatabase($playlist->user);
            return;
        }

        // Setup variables and get the series info
        $batchNo = Str::orderedUuid()->toString();
        $detail = $xtream->getSeriesInfo($this->playlistSeries->source_series_id);
        $info = $detail['info'] ?? [];
        $eps = $detail['episodes'] ?? [];

        // Skip if the episodes are empty
        if (count($eps) === 0) {
            Notification::make()
                ->warning()
                ->title('Series Sync Completed')
                ->body("No episodes found for \"{$this->playlistSeries->name}\".")
                ->broadcast($playlist->user)
                ->sendToDatabase($playlist->user);
            return;
        }

        // Process the series episodes
        $playlistCategory = $this->playlistSeries->category;
        $episodeCount = 0;
        foreach ($eps as $season => $episodes) {
            // Check if the season exists in the playlist
            $playlistSeason = $this->playlistSeries->seasons()
                ->where('season_number', $season)
                ->first();

            if (!$playlistSeason) {
                // Create the season if it doesn't exist
                $seasonInfo = $info['seasons'][$season] ?? [];
                $playlistSeason = $this->playlistSeries->seasons()->create([
                    'season_number' => $season,
                    'name' => "Season " . str_pad($season, 2, '0', STR_PAD_LEFT),
                    'source_season_id' => $seasonInfo['id'] ?? null,
                    'episode_count' => $seasonInfo['episode_count'] ?? 0,
                    'cover' => $seasonInfo['cover'] ?? null,
                    'cover_big' => $seasonInfo['cover_big'] ?? null,
                    'user_id' => $playlist->user_id,
                    'playlist_id' => $playlist->id,
                    'series_id' => $this->playlistSeries->id,
                    'category_id' => $playlistCategory->id,
                    'import_batch_no' => $batchNo,
                ]);
            } else {
                // Update the season if it exists
                $playlistSeason->update([
                    'new' => false,
                    'source_season_id' => $seasonInfo['id'] ?? null,
                    'category_id' => $playlistCategory->id,
                    'episode_count' => $seasonInfo['episode_count'] ?? 0,
                    'cover' => $seasonInfo['cover'] ?? null,
                    'cover_big' => $seasonInfo['cover_big'] ?? null,
                    'series_id' => $this->playlistSeries->id,
                    'import_batch_no' => $batchNo,
                ]);
            }

            // Process each episode in the season
            $bulk = [];
            foreach ($episodes as $ep) {
                $episodeCount++;
                $url = $xtream->buildSeriesUrl($ep['id'], $ep['container_extension']);
                $title = preg_match('/S\d{2}E\d{2} - (.*)/', $ep['title'], $m) ? $m[1] : null;
                if (!$title) {
                    $title = $ep['title'] ?? "Episode {$ep['episode_num']}";
                }
                $bulk[] = [
                    'title' => $title,
                    'source_episode_id' => (int) $ep['id'],
                    'import_batch_no' => $batchNo,
                    'user_id' => $playlist->user_id,
                    'playlist_id' => $playlist->id,
                    'series_id' => $this->playlistSeries->id,
                    'season_id' => $playlistSeason->id,
                    'episode_num' => (int) $ep['episode_num'],
                    'container_extension' => $ep['container_extension'],
                    'custom_sid' => $ep['custom_sid'] ?? null,
                    'added' => $ep['added'] ?? null,
                    'season' => (int) $season,
                    'url' => $url,
                    'info' => json_encode([
                        'release_date' => $ep['info']['release_date'] ?? null,
                        'plot' => $ep['info']['plot'] ?? null,
                        'duration_secs' => $ep['info']['duration_secs'] ?? null,
                        'duration' => $ep['info']['duration'] ?? null,
                        'movie_image' => $ep['info']['movie_image'] ?? null,
                        'bitrate' => $ep['info']['bitrate'] ?? 0,
                        'rating' => $ep['info']['rating'] ?? null,
                        'season' => $ep['info']['season'] ?? null,
                        'tmdb_id' => $ep['info']['tmdb_id'] ?? null,
                        'cover_big' => $ep['info']['cover_big'] ?? null,
                    ]),
                ];
            }

            // Upsert the episodes in bulk
            Episode::upsert(
                $bulk,
                uniqueBy: ['source_episode_id', 'playlist_id'],
                update: [
                    'title',
                    'import_batch_no',
                    'episode_num',
                    'container_extension',
                    'custom_sid',
                    'added',
                    'season',
                    'url',
                    'info'
                ]
            );
        }

        // Check if the playlist has .strm file sync enabled
        $sync_settings = $this->playlistSeries->sync_settings;
        $syncStrmFiles = $sync_settings['enabled'] ?? false;
        if ($syncStrmFiles) {
            // Dispatch the job to sync .strm files
            dispatch(new SyncSeriesStrmFiles(series: $this->playlistSeries));
        }
        $body = "Series sync completed successfully for \"{$this->playlistSeries->name}\". Imported {$episodeCount} episodes.";
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
