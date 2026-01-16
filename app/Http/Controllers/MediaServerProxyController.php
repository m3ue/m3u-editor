<?php

namespace App\Http\Controllers;

use App\Models\MediaServerIntegration;
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

            // Build the media server image URL with authentication
            $imageUrl = "{$integration->base_url}/Items/{$itemId}/Images/{$imageType}";

            // Fetch the image with authentication
            $response = Http::withHeaders([
                'X-Emby-Token' => $integration->api_key,
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
            $integration = MediaServerIntegration::find($integrationId);

            if (! $integration) {
                return response()->json(['error' => 'Integration not found'], 404);
            }

            if (! $integration->enabled) {
                return response()->json(['error' => 'Integration is disabled'], 403);
            }

            // Build the media server stream URL with authentication
            $streamUrl = "{$integration->base_url}/Videos/{$itemId}/stream.{$container}";
            $params = [
                'static' => 'true',
                'api_key' => $integration->api_key,
            ];

            // Add any additional query parameters from the request (seeking, etc.)
            $forwardParams = ['StartTimeTicks', 'AudioStreamIndex', 'SubtitleStreamIndex'];
            foreach ($forwardParams as $param) {
                if ($request->has($param)) {
                    $params[$param] = $request->input($param);
                }
            }

            $fullUrl = $streamUrl.'?'.http_build_query($params);

            // Get content type based on container
            $contentType = $this->getContentTypeForContainer($container);

            // Handle range requests for seeking
            $headers = [
                'X-Emby-Token' => $integration->api_key,
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
        return url("/media-server/{$integrationId}/image/{$itemId}/{$imageType}");
    }

    /**
     * Generate a proxy URL for a stream.
     */
    public static function generateStreamProxyUrl(int $integrationId, string $itemId, string $container = 'ts'): string
    {
        return url("/media-server/{$integrationId}/stream/{$itemId}.{$container}");
    }
}
