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
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
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

        // Guard against providers that return a non-JSON body (e.g. a PNG error image)
        // with a 200 OK status. JsonMachine will throw a SyntaxError on the first byte
        // if the body is not JSON, which would crash the entire import chain.
        $firstChar = ltrim($seriesStreamsResponse->body())[0] ?? '';
        if ($firstChar !== '[' && $firstChar !== '{') {
            Log::warning('ProcessM3uImportSeriesChunk: Non-JSON response for series category, skipping', [
                'source_category_id' => $sourceCategoryId,
                'playlist_id' => $playlistId,
                'content_preview' => substr($seriesStreamsResponse->body(), 0, 12),
            ]);

            return;
        }

        // Single-pass stream: pluck existing rows once for O(1) lookup, then iterate
        // the JSON stream and immediately route each item to an update or a rolling
        // insert buffer — never accumulating more than 100 rows at a time.
        //
        // Keyed by source_series_id → last_modified. Use has() (not get()) for the
        // existence check so that series whose last_modified is NULL are not mistakenly
        // treated as new on every sync (get() returns null for both missing keys and
        // keys with a null value).
        $existingSeriesIds = $playlist->series()
            ->where('source_category_id', $sourceCategoryId)
            ->pluck('last_modified', 'source_series_id');

        $insertBuffer = [];

        foreach (Items::fromString($seriesStreamsResponse->body()) as $item) {
            $itemName = trim((string) ($item->name ?? $item->title ?? ''));
            if ($itemName === '') {
                continue;
            }

            $lastModified = isset($item->last_modified) && $item->last_modified
                ? Carbon::createFromTimestamp((int) $item->last_modified)->toDateTimeString()
                : null;

            if ($existingSeriesIds->has($item->series_id)) {
                // Already in DB — only update last_modified if it changed
                $storedModified = $existingSeriesIds->get($item->series_id);
                if ($lastModified && $lastModified !== $storedModified) {
                    $playlist->series()
                        ->where('source_series_id', $item->series_id)
                        ->where('source_category_id', $sourceCategoryId)
                        ->update(['last_modified' => $lastModified]);
                }

                continue;
            }

            $insertBuffer[] = [
                'enabled' => $this->autoEnable,
                'name' => $itemName,
                'source_series_id' => $item->series_id,
                'source_category_id' => $sourceCategoryId,
                'import_batch_no' => $this->batchNo,
                'user_id' => $playlist->user_id,
                'playlist_id' => $playlist->id,
                'sort' => $item->num ?? null,
                'cover' => $item->cover ?? null,
                'plot' => $item->plot ?? null,
                'genre' => $item->genre ?? null,
                'release_date' => $item->releaseDate ?? $item->release_date ?? null,
                'cast' => $item->cast ?? null,
                'director' => $item->director ?? null,
                'rating' => $item->rating ?? null,
                'rating_5based' => (float) ($item->rating_5based ?? 0),
                'backdrop_path' => json_encode($item->backdrop_path ?? []),
                'youtube_trailer' => $item->youtube_trailer ?? null,
                'last_modified' => $lastModified,
            ];

            if (count($insertBuffer) === 100) {
                $this->flushInsertBuffer($insertBuffer, $playlist, $sourceCategoryId, $sourceCategoryName);
                $insertBuffer = [];
            }
        }

        if ($insertBuffer !== []) {
            $this->flushInsertBuffer($insertBuffer, $playlist, $sourceCategoryId, $sourceCategoryName);
        }

        // Update progress: scale 0→99 across all chunks using index position
        $chunkProgress = (int) round(($this->index + 1) / max(1, $this->batchCount) * 99);
        $playlist->update([
            'series_progress' => min(99, $chunkProgress),
        ]);
    }

    /**
     * Insert a buffer of new series rows inside a retryable transaction.
     *
     * The category is resolved inside the transaction so it always holds the
     * correct import_batch_no. This prevents seriesCleanup in a concurrent sync
     * from deleting the category row while the INSERT is in flight (which would
     * cause a series_category_id_foreign FK violation). SQLSTATE 23503 is caught
     * and logged without rethrowing so a transient race doesn't abort the chain.
     */
    private function flushInsertBuffer(array $rows, Playlist $playlist, mixed $sourceCategoryId, ?string $sourceCategoryName): void
    {
        try {
            DB::transaction(function () use ($rows, $playlist, $sourceCategoryId, $sourceCategoryName) {
                $category = Category::firstOrCreate(
                    ['playlist_id' => $playlist->id, 'source_category_id' => $sourceCategoryId],
                    [
                        'name' => $sourceCategoryName,
                        'name_internal' => $sourceCategoryName,
                        'user_id' => $playlist->user_id,
                        'import_batch_no' => $this->batchNo,
                    ]
                );

                Series::insertOrIgnore(
                    array_map(fn ($row) => $row + ['category_id' => $category->id], $rows)
                );
            }, 5);
        } catch (QueryException $e) {
            // SQLSTATE 23503 = foreign_key_violation (PostgreSQL).
            // Occurs when a concurrent sync's seriesCleanup deletes the category between
            // transaction retries. Log and skip — series are re-imported on next sync.
            if ($e->getCode() === '23503') {
                Log::warning('Series insert skipped: category deleted by concurrent sync', [
                    'source_category_id' => $sourceCategoryId,
                    'playlist_id' => $playlist->id,
                    'error' => $e->getMessage(),
                ]);

                return;
            }

            Log::error('Series bulk insert failed', [
                'exception' => $e->getMessage(),
                'source_category_id' => $sourceCategoryId,
            ]);

            throw $e;
        }
    }
}
