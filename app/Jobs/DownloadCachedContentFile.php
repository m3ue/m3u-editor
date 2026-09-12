<?php

namespace App\Jobs;

use App\Enums\CachedContentFileStatus;
use App\Models\CachedContentFile;
use App\Models\DynamicGroup;
use App\Services\M3uProxyService;
use App\Services\TmdbService;
use App\Settings\GeneralSettings;
use App\Traits\ProviderRequestDelay;
use GuzzleHttp\RequestOptions;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DownloadCachedContentFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, ProviderRequestDelay, Queueable;

    /**
     * Unused once retryUntil() is defined below (retryUntil takes over the
     * max-attempts check entirely — see Worker::markJobAsFailedIfAlreadyExceedsMaxAttempts).
     * Kept at the framework default only as a defensive fallback.
     */
    public int $tries = 0;

    public int $timeout;

    /**
     * Wall-clock deadline for Step 3's concurrency-throttle release() calls.
     * Fixed at dispatch time (retryUntil() is only invoked once, when the
     * payload is built) so it stays constant across every redelivery of
     * this job. Without this, $tries counts each release() as a failed
     * "attempt" — a job that hits the concurrency gate even once would
     * permanently fail on its very next redelivery. This shipped as
     * `$tries = 1` and produced 1000+ MaxAttemptsExceededException rows in
     * failed_jobs in practice, since every throttled job died this way.
     */
    private Carbon $retryDeadline;

    /**
     * Transient per-job progress state (set in handle(), read by reportDownloadProgress()
     * and Step 8). Horizon workers fork per job, so instance state is safe across the
     * lifetime of a single handle() invocation.
     */
    private ?int $bytesExpected = null;

    private int $lastReportedBytes = 0;

    private ?Carbon $lastProgressAt = null;

    private int $progressThreshold = 1_048_576; // 1 MiB

    private ?CachedContentFile $progressFile = null;

    /**
     * Throttle for checkCancellation()'s Cache::has() call — time-based
     * (not byte-based like $progressThreshold) so a sub-1-MiB download
     * still gets checked at least once, while a multi-GB one doesn't hit
     * Redis on every ~64KB Guzzle progress tick.
     */
    private ?Carbon $lastCancellationCheckAt = null;

    /**
     * Set by checkCancellation() when an operator cancels/deletes the row
     * mid-download. Checked between every 64 KiB read in
     * streamResponseToFile() (real mid-transfer abort — see that method's
     * docblock) and again after Step 6 returns, in case cancellation lands
     * after the last chunk but before Step 7.
     */
    private bool $cancelled = false;

    public function __construct(
        public DynamicGroup $dynamicGroup,
        public string $contentType,
        public ?string $tmdbId,
        public ?string $tvdbId,
        public ?int $seasonNumber,
        public ?int $episodeNumber,
        public ?string $quality,
        public string $sourceUrl,
    ) {
        // Generous timeout — movie files can be multi-GB. Default 1h.
        // Future pass: add a dedicated config('dynamic_group_cache.download_timeout', ...).
        $this->timeout = (int) config('dvr.playlist_download_timeout', 3600);
        $this->retryDeadline = now()->addHours(48);
        $this->onQueue('dynamic-group-cache');
    }

    /**
     * See $retryDeadline docblock — this replaces $tries as the max-attempts
     * gate so concurrency-throttle release()s never count toward a failure.
     */
    public function retryUntil(): Carbon
    {
        return $this->retryDeadline;
    }

    public function handle(GeneralSettings $settings, M3uProxyService $proxy, TmdbService $tmdb): void
    {
        // Step 1: Compute fingerprint
        $fingerprint = CachedContentFile::fingerprintFor([
            'content_type' => $this->contentType,
            'tmdb_id' => $this->tmdbId,
            'tvdb_id' => $this->tvdbId,
            'season_number' => $this->seasonNumber,
            'episode_number' => $this->episodeNumber,
            'quality' => $this->quality,
        ]);

        // Step 2: Cross-playlist/cross-group dedup. If a Completed row already
        // exists for this fingerprint, attach this group and return.
        $existing = CachedContentFile::where('content_fingerprint', $fingerprint)->first();
        if ($existing && $existing->status === CachedContentFileStatus::Completed) {
            $existing->dynamicGroups()->syncWithoutDetaching([$this->dynamicGroup->id]);

            return;
        }

        // Step 3: Concurrency gate (whole-system, not per-playlist)
        $maxConcurrent = (int) ($settings->dynamic_group_cache_max_concurrent_downloads ?? 2);
        $active = CachedContentFile::where('status', CachedContentFileStatus::Downloading)->count();
        if ($active >= $maxConcurrent) {
            $this->release(30);

            return;
        }

        // Step 4: Connection-limit pre-flight against the proxy's active-stream count
        $playlist = $this->dynamicGroup->playlist;
        $availableStreams = $playlist ? (int) $playlist->available_streams : 0;
        if ($availableStreams > 0) {
            $activeStreams = $proxy::getCachedPlaylistActiveStreamsCount($playlist);
            if ($activeStreams >= $availableStreams) {
                $this->release(60);

                return;
            }
        }

        // Step 5: Get-or-create the tracking row and atomically move it to
        // Downloading. DynamicGroupCacheDispatchService::dispatchJob() now
        // creates the row in Pending status immediately at dispatch time (so
        // the activity widget can show queued items before a worker ever
        // picks them up) — reclaimExistingRow()'s Pending branch is what
        // turns that into a live "Downloading" row here. The other branches
        // it handles:
        //  - Failed row past cooldown  → atomically reclaim (retry the download)
        //  - Stale Downloading row     → atomically reclaim (crashed worker recovery)
        //  - Fresh Downloading row     → attach this group, return (another worker
        //                                  is plausibly still on it)
        // The affected-row-count on each reclaim UPDATE acts as a race guard so two
        // workers reclaiming the same row simultaneously don't both proceed.
        //
        // A direct dispatch with no pre-created row (e.g. tests calling the
        // job's handle() directly, or a future caller that bypasses the
        // dispatch service) falls through to the INSERT below. Wrapped in
        // DB::transaction so a unique-constraint failure (lost an INSERT race
        // against a concurrent dispatch for the same fingerprint) only rolls
        // back the inner savepoint and leaves the outer transaction
        // (RefreshDatabase's per-test wrapper, or the queue worker's outer
        // txn) in a valid state for the catch's SELECT below. Postgres aborts
        // the surrounding transaction on ANY failed statement (SQLSTATE
        // 25P02), so this nesting is required there. SQLite is lenient about
        // post-failure statements, which is why the same code passes under
        // sqlite_testing locally.
        $existing = CachedContentFile::where('content_fingerprint', $fingerprint)->first();

        if ($existing) {
            $file = $this->reclaimExistingRow($existing);
            if ($file === null) {
                return;
            }
        } else {
            // No row found — either a direct dispatch with nothing
            // pre-created, or the dispatch-time Pending row was deleted out
            // from under us (operator cancelled it before this worker ever
            // reclaimed it). Cache::pull() so the flag is consumed here and
            // can never suppress a later, unrelated legitimate dispatch for
            // the same fingerprint.
            if (Cache::pull(CachedContentFile::pendingCancellationCacheKey($fingerprint))) {
                return;
            }

            try {
                $file = DB::transaction(fn () => CachedContentFile::create([
                    'content_type' => $this->contentType,
                    'tmdb_id' => $this->tmdbId,
                    'tvdb_id' => $this->tvdbId,
                    'season_number' => $this->seasonNumber,
                    'episode_number' => $this->episodeNumber,
                    'quality' => $this->quality,
                    'content_fingerprint' => $fingerprint,
                    'status' => CachedContentFileStatus::Downloading,
                ]));
            } catch (QueryException $e) {
                $existing = CachedContentFile::where('content_fingerprint', $fingerprint)->first();
                if (! $existing) {
                    // Race we lost AND no row found — permanent skip (defensive).
                    return;
                }

                $file = $this->reclaimExistingRow($existing);
                if ($file === null) {
                    return;
                }
            }
        }

        // Step 5.5: Resolve and persist the TMDB title for the activity widget.
        // - Synchronous (one TMDB HTTP call, ~200ms with built-in rate limiting)
        //   because we already know tmdb_id here and the row is freshly created.
        // - Wrapped in a try/catch — TMDB outage must NEVER block a download.
        // - Skipped if title is already populated (reclaim / Step 2 dedup path).
        $this->resolveAndStoreTitle($file, $tmdb);

        // Step 6: Download to temp file (mirrors ProcessM3uImport.php:460-470 shape)
        // and stream byte-level progress into the row for the Filament progress UI.
        // - RequestOptions::STREAM routes the request through Guzzle's StreamHandler
        //   (native PHP streams, not curl) — see streamResponseToFile()'s docblock
        //   for why that matters for cancellation.
        // - bytes_expected is set from Content-Length if present; -1 (unknown) is
        //   passed to reportDownloadProgress() for chunked responses.
        // - Progress-write failures are swallowed — a missed update must NEVER abort the
        //   actual download. Step 8 stamps the final tally from Storage::size().
        $tempPath = tempnam(sys_get_temp_dir(), 'dgc_');
        $this->progressFile = $file;
        $this->bytesExpected = null;
        $this->lastReportedBytes = 0;

        try {
            $this->withProviderThrottling(function () use ($tempPath) {
                $response = Http::withUserAgent('m3u-editor/'.config('app.version', '0.0'))
                    ->withOptions([
                        RequestOptions::STREAM => true,
                    ])
                    ->timeout($this->timeout)
                    ->throw()
                    ->get($this->sourceUrl);

                $this->streamResponseToFile($response, $tempPath);
            });
        } catch (RequestException|ConnectionException $e) {
            $this->markFailed($file, $e->getMessage());
            @unlink($tempPath);

            return;
        } catch (\Throwable $e) {
            $this->markFailed($file, $e->getMessage());
            @unlink($tempPath);

            return;
        }

        // Operator deleted/cancelled the row while the GET above was in
        // flight. streamResponseToFile() already broke its read loop and
        // closed the connection as soon as checkCancellation() flagged it
        // (real mid-transfer abort — see that method's docblock); this catches
        // the narrow edge case where cancellation landed after the last chunk
        // but before Step 6 returned. The row itself was already removed by
        // DynamicGroupCacheActivityWidget::deleteCachedFile(); don't
        // resurrect it via Step 7/8, just discard the downloaded bytes and
        // let handle() return normally so JobProcessed fires as usual.
        if ($this->cancelled) {
            Cache::forget(CachedContentFile::cancellationCacheKey($file->id));
            Log::info("DownloadCachedContentFile: fingerprint={$fingerprint} cancelled mid-download.");
            @unlink($tempPath);

            return;
        }

        // Step 7: Resolve destination — default disk + path derived from fingerprint.
        // Per-group cache_location_override resolution is left for a later pass; for
        // Phase 2 we only honor the global setting + default disk root.
        $disk = $settings->dynamic_group_cache_location
            ? null // explicit location handling would go here in a later pass
            : config('filesystems.default');

        $extension = pathinfo((string) parse_url($this->sourceUrl, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'mp4';
        $path = 'cache/'.$fingerprint.'.'.$extension;

        try {
            // Stream the temp file into storage instead of loading it into memory.
            // Multi-GB downloads would OOM against file_get_contents() (we hit a 2GB
            // worker memory_limit exactly this way). Laravel's put() accepts a
            // resource and writes it via Flysystem's writeStream, which streams in
            // chunks — peak memory stays bounded regardless of file size.
            $stream = fopen($tempPath, 'rb');
            if ($stream === false) {
                throw new \RuntimeException("Unable to open temp file for reading: {$tempPath}");
            }
            Storage::disk($disk)->put($path, $stream);
        } catch (\Throwable $e) {
            $this->markFailed($file, 'Failed to move downloaded file: '.$e->getMessage());
            @unlink($tempPath);

            return;
        } finally {
            if (isset($stream) && is_resource($stream)) {
                fclose($stream);
            }
        }

        // Step 8: Mark Completed. Use Storage::size() rather than filesize(Storage::path())
        // so the call works against Storage::fake() in tests. bytes_downloaded is also
        // re-stamped from Storage::size() in case the last 1-MiB progress window never
        // crossed the throttle boundary (e.g. a 200 KB clip).
        //
        // The final $file->update() can throw (DB connection lost, constraint
        // violation, etc). Step 7 already wrote the bytes to Storage, so a thrown
        // update leaves a multi-GB orphan: file_path stays null on the row, and
        // DynamicGroupCacheRetentionService::hardDelete() gates Storage cleanup
        // on hasFilePath() (which checks file_path is set). Roll back via
        // rollbackStorageWrite() on any Throwable so a future dispatch re-downloads
        // cleanly instead of leaking the file forever.
        $size = null;
        try {
            $size = Storage::disk($disk)->size($path) ?: null;
        } catch (\Throwable) {
            // file_path no longer exists on disk — leave size null
        }

        try {
            $file->update([
                'status' => CachedContentFileStatus::Completed,
                'disk' => $disk,
                'file_path' => $path,
                'file_size_bytes' => $size,
                'bytes_downloaded' => $size,
                'bytes_expected' => $this->bytesExpected,
                'last_progress_at' => now(),
                'last_verified_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $this->rollbackStorageWrite($disk, $path, $file, $e);
            // Temp file was already consumed by Step 7's storage stream — no
            // unlink needed, but kept the safety net in case Step 7 short-circuited
            // before reading the whole file.
            @unlink($tempPath);

            return;
        }

        // Sync the pivot AFTER the row update succeeded. If syncWithoutDetaching
        // throws here, the row is still Completed with file_path set, so the
        // retention service can clean it up later. No Storage leak risk.
        $file->dynamicGroups()->syncWithoutDetaching([$this->dynamicGroup->id]);
    }

    /**
     * Resolve an existing `cached_content_files` row found for this job's
     * fingerprint into "this worker should proceed" (returns the row,
     * atomically flipped to Downloading) or "another worker already owns
     * it / nothing to do" (attaches this job's dynamic group and returns
     * null so the caller exits without downloading).
     *
     * Shared by both paths that can discover an existing row in Step 5: the
     * upfront SELECT (the common case now that rows are pre-created in
     * Pending status at dispatch time) and the catch-after-failed-INSERT
     * fallback (a direct dispatch lost a race against a concurrent create
     * for the same fingerprint).
     */
    private function reclaimExistingRow(CachedContentFile $existing): ?CachedContentFile
    {
        if ($existing->status === CachedContentFileStatus::Pending) {
            // Nobody else can be "in flight" on a Pending row — any worker
            // that reaches it may claim it. The affected-row-count guard
            // still matters because shouldSkip() permits multiple dispatches
            // for the same fingerprint (e.g. two groups wanting the same
            // movie), so two DownloadCachedContentFile instances can race here.
            $reclaimed = CachedContentFile::where('id', $existing->id)
                ->where('status', CachedContentFileStatus::Pending->value)
                ->update(['status' => CachedContentFileStatus::Downloading->value]);

            if ($reclaimed === 0) {
                $existing->dynamicGroups()->syncWithoutDetaching([$this->dynamicGroup->id]);

                return null;
            }

            return $existing->fresh();
        }

        if ($existing->status === CachedContentFileStatus::Failed) {
            // Cooldown already expired (the dispatcher's shouldSkip() gates this
            // before dispatch). Atomically flip Failed → Downloading using
            // affected-row-count as the race guard.
            $reclaimed = CachedContentFile::where('id', $existing->id)
                ->where('status', CachedContentFileStatus::Failed->value)
                ->update([
                    'status' => CachedContentFileStatus::Downloading->value,
                    'failure_count' => 0,
                    'last_failed_at' => null,
                ]);

            if ($reclaimed === 0) {
                // Another worker won the reclaim race — attach and exit
                $existing->dynamicGroups()->syncWithoutDetaching([$this->dynamicGroup->id]);

                return null;
            }

            return $existing->fresh();
        }

        if ($existing->status === CachedContentFileStatus::Downloading) {
            // Stale = crashed worker. Threshold = job timeout + 5min safety margin.
            $staleThreshold = now()->subSeconds($this->timeout + 300);
            if ($existing->updated_at >= $staleThreshold) {
                // Fresh Downloading — another worker is plausibly still on it
                $existing->dynamicGroups()->syncWithoutDetaching([$this->dynamicGroup->id]);

                return null;
            }

            // WHERE clause pins both status AND updated_at so a concurrent
            // completion/failure can't be silently overwritten.
            $reclaimed = CachedContentFile::where('id', $existing->id)
                ->where('status', CachedContentFileStatus::Downloading->value)
                ->where('updated_at', '<', $staleThreshold)
                ->update([
                    'failure_count' => 0,
                    'last_failed_at' => null,
                ]);

            if ($reclaimed === 0) {
                $existing->dynamicGroups()->syncWithoutDetaching([$this->dynamicGroup->id]);

                return null;
            }

            return $existing->fresh();
        }

        // Completed (Step 2 should have already caught this) or any
        // unexpected state — attach and exit.
        $existing->dynamicGroups()->syncWithoutDetaching([$this->dynamicGroup->id]);

        return null;
    }

    /**
     * Roll back a Step 7 storage write when the Step 8 row update fails.
     *
     * Deletes the file from Storage (best-effort, logs on failure) and marks
     * the row Failed so the dispatcher's failure-cooldown logic governs when
     * the next attempt happens. Without this, the multi-GB file sits in
     * Storage forever — hasFilePath() returns false (file_path is still null
     * because the failed update never wrote it), so the retention service
     * can't find or clean it up.
     */
    private function rollbackStorageWrite(?string $disk, string $path, CachedContentFile $file, \Throwable $cause): void
    {
        try {
            Storage::disk($disk)->delete($path);
        } catch (\Throwable $deleteError) {
            Log::error("DownloadCachedContentFile: ORPHAN at {$path} after Step 8 update failure — Storage::delete also failed: {$deleteError->getMessage()}");
        }

        $this->markFailed($file, 'Step 8 update failed (Storage rolled back): '.$cause->getMessage());
    }

    /**
     * Update the row to Failed, increment failure_count, stamp last_failed_at,
     * and persist the error message onto the row itself.
     *
     * Never throws — wraps any exception so we don't bubble up and trigger Laravel's
     * queue retry (per $tries=1, the dispatcher controls retry timing via the
     * failure-cooldown settings).
     *
     * `last_error_message` is what surfaces in the activity widget's "View error"
     * record action so operators can see WHY a row failed without grepping logs.
     * Truncated to 8000 chars to fit reasonable Postgres row-size budgets while
     * still capturing the meaningful prefix of long Guzzle / Symfony exception
     * messages.
     */
    private function markFailed(CachedContentFile $file, string $reason): void
    {
        try {
            $file->update([
                'status' => CachedContentFileStatus::Failed,
                'last_failed_at' => now(),
                'failure_count' => (int) ($file->failure_count ?? 0) + 1,
                'last_error_message' => mb_substr($reason, 0, 8000),
            ]);
            Log::warning("DownloadCachedContentFile: fingerprint={$file->content_fingerprint} failed: {$reason}");
        } catch (\Throwable $e) {
            Log::error("DownloadCachedContentFile: markFailed itself failed for {$file->content_fingerprint}: {$e->getMessage()}");
        }
    }

    /**
     * Resolve the TMDB title for a freshly-created cached_content_files row and
     * persist it on the row itself. The Filament activity widget reads this
     * column to show "Wicked" instead of "movie: tmdb 860508".
     *
     * Format (matches the widget's getContentLabel() expectations):
     *   movie        → "Wicked"
     *   episode      → "Breaking Bad — I.F.T." (em-dash, space, episode name)
     *                 or "Breaking Bad S01E03" if the season has no episode title
     *   series       → "Breaking Bad"
     *
     * Failures are swallowed + logged at warning level — TMDB being down or
     * rate-limited must never block a download. The widget falls back to the
     * "type: tmdb N" label when title is null.
     */
    private function resolveAndStoreTitle(CachedContentFile $file, TmdbService $tmdb): void
    {
        if ($file->title !== null) {
            return;
        }

        $tmdbId = $file->tmdb_id !== null ? (int) $file->tmdb_id : 0;
        if ($tmdbId <= 0) {
            return;
        }

        $title = null;
        try {
            $title = match ($file->content_type) {
                'movie' => $this->resolveMovieTitle($tmdb, $tmdbId),
                'episode' => $this->resolveEpisodeTitle($tmdb, $tmdbId, $file),
                'series' => $this->resolveSeriesTitle($tmdb, $tmdbId),
                default => null,
            };
        } catch (\Throwable $e) {
            Log::warning("DownloadCachedContentFile: TMDB title lookup failed for fingerprint={$file->content_fingerprint}: ".$e->getMessage());

            return;
        }

        if ($title !== null && $title !== '') {
            $file->update(['title' => $title]);
        }
    }

    private function resolveMovieTitle(TmdbService $tmdb, int $tmdbId): ?string
    {
        $details = $tmdb->getMovieDetails($tmdbId);

        return $details['title'] ?? $details['original_title'] ?? null;
    }

    private function resolveSeriesTitle(TmdbService $tmdb, int $tmdbId): ?string
    {
        $details = $tmdb->getTvSeriesDetails($tmdbId);

        return $details['name'] ?? $details['original_name'] ?? null;
    }

    private function resolveEpisodeTitle(TmdbService $tmdb, int $tmdbId, CachedContentFile $file): ?string
    {
        $seriesName = $this->resolveSeriesTitle($tmdb, $tmdbId);
        if ($seriesName === null) {
            return null;
        }

        $seasonNumber = $file->season_number;
        $episodeNumber = $file->episode_number;
        if ($seasonNumber === null || $episodeNumber === null) {
            return $seriesName;
        }

        $season = $tmdb->getSeasonDetails($tmdbId, $seasonNumber);
        $episodes = $season['episodes'] ?? [];
        $episode = collect($episodes)->firstWhere('episode_number', $episodeNumber);
        $episodeName = is_array($episode) ? ($episode['name'] ?? null) : null;

        if ($episodeName !== null && $episodeName !== '') {
            return "{$seriesName} — {$episodeName}";
        }

        return sprintf('%s S%02dE%02d', $seriesName, $seasonNumber, $episodeNumber);
    }

    /**
     * Read Step 6's response body in 64 KiB chunks, writing each to the temp
     * file and checking for cancellation between reads — this is what makes
     * cancellation a real mid-transfer abort instead of a cooperative
     * discard-after-completion.
     *
     * RequestOptions::STREAM routes the request through Guzzle's
     * StreamHandler (native PHP streams over fopen()) instead of curl —
     * Utils::chooseHandler() wraps the default handler in
     * Proxy::wrapStreaming() specifically so `stream: true` requests bypass
     * curl entirely. That matters here: an earlier version of this method
     * aborted downloads by throwing from inside a CURLOPT_PROGRESSFUNCTION
     * callback, which doesn't unwind as a normal PHP exception — it crashes
     * the worker process, confirmed via a real Horizon run (the Redis queue
     * reservation and the queue_monitor row were both left stuck). Breaking
     * out of this plain PHP while-loop and closing the PSR-7 stream is
     * ordinary control flow: it drops the underlying connection immediately
     * and lets handle() return normally, so Laravel's own JobProcessed path
     * closes everything out the same way a successful download does.
     */
    private function streamResponseToFile(Response $response, string $tempPath): void
    {
        $contentLength = (int) $response->header('Content-Length');
        if ($contentLength > 0) {
            $this->bytesExpected = $contentLength;
        }

        $body = $response->toPsrResponse()->getBody();
        $out = fopen($tempPath, 'wb');
        if ($out === false) {
            throw new \RuntimeException("Unable to open temp file for writing: {$tempPath}");
        }

        $downloaded = 0;

        try {
            while (! $body->eof()) {
                $chunk = $body->read(65536);
                if ($chunk === '') {
                    break;
                }

                fwrite($out, $chunk);
                $downloaded += strlen($chunk);

                $this->reportDownloadProgress($this->bytesExpected ?? -1, $downloaded);

                if ($this->cancelled) {
                    // Stop pulling further bytes now — closing the stream
                    // below drops the connection instead of reading the
                    // transfer out to completion.
                    break;
                }
            }
        } finally {
            fclose($out);
            $body->close();
        }
    }

    /**
     * Throttled progress reporter, called on every 64 KiB chunk read by
     * streamResponseToFile() during Step 6's HTTP GET. We update the DB
     * only when the 1 MiB threshold is crossed (plus once more on the final
     * chunk). $downloadSize is -1 for chunked / no-Content-Length responses.
     *
     * Update failures are swallowed — progress is advisory; a missed write must NEVER
     * abort the actual download.
     */
    public function reportDownloadProgress(int $downloadSize, int $downloaded): void
    {
        if ($this->progressFile === null) {
            return;
        }

        $this->checkCancellation();

        if ($downloadSize > 0 && $this->bytesExpected === null) {
            $this->bytesExpected = $downloadSize;
        }

        if ($downloaded <= 0) {
            return;
        }

        if (($downloaded - $this->lastReportedBytes) < $this->progressThreshold) {
            return;
        }

        try {
            $now = now();
            $bytesPerSecond = $this->computeBytesPerSecond($downloaded, $now);
            $this->progressFile->update([
                'bytes_downloaded' => $downloaded,
                'bytes_expected' => $this->bytesExpected,
                'bytes_per_second' => $bytesPerSecond,
                'last_progress_at' => $now,
            ]);
            $this->lastReportedBytes = $downloaded;
            $this->lastProgressAt = $now;
        } catch (\Throwable) {
            // Swallow: progress is advisory; don't kill the download.
        }
    }

    /**
     * Check whether an operator cancelled this row (deleteCachedFile()) and,
     * if so, set $cancelled — streamResponseToFile()'s read loop checks it
     * after every chunk and breaks immediately, closing the connection
     * instead of reading the transfer out to completion.
     *
     * Time-throttled (not byte-throttled like $progressThreshold) so a
     * sub-64KiB file still gets checked, while a multi-GB one isn't hitting
     * Redis on every chunk.
     */
    private function checkCancellation(): void
    {
        $now = now();
        if ($this->lastCancellationCheckAt !== null && $this->lastCancellationCheckAt->diffInSeconds($now) < 2) {
            return;
        }
        $this->lastCancellationCheckAt = $now;

        if (Cache::has(CachedContentFile::cancellationCacheKey($this->progressFile->id))) {
            $this->cancelled = true;
        }
    }

    /**
     * Compute the bytes/second rate for the just-completed progress window
     * (typically ~1 MiB, gated by the throttle boundary in
     * reportDownloadProgress()).
     *
     * Returns null on the first event (no previous timestamp to subtract from)
     * or when the rate collapses to zero (defensive — would otherwise produce
     * an "infinite" ETA). No EMA / smoothing — the widget polls every 5s and
     * Guzzle's PROGRESS only fires on throttle-boundary crossings, so
     * consecutive samples are already comparable in scale.
     */
    private function computeBytesPerSecond(int $downloaded, Carbon $now): ?int
    {
        if ($this->lastProgressAt === null || $this->lastReportedBytes <= 0) {
            return null;
        }

        $bytesDelta = $downloaded - $this->lastReportedBytes;
        $seconds = max(1, (int) round($this->lastProgressAt->diffInSeconds($now, absolute: true)));

        $rate = (int) round($bytesDelta / $seconds);

        return $rate > 0 ? $rate : null;
    }
}
