<?php

namespace App\Http\Controllers;

use Exception;
use App\Models\Channel;
use App\Models\Episode;
use App\Services\HlsStreamService;
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
            streamUrl: $channel->url_custom ?? $channel->url,
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
            streamUrl: $episode->url,
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
     * @param string $streamUrl The URL to stream from
     * @param string $title The title of the channel or episode
     * 
     * @return \Illuminate\Http\Response
     */
    private function servePlaylist(
        $type,
        $encodedId,
        $model,
        $streamUrl,
        $title
    ) {
        // Start stream, if not already running
        if (!$this->hlsService->isRunning(type: $type, id: $model->id)) {
            $title = strip_tags($title);
            $playlist = $model->playlist;
            try {
                $this->hlsService->startStream(
                    type: $type,
                    id: $model->id,
                    streamUrl: $streamUrl,
                    title: $title,
                    userAgent: $playlist->user_agent ?? null,
                );
                Log::channel('ffmpeg')->info("Started HLS stream for $type {$model->id} ({$title})");
            } catch (Exception $e) {
                Log::channel('ffmpeg')->error("Failed to start HLS stream for $type {$model->id} ({$title}): {$e->getMessage()}");
                abort(500, 'Failed to start the stream.');
            }
        } else {
            // Stream is already running, no need to start it again
            // Log::channel('ffmpeg')->info("HLS stream already running for $type {$model->id} ({$title})");
        }

        // Return the Playlist
        $pid = Cache::get("hls:pid:{$type}:{$model->id}");
        $pathPrefix = $type === 'channel' ? '' : 'e/';
        $path = Storage::disk('app')->path("hls/$pathPrefix{$model->id}/stream.m3u8");
        $maxAttempts = 10;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            // If the playlist is ready, serve it immediately
            if (file_exists($path)) {
                return response('', 200, [
                    'Content-Type'      => 'application/vnd.apple.mpegurl',
                    'X-Accel-Redirect'  => "/internal/hls/$pathPrefix{$model->id}/stream.m3u8",
                    'Cache-Control'     => 'no-cache, no-transform',
                    'Connection'        => 'keep-alive',
                ]);
            }

            // On the last try, give up if FFmpeg isn’t running
            if ($attempt === $maxAttempts) {
                if (!$pid || !posix_kill($pid, 0)) {
                    Log::channel('ffmpeg')
                        ->error("FFmpeg process {$pid} is not running (or died) for $type {$model->id}");
                    abort(404, 'Playlist not found.');
                }

                // If it *is* running but playlist never appeared, tell the client to retry
                $route = $type === 'channel'
                    ? 'stream.hls.playlist'
                    : 'stream.hls.episode';
                return redirect()
                    ->route($route, ['encodedId' => $encodedId])
                    ->with('error', 'Playlist not ready yet. Please try again.');
            }

            // Otherwise, wait and retry
            sleep(1);
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

        // If segment is not found, return 404 error
        abort_unless(file_exists($path), 404, 'Segment not found.');

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
