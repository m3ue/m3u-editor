<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\Episode;
use App\Services\SharedStreamService;
use App\Services\ProxyService;
use App\Traits\TracksActiveStreams;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Shared Stream Controller - Implements xTeVe-like proxy functionality
 * 
 * This controller implements the core xTeVe functionality where multiple clients
 * can share the same upstream stream, reducing load on source servers and
 * providing better resource efficiency.
 */
class SharedStreamController extends Controller
{
    use TracksActiveStreams;

    private SharedStreamService $sharedStreamService;

    public function __construct(SharedStreamService $sharedStreamService)
    {
        $this->sharedStreamService = $sharedStreamService;
    }

    /**
     * Stream a channel using shared streaming (xTeVe-like)
     *
     * @param Request $request
     * @param string $encodedId
     * @param string $format
     * @return StreamedResponse
     */
    public function streamChannel(Request $request, string $encodedId, string $format = 'ts')
    {
        Log::channel('ffmpeg')->info("SharedStreamController: streamChannel called with encodedId: {$encodedId}, format: {$format}");

        // Validate format
        if (!in_array($format, ['ts', 'hls'])) {
            Log::channel('ffmpeg')->error("SharedStreamController: Invalid format specified: {$format}");
            abort(400, 'Invalid format specified. Use ts or hls.');
        }

        // Decode channel ID
        if (strpos($encodedId, '==') === false) {
            $encodedId .= '==';
        }

        $channelId = base64_decode($encodedId);
        Log::channel('ffmpeg')->debug("SharedStreamController: Decoded channel ID: {$channelId}");

        $channel = Channel::findOrFail($channelId);
        Log::channel('ffmpeg')->debug("SharedStreamController: Found channel: {$channel->title}");

        return $this->handleSharedStream($request, 'channel', $channel, $format);
    }

    /**
     * Stream an episode using shared streaming
     *
     * @param Request $request
     * @param string $encodedId
     * @param string $format
     * @return StreamedResponse
     */
    public function streamEpisode(Request $request, string $encodedId, string $format = 'ts')
    {
        // Validate format
        if (!in_array($format, ['ts', 'hls'])) {
            abort(400, 'Invalid format specified. Use ts or hls.');
        }

        // Decode episode ID
        if (strpos($encodedId, '==') === false) {
            $encodedId .= '==';
        }
        $episode = Episode::findOrFail(base64_decode($encodedId));

        return $this->handleSharedStream($request, 'episode', $episode, $format);
    }

    /**
     * Handle shared streaming for both channels and episodes
     */
    private function handleSharedStream(Request $request, string $type, $model, string $format)
    {
        Log::channel('ffmpeg')->info("SharedStreamController: Starting handleSharedStream for {$type} ID {$model->id}, format: {$format}");

        $title = $type === 'channel'
            ? ($model->title_custom ?? $model->title)
            : $model->title;
        $title = strip_tags($title);

        $streamUrl = $type === 'channel'
            ? ($model->url_custom ?? $model->url)
            : $model->url;

        if (!$streamUrl) {
            Log::channel('ffmpeg')->error("SharedStreamController: No stream URL available for {$type} ID {$model->id}");
            abort(404, 'No stream URL available.');
        }

        Log::channel('ffmpeg')->debug("SharedStreamController: Stream URL found for {$title}: {$streamUrl}");

        // Get playlist for stream limits
        $playlist = $model->getEffectivePlaylist();

        $clientId = $this->generateClientId($request);
        $userAgent = $playlist->user_agent ?? 'VLC/3.0.21';
        $ip = $request->headers->get('X-Forwarded-For', $request->ip());

        try {
            // Get or create shared stream
            $streamInfo = $this->sharedStreamService->getOrCreateSharedStream(
                $type,
                $model->id,
                $streamUrl,
                $title,
                $format,
                $clientId,
                [
                    'ip' => $ip,
                    'user_agent' => $userAgent,
                    'playlist_id' => $playlist->id,
                ],
                $model // Pass the full model for failover support
            );

            // Only check and increment stream limits for NEW streams
            if ($streamInfo['is_new_stream'] ?? false) {
                // Check stream limits
                $activeStreams = $this->incrementActiveStreams($playlist->id);
                if ($this->wouldExceedStreamLimit($playlist->id, $playlist->available_streams, $activeStreams)) {
                    $this->decrementActiveStreams($playlist->id);
                    // Clean up the just-created stream
                    $this->sharedStreamService->removeClient($streamInfo['stream_key'], $clientId);
                    abort(503, 'Maximum concurrent streams reached for this playlist.');
                }
            }

            Log::channel('ffmpeg')->info("Client {$clientId} connected to shared stream for {$type} {$title} ({$format})");

            // Return appropriate response based on format
            if ($format === 'hls') {
                return $this->streamHLS($streamInfo, $clientId, $request);
            } else {
                return $this->streamDirect($streamInfo, $clientId, $request);
            }
        } catch (\Exception $e) {
            // Only decrement if we incremented (for new streams)
            if (isset($streamInfo) && ($streamInfo['is_new_stream'] ?? false)) {
                $this->decrementActiveStreams($playlist->id);
            }
            Log::channel('ffmpeg')->error("Error starting shared stream for {$type} {$title}: " . $e->getMessage());
            abort(500, 'Failed to start stream: ' . $e->getMessage());
        }
    }

