<?php

namespace App\Http\Controllers;

use App\Facades\ProxyFacade;
use App\Models\MediaServerIntegration;
use App\Services\MediaServerService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
                    echo $data;
                    flush();

                    return strlen($data);
                });
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 0); // No timeout for streaming
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
     * Generate a proxy URL for an image.
     */
    public static function generateImageProxyUrl(int $integrationId, string $itemId, string $imageType = 'Primary'): string
    {
        return ProxyFacade::getBaseUrl()."/media-server/{$integrationId}/image/{$itemId}/{$imageType}";
    }

    /**
     * Generate a proxy URL for a stream.
     */
    public static function generateStreamProxyUrl(int $integrationId, string $itemId, string $container = 'ts'): string
    {
        return ProxyFacade::getBaseUrl()."/media-server/{$integrationId}/stream/{$itemId}.{$container}";
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
        return ProxyFacade::getBaseUrl()."/local-media/{$integrationId}/stream/{$itemId}";
    }
}
