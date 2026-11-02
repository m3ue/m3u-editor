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

        // A failed or unusable provider response must abort the whole import chain.
        // If we returned here, this category's series would silently miss the current
        // import_batch_no and ProcessM3uImportComplete::seriesCleanup() would then
        // delete every one of them - the "my series vanished and reappeared" churn.
        // Throwing lets the chain ->catch() mark the run failed and leaves existing
        // data untouched for the next sync.
        if (! $seriesStreamsResponse->ok()) {
            throw new \RuntimeException(
                "get_series returned HTTP {$seriesStreamsResponse->status()} for category {$sourceCategoryId} (playlist {$playlistId})"
            );
        }

        // Guard against providers that return a non-JSON body (e.g. a PNG error image)
        // with a 200 OK status. JsonMachine would throw a SyntaxError on the first byte,
        // but abort explicitly here for the same reason as above - a partial series
        // import must never be allowed to land.
        $firstChar = ltrim($seriesStreamsResponse->body())[0] ?? '';
        if ($firstChar !== '[' && $firstChar !== '{') {
            Log::warning('ProcessM3uImportSeriesChunk: Non-JSON response for series category, aborting import', [
                'source_category_id' => $sourceCategoryId,
                'playlist_id' => $playlistId,
                'content_preview' => substr($seriesStreamsResponse->body(), 0, 12),
            ]);

            throw new \RuntimeException(
                "get_series returned a non-JSON body for category {$sourceCategoryId} (playlist {$playlistId})"
            );
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

        $existingCovers = $playlist->series()
            ->where('source_category_id', $sourceCategoryId)
            ->pluck('cover', 'source_series_id');

        $insertBuffer = [];
        $listedExistingIds = [];
        $listedAnySeries = false;

        foreach (Items::fromString($seriesStreamsResponse->body()) as $item) {
            $itemName = trim((string) ($item->name ?? $item->title ?? ''));
            if ($itemName === '') {
                continue;
            }

            // Drop malformed provider rows that carry no series_id. Importing one
            // creates a Series with a null source_series_id, which later blows up
            // the series_metadata phase (XtreamService::getSeriesInfo(string) is
            // handed null -> TypeError). This is rejecting garbage, not a partial
            // sync, so skip the row and keep importing the rest of the category.
            if (blank($item->series_id ?? null)) {
                Log::warning('ProcessM3uImportSeriesChunk: series entry has no series_id, skipping', [
                    'source_category_id' => $sourceCategoryId,
                    'playlist_id' => $playlistId,
                    'name' => $itemName,
                ]);

                continue;
            }

            $listedAnySeries = true;

            $lastModified = isset($item->last_modified) && $item->last_modified
                ? Carbon::createFromTimestamp((int) $item->last_modified)->toDateTimeString()
                : null;

            if ($existingSeriesIds->has($item->series_id)) {
                $listedExistingIds[] = $item->series_id;

                // Already in DB — only update last_modified and the provider cover if they changed
                $changes = [];
                $storedModified = $existingSeriesIds->get($item->series_id);
                if ($lastModified && $lastModified !== $storedModified) {
                    $changes['last_modified'] = $lastModified;
                }

                $refreshedCover = $this->refreshedProviderCover($existingCovers->get($item->series_id), $item->cover ?? null);
                if ($refreshedCover !== null) {
                    $changes['cover'] = $refreshedCover;
                }

                if ($changes !== []) {
                    $playlist->series()
                        ->where('source_series_id', $item->series_id)
                        ->where('source_category_id', $sourceCategoryId)
                        ->update($changes);
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

        $this->stampListedSeries($playlist, $sourceCategoryId, $listedExistingIds, $listedAnySeries);

        // Update progress: scale 0→100 across all chunks. Intermediate chunks are
        // capped at 99 so the UI doesn't show 100% until the final chunk completes.
        $isLastChunk = $this->index === $this->batchCount - 1;
        $chunkProgress = $isLastChunk
            ? 100
            : (int) round(($this->index + 1) / max(1, $this->batchCount) * 99);

        $update = ['series_progress' => $chunkProgress];

        if ($isLastChunk) {
            $update['processing'] = [
                ...$playlist->processing ?? [],
                'series_processing' => false,
            ];
        }

        $playlist->update($update);
    }

    /**
     * The provider's new cover for a series already in the DB, or null to keep the stored one.
     *
     * Some providers sign cover URLs with a short expiry, so the cover saved when the series was
     * first imported stops loading a few days later. The listing carries a fresh URL, so take it
     * when the stored cover is blank or comes from the same image host. A cover from another host
     * (TMDB, or one set by hand) is left alone.
     */
    private function refreshedProviderCover(?string $storedCover, mixed $providerCover): ?string
    {
        $providerCover = is_string($providerCover) ? trim($providerCover) : '';
        if ($providerCover === '' || $providerCover === $storedCover || ! filter_var($providerCover, FILTER_VALIDATE_URL)) {
            return null;
        }

        if (blank($storedCover)) {
            return $providerCover;
        }

        $storedHost = parse_url($storedCover, PHP_URL_HOST);
        $providerHost = parse_url($providerCover, PHP_URL_HOST);

        return is_string($storedHost) && is_string($providerHost) && strcasecmp($storedHost, $providerHost) === 0
            ? $providerCover
            : null;
    }

    /**
     * Stamp the series the provider still lists with this sync's batch number.
     *
     * ProcessM3uImportComplete::seriesCleanup() removes every series in a surviving category
     * that did not get the current batch number, the same way channels are retired, so a series
     * the provider removed (or re-added under a new series_id) does not linger as a duplicate.
     *
     * A category that came back with no usable series is treated as a provider blip rather than
     * as every series having been removed: all of its existing series are kept.
     *
     * @param  list<int|string>  $listedExistingIds  series_ids already in the DB that the listing returned
     */
    private function stampListedSeries(Playlist $playlist, mixed $sourceCategoryId, array $listedExistingIds, bool $listedAnySeries): void
    {
        if (! $listedAnySeries) {
            $kept = $playlist->series()
                ->where('source_category_id', $sourceCategoryId)
                ->update(['import_batch_no' => $this->batchNo]);

            if ($kept > 0) {
                Log::warning('ProcessM3uImportSeriesChunk: category returned no series, keeping its existing series', [
                    'source_category_id' => $sourceCategoryId,
                    'playlist_id' => $playlist->id,
                    'kept_series' => $kept,
                ]);
            }

            return;
        }

        foreach (array_chunk($listedExistingIds, 500) as $ids) {
            $playlist->series()
                ->where('source_category_id', $sourceCategoryId)
                ->whereIn('source_series_id', $ids)
                ->update(['import_batch_no' => $this->batchNo]);
        }
    }

    /**
     * Drop rows whose series_id already exists in the playlist under another category.
     *
     * A series the provider moved to this category is moved here too, keeping its enabled state
     * and everything attached to it. One already stamped by an earlier category this sync (listed
     * under several categories) stays where it is, so it never flips between them on each sync.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>> The rows that really are new.
     */
    private function adoptExistingSeries(array $rows, Playlist $playlist, Category $category, mixed $sourceCategoryId): array
    {
        $existingIds = $playlist->series()
            ->whereIn('source_series_id', array_column($rows, 'source_series_id'))
            ->pluck('source_series_id')
            ->map(fn ($id) => (string) $id)
            ->all();

        if ($existingIds === []) {
            return $rows;
        }

        $playlist->series()
            ->whereIn('source_series_id', $existingIds)
            ->where('import_batch_no', '!=', $this->batchNo)
            ->update([
                'category_id' => $category->id,
                'source_category_id' => $sourceCategoryId,
                'import_batch_no' => $this->batchNo,
            ]);

        return array_values(array_filter(
            $rows,
            fn (array $row): bool => ! in_array((string) $row['source_series_id'], $existingIds, true)
        ));
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

                $rows = $this->adoptExistingSeries($rows, $playlist, $category, $sourceCategoryId);

                if ($rows !== []) {
                    Series::insertOrIgnore(
                        array_map(fn ($row) => $row + ['category_id' => $category->id], $rows)
                    );
                }
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
