<?php

namespace App\Http\Controllers\Concerns;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Shared HTTP Range + full-stream serving loop for controllers that
 * hand a local file back to the browser. Used by CachedContentStreamController
 * (cached content), DvrStreamController (DVR recordings) and
 * MediaServerProxyController (local media integrations).
 *
 * Returned responses are streamed (`StreamedResponse`), not buffered -
 * multi-MB DVR recordings and GB-class cached movies never sit in PHP
 * memory. Symmetric with the `Action::streamDownload()` antipattern
 * (Filament Action::streamDownload buffers and base64-encodes through
 * Livewire; this helper bypasses Livewire entirely and goes through a
 * plain controller route).
 *
 * Range semantics (RFC 7233):
 *  - No Range header  -> 200, full body
 *  - Unparseable Range -> 200, full body (spec: ignore malformed Range)
 *  - Out-of-bounds range -> 416 with Content-Range: bytes wildcard slash size
 *  - Valid range -> 206 with Content-Range: bytes start dash end slash size
 */
class StreamLocalFile
{
    /**
     * Read/write chunk size. 8 KiB matches fread's default chunk and is
     * small enough to keep memory bounded while still hitting PHP's
     * output buffer in a single call most of the time. Bumping this
     * trades memory for syscalls - keep it modest.
     */
    public const CHUNK_SIZE = 8192;

    /**
     * HTTP Range header regex. Anchored to `^bytes=...$` so leading/trailing
     * junk (and the multi-range form `bytes=N-M,P-Q`, which is currently
     * unsupported) falls through to the full-body 200 path. Accepts the three
     * RFC 7233 single-range forms:
     *  - Open-ended: `bytes=N-`   (group 1 = N,  group 2 = null)
     *  - Closed:     `bytes=N-M`  (group 1 = N,  group 2 = M)
     *  - Suffix:     `bytes=-M`   (group 1 = null, group 2 = M)
     *
     * Callers asking for a multi-range response should compose a
     * multipart/byteranges response themselves.
     */
    public const RANGE_PATTERN = '/^bytes=(\d+)?-(\d+)?$/';

    /**
     * Serve a local file with HTTP Range support.
     *
     * Returns a 206 StreamedResponse for a valid Range request,
     * a 416 StreamedResponse for a Range request that's syntactically
     * valid but out-of-bounds, and a 200 StreamedResponse when no
     * Range header was sent (or it was unparseable).
     *
     * @param  string  $fullPath  Absolute filesystem path to the file to serve.
     * @param  int  $fileSize  Size in bytes.
     * @param  string  $mimeType  Content-Type header value.
     * @param  string  $filename  Filename for the Content-Disposition header
     *                            (no path components - pass `basename($path)`).
     * @param  string|null  $range  Raw `Range` request header. Null/empty
     *                              -> serve the whole file (200). Malformed
     *                              -> also serve the whole file (200).
     *                              Out-of-bounds -> 416.
     * @param  array<string, string>  $extraHeaders  Additional headers merged into
     *                                               the 200/206 response.
     * @param  int  $chunkSize  Bytes read and flushed per iteration.
     */
    public static function serve(
        string $fullPath,
        int $fileSize,
        string $mimeType,
        string $filename,
        ?string $range,
        array $extraHeaders = [],
        int $chunkSize = self::CHUNK_SIZE,
    ): StreamedResponse {
        $headers = [
            'Content-Type' => $mimeType,
            'Accept-Ranges' => 'bytes',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            ...$extraHeaders,
        ];

        if ($range !== null && $range !== '' && preg_match(self::RANGE_PATTERN, $range) === 1) {
            try {
                [$start, $end, $length] = static::parseRangeOrThrow($range, $fileSize);
            } catch (InvalidRangeException) {
                return static::rangeNotSatisfiableResponse($fileSize, $mimeType, $filename);
            }

            return response()->stream(
                static fn () => static::streamBytes($fullPath, $start, $length, $chunkSize),
                206,
                [...$headers, 'Content-Length' => $length, 'Content-Range' => "bytes {$start}-{$end}/{$fileSize}"],
            );
        }

        return response()->stream(
            static fn () => static::streamBytes($fullPath, 0, $fileSize, $chunkSize),
            200,
            [...$headers, 'Content-Length' => $fileSize],
        );
    }

