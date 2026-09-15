<?php

namespace App\Http\Controllers\Concerns;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Shared HTTP Range + full-stream serving loop for controllers that
 * hand a local file back to the browser. Both `CachedContentStreamController`
 * (Dynamic Group Cache downloads) and `DvrStreamController` (DVR recordings)
 * had near-identical copies of this Range-parse + 206/200 + fopen+fread
 * pattern — extract them here so the chunk size, the partial-content
 * header, and the fclose-on-fail guard stay in lockstep.
 *
 * Mirrored on `DvrStreamController::stream()` and
 * `CachedContentStreamController::stream()`. Both controllers do their
 * own auth/ownership checks before calling `serve()`; this helper
 * trusts the path/size the caller passed in.
 *
 * Returned responses are streamed (`StreamedResponse`), not buffered —
 * multi-MB DVR recordings and GB-class cached movies never sit in PHP
 * memory. Symmetric with the `Action::streamDownload()` antipattern
 * called out in PR #1406 (Filament Action::streamDownload buffers and
 * base64-encodes through Livewire; this helper bypasses Livewire
 * entirely and goes through a plain controller route).
 */
class StreamLocalFile
{
    /**
     * Read/write chunk size. 8 KiB matches fread's default chunk and is
     * small enough to keep memory bounded while still hitting PHP's
     * output buffer in a single call most of the time. Bumping this
     * trades memory for syscalls — keep it modest.
     */
    public const CHUNK_SIZE = 8192;

    /**
     * HTTP Range header regex. Accepts `bytes=N-` (open-ended) and
     * `bytes=N-M` (closed) — the spec form `bytes=N-M,P-Q` (multi-range)
     * is rejected by this single-pass regex; callers asking for a
     * multi-range response should compose a multipart/byteranges
     * response themselves.
     */
    public const RANGE_PATTERN = '/bytes=(\d+)-(\d*)/';

    /**
     * Serve a local file with HTTP Range support.
     *
     * Returns a 206 StreamedResponse when the caller passed a parseable
     * Range header, otherwise a 200 StreamedResponse. The body is
     * streamed through `fopen('rb')` + `fread` chunks so the file never
     * loads into PHP memory.
     *
     * @param  string  $fullPath  Absolute filesystem path to the file to serve.
     * @param  int  $fileSize  Size in bytes — used for the Content-Length /
     *                         Content-Range headers and to bound open-ended
     *                         Range requests.
     * @param  string  $mimeType  Content-Type header value.
     * @param  string  $filename  Filename for the Content-Disposition header
     *                            (no path components — pass `basename($path)`).
     * @param  string|null  $range  Raw `Range` request header. Null/empty/malformed
     *                              → serve the whole file (200).
     */
    public static function serve(
        string $fullPath,
        int $fileSize,
        string $mimeType,
        string $filename,
        ?string $range,
    ): StreamedResponse {
        $parsed = $range !== null ? static::parseRange($range, $fileSize) : null;

        if ($parsed !== null) {
            [$start, $end, $length] = $parsed;

            $headers = [
                'Content-Type' => $mimeType,
                'Content-Length' => $length,
                'Content-Range' => "bytes {$start}-{$end}/{$fileSize}",
                'Accept-Ranges' => 'bytes',
                'Content-Disposition' => 'inline; filename="'.$filename.'"',
            ];

            return response()->stream(static function () use ($fullPath, $start, $length): void {
                $handle = fopen($fullPath, 'rb');
                if ($handle === false) {
                    return;
                }
                fseek($handle, $start);
                $remaining = $length;

                while (! feof($handle) && $remaining > 0) {
                    $chunkSize = min(self::CHUNK_SIZE, $remaining);
                    echo fread($handle, $chunkSize);
                    $remaining -= $chunkSize;
                }

                fclose($handle);
            }, 206, $headers);
        }

        $headers = [
            'Content-Type' => $mimeType,
            'Content-Length' => $fileSize,
            'Accept-Ranges' => 'bytes',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ];

        return response()->stream(static function () use ($fullPath): void {
            $handle = fopen($fullPath, 'rb');
            if ($handle === false) {
                return;
            }

            while (! feof($handle)) {
                echo fread($handle, self::CHUNK_SIZE);
                flush();
            }

            fclose($handle);
        }, 200, $headers);
    }

    /**
     * Parse an HTTP Range header into [start, end, length], or null when
     * the header is absent / malformed. Exposed for testability —
     * `CachedContentStreamControllerTest` exercises it indirectly through
     * the public serve() but direct unit tests are easier against this
     * pure function.
     *
     * The end byte defaults to `fileSize - 1` for open-ended ranges
     * (`bytes=N-`), matching the spec: "If the last-byte-value is absent
     * [...] the response length is calculated as the number of octets
     * remaining in the selected representation".
     *
     * @return array{0: int, 1: int, 2: int}|null
     */
    public static function parseRange(string $range, int $fileSize): ?array
    {
        if (! preg_match(self::RANGE_PATTERN, $range, $matches)) {
            return null;
        }

        $start = (int) $matches[1];
        $end = isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : $fileSize - 1;
        $length = $end - $start + 1;

        return [$start, $end, $length];
    }
}
