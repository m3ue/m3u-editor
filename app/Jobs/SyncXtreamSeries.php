<?php

namespace App\Jobs;

use App\Models\Playlist;
use App\Services\XtreamService;
use App\Traits\ProviderRequestDelay;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

class SyncXtreamSeries implements ShouldQueue
{
    use ProviderRequestDelay;
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $playlist,
        public int $catId,
        public string $catName,
        public array $series,
        public bool $importAll = false,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(XtreamService $xtream): void
    {
        $playlist = Playlist::findOrFail($this->playlist);
        if (! $playlist->xtream) {
            return;
        }
        $xtream = $xtream->init($playlist);
        if (! $xtream) {
            return;
        }

        // Generate a unique batch number for this import
        $batchNo = Str::orderedUuid()->toString();

        // See if the category exists
        $playlistCategory = $playlist->categories()
            ->where('source_category_id', $this->catId)
            ->first();

        // Create the category if it doesn't exist
        if (! $playlistCategory) {
            $catName = $this->catName;
            $catName = Str::of($catName)->replace(' | ', ' - ')->trim();
            $playlistCategory = $playlist
                ->categories()->create([
                    'name' => $catName,
                    'name_internal' => $catName,
                    'user_id' => $playlist->user_id,
                    'playlist_id' => $playlist->id,
                    'source_category_id' => $this->catId,
                ]);
        }

        if ($this->importAll) {
            // If importAll is true, we need to import all series from the category
            // Use provider throttling to limit concurrent requests and apply delay
            $this->series = $this->withProviderThrottling(fn () => collect($xtream->getSeries($this->catId))
                ->pluck('series_id')
                ->toArray());
        }
        foreach ($this->series as $seriesId) {
            // Check if the series exists for the playlist
            $playlistSeries = $playlist->series()
                ->where('source_series_id', $seriesId)
                ->first();
            if (! $playlistSeries) {
                // Get the series info from the API with provider throttling
                $seriesInfo = $this->withProviderThrottling(fn () => $xtream->getSeriesInfo($seriesId)['info']);

                // Create new series
                $playlistSeries = $playlist->series()->create([
                    'enabled' => true, // Enable the series by default
                    'name' => $seriesInfo['name'],
                    'source_series_id' => $seriesId,
                    'source_category_id' => $this->catId,
                    'import_batch_no' => $batchNo,
                    'user_id' => $playlist->user_id,
                    'playlist_id' => $playlist->id,
                    'category_id' => $playlistCategory->id,
                    'sort' => $seriesInfo['num'] ?? null,
                    'cover' => $seriesInfo['cover'] ?? null,
                    'plot' => $seriesInfo['plot'] ?? null,
                    'genre' => $seriesInfo['genre'] ?? null,
                    'release_date' => $seriesInfo['releaseDate'] ?? $item['release_date'] ?? null,
                    'cast' => $seriesInfo['cast'] ?? null,
                    'director' => $seriesInfo['director'],
                    'rating' => $seriesInfo['rating'] ?? null,
                    'rating_5based' => (float) $seriesInfo['rating_5based'] ?? null,
                    'backdrop_path' => $seriesInfo['backdrop_path'] ?? null,
                    'youtube_trailer' => $seriesInfo['youtube_trailer'] ?? null,
                ]);
            } else {
                // Update the series if it exists
                $playlistSeries->update([
                    'new' => false,
                    'source_category_id' => $this->catId,
                    'import_batch_no' => $batchNo,
                ]);
            }
            if ($playlistSeries->enabled) {
                dispatch(new ProcessM3uImportSeriesEpisodes($playlistSeries));
            }
        }
    }
}
