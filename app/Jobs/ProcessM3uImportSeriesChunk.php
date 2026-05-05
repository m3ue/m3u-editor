<?php

namespace App\Jobs;

use App\Enums\Status;
use App\Models\Category;
use App\Models\Playlist;
use App\Models\Series;
use App\Traits\ProviderRequestDelay;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonMachine\Items;

class ProcessM3uImportSeriesChunk implements ShouldQueue
{
    use ProviderRequestDelay;
    use Queueable;

    // Don't retry the job on failure
    public $tries = 1;

    // Giving a timeout of 30 minutes to the Job to process the file
    public $timeout = 60 * 30;

    /** Default user agent used when the playlist has none configured. */
    public string $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36';

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
        $playlistId = $this->payload['playlistId'] ?? null;
        $sourceCategoryId = $this->payload['categoryId'] ?? null;
        $sourceCategoryName = $this->payload['categoryName'] ?? null;

        if (! $sourceCategoryId || ! $playlistId) {
            return;
        }

        $playlist = Playlist::find($playlistId);
        if (! $playlist) {
            return;
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
        $verify = ! $playlist->disable_ssl_verification;
        $userAgent = $playlist->user_agent ?: $this->userAgent;

        $xtreamConfig = $playlist->xtream_config;
        if (! $xtreamConfig) {
            return;
        }

        $baseUrl = $xtreamConfig['url'] ?? '';
        $user = $xtreamConfig['username'] ?? '';
        $password = $xtreamConfig['password'] ?? '';
        if (! $baseUrl || ! $user || ! $password) {
            return;
        }

        // Get the series streams for this category with provider throttling
        $seriesStreamsUrl = "$baseUrl/player_api.php?username=$user&password=$password&action=get_series&category_id={$sourceCategoryId}";
        $seriesStreamsResponse = $this->withProviderThrottling(fn () => Http::withUserAgent($userAgent)
            ->withOptions(['verify' => $verify])
            ->timeout(60) // set timeout to 1 minute
            ->throw()->get($seriesStreamsUrl));
        if (! $seriesStreamsResponse->ok()) {
            return; // skip this category if there's an error
        }

        $bulk = [];
        $seriesStreams = Items::fromString($seriesStreamsResponse->body());

        // Get the category for this import, creating it if it doesn't yet exist
        $category = Category::firstOrCreate(
            ['playlist_id' => $playlist->id, 'source_category_id' => $sourceCategoryId],
            [
                'name' => $sourceCategoryName,
                'name_internal' => $sourceCategoryName,
                'user_id' => $playlist->user_id,
            ]
        );

        // Create the streams
        foreach ($seriesStreams as $item) {
            // Normalize the name — some providers omit it which violates the DB NOT NULL constraint
            $itemName = trim((string) ($item->name ?? $item->title ?? ''));
            if ($itemName === '') {
                continue;
            }

            // Check if we already have this series in the playlist
            $existingSeries = $playlist->series()
                ->where('source_series_id', $item->series_id)
                ->where('source_category_id', $sourceCategoryId)
                ->first();

            $lastModified = isset($item->last_modified) && $item->last_modified
                ? Carbon::createFromTimestamp((int) $item->last_modified)->toDateTimeString()
                : null;

            if ($existingSeries) {
                if ($lastModified) {
                    $existingSeries->update(['last_modified' => $lastModified]);
                }

                continue;
            }

            $bulk[] = [
                'enabled' => $this->autoEnable, // Disable the series by default
                'name' => $itemName,
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
                'last_modified' => $lastModified,
            ];
        }

        // Update progress
        $playlist->update([
            'series_progress' => min(99, $playlist->series_progress + ($this->batchCount / 100) * 5),
        ]);

        // Bulk insert the series in chunks with logging on failure
        collect($bulk)->chunk(100)->each(function ($chunk) {
            try {
                Series::insertOrIgnore($chunk->toArray());
            } catch (\Throwable $e) {
                Log::error('Series bulk insert failed', [
                    'exception' => $e->getMessage(),
                    'chunk' => $chunk->toArray(),
                ]);

                throw $e;
            }
        });
    }
}
