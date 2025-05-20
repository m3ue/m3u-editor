<?php

namespace App\Http\Controllers;

use Exception;
use App\Models\Channel;
use App\Services\HlsStreamService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

class ChannelHlsStreamController extends Controller
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
    public function __invoke(Request $request, $encodedId, $playlist = null)
    {
        // Find the channel by ID
        if (strpos($encodedId, '==') === false) {
            $encodedId .= '=='; // right pad to ensure proper decoding
        }
        $channel = Channel::findOrFail(base64_decode($encodedId));
        $channelId = $channel->id;
        $streamUrl = $channel->url_custom ?? $channel->url;
        $title = $channel->title_custom ?? $channel->title;
        $title = strip_tags($title);

        // Start stream, if not already running
        if (!$this->hlsService->isRunning($channelId)) {
            try {
                $this->hlsService->startStream($channelId, $streamUrl, $playlist);
                Log::channel('ffmpeg')->info("Started HLS stream for channel {$channelId} ({$title})");
            } catch (Exception $e) {
                Log::channel('ffmpeg')->error("Failed to start HLS stream for channel {$channelId} ({$title}): {$e->getMessage()}");
                abort(500, 'Failed to start the stream.');
            }
        } else {
            // Stream is already running, no need to start it again
            // Log::channel('ffmpeg')->info("HLS stream already running for channel {$channelId} ({$title})");
        }

        // Return the Playlist
        $pid = Cache::get("hls:pid:{$channelId}");
        $path = Storage::disk('app')->path("hls/{$channelId}/stream.m3u8");
        $maxAttempts = 10;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            // If the playlist is ready, serve it immediately
            if (file_exists($path)) {
                return response('', 200, [
                    'Content-Type'      => 'application/vnd.apple.mpegurl',
                    'X-Accel-Redirect'  => "/internal/hls/{$channelId}/stream.m3u8",
                    'Cache-Control'     => 'no-cache, no-transform',
                    'Connection'        => 'keep-alive',
                ]);
            }

            // On the last try, give up if FFmpeg isn’t running
            if ($attempt === $maxAttempts) {
                if (!$pid || !posix_kill($pid, 0)) {
                    Log::channel('ffmpeg')
                        ->error("FFmpeg process {$pid} is not running (or died) for channel {$channelId}");
                    abort(404, 'Playlist not found.');
                }

                // If it *is* running but playlist never appeared, tell the client to retry
                return redirect()
                    ->route('stream.hls.playlist', ['encodedId' => $encodedId])
                    ->with('error', 'Playlist not ready yet. Please try again.');
            }

            // Otherwise, wait and retry
            sleep(1);
        }
    }

    /**
     * Serve individual .ts segments.
     * 
     * @param Request $request
     * @param int|string $channelId
     * 
     * @return \Illuminate\Http\Response
     */
    public function serveSegment(Request $request, $channelId, $segment)
    {
        $path = Storage::disk('app')->path("hls/{$channelId}/{$segment}");

        // If segment is not found, return 404 error
        abort_unless(file_exists($path), 404, 'Segment not found.');

        // Record timestamp in Redis (never expires until we prune)
        Redis::set("hls:last_seen:{$channelId}", now()->timestamp);

        // Add to active IDs set
        Redis::sadd('hls:active_ids', $channelId);

        return response('', 200, [
            'Content-Type'     => 'video/mp2t',
            'X-Accel-Redirect' => "/internal/hls/{$channelId}/{$segment}",
            'Cache-Control'    => 'no-cache, no-transform',
            'Connection'       => 'keep-alive',
        ]);
    }
}
