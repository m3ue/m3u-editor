<?php

namespace App\Jobs;

use App\Enums\Status;
use App\Models\Category;
use App\Models\Playlist;
use App\Models\Series;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use JsonMachine\Items;

class ProcessM3uImportSeriesChunk implements ShouldQueue
{
    use Queueable;

    // Don't retry the job on failure
    public $tries = 1;

    // Giving a timeout of 30 minutes to the Job to process the file
    public $timeout = 60 * 30;

    // Default user agent to use for HTTP requests
    // Used when user agent is not set in the playlist
    public $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36';

    /**
     * Create a new job instance.
     */
    public function __construct(
        public array $payload,
        public int $batchCount,
        public string $batchNo,
        public int $index,
        public bool $autoEnable = false,
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Get the job payload
        $payload = $this->payload;

        $playlistId = $payload['playlistId'] ?? null;
        $sourceCategoryId = $payload['categoryId'] ?? null;
        $sourceCategoryName = $payload['categoryName'] ?? null;

        if (!$sourceCategoryId || !$playlistId) {
            return; // skip if no category or playlist
        }

        // Get the playlist
        $playlist = Playlist::find($playlistId);
        if (!$playlist) {
            return; // skip if no playlist found
        }

        // If this is the first chunk, reset the series progress and notify the user
        // This is to ensure that the series progress is reset for each import
        if ($this->index === 0) {
            // Notify the user that series import is starting
            Notification::make()
                ->info()
                ->title('Syncing Series')
                ->body('Syncing series now. This may take a while depending on the number of series your provider offers.')
                ->broadcast($playlist->user)
                ->sendToDatabase($playlist->user);
            $playlist->update([
                'processing' => [
                    ...$playlist->processing ?? [],
                    'series_processing' => true,
                ],
                'status' => Status::Processing,
                'errors' => null,
                'series_progress' => 0,
            ]);
        }

        // Setup the user agent and SSL verification
        $verify = !$playlist->disable_ssl_verification;
        $userAgent = empty($playlist->user_agent)
            ? $this->userAgent
            : $playlist->user_agent;

        // Get the Xtream config
        $xtreamConfig = $playlist->xtream_config;
        if (!$xtreamConfig) {
            return; // skip if no Xtream config
        }

        // Setup the base url and credentials
        $baseUrl = $xtreamConfig['url'] ?? '';
        $user = $xtreamConfig['username'] ?? '';
        $password = $xtreamConfig['password'] ?? '';
        if (!$baseUrl || !$user || !$password) {
            return; // skip if no base url or credentials
        }

        // Get the series streams for this category
        $seriesStreamsUrl = "$baseUrl/player_api.php?username=$user&password=$password&action=get_series&category_id={$sourceCategoryId}";
        $seriesStreamsResponse = Http::withUserAgent($userAgent)
            ->withOptions(['verify' => $verify])
            ->timeout(60) // set timeout to 1 minute
            ->throw()->get($seriesStreamsUrl);
        if (!$seriesStreamsResponse->ok()) {
            return; // skip this category if there's an error
        }

        $bulk = [];
        $seriesStreams = Items::fromString($seriesStreamsResponse->body());

        // Get the category, or create it if it doesn't exist
        $category = Category::where([
            'playlist_id' => $playlist->id,
            'source_category_id' => $sourceCategoryId,
        ])->first();
        if (!$category) {
            $category = Category::create([
                'name' => $sourceCategoryName,
                'name_internal' => $sourceCategoryName,
                'source_category_id' => $sourceCategoryId,
                'user_id' => $playlist->user_id,
                'playlist_id' => $playlist->id,
            ]);
        }

        // Create the streams
        foreach ($seriesStreams as $item) {
            // Check if we already have this series in the playlist
            $existingSeries = $playlist->series()
                ->where('source_series_id', $item->series_id)
                ->where('source_category_id', $sourceCategoryId)
                ->first();

            if ($existingSeries) {
                // If the series already exists, skip it
                continue;
            }

            // If we reach here, it means we need to create a new series
            $bulk[] = [
                'enabled' => $this->autoEnable, // Disable the series by default
                'name' => $item->name,
                'source_series_id' => $item->series_id,
                'source_category_id' => $sourceCategoryId,
                'import_batch_no' => $this->batchNo,
                'user_id' => $playlist->user_id,
                'playlist_id' => $playlist->id,
                'category_id' => $category->id,
                'sort' => $item->num ?? null,
                'cover' => $item->cover ?? null,
                'plot' => $item->plot ?? null,
                'genre' => $item->genre ?? null,
                'release_date' => $item->releaseDate ?? $item->release_date ?? null,
                'cast' => $item->cast ?? null,
                'director' => $item->director,
                'rating' => $item->rating ?? null,
                'rating_5based' => (float) ($item->rating_5based ?? 0),
                'backdrop_path' => json_encode($item->backdrop_path ?? []),
                'youtube_trailer' => $item->youtube_trailer ?? null,
            ];
        }

        // Update progress
        $playlist->update([
            'series_progress' => min(99, $playlist->series_progress + ($this->batchCount / 100) * 5),
        ]);

        // Bulk insert the series in chunks
        collect($bulk)->chunk(100)->each(fn($chunk) => Series::insert($chunk->toArray()));
    }
}