    /**
     * Echo $length bytes of the file starting at $start, flushing each chunk
     * and stopping early once the client disconnects.
     */
    private static function streamBytes(string $fullPath, int $start, int $length, int $chunkSize): void
    {
        $handle = fopen($fullPath, 'rb');
        if ($handle === false) {
            return;
        }

        fseek($handle, $start);
        $remaining = $length;

        while ($remaining > 0 && ! feof($handle) && connection_status() === CONNECTION_NORMAL) {
            $data = fread($handle, min($chunkSize, $remaining));
            if ($data === false || $data === '') {
                break;
            }

            echo $data;
            flush();
            $remaining -= strlen($data);
        }

        fclose($handle);
    }

    /**
     * Parse an HTTP Range header into [start, end, length], or null when
     * the header is malformed / absent. Exposed for testability - the
     * direct unit tests cover the "syntactically OK but not Range" case.
     *
     * Per RFC 7233 an unparseable Range header is treated as if the
     * header was absent - we return null so the caller falls through to
     * the 200 full-body path.
     *
     * @return array{0: int, 1: int, 2: int}|null
     */
    public static function parseRange(string $range, int $fileSize): ?array
    {
        if (! preg_match(self::RANGE_PATTERN, $range, $matches)) {
            return null;
        }

        try {
            return static::parseRangeOrThrow($range, $fileSize);
        } catch (InvalidRangeException) {
            return null;
        }
    }

    /**
     * Parse a Range header strictly. Throws `InvalidRangeException` when
     * the parsed range is out-of-bounds, so the caller can return a
     * proper 416 instead of silently clamping to the file size.
     *
     * Handles the three RFC 7233 single-range forms accepted by
     * RANGE_PATTERN. Suffix `bytes=-N` resolves to `start = max(0, size - N)`,
     * so callers probing the last N bytes (typical MP4/MKV trailer fetch)
     * get a real 206 instead of a silently widened 200.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    public static function parseRangeOrThrow(string $range, int $fileSize): array
    {
        if ($fileSize <= 0) {
            throw new InvalidRangeException('Empty file cannot be ranged.');
        }

        if (! preg_match(self::RANGE_PATTERN, $range, $matches)) {
            throw new InvalidRangeException("Malformed Range header: {$range}");
        }

        // Optional capture groups may not populate their array key when
        // they match the empty string -- guard with isset() before
        // dereferencing, otherwise PHP warns "undefined array key N".
        $hasStart = isset($matches[1]) && $matches[1] !== '';
        $hasEnd = isset($matches[2]) && $matches[2] !== '';

        if (! $hasStart && ! $hasEnd) {
            throw new InvalidRangeException("Range header has neither start nor end: {$range}");
        }

        // Suffix form: bytes=-N  (e.g. player probing trailing MP4/MKV metadata).
        if (! $hasStart && $hasEnd) {
            $suffixLength = (int) $matches[2];
            if ($suffixLength === 0) {
                throw new InvalidRangeException('Suffix length 0 is not satisfiable.');
            }
            $start = max(0, $fileSize - $suffixLength);
            $end = $fileSize - 1;

            return [$start, $end, $end - $start + 1];
        }

        $start = (int) $matches[1];
        // Open-ended (bytes=N-) -> end = fileSize - 1 per RFC 7233 §2.1.
        $end = $hasEnd ? (int) $matches[2] : $fileSize - 1;

        if ($start < 0 || $start >= $fileSize) {
            throw new InvalidRangeException("Range start {$start} outside 0..".($fileSize - 1));
        }
        if ($end < $start) {
            throw new InvalidRangeException("Range end {$end} precedes start {$start}");
        }
        $end = min($end, $fileSize - 1);

        return [$start, $end, $end - $start + 1];
    }

    /**
     * Build a 416 Range Not Satisfiable response. Empty body per the
     * spec - the client learns the file size from the Content-Range
     * header (bytes wildcard, then slash, then total size).
     */
    private static function rangeNotSatisfiableResponse(int $fileSize, string $mimeType, string $filename): StreamedResponse
    {
        $headers = [
            'Content-Type' => $mimeType,
            'Content-Range' => "bytes */{$fileSize}",
            'Accept-Ranges' => 'bytes',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ];

        return response()->stream(static function (): void {
            // 416 has no body per RFC 7233.
        }, 416, $headers);
    }
}