    /**
     * Stream HLS content
     */
    private function streamHLS(array $streamInfo, string $clientId, Request $request): Response
    {
        // Disable execution time limit for streaming
        @ini_set('max_execution_time', 0);
        @ini_set('output_buffering', 'off');
        @ini_set('implicit_flush', 1);

        $streamKey = $streamInfo['stream_key'];

        // Wait for stream to be ready
        $maxWait = 30; // 30 seconds
        $waited = 0;
        while ($waited < $maxWait) {
            $playlist = $this->sharedStreamService->getHLSPlaylist($streamKey, $clientId);
            if ($playlist) {
                return response($playlist, 200, [
                    'Content-Type' => 'application/vnd.apple.mpegurl',
                    'Cache-Control' => 'no-cache, no-store, must-revalidate',
                    'Connection' => 'keep-alive'
                ]);
            }
            sleep(1);
            $waited++;
        }

        abort(503, 'Stream not ready within timeout period');
    }

    /**
     * Stream direct TS content with shared buffering
     */
    private function streamDirect(array $streamInfo, string $clientId, Request $request): StreamedResponse
    {
        $streamKey = $streamInfo['stream_key'];

        // Step 2.1: Wait for stream to become active
        $streamReadyTimeout = 25; // seconds
        $startTime = time();
        $streamReady = false;

        Log::channel('ffmpeg')->debug("Stream {$streamKey}: Client {$clientId} waiting up to {$streamReadyTimeout}s for stream to become active.");

        while (time() - $startTime < $streamReadyTimeout) {
            $stats = $this->sharedStreamService->getStreamStats($streamKey);

            if (!$stats) {
                Log::channel('ffmpeg')->debug("Stream {$streamKey}: Client {$clientId} - Stream stats are null, possibly during a restart. Waiting.");
                usleep(500000); // 0.5 seconds
                continue;
            }

            if (in_array($stats['status'], ['error', 'stopped'])) {
                Log::channel('ffmpeg')->error("Stream {$streamKey}: Client {$clientId} - Stream status is '{$stats['status']}' while waiting for active. Aborting.");
                abort(503, 'Stream failed to start or is unavailable.');
            }

            if ($stats['status'] === 'active') {
                Log::channel('ffmpeg')->info("Stream {$streamKey}: Client {$clientId} - Stream is now active. Proceeding.");
                $streamReady = true;
                break;
            }

            Log::channel('ffmpeg')->debug("Stream {$streamKey}: Client {$clientId} - Waiting for active status. Current: {$stats['status']}. Time elapsed: " . (time() - $startTime) . "s.");
            usleep(500000); // 0.5 seconds
        }

        if (!$streamReady) {
            Log::channel('ffmpeg')->error("Stream {$streamKey}: Client {$clientId} - Stream did not become active within {$streamReadyTimeout}s timeout. Aborting.");
            abort(503, 'Stream not ready within timeout period.');
        }

        return new StreamedResponse(function () use ($streamKey, $clientId, $request) {
            @ini_set('max_execution_time', 0);
            @ini_set('output_buffering', 'off');
            @ini_set('implicit_flush', 1);
            ignore_user_abort(true);

            try {
                // Stream should already be active or starting from the service
                $stats = $this->sharedStreamService->getStreamStats($streamKey);
                if (!$stats || !in_array($stats['status'], ['active', 'starting'])) {
                    Log::channel('ffmpeg')->warning("Stream {$streamKey} is not active, aborting for client {$clientId}. Status: " . ($stats['status'] ?? 'unknown'));
                    return;
                }

                Log::channel('ffmpeg')->debug("Stream {$streamKey} is active, starting data flow for client {$clientId}");

                $lastSegment = -1; // Start at -1 so segment 0 will be retrieved
                $lastDataTime = time();
                $dataSent = false;
                $initialTimeout = 30; // 30 seconds to get the first chunk of data

                while (!connection_aborted()) {
                    $data = $this->sharedStreamService->getNextStreamSegments($streamKey, $clientId, $lastSegment);

                    if ($data) {
                        // Only log significant data transfers based on config
                        $debugLogging = config('app.shared_streaming.debug_logging', false);
                        $logThreshold = config('app.shared_streaming.log_data_threshold', 51200);

                        if (!$dataSent || $debugLogging || strlen($data) > $logThreshold) {
                            Log::channel('ffmpeg')->debug("Stream {$streamKey}: Client {$clientId} received " . round(strlen($data) / 1024, 1) . "KB. Segment: {$lastSegment}");
                        }

                        echo $data;
                        if (ob_get_level() > 0) {
                            ob_flush();
                            flush();
                        } else {
                            flush();
                        }

                        if (!$dataSent) {
                            Log::channel('ffmpeg')->info("Sent initial " . round(strlen($data) / 1024, 1) . "KB to client {$clientId}");
                            $dataSent = true;
                        }

                        $lastDataTime = time();
                        usleep(5000); // Small delay to prevent high CPU usage
                    } else {
                        // No data from stream
                        if (!$dataSent && (time() - $lastDataTime) > $initialTimeout) {
                            Log::channel('ffmpeg')->warning("Stream {$streamKey}: Client {$clientId} - Initial data timeout. No data received for {$initialTimeout}s. DataSent: " . ($dataSent ? 'yes' : 'no'));
                            break;
                        }

                        // Check if a failover is happening and extend timeout accordingly
                        $timeoutSeconds = 20; // Default timeout
                        $failoverExtendedTimeout = 60; // Extended timeout during failover

                        // Check if we're in the middle of a failover
                        $isFailoverHappening = $this->sharedStreamService->isFailoverInProgress($streamKey);

                        if ($isFailoverHappening) {
                            $timeoutSeconds = $failoverExtendedTimeout;
                            Log::channel('ffmpeg')->debug("Stream {$streamKey}: Client {$clientId} - Failover detected, extending timeout to {$timeoutSeconds}s");
                        }

                        if ($dataSent && (time() - $lastDataTime > $timeoutSeconds)) {
                            Log::channel('ffmpeg')->warning("Stream {$streamKey}: Client {$clientId} - Subsequent data timeout. No data from stream for {$timeoutSeconds}s. DataSent: " . ($dataSent ? 'yes' : 'no') . ". Failover: " . ($isFailoverHappening ? 'yes' : 'no'));
                            break;
                        }

                        usleep(100000); // Wait before trying for more data
                    }

                    // !! NOTE: Causing false possitives, and the stream being killed and restarted prematurely
                    // Check stream status periodically to detect if the source has died
                    // if ($lastSegment > 0 && $lastSegment % 100 === 0) {
                    //     $currentStats = $this->sharedStreamService->getStreamStats($streamKey);
                    //     if (!$currentStats || !in_array($currentStats['status'], ['active', 'starting'])) {
                    //         Log::channel('ffmpeg')->info("Stream {$streamKey} is no longer active, disconnecting client {$clientId}.");
                    //         break;
                    //     }
                    // }
                }
            } finally {
                Log::channel('ffmpeg')->info("Stream {$streamKey}: Client {$clientId} disconnecting. Attempting to remove client from stream service.");
                $this->sharedStreamService->removeClient($streamKey, $clientId);
                Log::channel('ffmpeg')->info("Stream {$streamKey}: Client {$clientId} successfully removed by stream service.");
            }
        }, 200, [
            'Content-Type' => 'video/MP2T',
            'Cache-Control' => 'no-cache, no-store, must-revalidate, private',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
            'Accept-Ranges' => 'none',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'Transfer-Encoding' => 'chunked'
        ]);
    }

