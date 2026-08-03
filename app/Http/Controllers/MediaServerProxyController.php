<?php

namespace App\Http\Controllers;

use App\Facades\ProxyFacade;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\MediaServerIntegration;
use App\Models\Scopes\ExcludeAioFailoverClonesScope;
use App\Services\MediaServerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * MediaServerProxyController - Secure proxy for Emby/Jellyfin content
 *
 * This controller proxies requests to media servers, hiding the API key
 * from external clients (IPTV players). Similar to SchedulesDirectImageProxyController.
 */
class MediaServerProxyController extends Controller
{
    /**
     * Proxy an image from the media server.
     *
     * Route: /media-server/{integrationId}/image/{itemId}/{imageType?}
     *
     * @param  int  $integrationId  The integration ID
     * @param  string  $itemId  The media server's item ID
     * @param  string  $imageType  The image type (Primary, Backdrop, Logo, etc.)
     * @return Response|StreamedResponse
     */
    public function proxyImage(Request $request, int $integrationId, string $itemId, string $imageType = 'Primary')
    {
        if ($staleResponse = $this->rejectIfStaleUrlVersion($request)) {
            return $staleResponse;
        }

        try {
            $integration = MediaServerIntegration::find($integrationId);

            if (! $integration) {
                return response()->json(['error' => 'Integration not found'], 404);
            }

            if (! $integration->enabled) {
                return response()->json(['error' => 'Integration is disabled'], 403);
            }

            // Build cache key for this image
            $cacheKey = "media_server_image_{$integrationId}_{$itemId}_{$imageType}";

            // Check cache first (cache for 24 hours)
            $cachedResponse = Cache::get($cacheKey);
            if ($cachedResponse) {
                return response($cachedResponse['body'], 200, $cachedResponse['headers']);
            }

            $mediaServer = MediaServerService::make($integration);
            $imageUrl = $mediaServer->getDirectImageUrl($itemId, $imageType);

            // Fetch the image with authentication
            $response = Http::withHeaders([
                'Accept' => 'image/*',
            ])->timeout(30)->get($imageUrl);

            if ($response->successful()) {
                $body = $response->body();
                $contentType = $response->header('Content-Type', 'image/jpeg');

                // Prepare headers for the proxied response
                $headers = [
                    'Content-Type' => $contentType,
                    'Content-Length' => strlen($body),
                    'Cache-Control' => 'public, max-age=86400', // Cache for 24 hours
                    'X-Proxied-From' => 'MediaServer',
                ];

                // Cache the successful response for 24 hours
                Cache::put($cacheKey, [
                    'body' => $body,
                    'headers' => $headers,
                ], now()->addHours(24));

                Log::debug('Successfully proxied media server image', [
                    'integration_id' => $integrationId,
                    'item_id' => $itemId,
                    'image_type' => $imageType,
                    'content_type' => $contentType,
                    'size_bytes' => strlen($body),
                ]);

                return response($body, 200, $headers);
            }

            Log::warning('Failed to fetch media server image', [
                'integration_id' => $integrationId,
                'item_id' => $itemId,
                'image_type' => $imageType,
                'status' => $response->status(),
            ]);

            return response()->json([
                'error' => 'Failed to fetch image from media server',
                'status' => $response->status(),
            ], $response->status());
        } catch (\Exception $e) {
            Log::error('Exception in media server image proxy', [
                'integration_id' => $integrationId,
                'item_id' => $itemId,
                'image_type' => $imageType,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Internal server error while proxying image',
            ], 500);
        }
    }

