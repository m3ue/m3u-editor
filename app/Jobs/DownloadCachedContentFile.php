<?php

namespace App\Jobs;

use App\Enums\CachedContentFileStatus;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Services\M3uProxyService;
use App\Settings\GeneralSettings;
use App\Support\PrivateNetworkGuard;
use App\Traits\ProviderRequestDelay;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Download one cached_content_files row's source (a VOD channel or series
 * episode) onto the `cache` disk.
 *
 * - One download per playlist at a time (WithoutOverlapping keyed by
 *   playlist), and the job waits while the playlist is at its
 *   `available_streams` limit, so background downloads don't take the
 *   provider connections live viewers need.
 * - Uses the playlist's User-Agent and the item's custom URL override.
 * - Every hop (initial URL and each redirect) is checked by
 *   PrivateNetworkGuard and pinned with CURLOPT_RESOLVE.
 * - Streams straight into `<path>.part` on the cache disk, then renames it
 *   into place, so there is no second copy in the system temp dir.
 * - Byte progress is written every 1 MiB; cancellation is polled between
 *   chunks via `CachedContentFile::cancellationCacheKey()`.
 * - `failed()` marks the row Failed when the job times out or runs out of
 *   attempts, so rows are never left stuck in Downloading.
 */
class DownloadCachedContentFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, ProviderRequestDelay, Queueable;

    /**
     * Real failures (exceptions) allowed before giving up. Releases while
     * waiting for a free playlist slot don't count toward this.
     */
    public int $maxExceptions = 3;

    /**
     * Movie files can be multi-GB.
     */
    public int $timeout = 3600;

    /**
     * A Downloading row with no progress for this long is treated as
     * abandoned (worker crashed) and can be reclaimed.
     */
    public const STALE_DOWNLOAD_SECONDS = 600;

    /**
     * Seconds to wait before trying again when the playlist is busy.
     */
    private const BUSY_RELEASE_SECONDS = 120;

    private const DEFAULT_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36';

    private const ALLOWED_EXTENSIONS = ['mp4', 'm4v', 'mkv', 'ts', 'avi', 'webm'];

    private int $progressThreshold = 1_048_576;

    private int $cancellationCheckIntervalSeconds = 2;

    private int $readChunkSize = 65_536;

    private int $maxRedirects = 5;

    private ?int $bytesExpected = null;

    private int $lastReportedBytes = 0;

    private ?Carbon $lastProgressAt = null;

    private ?CachedContentFile $progressFile = null;

    private ?Carbon $lastCancellationCheckAt = null;

    private bool $cancelled = false;

    public function __construct(public int $cachedContentFileId)
    {
        $this->onQueue('cache');
    }

    /**
     * Keep attempting (through busy-playlist releases) for a day.
     */
    public function retryUntil(): Carbon
    {
        return now()->addDay();
    }

    /**
     * Backoff between attempts that failed with an exception.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    /**
     * Serialize downloads per playlist.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        $playlistId = CachedContentFile::query()->whereKey($this->cachedContentFileId)->value('playlist_id');

        return [
            (new WithoutOverlapping('cached-content-download:playlist:'.($playlistId ?? 'none')))
                ->releaseAfter(self::BUSY_RELEASE_SECONDS)
                ->expireAfter($this->timeout + 300),
        ];
    }

    public function handle(): void
    {
        $file = CachedContentFile::find($this->cachedContentFileId);
        if (! $file) {
            // Deleted (or cancelled while Pending) before we got to it.
            return;
        }

        if (! (bool) (app(GeneralSettings::class)->enable_cache ?? false)) {
            $this->markFailed($file, 'Caching is disabled.');

            return;
        }

        $item = $file->cacheable;
        if (! $item instanceof Channel && ! $item instanceof Episode) {
            $this->markFailed($file, 'The source channel or episode no longer exists.');

            return;
        }

        $playlist = $file->playlist;
        if (! $playlist instanceof Playlist) {
            $this->markFailed($file, 'The source playlist no longer exists.');

            return;
        }

        if ($this->playlistAtCapacity($playlist)) {
            $this->release(self::BUSY_RELEASE_SECONDS);

            return;
        }

        $file = $this->claim($file);
        if ($file === null) {
            return;
        }

        $this->progressFile = $file;
        $this->checkCancellation();
        if ($this->cancelled) {
            $this->markCancelled($file, 'Cancelled before download started.');

            return;
        }

        $url = $item->cacheSourceUrl();
        if ($url === '') {
            $this->markFailed($file, 'Source URL is empty.');

            return;
        }

        try {
            PrivateNetworkGuard::assertUrlSafe($url);
        } catch (InvalidArgumentException $e) {
            $this->markFailed($file, 'Source URL rejected (private network guard): '.$e->getMessage());

            return;
        }

        $disk = Storage::disk(CachedContentFile::DISK);
        $relativePath = $file->storagePathFor($this->resolveExtension($url, $item));
        $partialPath = $relativePath.'.part';

        try {
            $response = $this->withProviderThrottling(
                fn (): Response => $this->fetchWithSafeRedirects($url, $playlist)
            );

            $contentType = strtolower((string) $response->header('Content-Type'));
            if (str_contains($contentType, 'mpegurl')) {
                $response->toPsrResponse()->getBody()->close();
                $this->markFailed($file, 'The source is an HLS stream, which cannot be cached as a single file.');

                return;
            }

            $this->streamResponseToDisk($response, $disk, $partialPath);
        } catch (InvalidArgumentException $e) {
            $disk->delete($partialPath);
            $this->markFailed($file, 'Redirect rejected (private network guard): '.$e->getMessage());

            return;
        } catch (RequestException|ConnectionException $e) {
            $disk->delete($partialPath);
            $this->markFailed($file, 'HTTP error: '.$e->getMessage());

            throw $e;
        } catch (Throwable $e) {
            $disk->delete($partialPath);
            $this->markFailed($file, 'Unexpected error: '.$e->getMessage());

            throw $e;
        }

        $this->checkCancellation(force: true);
        if ($this->cancelled) {
            $disk->delete($partialPath);
            $this->markCancelled($file, 'Cancelled mid-download.');

            return;
        }

        $size = (int) ($disk->size($partialPath) ?: 0);
        if ($size < 1) {
            $disk->delete($partialPath);
            $this->markFailed($file, 'The provider returned an empty file.');

            return;
        }

        if (! $disk->move($partialPath, $relativePath)) {
            $disk->delete($partialPath);
            $this->markFailed($file, 'Could not move the downloaded file into place.');

            return;
        }

        $file->forceFill([
            'status' => CachedContentFileStatus::Completed,
            'disk' => CachedContentFile::DISK,
            'file_path' => $relativePath,
            'file_size_bytes' => $size,
            'bytes_downloaded' => $size,
            'bytes_expected' => $this->bytesExpected,
            'last_progress_at' => now(),
            'last_verified_at' => now(),
            'failure_count' => 0,
            'last_failed_at' => null,
            'last_error_message' => null,
        ])->save();
    }

    /**
     * Called by the queue when the job times out or exhausts its attempts.
     */
    public function failed(?Throwable $exception): void
    {
        $file = CachedContentFile::find($this->cachedContentFileId);
        if (! $file || $file->status === CachedContentFileStatus::Completed) {
            return;
        }

        $file->deleteStoredFile();
        Storage::disk(CachedContentFile::DISK)->delete($file->storagePathFor($this->resolveExtensionFromFile($file)).'.part');

        if ($file->status !== CachedContentFileStatus::Failed) {
            $this->markFailed($file, $exception?->getMessage() ?: 'Download job failed.');
        }
    }

    /**
     * Whether the playlist's connection limit is already used up by live
     * streams. Unlimited playlists (available_streams = 0) never are.
     */
    private function playlistAtCapacity(Playlist $playlist): bool
    {
        $limit = (int) ($playlist->available_streams ?? 0);
        if ($limit <= 0) {
            return false;
        }

        return M3uProxyService::getPlaylistActiveStreamsCount($playlist) >= $limit;
    }

    /**
     * Atomically move the row to Downloading. Pending and Failed rows can be
     * claimed; so can a Downloading row whose worker went quiet for
     * STALE_DOWNLOAD_SECONDS. Returns null if someone else owns it.
     */
    private function claim(CachedContentFile $file): ?CachedContentFile
    {
        $staleBefore = now()->subSeconds(self::STALE_DOWNLOAD_SECONDS);

        $claimed = DB::table('cached_content_files')
            ->where('id', $file->id)
            ->where(function ($q) use ($staleBefore): void {
                $q->whereIn('status', [CachedContentFileStatus::Pending->value, CachedContentFileStatus::Failed->value])
                    ->orWhere(function ($stale) use ($staleBefore): void {
                        $stale->where('status', CachedContentFileStatus::Downloading->value)
                            ->where('updated_at', '<', $staleBefore);
                    });
            })
            ->update([
                'status' => CachedContentFileStatus::Downloading->value,
                'bytes_downloaded' => 0,
                'bytes_per_second' => null,
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            Log::info("DownloadCachedContentFile: row {$file->id} is already Downloading/Completed or was deleted.");

            return null;
        }

        return $file->fresh();
    }

    /**
     * GET with redirects disabled, following each Location manually so every
     * hop passes the private network guard.
     */
    private function fetchWithSafeRedirects(string $url, Playlist $playlist): Response
    {
        $userAgent = $playlist->user_agent ?: self::DEFAULT_USER_AGENT;
        $current = $url;

        for ($i = 0; $i <= $this->maxRedirects; $i++) {
            $resolvedIp = PrivateNetworkGuard::assertUrlSafe($current);
            $parts = parse_url($current);
            $host = (string) $parts['host'];
            $port = (int) ($parts['port'] ?? (strtolower((string) $parts['scheme']) === 'https' ? 443 : 80));

            $response = Http::withUserAgent($userAgent)
                ->timeout($this->timeout)
                ->withOptions([
                    'stream' => true,
                    'allow_redirects' => false,
                    'curl' => [
                        CURLOPT_RESOLVE => [trim($host, '[]').":{$port}:{$resolvedIp}"],
                    ],
                ])
                ->throw()
                ->get($current);

            $status = $response->status();
            $location = $response->header('Location');
            if ($status < 300 || $status >= 400 || ! $location) {
                return $response;
            }

            $current = (string) UriResolver::resolve(Utils::uriFor($current), Utils::uriFor($location));
        }

        throw new RuntimeException("Exceeded {$this->maxRedirects} redirects while fetching {$url}.");
    }

    /**
     * Read the response body in chunks straight into the partial file on the
     * cache disk, reporting progress and polling cancellation.
     */
    private function streamResponseToDisk(Response $response, Filesystem $disk, string $partialPath): void
    {
        $contentLength = (int) $response->header('Content-Length');
        $this->bytesExpected = $contentLength > 0 ? $contentLength : null;

        $directory = dirname($partialPath);
        if ($directory !== '.' && ! $disk->exists($directory)) {
            $disk->makeDirectory($directory);
        }

        $out = fopen($disk->path($partialPath), 'wb');
        if ($out === false) {
            throw new RuntimeException("Unable to open {$partialPath} for writing on the cache disk.");
        }

        $body = $response->toPsrResponse()->getBody();
        $downloaded = 0;

        try {
            while (! $body->eof()) {
                $chunk = $body->read($this->readChunkSize);
                if ($chunk === '') {
                    break;
                }

                $written = fwrite($out, $chunk);
                if ($written === false || $written !== strlen($chunk)) {
                    throw new RuntimeException("Write to the cache disk failed after {$downloaded} bytes (disk full?).");
                }
                $downloaded += $written;

                $this->reportDownloadProgress($downloaded);

                if ($this->cancelled) {
                    break;
                }
            }

            $this->reportDownloadProgress($downloaded, force: true);
        } finally {
            fclose($out);
            $body->close();
        }
    }

    /**
     * Persist byte progress every `progressThreshold` bytes. Failures are
     * swallowed; progress is advisory.
     */
    private function reportDownloadProgress(int $downloaded, bool $force = false): void
    {
        if ($this->progressFile === null) {
            return;
        }

        $this->checkCancellation();

        if ($downloaded <= 0 || $downloaded === $this->lastReportedBytes) {
            return;
        }

        if (! $force && ($downloaded - $this->lastReportedBytes) < $this->progressThreshold) {
            return;
        }

        try {
            $now = now();
            $this->progressFile->forceFill([
                'bytes_downloaded' => $downloaded,
                'bytes_expected' => $this->bytesExpected,
                'bytes_per_second' => $this->computeBytesPerSecond($downloaded, $now),
                'last_progress_at' => $now,
            ])->save();
            $this->lastReportedBytes = $downloaded;
            $this->lastProgressAt = $now;
        } catch (Throwable) {
            // Progress is advisory.
        }
    }

    /**
     * Poll the cancellation flag (time-throttled unless forced).
     */
    private function checkCancellation(bool $force = false): void
    {
        if ($this->progressFile === null) {
            return;
        }

        $now = now();
        if (! $force && $this->lastCancellationCheckAt !== null
            && $this->lastCancellationCheckAt->diffInSeconds($now, absolute: true) < $this->cancellationCheckIntervalSeconds) {
            return;
        }
        $this->lastCancellationCheckAt = $now;

        if (Cache::has(CachedContentFile::cancellationCacheKey($this->progressFile->id))) {
            $this->cancelled = true;
        }
    }

    private function computeBytesPerSecond(int $downloaded, Carbon $now): ?int
    {
        if ($this->lastProgressAt === null || $this->lastReportedBytes <= 0) {
            return null;
        }

        $seconds = max(1, (int) round($this->lastProgressAt->diffInSeconds($now, absolute: true)));
        $rate = (int) round(($downloaded - $this->lastReportedBytes) / $seconds);

        return $rate > 0 ? $rate : null;
    }

    /**
     * Move the row to Failed and record why.
     */
    protected function markFailed(CachedContentFile $file, string $reason): void
    {
        try {
            $file->forceFill([
                'status' => CachedContentFileStatus::Failed,
                'failure_count' => ($file->failure_count ?? 0) + 1,
                'last_failed_at' => now(),
                'last_error_message' => mb_substr($reason, 0, 8000),
            ])->save();
            Log::warning("DownloadCachedContentFile failed for row {$file->id}: {$reason}");
        } catch (Throwable $e) {
            Log::error("DownloadCachedContentFile: markFailed itself failed for {$file->id}: {$e->getMessage()}");
        }
    }

    /**
     * Clear the cancellation flag and, if the row still exists, mark it
     * Failed ("Cancelled") without counting it as a failure.
     */
    private function markCancelled(CachedContentFile $file, string $reason): void
    {
        Cache::forget(CachedContentFile::cancellationCacheKey($file->id));

        CachedContentFile::whereKey($file->id)->update([
            'status' => CachedContentFileStatus::Failed->value,
            'last_failed_at' => now(),
            'last_error_message' => mb_substr($reason, 0, 8000),
        ]);
    }

    /**
     * Pick the file extension: the URL's, then the item's container
     * extension, then mp4.
     */
    private function resolveExtension(string $url, Channel|Episode $item): string
    {
        $fromUrl = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        if (in_array($fromUrl, self::ALLOWED_EXTENSIONS, true)) {
            return $fromUrl;
        }

        $container = strtolower((string) $item->container_extension);

        return in_array($container, self::ALLOWED_EXTENSIONS, true) ? $container : 'mp4';
    }

    /**
     * Best-effort extension for cleaning up a partial file in failed().
     */
    private function resolveExtensionFromFile(CachedContentFile $file): string
    {
        $item = $file->cacheable;

        return $item instanceof Channel || $item instanceof Episode
            ? $this->resolveExtension($item->cacheSourceUrl(), $item)
            : 'mp4';
    }
}