    /**
     * Serve HLS segment for shared stream
     */
    public function serveHLSSegment(Request $request, string $encodedId, string $segment)
    {
        // Decode channel/episode ID
        if (strpos($encodedId, '==') === false) {
            $encodedId .= '==';
        }
        $modelId = base64_decode($encodedId);

        // Generate client ID and stream key
        $clientId = $this->generateClientId($request);

        // Try to find the stream (we need to determine if it's channel or episode)
        $streamKey = null;
        $model = Channel::find($modelId);
        if ($model) {
            $streamUrl = $model->url_custom ?? $model->url;
            $streamKey = md5("channel:{$modelId}:{$streamUrl}");
        } else {
            $model = Episode::find($modelId);
            if ($model) {
                $streamUrl = $model->url;
                $streamKey = md5("episode:{$modelId}:{$streamUrl}");
            }
        }

        if (!$streamKey || !$model) {
            abort(404, 'Stream not found');
        }

        // Get segment data from shared stream
        $segmentData = $this->sharedStreamService->getHLSSegment($streamKey, $clientId, $segment);

        if (!$segmentData) {
            abort(404, 'Segment not found');
        }

        return response($segmentData, 200, [
            'Content-Type' => 'video/MP2T',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive'
        ]);
    }

    /**
     * Get stream statistics (similar to xTeVe's web interface)
     */
    public function getStreamStats(Request $request): \Illuminate\Http\JsonResponse
    {
        $streams = $this->sharedStreamService->getAllActiveStreams();

        $stats = [
            'total_streams' => count($streams),
            'total_clients' => array_sum(array_column($streams, 'client_count')),
            'streams' => []
        ];

        foreach ($streams as $streamKey => $streamData) {
            $streamInfo = $streamData['stream_info'];
            $stats['streams'][] = [
                'key' => $streamKey,
                'type' => $streamInfo['type'],
                'title' => $streamInfo['title'],
                'format' => $streamInfo['format'],
                'client_count' => $streamData['client_count'],
                'uptime' => $streamData['uptime'],
                'status' => $streamInfo['status'],
                'clients' => $streamData['clients']
            ];
        }

        return response()->json($stats);
    }

