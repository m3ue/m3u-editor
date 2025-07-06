<?php

namespace App\Http\Controllers;

use Exception;
use App\Models\Channel;
use App\Models\Episode;
use App\Settings\GeneralSettings;
use App\Services\HlsStreamService;
use App\Traits\TracksActiveStreams;
use App\Exceptions\AllPlaylistsExhaustedException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

/**
 * Class HlsStreamController
 * 
 * This controller handles the HLS streaming for channels.
 * It manages the starting of the FFmpeg process and serves the HLS playlist and segments.
 * 
 * NOTE: Using NGINX internal redirects for serving the playlist and segments.
 *       If running locally, make sure to set up NGINX with the following configuration:
 * 
 * location /internal/hls/ {
 *     internal;
 *     alias [PROJECT_ROOT_PATH]/storage/app/hls/;
 *     access_log off;
 *     add_header Cache-Control no-cache;
 * }
 * 
 */
class HlsStreamController extends Controller
{
    use TracksActiveStreams;

    private $hlsService;

    public function __construct(HlsStreamService $hlsStreamService)
    {
        $this->hlsService = $hlsStreamService;
    }

    /**
     * Launch (or re-launch) an FFmpeg HLS job for this channel,
     * then send the contents of the .m3u8 file.
     * 
     * @param Request $request
     * @param int|string $encodedId
     * 
     * @return \Illuminate\Http\Response
     */
    public function serveChannelPlaylist(Request $request, $encodedId)
    {
        // Find the channel by ID
        if (strpos($encodedId, '==') === false) {
            $encodedId .= '=='; // right pad to ensure proper decoding
        }
        $channel = Channel::findOrFail(base64_decode($encodedId));

        // Serve the playlist for the channel
        return $this->servePlaylist(
            type: 'channel',
            encodedId: $encodedId,
            model: $channel,
            title: $channel->title_custom ?? $channel->title
        );
    }

    /**
     * Serve individual .ts segments.
     * 
     * @param Request $request
     * @param int|string $channelId
     * 
     * @return \Illuminate\Http\Response
     */
    public function serveChannelSegment(Request $request, $channelId, $segment)
    {
        return $this->serveSegment(
            type: 'channel',
            modelId: $channelId,
            segment: $segment
        );
    }

    /**
     * Launch (or re-launch) an FFmpeg HLS job for this episode,
     * then send the contents of the .m3u8 file.
     * 
     * @param Request $request
     * @param int|string $encodedId
     * 
     * @return \Illuminate\Http\Response
     */
    public function serveEpisodePlaylist(Request $request, $encodedId)
    {
        // Find the channel by ID
        if (strpos($encodedId, '==') === false) {
            $encodedId .= '=='; // right pad to ensure proper decoding
        }
        $episode = Episode::findOrFail(base64_decode($encodedId));

        // Serve the playlist for the episode
        return $this->servePlaylist(
            type: 'episode',
            encodedId: $encodedId,
            model: $episode,
            title: $episode->title
        );
    }

    /**
     * Serve individual .ts segments.
     * 
     * @param Request $request
     * @param int|string $episodeId
     * 
     * @return \Illuminate\Http\Response
     */
    public function serveEpisodeSegment(Request $request, $episodeId, $segment)
    {
        return $this->serveSegment(
            type: 'episode',
            modelId: $episodeId,
            segment: $segment
        );
    }

