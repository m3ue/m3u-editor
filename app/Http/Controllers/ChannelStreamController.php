<?php

namespace App\Http\Controllers;

use Exception;
use App\Models\Channel;
use App\Settings\GeneralSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Process as SymphonyProcess;

class ChannelStreamController extends Controller
{
    /**
     * Stream an IPTV channel.
     *
     * @param Request $request
     * @param int|string $id
     * @param string $format
     *
     * @return StreamedResponse
     */
    public function __invoke(Request $request, $id, $format = 'mp2t')
    {
        // Validate the format
        if (!in_array($format, ['mp2t', 'mp4'])) {
            abort(400, 'Invalid format specified.');
        }

        // Prevent timeouts, etc.
        ini_set('max_execution_time', 0);
        ini_set('output_buffering', 'off');
        ini_set('implicit_flush', 1);

        // Find the channel by ID
        if (strpos($id, '==') === false) {
            $id .= '=='; // right pad to ensure proper decoding
        }
        $channel = Channel::findOrFail(base64_decode($id));
        $title = $channel->title_custom ?? $channel->title;
        $title = strip_tags($title);

        // Setup streams array
        $streamUrls = [
            $channel->url_custom ?? $channel->url
            // leave this here for future use...
            // @TODO: implement ability to assign fallback channels
        ];

        // Get user preferences
        $userPreferences = app(GeneralSettings::class);
        $settings = [
            'ffmpeg_debug' => false,
            'ffmpeg_max_tries' => 3,
            'ffmpeg_user_agent' => 'VLC/3.0.21 LibVLC/3.0.21',
        ];
        try {
            $settings = [
                'ffmpeg_debug' => $userPreferences->ffmpeg_debug ?? $settings['ffmpeg_debug'],
                'ffmpeg_max_tries' => $userPreferences->ffmpeg_max_tries ?? $settings['ffmpeg_max_tries'],
                'ffmpeg_user_agent' => $userPreferences->ffmpeg_user_agent ?? $settings['ffmpeg_user_agent'],
            ];
        } catch (Exception $e) {
            // Ignore
        }
        $extension = $format === 'mp2t'
            ? 'ts'
            : $format;
        return new StreamedResponse(function () use ($streamUrls, $title, $settings, $format) {
            while (ob_get_level()) {
                ob_end_flush();
            }
            flush();

            // Disable output buffering to ensure real-time streaming
            ini_set('zlib.output_compression', 0);

            // Get user agent
            $userAgent = escapeshellarg($settings['ffmpeg_user_agent']);
            $maxRetries = $settings['ffmpeg_max_tries'];

            // Loop through available streams...
            $output = $format === 'mp2t'
                ? '-c copy -f mpegts pipe:1'
                : '-c:v copy -c:a copy -bsf:a aac_adtstoasc -f mp4 -movflags frag_keyframe+empty_moov+default_base_moof pipe:1';
            foreach ($streamUrls as $streamUrl) {
                $cmd = sprintf(
                    'ffmpeg ' .
                        // Pre-input HTTP options:
                        '-user_agent "%s" -referer "MyComputer" ' .
                        '-multiple_requests 1 -reconnect_on_network_error 1 ' .
                        '-reconnect_on_http_error 5xx,4xx -reconnect_streamed 1 ' .
                        '-reconnect_delay_max 5 -noautorotate ' .

                        // Input:
                        '-re -i "%s" ' .

                        // Output:
                        '%s ' .

                        // Logging:
                        '%s',
                    $userAgent,                   // for -user_agent
                    $streamUrl,                   // input URL
                    $output,                      // for -f
                    $settings['ffmpeg_debug'] ? '' : '-hide_banner -nostats -loglevel error'
                );

                // Continue trying until the client disconnects, or max retries are reached
                $retries = 0;
                while (!connection_aborted()) {
                    $process = SymphonyProcess::fromShellCommandline($cmd);
                    $process->setTimeout(null);
                    try {
                        $process->run(function ($type, $buffer) {
                            if (connection_aborted()) {
                                throw new \Exception("Connection aborted by client.");
                            }
                            if ($type === SymphonyProcess::OUT) {
                                echo $buffer;
                                flush();
                                usleep(10000); // Reduce CPU usage
                            }
                            if ($type === SymphonyProcess::ERR) {
                                Log::channel('ffmpeg')->error($buffer);
                            }
                        });
                    } catch (\Exception $e) {
                        // Log eror and attempt to reconnect.
                        if (!connection_aborted()) {
                            Log::channel('ffmpeg')
                                ->error("Error streaming channel (\"$title\"): " . $e->getMessage());
                        }
                    }
                    // If we get here, the process ended.
                    if (connection_aborted()) {
                        if ($process->isRunning()) {
                            $process->stop(1); // SIGTERM then SIGKILL
                        }
                        return;
                    }
                    if (++$retries >= $maxRetries) {
                        // Log error and stop trying this stream...
                        Log::channel('ffmpeg')
                            ->error("FFmpeg error: max retries of $maxRetries reached for stream for channel $title.");

                        // ...break and try the next stream
                        break;
                    }
                    // Wait a short period before trying to reconnect.
                    sleep(min(8, $retries));
                }
            }

            echo "Error: No available streams.";
        }, 200, [
            'Content-Type' => "video/$format",
            'Connection' => 'keep-alive',
            'Cache-Control' => 'no-store, no-transform',
            'Content-Disposition' => "inline; filename=\"stream.$extension\"",
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Launch (or re-launch) an FFmpeg HLS job for this channel,
     * then redirect clients to the static .m3u8 URL.
     * 
     * @param Request $request
     * @param int|string $id
     * 
     * @return \Illuminate\Http\RedirectResponse
     */
    public function startHls(Request $request, $id)
    {
        // Find the channel by ID
        if (strpos($id, '==') === false) {
            $id .= '=='; // right pad to ensure proper decoding
        }
        $channel = Channel::findOrFail(base64_decode($id));
        $title = $channel->title_custom ?? $channel->title;
        $title = strip_tags($title);
        $streamUrl = $channel->url_custom ?? $channel->url;

        $storageDir = Storage::disk('app')->path("hls/{$channel->id}");
        File::ensureDirectoryExists($storageDir, 0755);

        // Get user preferences
        $userPreferences = app(GeneralSettings::class);
        $settings = [
            'ffmpeg_debug' => false,
            'ffmpeg_max_tries' => 3,
            'ffmpeg_user_agent' => 'VLC/3.0.21 LibVLC/3.0.21',
        ];
        try {
            $settings = [
                'ffmpeg_debug' => $userPreferences->ffmpeg_debug ?? $settings['ffmpeg_debug'],
                'ffmpeg_max_tries' => $userPreferences->ffmpeg_max_tries ?? $settings['ffmpeg_max_tries'],
                'ffmpeg_user_agent' => $userPreferences->ffmpeg_user_agent ?? $settings['ffmpeg_user_agent'],
            ];
        } catch (Exception $e) {
            // Ignore
        }

        // Get user agent
        $userAgent = escapeshellarg($settings['ffmpeg_user_agent']);

        // Only start one FFmpeg per channel at a time
        $cacheKey = "hls:pid:{$channel->id}";
        $existingPid = Cache::get($cacheKey);

        /*
         * NOTE: Opting to not kill existing FFmpeg processes
         *       in case multiple clients connect to same channel.
         *       May want to revisit this later.
         */
        // (Optionally) kill any currently running existing FFmpeg process
        // if ($existingPid && $this->isFfmpeg($existingPid)) {
        //     posix_kill($existingPid, SIGTERM);
        //     sleep(1);
        //     if (posix_kill($existingPid, 0)) {
        //         posix_kill($existingPid, SIGKILL);
        //     }
        // }

        // If no existing process, or it’s not FFmpeg, start a new one
        if (!$existingPid || !$this->isFfmpeg($existingPid)) {
            $playlist = "{$storageDir}/stream.m3u8";
            $segment = "{$storageDir}/segment_%03d.ts";
            $segmentBaseUrl = url("/stream/hls/{$channel->id}") . '/';

            $cmd = sprintf(
                'ffmpeg ' .
                    // Optimization options:
                    '-fflags nobuffer -flags low_delay ' .

                    // Pre-input HTTP options:
                    '-user_agent "%s" -referer "MyComputer" ' .
                    '-multiple_requests 1 -reconnect_on_network_error 1 ' .
                    '-reconnect_on_http_error 5xx,4xx -reconnect_streamed 1 ' .
                    '-reconnect_delay_max 5 -noautorotate ' .

                    // I/O options:
                    '-re -i "%s" ' .
                    '-c:v libx264 -preset veryfast -g 15 -keyint_min 15 -sc_threshold 0 ' .
                    '-c:a aac -f hls -hls_time 1 -hls_list_size 4 ' .
                    '-hls_flags delete_segments+append_list ' .
                    '-use_wallclock_as_timestamps 1 ' .
                    '-hls_segment_filename %s ' .
                    '-hls_base_url %s %s ' .

                    // Logging:
                    '%s',
                $userAgent,                   // for -user_agent
                $streamUrl,                   // input URL
                $segment,                     // segment filename 
                $segmentBaseUrl,              // base URL for segments (want to make sure routed through the proxy to track active users)
                $playlist,                    // playlist filename
                $settings['ffmpeg_debug'] ? '' : '-hide_banner -nostats -loglevel error'
            );

            // Tell proc_open to give us back a stderr pipe
            $descriptors = [
                0 => ['pipe', 'r'], // stdin (we won't use)
                1 => ['pipe', 'w'], // stdout (we won't use)
                2 => ['pipe', 'w'], // stderr (we will log)
            ];
            $pipes = [];
            $process = proc_open($cmd, $descriptors, $pipes);

            if (!is_resource($process)) {
                Log::channel('ffmpeg')->error("Failed to launch FFmpeg for channel {$channel->id}");
                abort(500, 'Could not start stream.');
            }

            // Immediately close stdin/stdout
            fclose($pipes[0]);
            fclose($pipes[1]);

            // Make stderr non-blocking
            stream_set_blocking($pipes[2], false);

            // Spawn a little "reader" that pulls from stderr and logs
            $logger = Log::channel('ffmpeg');
            $stderr = $pipes[2];

            // Register shutdown function to ensure the pipe is drained
            register_shutdown_function(function () use ($stderr, $process, $logger) {
                while (!feof($stderr)) {
                    $line = fgets($stderr);
                    if ($line !== false) {
                        $logger->error(trim($line));
                    }
                }
                fclose($stderr);
                proc_close($process);
            });

            // Cache the actual FFmpeg PID
            $status = proc_get_status($process);
            Cache::forever("hls:pid:{$channel->id}", $status['pid']);

            // Record timestamp in Redis (never expires until we prune)
            Redis::set("hls:last_seen:{$channel->id}", now()->timestamp);

            // Add to active IDs set
            Redis::sadd('hls:active_ids', $channel->id);
        }

        // Redirect the client to the playlist URL
        return redirect()->route('stream.hls.playlist', ['id' => $channel->id]);
    }

    /**
     * Serve the generated playlist as a static file.
     * 
     * @param Request $request
     * @param int|string $id
     * 
     * @return \Illuminate\Http\Response
     */
    public function servePlaylist(Request $request, $id)
    {
        $cacheKeyPid = "hls:pid:{$id}";
        $pid = Cache::get($cacheKeyPid);
        $path = Storage::disk('app')->path("hls/{$id}/stream.m3u8");

        $maxAttempts = 10;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            // If the playlist is ready, serve it immediately
            if (file_exists($path)) {
                return response()->file($path, [
                    'Content-Type' => 'application/vnd.apple.mpegurl',
                ]);
            }

            // On the last try, give up if FFmpeg isn’t running
            if ($attempt === $maxAttempts) {
                if (!$pid || !posix_kill($pid, 0)) {
                    Log::channel('ffmpeg')
                        ->error("FFmpeg process {$pid} is not running (or died) for channel {$id}");
                    abort(404, 'Playlist not found.');
                }

                // If it *is* running but playlist never appeared, tell the client to retry
                return redirect()
                    ->route('stream.hls.playlist', ['id' => $id])
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
     * @param int|string $id
     * 
     * @return \Illuminate\Http\Response
     */
    public function serveSegment(Request $request, $id, $segment)
    {
        $path = Storage::disk('app')->path("hls/{$id}/{$segment}");
        abort_unless(file_exists($path), 404, 'Segment not found.');

        // Record timestamp in Redis (never expires until we prune)
        Redis::set("hls:last_seen:{$id}", now()->timestamp);

        // Add to active IDs set
        Redis::sadd('hls:active_ids', $id);

        return response()->file($path, [
            'Content-Type' => 'video/mp2t',
        ]);
    }

    /**
     * Return true if $pid is alive and matches an ffmpeg command.
     */
    protected function isFfmpeg(int $pid): bool
    {
        $cmdlinePath = "/proc/{$pid}/cmdline";
        if (! file_exists($cmdlinePath)) {
            return false;
        }

        $cmd = @file_get_contents($cmdlinePath);
        // FFmpeg’s binary name should appear first
        return $cmd && strpos($cmd, 'ffmpeg') !== false;
    }
}