    /**
     * Stop a specific shared stream (admin function)
     */
    public function stopStream(Request $request, string $streamKey): \Illuminate\Http\JsonResponse
    {
        $stats = $this->sharedStreamService->getStreamStats($streamKey);
        if (!$stats) {
            abort(404, 'Stream not found');
        }

        // Remove all clients to trigger cleanup
        foreach ($stats['clients'] as $client) {
            $this->sharedStreamService->removeClient($streamKey, $client['id']);
        }

        Log::channel('ffmpeg')->info("Manually stopped shared stream {$streamKey}");

        return response()->json(['message' => 'Stream stopped successfully']);
    }

    /**
     * Test streaming with the provided URL
     */
    public function testStream(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'url' => 'required|url',
            'format' => 'in:ts,hls'
        ]);

        $streamUrl = $request->input('url');
        $format = $request->input('format', 'ts');
        $clientId = $this->generateClientId($request);

        try {
            // Create a test stream
            $streamInfo = $this->sharedStreamService->getOrCreateSharedStream(
                'test',
                0,
                $streamUrl,
                'Test Stream: ' . parse_url($streamUrl, PHP_URL_HOST),
                $format,
                $clientId,
                [
                    'user_agent' => 'VLC/3.0.21',
                    'test' => true,
                    'ip' => $request->ip()
                ]
            );

            return response()->json([
                'message' => 'Test stream started successfully',
                'stream_key' => $streamInfo['stream_key'],
                'stream_url' => $format === 'hls'
                    ? route('shared.stream.hls', ['streamKey' => $streamInfo['stream_key']])
                    : route('shared.stream.direct', ['streamKey' => $streamInfo['stream_key']])
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to start test stream: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate unique client ID
     */
    private function generateClientId(Request $request): string
    {
        $ip = $request->headers->get('X-Forwarded-For', $request->ip());
        return md5($ip . $request->userAgent() . microtime(true));
    }

    /**
     * Serve shared stream directly (for testing)
     */
    public function serveSharedStream(Request $request, string $streamKey)
    {
        $clientId = $this->generateClientId($request);

        try {
            $data = $this->sharedStreamService->getStreamData($streamKey, $clientId);

            if (!$data) {
                abort(404, 'Stream not found or no data available');
            }

            return response($data, 200, [
                'Content-Type' => 'video/MP2T',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Connection' => 'keep-alive'
            ]);
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Error serving shared stream {$streamKey}: " . $e->getMessage());
            abort(500, 'Stream error');
        }
    }

    /**
     * Serve HLS playlist for shared stream (for testing)
     */
    public function serveHLS(Request $request, string $streamKey)
    {
        $clientId = $this->generateClientId($request);

        try {
            $playlist = $this->sharedStreamService->getHLSPlaylist($streamKey, $clientId);

            if (!$playlist) {
                abort(404, 'Stream not found or playlist not ready');
            }

            return response($playlist, 200, [
                'Content-Type' => 'application/vnd.apple.mpegurl',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Connection' => 'keep-alive'
            ]);
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Error serving HLS playlist for {$streamKey}: " . $e->getMessage());
            abort(500, 'Stream error');
        }
    }
}