    /**
     * Proxy a video stream from the media server.
     *
     * Route: /media-server/{integrationId}/stream/{itemId}.{container}
     *
     * This streams the video content directly, hiding the API key from the client.
     * Uses chunked streaming to handle large video files efficiently.
     *
     * @param  int  $integrationId  The integration ID
     * @param  string  $itemId  The media server's item ID
     * @param  string  $container  The container format (mp4, mkv, ts, etc.)
     * @return StreamedResponse
     */
    public function proxyStream(Request $request, int $integrationId, string $itemId, string $container = 'ts')
    {
        if ($staleResponse = $this->rejectIfStaleUrlVersion($request)) {
            return $staleResponse;
        }

        try {
            // Ensure long-running streaming inside closure is not subject to the default timeout
            set_time_limit(0);
            ignore_user_abort(true);

            $integration = MediaServerIntegration::find($integrationId);

            if (! $integration) {
                return response()->json(['error' => 'Integration not found'], 404);
            }

            if (! $integration->enabled) {
                return response()->json(['error' => 'Integration is disabled'], 403);
            }

            $mediaServer = MediaServerService::make($integration);
            $fullUrl = $mediaServer->getDirectStreamUrl($request, $itemId, $container);

            // Make sure we got a valid URL back
            if (empty($fullUrl)) {
                Log::warning('Media server returned empty stream URL', [
                    'integration_id' => $integrationId,
                    'item_id' => $itemId,
                    'container' => $container,
                ]);

                return response()->json(['error' => 'Media server could not resolve stream URL'], 502);
            }

            // Get content type based on container
            $contentType = $this->getContentTypeForContainer($container);

            // Handle range requests for seeking
            $headers = [
                'Accept' => '*/*',
            ];

            if ($request->hasHeader('Range')) {
                $headers['Range'] = $request->header('Range');
            }

            // Make the request to get headers first
            $headResponse = Http::withHeaders($headers)
                ->timeout(10)
                ->head($fullUrl);

            $responseHeaders = [
                'Content-Type' => $contentType,
                'Accept-Ranges' => 'bytes',
                'X-Proxied-From' => 'MediaServer',
                'Connection' => 'keep-alive',
            ];

            // Forward content-length if available
            if ($headResponse->hasHeader('Content-Length')) {
                $responseHeaders['Content-Length'] = $headResponse->header('Content-Length');
            }

            // Forward content-range for partial content
            if ($headResponse->hasHeader('Content-Range')) {
                $responseHeaders['Content-Range'] = $headResponse->header('Content-Range');
            }

            $statusCode = $request->hasHeader('Range') && $headResponse->status() === 206 ? 206 : 200;

            Log::debug('Proxying media server stream', [
                'integration_id' => $integrationId,
                'item_id' => $itemId,
                'container' => $container,
                'has_range' => $request->hasHeader('Range'),
            ]);

            // Stream the response
            return new StreamedResponse(function () use ($fullUrl, $headers) {
                $ch = curl_init($fullUrl);
                curl_setopt($ch, CURLOPT_HTTPHEADER, array_map(
                    fn ($k, $v) => "{$k}: {$v}",
                    array_keys($headers),
                    array_values($headers)
                ));
                curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) {
                    // A player that seeks abandons this connection without closing it (it just
                    // opens a new one for the new Range). ignore_user_abort(true) above means
                    // PHP won't notice on its own, and CURLOPT_LOW_SPEED_LIMIT never trips here
                    // because Plex/Emby keeps sending data just fine — nobody's reading it, but
                    // it's not a stall. Without this check the worker is pinned forever.
                    if (connection_aborted()) {
                        return 0;
                    }
                    echo $data;
                    flush();

                    return strlen($data);
                });
                curl_setopt($ch, CURLOPT_NOPROGRESS, false);
                curl_setopt($ch, CURLOPT_XFERINFOFUNCTION, fn () => connection_aborted() ? 1 : 0);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 0); // No total timeout for streaming
                // Abort if the upstream stalls completely (0 bytes) for 30s, so a dead
                // Plex/Emby/Jellyfin session can't pin a PHP-FPM worker forever.
                curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, 1);
                curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, 30);
                curl_exec($ch);
                curl_close($ch);
            }, $statusCode, $responseHeaders);
        } catch (\Exception $e) {
            Log::error('Exception in media server stream proxy', [
                'integration_id' => $integrationId,
                'item_id' => $itemId,
                'container' => $container,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Internal server error while proxying stream',
            ], 500);
        }
    }

    /**
     * Get the appropriate content type for a container format.
     */
    protected function getContentTypeForContainer(string $container): string
    {
        return match (strtolower($container)) {
            'mp4', 'm4v' => 'video/mp4',
            'mkv' => 'video/x-matroska',
            'avi' => 'video/x-msvideo',
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            'ts', 'm2ts' => 'video/mp2t',
            'wmv' => 'video/x-ms-wmv',
            'flv' => 'video/x-flv',
            default => 'application/octet-stream',
        };
    }

    /**
     * Stored, "permanent" media-server proxy URLs never re-sign themselves, so a
     * signature alone can't be revoked once handed out. Every generated URL carries
     * this version, and every request re-checks it against the live config value —
     * bumping `MEDIA_SERVER_PROXY_URL_VERSION` invalidates every previously
     * generated URL at once (they're regenerated with the new version on next sync).
     */
    protected static function currentUrlVersion(): int
    {
        return (int) config('proxy.media_server_url_version', 1);
    }

    /**
     * Reject the request if the signed URL's stamped version doesn't match the
     * live config value. Signature validity (handled by the ValidateSignature
     * route middleware) only proves the URL wasn't tampered with — it says
     * nothing about whether it's still considered current.
     */
    protected function rejectIfStaleUrlVersion(Request $request): ?JsonResponse
    {
        $requestedVersion = (int) $request->query('v', 0);

        if ($requestedVersion === static::currentUrlVersion()) {
            return null;
        }

        Log::warning('Media server proxy URL rejected — stale version', [
            'path' => $request->path(),
            'requested_version' => $requestedVersion,
            'current_version' => static::currentUrlVersion(),
        ]);

        return response()->json(['error' => 'This URL is no longer valid. Please re-sync to generate a new one.'], 403);
    }

    /**
     * Generate a proxy URL for an image.
     */
    public static function generateImageProxyUrl(int $integrationId, string $itemId, string $imageType = 'Primary'): string
    {
        return ProxyFacade::getBaseUrl().URL::signedRoute(
            'media-server.image.proxy',
            ['integrationId' => $integrationId, 'itemId' => $itemId, 'imageType' => $imageType, 'v' => static::currentUrlVersion()],
            absolute: false
        );
    }

    /**
     * Generate a proxy URL for a stream.
     */
    public static function generateStreamProxyUrl(int $integrationId, string $itemId, string $container = 'ts'): string
    {
        return ProxyFacade::getBaseUrl().URL::signedRoute(
            'media-server.stream.proxy',
            ['integrationId' => $integrationId, 'itemId' => $itemId, 'container' => $container, 'v' => static::currentUrlVersion()],
            absolute: false
        );
    }

    /**
     * Stream a local media file.
     *
     * Route: /local-media/{integration}/stream/{item}
     *
     * This streams local video files that are mounted to the container.
     * Supports range requests for seeking.
     *
     * @param  int  $integration  The integration ID
     * @param  string  $item  Base64-encoded file path
     * @return Response|StreamedResponse
     */
    public function streamLocalMedia(Request $request, int $integration, string $item)
    {
        if ($staleResponse = $this->rejectIfStaleUrlVersion($request)) {
            return $staleResponse;
        }

        try {
            set_time_limit(0);
            ignore_user_abort(true);

            $mediaIntegration = MediaServerIntegration::find($integration);

            if (! $mediaIntegration) {
                return response()->json(['error' => 'Integration not found'], 404);
            }

            if (! $mediaIntegration->enabled) {
                return response()->json(['error' => 'Integration is disabled'], 403);
            }

            if ($mediaIntegration->type !== 'local') {
                return response()->json(['error' => 'Integration is not a local media type'], 400);
            }

            // Decode the file path from base64
            $filePath = base64_decode($item);

            if (! $filePath || ! file_exists($filePath)) {
                Log::warning('Local media file not found', [
                    'integration_id' => $integration,
                    'item_id' => $item,
                    'decoded_path' => $filePath,
                ]);

                return response()->json(['error' => 'File not found'], 404);
            }

            // Security check: ensure the file is within one of the configured paths
            $configuredPaths = $mediaIntegration->local_media_paths ?? [];
            $isAllowed = false;

            foreach ($configuredPaths as $pathConfig) {
                $allowedPath = realpath($pathConfig['path'] ?? '');
                $realFilePath = realpath($filePath);

                if ($allowedPath && $realFilePath && (str_starts_with($realFilePath, $allowedPath.DIRECTORY_SEPARATOR) || $realFilePath === $allowedPath)) {
                    $isAllowed = true;
                    break;
                }

                // Also allow entries that are symlinks located inside a configured path
                // but resolving outside it (e.g. *arr libraries symlinked into a
                // debrid/cloud mount). Only the final path component may be a symlink:
                // the parent directory is resolved with realpath(), so `..` traversal
                // and symlinked directories cannot escape the configured paths.
                $linkDir = realpath(dirname($filePath));

                if ($allowedPath && $linkDir && is_file($filePath)
                    && (str_starts_with($linkDir.DIRECTORY_SEPARATOR, $allowedPath.DIRECTORY_SEPARATOR) || $linkDir === $allowedPath)) {
                    $isAllowed = true;
                    break;
                }
            }

            if (! $isAllowed) {
                Log::warning('Local media access denied - file outside configured paths', [
                    'integration_id' => $integration,
                    'file_path' => $filePath,
                ]);

                return response()->json(['error' => 'Access denied'], 403);
            }

            $fileSize = filesize($filePath);
            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            $contentType = $this->getContentTypeForContainer($extension);

            // Handle range requests for video seeking
            $start = 0;
            $end = $fileSize - 1;
            $statusCode = 200;

            $headers = [
                'Content-Type' => $contentType,
                'Accept-Ranges' => 'bytes',
                'Content-Disposition' => 'inline; filename="'.basename($filePath).'"',
                'X-Content-Duration' => 'unknown',
            ];

            if ($request->hasHeader('Range')) {
                $range = $request->header('Range');

                if (preg_match('/bytes=(\d*)-(\d*)/', $range, $matches)) {
                    $start = $matches[1] !== '' ? (int) $matches[1] : 0;
                    $end = $matches[2] !== '' ? (int) $matches[2] : $fileSize - 1;

                    // Validate range
                    if ($start > $end || $start >= $fileSize) {
                        return response('', 416, [
                            'Content-Range' => "bytes */{$fileSize}",
                        ]);
                    }

                    $end = min($end, $fileSize - 1);
                    $statusCode = 206;
                    $headers['Content-Range'] = "bytes {$start}-{$end}/{$fileSize}";
                }
            }

            $length = $end - $start + 1;
            $headers['Content-Length'] = $length;

            Log::debug('Streaming local media file', [
                'integration_id' => $integration,
                'file_path' => $filePath,
                'file_size' => $fileSize,
                'range' => $request->header('Range'),
                'start' => $start,
                'end' => $end,
                'length' => $length,
            ]);

            return new StreamedResponse(function () use ($filePath, $start, $end) {
                $handle = fopen($filePath, 'rb');

                if (! $handle) {
                    return;
                }

                fseek($handle, $start);
                $remaining = $end - $start + 1;
                $bufferSize = 1024 * 1024; // 1MB chunks

                while ($remaining > 0 && ! feof($handle) && connection_status() === CONNECTION_NORMAL) {
                    $readSize = min($bufferSize, $remaining);
                    $data = fread($handle, $readSize);

                    if ($data === false) {
                        break;
                    }

                    echo $data;
                    flush();
                    $remaining -= strlen($data);
                }

                fclose($handle);
            }, $statusCode, $headers);
        } catch (\Exception $e) {
            Log::error('Exception in local media stream', [
                'integration_id' => $integration,
                'item_id' => $item,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Internal server error while streaming local media',
            ], 500);
        }
    }

    /**
     * Generate a URL for streaming local media.
     */
    public static function generateLocalMediaStreamUrl(int $integrationId, string $itemId): string
    {
        return ProxyFacade::getBaseUrl().URL::signedRoute(
            'local-media.stream',
            ['integration' => $integrationId, 'item' => $itemId, 'v' => static::currentUrlVersion()],
            absolute: false
        );
    }

    /**
     * Generate a URL for streaming WebDAV media.
     */
    public static function generateWebDavStreamUrl(int $integrationId, string $itemId): string
    {
        return ProxyFacade::getBaseUrl().URL::signedRoute(
            'webdav-media.stream',
            ['integration' => $integrationId, 'item' => $itemId, 'v' => static::currentUrlVersion()],
            absolute: false
        );
    }

    /**
     * Stream media from a WebDAV server.
     *
     * Route: /webdav-media/{integration}/stream/{item}
     *
     * This proxies video files from a WebDAV server, handling authentication.
     * Supports range requests for seeking.
     *
     * @param  int  $integration  The integration ID
     * @param  string  $item  Base64-encoded file path on the WebDAV server
     * @return Response|StreamedResponse
     */
    public function streamWebDavMedia(Request $request, int $integration, string $item)
    {
        if ($staleResponse = $this->rejectIfStaleUrlVersion($request)) {
            return $staleResponse;
        }

        try {
            set_time_limit(0);
            ignore_user_abort(true);

            $mediaIntegration = MediaServerIntegration::find($integration);

            if (! $mediaIntegration) {
                return response()->json(['error' => 'Integration not found'], 404);
            }

            if (! $mediaIntegration->enabled) {
                return response()->json(['error' => 'Integration is disabled'], 403);
            }

            if ($mediaIntegration->type !== 'webdav') {
                return response()->json(['error' => 'Integration is not a WebDAV type'], 400);
            }

            // Decode the file path from base64
            $filePath = base64_decode($item);

            if (! $filePath) {
                Log::warning('WebDAV media: Invalid item ID', [
                    'integration_id' => $integration,
                    'item_id' => $item,
                ]);

                return response()->json(['error' => 'Invalid item ID'], 400);
            }

            // Build the WebDAV URL with proper URL encoding for path segments
            $protocol = $mediaIntegration->ssl ? 'https' : 'http';
            $host = $mediaIntegration->host;
            $port = $mediaIntegration->port;

            $baseUrl = "{$protocol}://{$host}";
            if ($port && $port !== 80 && $port !== 443) {
                $baseUrl .= ":{$port}";
            }

            // URL-encode each path segment individually to handle spaces, brackets, etc.
            $encodedPath = implode('/', array_map('rawurlencode', explode('/', $filePath)));
            $fileUrl = rtrim($baseUrl, '/').'/'.ltrim($encodedPath, '/');

            // Build curl auth options
            $username = $mediaIntegration->webdav_username;
            $password = $mediaIntegration->webdav_password;

            // First, get the file size with a HEAD request via curl
            $headCh = curl_init($fileUrl);
            curl_setopt($headCh, CURLOPT_NOBODY, true);
            curl_setopt($headCh, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($headCh, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($headCh, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($headCh, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($headCh, CURLOPT_TIMEOUT, 30);

            if ($username && $password) {
                curl_setopt($headCh, CURLOPT_USERPWD, "{$username}:{$password}");
            }

            curl_exec($headCh);
            $headStatus = curl_getinfo($headCh, CURLINFO_HTTP_CODE);
            $fileSize = (int) curl_getinfo($headCh, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
            $curlError = curl_error($headCh);
            curl_close($headCh);

            if ($headStatus < 200 || $headStatus >= 300) {
                Log::warning('WebDAV media: File not accessible', [
                    'integration_id' => $integration,
                    'file_path' => $filePath,
                    'file_url' => $fileUrl,
                    'status' => $headStatus,
                    'curl_error' => $curlError,
                ]);

                return response()->json(['error' => 'File not accessible'], $headStatus ?: 502);
            }

            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            $contentType = $this->getContentTypeForContainer($extension);

            // Handle range requests for video seeking
            $start = 0;
            $end = $fileSize > 0 ? $fileSize - 1 : 0;
            $statusCode = 200;

            $responseHeaders = [
                'Content-Type' => $contentType,
                'Accept-Ranges' => 'bytes',
                'Content-Disposition' => 'inline; filename="'.basename($filePath).'"',
                'Connection' => 'keep-alive',
            ];

            if ($request->hasHeader('Range') && $fileSize > 0) {
                $range = $request->header('Range');

                if (preg_match('/bytes=(\d*)-(\d*)/', $range, $matches)) {
                    $start = $matches[1] !== '' ? (int) $matches[1] : 0;
                    $end = $matches[2] !== '' ? (int) $matches[2] : $fileSize - 1;

                    if ($start > $end || $start >= $fileSize) {
                        return response('', 416, [
                            'Content-Range' => "bytes */{$fileSize}",
                        ]);
                    }

                    $end = min($end, $fileSize - 1);
                    $statusCode = 206;
                    $responseHeaders['Content-Range'] = "bytes {$start}-{$end}/{$fileSize}";
                }
            }

            $length = $end - $start + 1;
            $responseHeaders['Content-Length'] = $length;

            Log::debug('Streaming WebDAV media file', [
                'integration_id' => $integration,
                'file_path' => $filePath,
                'file_url' => $fileUrl,
                'file_size' => $fileSize,
                'range' => $request->header('Range'),
                'start' => $start,
                'end' => $end,
                'length' => $length,
            ]);

            // Stream using curl to avoid loading entire file into memory
            $rangeHeader = "bytes={$start}-{$end}";

            return new StreamedResponse(function () use ($fileUrl, $rangeHeader, $username, $password) {
                $ch = curl_init($fileUrl);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ["Range: {$rangeHeader}"]);
                curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) {
                    // See proxyStream(): a seek abandons this connection without closing it,
                    // and ignore_user_abort(true) means PHP won't notice unless we check here.
                    if (connection_aborted()) {
                        return 0;
                    }
                    echo $data;
                    flush();

                    return strlen($data);
                });
                curl_setopt($ch, CURLOPT_NOPROGRESS, false);
                curl_setopt($ch, CURLOPT_XFERINFOFUNCTION, fn () => connection_aborted() ? 1 : 0);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                curl_setopt($ch, CURLOPT_TIMEOUT, 0);
                // Abort if the upstream stalls completely (0 bytes) for 30s, so a dead
                // WebDAV connection can't pin a PHP-FPM worker forever.
                curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, 1);
                curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, 30);

                if ($username && $password) {
                    curl_setopt($ch, CURLOPT_USERPWD, "{$username}:{$password}");
                }

                curl_exec($ch);
                curl_close($ch);
            }, $statusCode, $responseHeaders);
        } catch (\Exception $e) {
            Log::error('Exception in WebDAV media stream', [
                'integration_id' => $integration,
                'item_id' => $item,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Internal server error while streaming WebDAV media',
            ], 500);
        }
    }

    /**
     * Generate a URL for streaming a synced (persisted) AIOStreams-backed Channel.
     *
     * AIOStreams resolves each item to an opaque, provider-hosted URL (a debrid or
     * torrent-streaming host) rather than a stable item ID we could re-query — so
     * unlike Plex/Emby, there's nothing to look up from the integration itself.
     * That resolved URL was already discovered and stored on the channel by
     * ResolveAioStreamsChannel (movie_data['aiostreams']['resolved_url']), so this
     * route just references that row's own ID, the same way generateStreamProxyUrl()
     * references a Plex/Emby itemId — no payload, no cache.
     */
    public static function generateAioStreamsChannelProxyUrl(int $integrationId, int $channelId): string
    {
        return ProxyFacade::getBaseUrl().URL::signedRoute(
            'aiostreams-media.channel.stream',
            ['integration' => $integrationId, 'channel' => $channelId, 'v' => static::currentUrlVersion()],
            absolute: false
        );
    }

    /**
     * Generate a URL for streaming a synced (persisted) AIOStreams-backed Episode.
     * See generateAioStreamsChannelProxyUrl() — same shape, keyed by Episode ID.
     */
    public static function generateAioStreamsEpisodeProxyUrl(int $integrationId, int $episodeId): string
    {
        return ProxyFacade::getBaseUrl().URL::signedRoute(
            'aiostreams-media.episode.stream',
            ['integration' => $integrationId, 'episode' => $episodeId, 'v' => static::currentUrlVersion()],
            absolute: false
        );
    }

    /**
     * How long a "live" AIOStreams proxy token stays reachable. This path has no
     * durable Channel/Episode row backing it (ad-hoc browse-and-preview, and the
     * Xtream-style catalog/stream-list endpoint used by the m3u-tv Flutter client),
     * so the resolved URL is cached rather than persisted. A video player keeps
     * issuing range requests against the same URL for the life of one viewing
     * session (seeking, buffering ahead, resuming from a pause) — 24 hours
     * comfortably covers even a long title left paused overnight, while staying
     * clearly short-lived rather than long-term storage.
     */
    private const AIOSTREAMS_LIVE_TOKEN_CACHE_HOURS = 24;

    /**
     * Cache key an AIOStreams live proxy token resolves to. Scoped by integration
     * so a token can't be replayed against a different integration's namespace.
     */
    protected static function aioStreamsLiveCacheKey(int $integrationId, string $token): string
    {
        return "aiostreams-live-proxy:{$integrationId}:{$token}";
    }

    /**
     * Generate a URL for streaming a resolved AIOStreams URL that has no durable
     * Channel/Episode row yet. See generateAioStreamsLiveProxyUrls() for the batch
     * form and the full rationale — this is that method for a single URL.
     */
    public static function generateAioStreamsLiveProxyUrl(int $integrationId, string $resolvedUrl): string
    {
        return static::generateAioStreamsLiveProxyUrls($integrationId, [$resolvedUrl])[0];
    }

    /**
     * Batch form of generateAioStreamsLiveProxyUrl() — used by the ad-hoc
     * browse-and-preview flow and the catalog/stream-list endpoint
     * (AIOStreamsProxyController::stream(), consumed by the m3u-tv Flutter client
     * and any other Xtream-style caller), neither of which has a durable row to
     * key off. A stream-list response can hold dozens of candidates; writing each
     * one's cache entry with its own Cache::put() means one database round trip
     * per candidate. Cache::putMany() performs a single upsert() query for the
     * whole batch instead (see Illuminate\Cache\DatabaseStore::putMany()).
     *
     * The resolved URL itself can also be extremely long (magnet URIs with many
     * trackers, chained resolver links), so it's cached server-side under a short
     * random token rather than embedded in the route — the generated URL's length
     * never depends on the upstream URL's length, and the upstream URL (which may
     * carry the debrid account's own auth token) never appears in it at all.
     *
     * @param  array<int|string, string>  $resolvedUrls  Keyed however the caller likes; the same keys come back in the result.
     * @return array<int|string, string>
     */
    public static function generateAioStreamsLiveProxyUrls(int $integrationId, array $resolvedUrls): array
    {
        if ($resolvedUrls === []) {
            return [];
        }

        $tokensByKey = [];
        $cacheValues = [];

        foreach ($resolvedUrls as $key => $resolvedUrl) {
            $token = Str::random(40);
            $tokensByKey[$key] = $token;
            $cacheValues[static::aioStreamsLiveCacheKey($integrationId, $token)] = $resolvedUrl;
        }

        Cache::putMany($cacheValues, now()->addHours(self::AIOSTREAMS_LIVE_TOKEN_CACHE_HOURS));

        $urlsByKey = [];

        foreach ($tokensByKey as $key => $token) {
            $urlsByKey[$key] = ProxyFacade::getBaseUrl().URL::signedRoute(
                'aiostreams-media.live.stream',
                ['integration' => $integrationId, 'item' => $token, 'v' => static::currentUrlVersion()],
                absolute: false
            );
        }

        return $urlsByKey;
    }

    /**
     * Common integration lookup/gating shared by all three AIOStreams handlers.
     * Returns an error JsonResponse to short-circuit with, or null to proceed.
     */
    protected function rejectInvalidAioStreamsIntegration(int $integration): ?JsonResponse
    {
        $mediaIntegration = MediaServerIntegration::find($integration);

        if (! $mediaIntegration) {
            return response()->json(['error' => 'Integration not found'], 404);
        }

        if (! $mediaIntegration->enabled) {
            return response()->json(['error' => 'Integration is disabled'], 403);
        }

        if ($mediaIntegration->type !== 'aiostreams') {
            return response()->json(['error' => 'Integration is not an AIOStreams type'], 400);
        }

        return null;
    }

    /**
     * Stream a resolved AIOStreams URL for a synced (persisted) Channel.
     *
     * Route: /aiostreams-media/{integration}/channel/{channel}/stream
     *
     * The resolved URL was already discovered and stored by ResolveAioStreamsChannel
     * (movie_data['aiostreams']['resolved_url']) — this is a plain DB lookup keyed
     * by the channel's own ID. Supports range requests for seeking.
     */
    public function streamAioStreamsChannel(Request $request, int $integration, int $channel)
    {
        if ($staleResponse = $this->rejectIfStaleUrlVersion($request)) {
            return $staleResponse;
        }

        try {
            set_time_limit(0);
            ignore_user_abort(true);

            if ($errorResponse = $this->rejectInvalidAioStreamsIntegration($integration)) {
                return $errorResponse;
            }

            // Failover clone channels are hidden by ExcludeAioFailoverClonesScope
            // everywhere else in the app (Xtream API, M3U export, table listings) —
            // opt back in explicitly, since this is the one place that needs them.
            $channelModel = Channel::withoutGlobalScope(ExcludeAioFailoverClonesScope::class)
                ->where('id', $channel)
                ->where('aio_integration_id', $integration)
                ->first();

            $resolvedUrl = $channelModel?->movie_data['aiostreams']['resolved_url'] ?? null;

            return $this->proxyResolvedUrl($request, $resolvedUrl, (string) $integration);
        } catch (\Exception $e) {
            Log::error('Exception in AIOStreams channel media proxy', [
                'integration_id' => $integration,
                'channel_id' => $channel,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Internal server error while proxying AIOStreams media',
            ], 500);
        }
    }

    /**
     * Stream a resolved AIOStreams URL for a synced (persisted) Episode.
     *
     * Route: /aiostreams-media/{integration}/episode/{episode}/stream
     *
     * See streamAioStreamsChannel() — same shape, reading from Episode::info
     * (Episodes don't carry their own aio_integration_id; it lives on the parent
     * Series, so it's resolved via that relation instead of a direct column).
     */
    public function streamAioStreamsEpisode(Request $request, int $integration, int $episode)
    {
        if ($staleResponse = $this->rejectIfStaleUrlVersion($request)) {
            return $staleResponse;
        }

        try {
            set_time_limit(0);
            ignore_user_abort(true);

            if ($errorResponse = $this->rejectInvalidAioStreamsIntegration($integration)) {
                return $errorResponse;
            }

            $episodeModel = Episode::withoutGlobalScope(ExcludeAioFailoverClonesScope::class)
                ->where('id', $episode)
                ->whereHas('series', fn ($query) => $query->where('aio_integration_id', $integration))
                ->first();

            $resolvedUrl = $episodeModel?->info['aiostreams']['resolved_url'] ?? null;

            return $this->proxyResolvedUrl($request, $resolvedUrl, (string) $integration);
        } catch (\Exception $e) {
            Log::error('Exception in AIOStreams episode media proxy', [
                'integration_id' => $integration,
                'episode_id' => $episode,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Internal server error while proxying AIOStreams media',
            ], 500);
        }
    }

    /**
     * Stream a resolved AIOStreams URL cached under a short-lived token — used by
     * the ad-hoc browse-and-preview flow and the catalog/stream-list endpoint
     * (see generateAioStreamsLiveProxyUrls()), neither of which has a durable
     * Channel/Episode row to look the URL up from.
     *
     * Route: /aiostreams-media/{integration}/live/{item}/stream
     */
    public function streamAioStreamsLive(Request $request, int $integration, string $item)
    {
        if ($staleResponse = $this->rejectIfStaleUrlVersion($request)) {
            return $staleResponse;
        }

        try {
            set_time_limit(0);
            ignore_user_abort(true);

            if ($errorResponse = $this->rejectInvalidAioStreamsIntegration($integration)) {
                return $errorResponse;
            }

            $resolvedUrl = Cache::get(static::aioStreamsLiveCacheKey($integration, $item));

            return $this->proxyResolvedUrl($request, $resolvedUrl, (string) $integration);
        } catch (\Exception $e) {
            Log::error('Exception in AIOStreams live media proxy', [
                'integration_id' => $integration,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Internal server error while proxying AIOStreams media',
            ], 500);
        }
    }

    /**
     * Proxy a resolved AIOStreams URL with exactly one upstream request — no
     * preflight HEAD/metadata probe. AIOStreams resolves to arbitrary third-party
     * debrid/torrent-streaming hosts, many of which rate-limit or cap concurrent
     * connections per link; a separate HEAD request against the same resolved URL
     * before the real GET counts as a second connection against that cap and can
     * get the actual playback request throttled or rejected outright. Status and
     * headers are instead relayed live from curl's own response as it arrives,
     * before any body bytes are written — a transparent single-pass proxy.
     */
    protected function proxyResolvedUrl(Request $request, ?string $resolvedUrl, string $integrationId): StreamedResponse|JsonResponse
    {
        if (
            ! $resolvedUrl
            || ! filter_var($resolvedUrl, FILTER_VALIDATE_URL)
            || ! in_array(parse_url($resolvedUrl, PHP_URL_SCHEME), ['http', 'https'], true)
        ) {
            Log::warning('AIOStreams media: resolved URL missing or invalid', [
                'integration_id' => $integrationId,
            ]);

            return response()->json(['error' => 'Resolved URL not found. Please re-sync.'], 404);
        }

        $requestHeaders = ['Accept' => '*/*'];

        if ($request->hasHeader('Range')) {
            $requestHeaders['Range'] = $request->header('Range');
        }

        Log::debug('Proxying AIOStreams media', [
            'integration_id' => $integrationId,
            'has_range' => $request->hasHeader('Range'),
        ]);

        return new StreamedResponse(function () use ($resolvedUrl, $requestHeaders) {
            $ch = curl_init($resolvedUrl);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array_map(
                fn ($k, $v) => "{$k}: {$v}",
                array_keys($requestHeaders),
                array_values($requestHeaders)
            ));
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 0);
            // Abort if the upstream stalls completely (0 bytes) for 30s, so a dead
            // debrid/torrent-streaming connection can't pin a PHP-FPM worker forever.
            curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, 1);
            curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, 30);

            // CURLOPT_FOLLOWLOCATION means the header callback fires once per
            // redirect hop. A fresh "HTTP/…" status line marks the start of a new
            // hop's header block, so resetting the buffer on each one leaves
            // exactly the FINAL hop's headers by the time the first body byte
            // arrives below.
            $currentHopHeaders = [];
            $finalHeaders = [];

            curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $headerLine) use (&$currentHopHeaders, &$finalHeaders) {
                $trimmed = rtrim($headerLine, "\r\n");

                if (preg_match('#^HTTP/\S+\s+(\d+)#', $trimmed, $matches)) {
                    $currentHopHeaders = ['status' => (int) $matches[1]];
                } elseif ($trimmed === '') {
                    $finalHeaders = $currentHopHeaders;
                } elseif (str_contains($trimmed, ':')) {
                    [$name, $value] = explode(':', $trimmed, 2);
                    $currentHopHeaders[strtolower(trim($name))] = trim($value);
                }

                return strlen($headerLine);
            });

            $responseStarted = false;

            curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (&$responseStarted, &$finalHeaders) {
                // See other proxy methods in this class: a seek abandons this
                // connection without closing it, and ignore_user_abort(true) means
                // PHP won't notice unless we check here.
                if (connection_aborted()) {
                    return 0;
                }

                if (! $responseStarted) {
                    $responseStarted = true;

                    $status = $finalHeaders['status'] ?? 200;
                    http_response_code(in_array($status, [200, 206], true) ? $status : 200);

                    header('Content-Type: '.($finalHeaders['content-type'] ?? 'application/octet-stream'));
                    header('Accept-Ranges: bytes');
                    header('X-Proxied-From: AIOStreams');

                    if (isset($finalHeaders['content-length'])) {
                        header('Content-Length: '.$finalHeaders['content-length']);
                    }

                    if (isset($finalHeaders['content-range'])) {
                        header('Content-Range: '.$finalHeaders['content-range']);
                    }
                }

                echo $data;
                flush();

                return strlen($data);
            });

            curl_setopt($ch, CURLOPT_NOPROGRESS, false);
            curl_setopt($ch, CURLOPT_XFERINFOFUNCTION, fn () => connection_aborted() ? 1 : 0);

            curl_exec($ch);
            $curlError = curl_error($ch);
            curl_close($ch);

            // The upstream never sent any body (connection failure, DNS failure, or
            // an error status with no content) — headers were never emitted by
            // CURLOPT_WRITEFUNCTION above, so do it here instead.
            if (! $responseStarted) {
                Log::warning('AIOStreams media: upstream request failed before any content was received', [
                    'curl_error' => $curlError,
                ]);
                http_response_code(502);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Failed to reach the resolved upstream URL']);
            }
        });
    }
}