    /**
     * Serve the HLS playlist for a channel or episode.
     * 
     * @param string $type 'channel' or 'episode'
     * @param string $encodedId Base64 encoded ID of the channel or episode
     * @param mixed $model The Channel or Episode model instance
     * @param string $title The title of the channel or episode
     * 
     * @return \Illuminate\Http\Response
     */
    private function servePlaylist(
        $type,
        $encodedId,
        $model,
        $title
    ) {
        $actualStreamingModel = $model; // Initialize with the original model

        // First check if there's already a cached mapping for this original model to an active stream
        $streamMappingKey = "hls:stream_mapping:{$type}:{$model->id}";
        $activeStreamId = Cache::get($streamMappingKey);
        
        if ($activeStreamId && $this->hlsService->isRunning($type, $activeStreamId)) {
            // There's an active stream for a mapped channel, use that
            if ($activeStreamId != $model->id) {
                // It's a failover channel that's running
                if ($type === 'channel') {
                    $actualStreamingModel = Channel::find($activeStreamId);
                } else {
                    $actualStreamingModel = Episode::find($activeStreamId);
                }
                if ($actualStreamingModel) {
                    $logTitle = strip_tags($title);
                    // Log::channel('ffmpeg')->debug("HLS Stream: Found existing failover stream for original $type ID {$model->id} ({$logTitle}). Using active $type ID {$actualStreamingModel->id} (" . ($actualStreamingModel->title_custom ?? $actualStreamingModel->title) . ").");
                } else {
                    // The mapped model doesn't exist anymore, clear the mapping
                    Cache::forget($streamMappingKey);
                    $activeStreamId = null;
                }
            } else {
                // Log::channel('ffmpeg')->debug("HLS Stream: Found existing stream for $type ID {$model->id} (" . strip_tags($title) . ").");
            }
        }

        // If no active stream found, try to start one
        if (!$activeStreamId || !$this->hlsService->isRunning($type, $activeStreamId)) {
            $logTitle = strip_tags($title); // Use original title for initial logging
            try {
                // Attempt to start the stream, potentially with failover
                $returnedModel = $this->hlsService->startStream(
                    type: $type,
                    model: $model, // Pass original model to startStream
                    title: $logTitle
                );

                if ($returnedModel) {
                    $actualStreamingModel = $returnedModel; // This is the model that is actually streaming
                    Cache::put($streamMappingKey, $actualStreamingModel->id, now()->addHours(24));
                    Log::channel('ffmpeg')->debug("HLS Stream: Original request for $type ID {$model->id} ({$logTitle}). Actual streaming $type ID {$actualStreamingModel->id} (" . ($actualStreamingModel->title_custom ?? $actualStreamingModel->title) . ").");

                    // If a failover occurred (actual streaming model is different from the original),
                    // explicitly update the 'last_seen' timestamp for the *original* model ID.
                    // This prevents the pruning job from removing the original model's HLS data
                    // prematurely while the client switches to the failover stream.
                    if ($model->id != $actualStreamingModel->id) {
                        Redis::set("hls:{$type}_last_seen:{$model->id}", now()->timestamp);
                        Log::channel('ffmpeg')->debug("HLS Stream: Touched last_seen for original $type ID {$model->id} because failover to $type ID {$actualStreamingModel->id} occurred.");
                    }
                } else {
                    // This case should ideally be less frequent if AllPlaylistsExhaustedException is thrown for specific limit failures.
                    // This would now primarily catch other null returns from startStream.
                    Log::channel('ffmpeg')->error("HLS Stream: No stream could be started for $type ID {$model->id} ({$logTitle}) after trying all sources (and not due to all playlists exhausted).");
                    abort(503, 'Service unavailable. Failed to start the stream or any failovers.');
                }
            } catch (AllPlaylistsExhaustedException $e) {
                Log::channel('ffmpeg')->warning("HLS Stream: Max streams limit reached for all sources for $type ID {$model->id} ({$logTitle}). Message: {$e->getMessage()}");
                abort(429, 'Maximum stream limits have been reached for this channel. Please try again later.');
            } catch (Exception $e) {
                Log::channel('ffmpeg')->error("HLS Stream: Generic exception while trying to start stream for $type ID {$model->id} ({$logTitle}): {$e->getMessage()}");
                abort(503, 'Service unavailable. Error during stream startup.');
            }
        }

        // Use $actualStreamingModel->id for PID and path
        $pidCacheKey = "hls:pid:{$type}:{$actualStreamingModel->id}";
        $pid = Cache::get($pidCacheKey);

        $pathPrefix = $type === 'channel' ? '' : 'e/';
        $m3u8Path = Storage::disk('app')->path("hls/$pathPrefix{$actualStreamingModel->id}/stream.m3u8");

        // Log::channel('ffmpeg')->debug("HLS Stream: Checking for playlist for $type ID {$actualStreamingModel->id}. Path: {$m3u8Path}. PID found from cache key '{$pidCacheKey}': " . ($pid ?: 'None'));

        // Get configurable loop parameters
        $settings = app(GeneralSettings::class);
        $maxAttempts = $settings->hls_playlist_max_attempts ?? 10;
        $sleepSeconds = $settings->hls_playlist_sleep_seconds ?? 1.0;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if (file_exists($m3u8Path)) {
                return response('', 200, [
                    'Content-Type'      => 'application/vnd.apple.mpegurl',
                    'X-Accel-Redirect'  => "/internal/hls/$pathPrefix{$actualStreamingModel->id}/stream.m3u8",
                    'Cache-Control'     => 'no-cache, no-transform',
                    'Connection'        => 'keep-alive',
                ]);
            }

            if ($attempt === $maxAttempts) {
                // Check if the specific ffmpeg process for $actualStreamingModel->id is running
                $isActualPidRunning = $this->hlsService->isRunning($type, $actualStreamingModel->id); // Checks PID from cache AND process signal

                if (!$isActualPidRunning) {
                    // Use $pid obtained from $actualStreamingModel->id cache for logging, if available
                    Log::channel('ffmpeg')->error("HLS Stream: FFmpeg process for $type ID {$actualStreamingModel->id} (Cached PID: {$pid}) is not running or died. Aborting with 404 for M3U8: {$m3u8Path}");
                    abort(404, 'Playlist not found. Stream process ended.');
                }

                Log::channel('ffmpeg')->warning("HLS Stream: Playlist for $type ID {$actualStreamingModel->id} not ready after {$maxAttempts} attempts, but process (Cached PID: {$pid}) is running. Redirecting. M3U8: {$m3u8Path}");
                $route = $type === 'channel'
                    ? 'stream.hls.playlist'
                    : 'stream.hls.episode';

                // The redirect should still use the original encodedId as that's what the client initially requested.
                return redirect()
                    ->route($route, ['encodedId' => $encodedId])
                    ->with('error', 'Playlist not ready yet. Please try again.');
            }
            usleep((int)($sleepSeconds * 1000000));
        }
    }

    /**
     * Serve a segment for a channel or episode.
     * 
     * @param string $type 'channel' or 'episode'
     * @param int|string $modelId The ID of the channel or episode
     * @param string $segment The segment file name
     * 
     * @return \Illuminate\Http\Response
     */
    private function serveSegment($type, $modelId, $segment)
    {
        $pathPrefix = $type === 'channel' ? '' : 'e/';
        $path = Storage::disk('app')->path("hls/$pathPrefix{$modelId}/{$segment}");

        // Check if the segment file exists
        if (!file_exists($path)) {
            Log::channel('ffmpeg')->error("HLS Stream: Segment not found for $type ID {$modelId}. Path: {$path}. Segment: {$segment}");
            
            // Return an empty response, empty segments is normal during startup
            return response('Segment not found', 404, [
                'Content-Type' => 'video/MP2T',
                'Cache-Control' => 'no-cache, no-transform',
                'Connection' => 'keep-alive',
            ]);
        }

        Redis::transaction(function () use ($type, $modelId) {
            // Record timestamp in Redis (never expires until we prune)
            Redis::set("hls:{$type}_last_seen:{$modelId}", now()->timestamp);

            // Add to active IDs set
            Redis::sadd("hls:active_{$type}_ids", $modelId);
        });

        return response('', 200, [
            'Content-Type'     => 'video/MP2T',
            'X-Accel-Redirect' => "/internal/hls/$pathPrefix{$modelId}/{$segment}",
            'Cache-Control'    => 'no-cache, no-transform',
            'Connection'       => 'keep-alive',
        ]);
    }
}
