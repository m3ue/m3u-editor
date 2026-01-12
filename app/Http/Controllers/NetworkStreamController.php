<?php

namespace App\Http\Controllers;

use App\Models\MediaServerIntegration;
use App\Models\Network;
use App\Models\NetworkProgramme;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * NetworkStreamController - Stream the currently-scheduled content for a Network.
 *
 * This controller finds the currently-airing programme and streams its content
 * from the media server, with optional seeking to the current position.
 */
class NetworkStreamController extends Controller
{
    /**
     * Stream the currently-airing content for a network.
     *
     * Route: /network/{network}/stream.{container}
     *
     * @param  string  $container  The container format (ts, mp4, mkv, etc.)
     */
    public function stream(Request $request, Network $network, string $container = 'ts'): StreamedResponse
    {
        if (! $network->enabled) {
            abort(404, 'Network is disabled');
        }

        // Find the currently-airing programme
        $now = Carbon::now();
        $programme = NetworkProgramme::where('network_id', $network->id)
            ->where('start_time', '<=', $now)
            ->where('end_time', '>', $now)
            ->first();

        if (! $programme) {
            abort(404, 'No content currently scheduled');
        }

        $content = $programme->contentable;

        if (! $content) {
            abort(404, 'Programme content not found');
        }

        // Get the media server integration - try network first, then extract from content
        $integration = $network->mediaServerIntegration;

        if (! $integration) {
            // Try to get integration ID from content's cover URL or info
            $integrationId = $this->getIntegrationIdFromContent($content);
            if ($integrationId) {
                $integration = MediaServerIntegration::find($integrationId);
            }
        }

        if (! $integration) {
            abort(404, 'No media server integration configured');
        }

        // Get the media server item ID from the content
        $itemId = $this->getMediaServerItemId($content);

        if (! $itemId) {
            abort(404, 'Content has no media server item ID');
        }

        // Calculate the offset into the current programme
        $offsetSeconds = $programme->getCurrentOffsetSeconds();
        $offsetTicks = $offsetSeconds * 10_000_000; // Jellyfin/Emby use ticks (100ns intervals)

        // Build the media server stream URL
        $streamUrl = "{$integration->base_url}/Videos/{$itemId}/stream.{$container}";
        $params = [
            'static' => 'true',
            'api_key' => $integration->api_key,
        ];

        // Add seek position if we're not at the start
        if ($offsetTicks > 0 && ! $request->has('noSeek')) {
            $params['StartTimeTicks'] = $offsetTicks;
        }

        $fullUrl = $streamUrl.'?'.http_build_query($params);

        // Get content type based on container
        $contentType = $this->getContentTypeForContainer($container);

        // Build headers for the upstream request
        $headers = [
            'X-Emby-Token' => $integration->api_key,
            'Accept' => '*/*',
        ];

        // Handle range requests for seeking
        if ($request->hasHeader('Range')) {
            $headers['Range'] = $request->header('Range');
        }

        Log::debug('Streaming network content', [
            'network_id' => $network->id,
            'network_name' => $network->name,
            'programme_id' => $programme->id,
            'content_title' => $programme->title,
            'item_id' => $itemId,
            'offset_seconds' => $offsetSeconds,
            'container' => $container,
        ]);

        // Make a HEAD request to get headers first
        try {
            $headResponse = Http::withHeaders($headers)
                ->timeout(10)
                ->head($fullUrl);

            $responseHeaders = [
                'Content-Type' => $contentType,
                'Accept-Ranges' => 'bytes',
                'X-Network-Name' => $network->name,
                'X-Programme-Title' => $programme->title ?? 'Unknown',
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

        } catch (\Exception $e) {
            Log::warning('Failed to get stream headers', [
                'network_id' => $network->id,
                'error' => $e->getMessage(),
            ]);

            $responseHeaders = [
                'Content-Type' => $contentType,
                'Accept-Ranges' => 'bytes',
            ];
            $statusCode = 200;
        }

        // Stream the response using curl
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
    }

    /**
     * Get info about the currently-airing programme.
     *
     * Route: /network/{network}/now-playing
     */
    public function nowPlaying(Network $network): array
    {
        if (! $network->enabled) {
            abort(404, 'Network is disabled');
        }

        $now = Carbon::now();
        $programme = NetworkProgramme::where('network_id', $network->id)
            ->where('start_time', '<=', $now)
            ->where('end_time', '>', $now)
            ->first();

        if (! $programme) {
            return [
                'status' => 'off_air',
                'message' => 'No content currently scheduled',
            ];
        }

        $content = $programme->contentable;

        return [
            'status' => 'on_air',
            'network' => [
                'id' => $network->id,
                'name' => $network->name,
                'channel_number' => $network->channel_number,
            ],
            'programme' => [
                'title' => $programme->title,
                'start_time' => $programme->start_time->toIso8601String(),
                'end_time' => $programme->end_time->toIso8601String(),
                'duration_seconds' => $programme->duration_seconds,
                'elapsed_seconds' => $programme->getCurrentOffsetSeconds(),
                'remaining_seconds' => max(0, $programme->end_time->diffInSeconds($now)),
            ],
            'content' => [
                'type' => $content ? class_basename($content) : null,
                'title' => $content->name ?? $content->title ?? 'Unknown',
            ],
        ];
    }

    /**
     * Generate a stream URL for a network.
     */
    public static function generateStreamUrl(Network $network, string $container = 'ts'): string
    {
        return url("/network/{$network->uuid}/stream.{$container}");
    }

    /**
     * Get the media server item ID from content.
     */
    protected function getMediaServerItemId($content): ?string
    {
        // Check for source_episode_id (for Episodes) or source_channel_id (for Channels)
        if (isset($content->source_episode_id)) {
            return (string) $content->source_episode_id;
        }

        if (isset($content->source_channel_id)) {
            return (string) $content->source_channel_id;
        }

        // Check info array for media server ID
        if (isset($content->info['media_server_id'])) {
            return (string) $content->info['media_server_id'];
        }

        // For channels that might store it differently
        if (isset($content->movie_data['movie_data']['id'])) {
            return (string) $content->movie_data['movie_data']['id'];
        }

        return null;
    }

    /**
     * Get the integration ID from content by parsing URLs or info.
     */
    protected function getIntegrationIdFromContent($content): ?int
    {
        // Try to extract from cover_big URL (e.g., /media-server/34/image/...)
        $coverUrl = $content->info['cover_big'] ?? $content->info['movie_image'] ?? null;
        if ($coverUrl && preg_match('#/media-server/(\d+)/#', $coverUrl, $matches)) {
            return (int) $matches[1];
        }

        // Try playlist's media server integration if content has a playlist
        if (isset($content->playlist_id) && $content->playlist) {
            $playlist = $content->playlist;
            if ($playlist->media_server_integration_id) {
                return $playlist->media_server_integration_id;
            }
        }

        return null;
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
}
