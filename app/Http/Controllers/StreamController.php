<?php

namespace App\Http\Controllers;

use Exception;
use App\Models\Channel;
use App\Models\Episode;
use App\Services\ProxyService;
use App\Exceptions\SourceNotResponding;
use App\Traits\TracksActiveStreams;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Process as SymfonyProcess;

class StreamController extends Controller
{
    use TracksActiveStreams;

    /**
     * Stream a channel.
     *
     * @param Request $request
     * @param int|string $encodedId
     * @param string $format
     *
     * @return StreamedResponse
     */
    public function __invoke(
        Request $request,
        $encodedId,
        $format = 'ts',
    ) {
        // Validate the format
        if (!in_array($format, ['ts', 'mp4'])) {
            abort(400, 'Invalid format specified.');
        }

        // Find the channel by ID
        if (strpos($encodedId, '==') === false) {
            $encodedId .= '=='; // right pad to ensure proper decoding
        }
        $channel = Channel::findOrFail(base64_decode($encodedId));

        // Get the failover channels (if any)
        $sourceChannel = $channel;
        $streams = collect([$channel])->concat($channel->failoverChannels);

        // Loop over the failover channels and grab the first one that works.
        foreach ($streams as $stream) {
            // Get the title for the channel
            $title = $stream->title_custom ?? $stream->title;
            $title = strip_tags($title);

            // Setup streams array
            $streamUrl = $stream->url_custom ?? $stream->url;
            if ($stream->is_custom && !$streamUrl) {
                Log::channel('ffmpeg')->debug("Custom channel {$stream->id} ({$title}) has no URL set. Using failover channels only.");
                continue; // Skip if no URL is set
            }

            // Check if playlist is specified
            $playlist = $stream->getEffectivePlaylist();

            // Make sure we have a valid source channel
            $badSourceCacheKey = ProxyService::BAD_SOURCE_CACHE_PREFIX . $stream->id . ':' . $playlist->id;
            if (Redis::exists($badSourceCacheKey)) {
                if ($sourceChannel->id === $stream->id) {
                    Log::channel('ffmpeg')->debug("Skipping source ID {$title} ({$sourceChannel->id}) for as it was recently marked as bad. Reason: " . (Redis::get($badSourceCacheKey) ?: 'N/A'));
                } else {
                    Log::channel('ffmpeg')->debug("Skipping Failover Channel {$stream->name} for source {$title} ({$sourceChannel->id}) as it was recently marked as bad. Reason: " . (Redis::get($badSourceCacheKey) ?: 'N/A'));
                }
                continue;
            }

            // Keep track of the active streams for this playlist using optimistic locking pattern
            $activeStreams = $this->incrementActiveStreams($playlist->id);

            // Then check if we're over limit
            // Ignore for MP4 since those will be requests from Video.js
            // Video.js will make a request for the metadate before loading the stream, so can use twp connections in a short amount of time
            if ($format !== 'mp4' && $this->wouldExceedStreamLimit($playlist->id, $playlist->available_streams, $activeStreams)) {
                // We're over limit, so decrement and skip
                $this->decrementActiveStreams($playlist->id);
                Log::channel('ffmpeg')->debug("Max streams reached for playlist {$playlist->name} ({$playlist->id}). Skipping channel {$title}.");
                continue;
            }

            // Determine the output format
            // Check if `X-Forwarded-For` header is set, otherwise use the request IP
            if ($request->headers->has('X-Forwarded-For')) {
                $ip = $request->headers->get('X-Forwarded-For');
            } else {
                $ip = $request->ip();
            }
            $streamId = uniqid();
            $channelId = $stream->id;
            $contentType = $format === 'ts' ? 'video/MP2T' : 'video/mp4';

            // Start the stream for the current source (primary or failover channel)
            try {
                return $this->startStream(
                    type: 'channel',
                    modelId: $channelId,
                    streamUrl: $streamUrl,
                    title: $title,
                    format: $format,
                    ip: $ip,
                    streamId: $streamId,
                    contentType: $contentType,
                    userAgent: $playlist->user_agent ?? null,
                    failoverSupport: $format === 'ts', // Only support failover for TS streams
                    playlistId: $playlist->id
                );
            } catch (SourceNotResponding $e) {
                // Log the error and cache the bad source
                $this->decrementActiveStreams($playlist->id);
                Log::channel('ffmpeg')->error("Source not responding for channel {$title}: " . $e->getMessage());
                Redis::setex($badSourceCacheKey, ProxyService::BAD_SOURCE_CACHE_SECONDS, $e->getMessage());

                // Try the next failover channel
                continue;
            } catch (Exception $e) {
                // Log the error and abort
                $this->decrementActiveStreams($playlist->id);
                Log::channel('ffmpeg')->error("Error streaming channel {$title}: " . $e->getMessage());
                Redis::setex($badSourceCacheKey, ProxyService::BAD_SOURCE_CACHE_SECONDS, $e->getMessage());

                // Try the next failover channel
                continue;
            }
        }

        // Out of streams to try
        Log::channel('ffmpeg')->error("No available streams for channel {$channel->id} ({$channel->title}).");
        abort(503, 'No valid streams found for this channel.');
    }

    /**
     * Stream an episode.
     *
     * @param Request $request
     * @param int|string $encodedId
     * @param string $format
     *
     * @return StreamedResponse
     */
    public function episode(
        Request $request,
        $encodedId,
        $format = 'ts',
    ) {
        // Validate the format
        if (!in_array($format, ['ts', 'mp4'])) {
            abort(400, 'Invalid format specified.');
        }

        // Find the channel by ID
        if (strpos($encodedId, '==') === false) {
            $encodedId .= '=='; // right pad to ensure proper decoding
        }
        $episode = Episode::findOrFail(base64_decode($encodedId));
        $title = $episode->title;
        $title = strip_tags($title);

        // Check if playlist is specified
        $playlist = $episode->getEffectivePlaylist();

        // Keep track of the active streams for this playlist using optimistic locking pattern
        $activeStreams = $this->incrementActiveStreams($playlist->id);

        // Then check if we're over limit
        // Ignore for MP4 since those will be requests from Video.js
        // Video.js will make a request for the metadate before loading the stream, so can use twp connections in a short amount of time
        if ($format !== 'mp4' && $this->wouldExceedStreamLimit($playlist->id, $playlist->available_streams, $activeStreams)) {
            // We're over limit, so decrement and skip
            $this->decrementActiveStreams($playlist->id);
            Log::channel('ffmpeg')->debug("Max streams reached for playlist {$playlist->name} ({$playlist->id}). Aborting episode {$title}.");
            abort(503, 'Max streams reached for this playlist.');
        }

        // Setup streams array
        $streamUrl = $episode->url;

        // Determine the output format
        // Check if `X-Forwarded-For` header is set, otherwise use the request IP
        if ($request->headers->has('X-Forwarded-For')) {
            $ip = $request->headers->get('X-Forwarded-For');
        } else {
            $ip = $request->ip();
        }
        $streamId = uniqid();
        $episodeId = $episode->id;
        $contentType = $format === 'ts' ? 'video/MP2T' : 'video/mp4';

        // Start the stream
        return $this->startStream(
            type: 'episode',
            modelId: $episodeId,
            streamUrl: $streamUrl,
            title: $title,
            format: $format,
            ip: $ip,
            streamId: $streamId,
            contentType: $contentType,
            userAgent: $playlist->user_agent ?? null,
            failoverSupport: $format === 'ts', // Only support failover for TS streams
            playlistId: $playlist->id
        );
    }

    /**
     * Start the stream using FFmpeg.
     *
     * @param string $type
     * @param int $modelId
     * @param string $streamUrl
     * @param string $title
     * @param string $format
     * @param string $ip
     * @param string $streamId
     * @param string $contentType
     * @param string|null $userAgent
     * @param bool $failoverSupport Whether to support failover streams
     * @param int|null $playlistId Optional playlist ID for tracking active streams
     *
     * @return StreamedResponse
     */
    private function startStream(
        $type,
        $modelId,
        $streamUrl,
        $title,
        $format,
        $ip,
        $streamId,
        $contentType,
        $userAgent,
        $failoverSupport = false,
        $playlistId = null
    ) {
        // Prevent timeouts, etc.
        ini_set('max_execution_time', 0);
        ini_set('output_buffering', 'off');
        ini_set('implicit_flush', 1);

        // Get user preferences
        $settings = ProxyService::getStreamSettings();

        // Get user agent
        $userAgent = escapeshellarg($userAgent) ?: escapeshellarg($settings['ffmpeg_user_agent']);

        // If failover support is enabled, we need to run a pre-check with ffprobe to ensure the source is valid
        if ($failoverSupport) {
            // Determine the command/path for ffmpeg execution first
            $ffmpegExecutable = config('proxy.ffmpeg_path') ?: $settings['ffmpeg_path'];
            if (empty($ffmpegExecutable)) {
                $ffmpegExecutable = 'jellyfin-ffmpeg'; // Default ffmpeg command
            }

            // Next, derive the ffprobe path based on `ffmpegExecutable`
            if (str_contains($ffmpegExecutable, '/')) {
                $ffprobePath = dirname($ffmpegExecutable) . '/ffprobe';
            } else {
                $ffprobePath = 'ffprobe';
            }
            $ffprobeTimeout = $settings['ffmpeg_ffprobe_timeout'] ?? 5; // Default timeout for ffprobe

            // Updated command to include -show_format and remove -select_streams to get all streams for detailed info
            $precheckCmd = "$ffprobePath -v quiet -print_format json -show_streams -show_format -user_agent " . $userAgent . " " . escapeshellarg($streamUrl);

            Log::channel('ffmpeg')->debug("[PRE-CHECK] Executing ffprobe command for [{$title}] with timeout {$ffprobeTimeout}s: {$precheckCmd}");
            $precheckProcess = SymfonyProcess::fromShellCommandline($precheckCmd);
            $precheckProcess->setTimeout($ffprobeTimeout);
            try {
                $precheckProcess->run();
                if (!$precheckProcess->isSuccessful()) {
                    Log::channel('ffmpeg')->error("[PRE-CHECK] ffprobe failed for source [{$title}]. Exit Code: " . $precheckProcess->getExitCode() . ". Error Output: " . $precheckProcess->getErrorOutput());
                    throw new SourceNotResponding("failed_ffprobe (Exit: " . $precheckProcess->getExitCode() . ")");
                }
                Log::channel('ffmpeg')->debug("[PRE-CHECK] ffprobe successful for source [{$title}].");

                // Check channel health
                $ffprobeJsonOutput = $precheckProcess->getOutput();
                $streamInfo = json_decode($ffprobeJsonOutput, true);
                $extractedDetails = [];

                if (json_last_error() === JSON_ERROR_NONE && !empty($streamInfo)) {
                    // Format Section
                    if (isset($streamInfo['format'])) {
                        $streamFormat = $streamInfo['format'];
                        $extractedDetails['format'] = [
                            'duration' => $streamFormat['duration'] ?? null,
                            'size' => $streamFormat['size'] ?? null,
                            'bit_rate' => $streamFormat['bit_rate'] ?? null,
                            'nb_streams' => $streamFormat['nb_streams'] ?? null,
                            'tags' => $streamFormat['tags'] ?? [],
                        ];
                    }

                    $videoStreamFound = false;
                    $audioStreamFound = false;

                    if (isset($streamInfo['streams']) && is_array($streamInfo['streams'])) {
                        foreach ($streamInfo['streams'] as $stream) {
                            if (!$videoStreamFound && isset($stream['codec_type']) && $stream['codec_type'] === 'video') {
                                $extractedDetails['video'] = [
                                    'codec_long_name' => $stream['codec_long_name'] ?? null,
                                    'width' => $stream['width'] ?? null,
                                    'height' => $stream['height'] ?? null,
                                    'color_range' => $stream['color_range'] ?? null,
                                    'color_space' => $stream['color_space'] ?? null,
                                    'color_transfer' => $stream['color_transfer'] ?? null,
                                    'color_primaries' => $stream['color_primaries'] ?? null,
                                    'tags' => $stream['tags'] ?? [],
                                ];
                                $logResolution = ($stream['width'] ?? 'N/A') . 'x' . ($stream['height'] ?? 'N/A');
                                Log::channel('ffmpeg')->debug(
                                    "[PRE-CHECK] Source [{$title}] video stream: " .
                                        "Codec: " . ($stream['codec_name'] ?? 'N/A') . ", " .
                                        "Format: " . ($stream['pix_fmt'] ?? 'N/A') . ", " .
                                        "Resolution: " . $logResolution . ", " .
                                        "Profile: " . ($stream['profile'] ?? 'N/A') . ", " .
                                        "Level: " . ($stream['level'] ?? 'N/A')
                                );
                                $videoStreamFound = true;
                            } elseif (!$audioStreamFound && isset($stream['codec_type']) && $stream['codec_type'] === 'audio') {
                                $extractedDetails['audio'] = [
                                    'codec_name' => $stream['codec_name'] ?? null,
                                    'profile' => $stream['profile'] ?? null,
                                    'channels' => $stream['channels'] ?? null,
                                    'channel_layout' => $stream['channel_layout'] ?? null,
                                    'tags' => $stream['tags'] ?? [],
                                ];
                                $audioStreamFound = true;
                            }
                            if ($videoStreamFound && $audioStreamFound) {
                                break;
                            }
                        }
                    }
                    if (!empty($extractedDetails)) {
                        $detailsCacheKey = "mpts:streaminfo:details:{$streamId}";
                        Redis::setex($detailsCacheKey, 86400, json_encode($extractedDetails)); // Cache for 24 hours
                        Log::channel('ffmpeg')->debug("[PRE-CHECK] Cached detailed streaminfo for {$type} ID {$modelId}.");
                    }
                } else {
                    Log::channel('ffmpeg')->warning("[PRE-CHECK] Could not decode ffprobe JSON output for [{$title}]. Output: " . $ffprobeJsonOutput);
                }
            } catch (Exception $e) {
                throw new SourceNotResponding("failed_ffprobe_exception (" . $e->getMessage() . ")");
            }
        }

        // Store the process start time
        $startTimeCacheKey = "mpts:streaminfo:starttime:{$streamId}";
        $currentTime = now()->timestamp;
        Redis::setex($startTimeCacheKey, 604800, $currentTime); // 7 days TTL
        Log::channel('ffmpeg')->debug("Stored ffmpeg process start time for {$type} ID {$modelId} at {$currentTime}");

        // Set the content type based on the format
        return new StreamedResponse(function () use (
            $modelId,
            $type,
            $playlistId,
            $streamUrl,
            $title,
            $settings,
            $format,
            $ip,
            $streamId,
            $userAgent
        ) {
            // Set unique client key (order is used for stats output)
            // E.g. $clientDetailss = Redis::smembers('mpts:active_ids');
            //      Loop over the client keys and extract the details:
            //      `explode('::', $clientDetails)` will return [ip, modelId, type, streamId]
            $clientDetails = "{$ip}::{$modelId}::{$type}::{$streamId}";

            // Make sure PHP doesn't ignore user aborts
            ignore_user_abort(false);

            // Register a shutdown function that ALWAYS runs when the script dies
            $self = $this;
            register_shutdown_function(function () use ($clientDetails, $streamId, $playlistId, $type, $title, $self) {
                Redis::srem('mpts:active_ids', $clientDetails);
                Redis::del("mpts:streaminfo:details:{$streamId}");
                Redis::del("mpts:streaminfo:starttime:{$streamId}");
                if ($playlistId) {
                    // Decrement the active streams count using the trait
                    $self->decrementActiveStreams($playlistId);
                }
                Log::channel('ffmpeg')->debug("Streaming stopped for {$type} {$title}");
            });

            // Add the client key to the active IDs set
            Redis::sadd('mpts:active_ids', $clientDetails);

            // Clear any existing output buffers
            // This is important for real-time streaming
            while (ob_get_level()) {
                ob_end_flush();
            }
            flush();

            // Disable output buffering to ensure real-time streaming
            ini_set('zlib.output_compression', 0);

            // Set the maximum number of retries
            $maxRetries = $settings['ffmpeg_max_tries'];

            // Build the FFmpeg command
            $cmd = $this->buildCmd(
                $format,
                $streamUrl,
                $userAgent
            );

            // Log the command for debugging
            Log::channel('ffmpeg')->debug("Streaming {$type} {$title} with command: {$cmd}");

            // Continue trying until the client disconnects, or max retries are reached
            $retries = 0;
            while (!connection_aborted()) {
                // Start the streaming process!
                $process = SymfonyProcess::fromShellCommandline($cmd);
                $process->setTimeout(null);
                try {
                    $process->run(function ($type, $buffer) {
                        if (connection_aborted()) {
                            throw new \Exception("Connection aborted by client.");
                        }
                        if ($type === SymfonyProcess::OUT) {
                            echo $buffer;
                            flush();
                            usleep(10000); // Reduce CPU usage
                        }
                        if ($type === SymfonyProcess::ERR) {
                            if (!empty(trim($buffer))) {
                                // Log the line if it's not empty
                                Log::channel('ffmpeg')->error($buffer);
                            }
                        }
                    });
                } catch (\Exception $e) {
                    // Log eror and attempt to reconnect.
                    if (!connection_aborted()) {
                        Log::channel('ffmpeg')
                            ->error("Error streaming $type (\"$title\"): " . $e->getMessage());
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
                        ->error("FFmpeg error: max retries of $maxRetries reached for stream for $type $title.");

                    // ...break and try the next stream
                    break;
                }
                // Wait a short period before trying to reconnect.
                sleep(min(8, $retries));
            }

            echo "Error: No available streams.";
        }, 200, [
            'Content-Type' => $contentType,
            'Connection' => 'keep-alive',
            'Cache-Control' => 'no-store, no-transform',
            'Content-Disposition' => "inline; filename=\"stream.$format\"",
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Build the FFmpeg command for streaming.
     *
     * @param string $format
     * @param string $streamUrl
     * @param string $userAgent
     *
     * @return string The complete FFmpeg command
     */
    private function buildCmd($format, $streamUrl, $userAgent): string
    {
        // Get default stream settings
        $settings = ProxyService::getStreamSettings();
        $customCommandTemplate = $settings['ffmpeg_custom_command_template'] ?? null;

        // Get user defined options
        $userArgs = config('proxy.ffmpeg_additional_args', '');
        if (!empty($userArgs)) {
            $userArgs .= ' ';
        }

        // Get ffmpeg path
        $ffmpegPath = config('proxy.ffmpeg_path') ?: $settings['ffmpeg_path'];
        if (empty($ffmpegPath)) {
            $ffmpegPath = 'jellyfin-ffmpeg';
        }

        // Determine the effective video codec based on config and settings
        $videoCodec = ProxyService::determineVideoCodec(
            config('proxy.ffmpeg_codec_video', null),
            $settings['ffmpeg_codec_video'] ?? 'copy' // Default to 'copy' if not set
        );

        // Command construction logic
        if (empty($customCommandTemplate)) {
            // Initialize FFmpeg command argument variables
            $hwaccelInitArgs = '';
            $hwaccelArgs = '';
            $videoFilterArgs = '';
            $codecSpecificArgs = ''; // For QSV or other codec-specific args not part of -vf

            // Get base ffmpeg output codec formats (these are defaults or from non-QSV/VA-API settings)
            $audioCodec = (config('proxy.ffmpeg_codec_audio') ?: ($settings['ffmpeg_codec_audio'] ?? null)) ?: 'copy';
            $subtitleCodec = (config('proxy.ffmpeg_codec_subtitles') ?: ($settings['ffmpeg_codec_subtitles'] ?? null)) ?: 'copy';

            // Hardware Acceleration Logic
            if ($settings['ffmpeg_vaapi_enabled'] ?? false) {
                $videoCodec = 'h264_vaapi'; // Default VA-API H.264 encoder
                if (!empty($settings['ffmpeg_vaapi_device'])) {
                    $escapedDevice = escapeshellarg($settings['ffmpeg_vaapi_device']);
                    $hwaccelInitArgs = "-init_hw_device vaapi=va_device:{$escapedDevice} ";
                    $hwaccelArgs = "-hwaccel vaapi -hwaccel_device va_device -hwaccel_output_format vaapi -filter_hw_device va_device ";
                }
                if (!empty($settings['ffmpeg_vaapi_video_filter'])) {
                    $videoFilterArgs = "-vf " . escapeshellarg(trim($settings['ffmpeg_vaapi_video_filter'], "'\",")) . " ";
                } else {
                    $videoFilterArgs = "-vf 'scale_vaapi=format=nv12' ";
                }
            } else if ($settings['ffmpeg_qsv_enabled'] ?? false) {
                $videoCodec = 'h264_qsv'; // Default QSV H.264 encoder

                // Simplify QSV initialization - don't specify device path directly
                $hwaccelInitArgs = "-init_hw_device qsv=qsv_device ";
                $hwaccelArgs = "-hwaccel qsv -hwaccel_device qsv_device -hwaccel_output_format qsv -filter_hw_device qsv_device ";

                if (!empty($settings['ffmpeg_qsv_video_filter'])) {
                    $videoFilterArgs = "-vf " . escapeshellarg(trim($settings['ffmpeg_qsv_video_filter'], "'\",")) . " ";
                } else {
                    // Default QSV video filter, matches user's working example
                    $videoFilterArgs = "-vf 'hwupload=extra_hw_frames=64,scale_qsv=format=nv12' ";
                }

                // Additional QSV specific options
                $codecSpecificArgs = $settings['ffmpeg_qsv_encoder_options'] ? escapeshellarg($settings['ffmpeg_qsv_encoder_options']) : '-preset medium -global_quality 23';
                if (!empty($settings['ffmpeg_qsv_additional_args'])) {
                    $userArgs = trim($settings['ffmpeg_qsv_additional_args']) . ($userArgs ? " " . $userArgs : "");
                }
            }

            // Set the output format and codecs
            $output = $format === 'ts'
                ? "-c:v {$videoCodec} " . ($codecSpecificArgs ? trim($codecSpecificArgs) . " " : "") . "-c:a {$audioCodec} -c:s {$subtitleCodec} -f mpegts pipe:1"
                : "-c:v {$videoCodec} -ac 2 -f mp4 -movflags frag_keyframe+empty_moov+default_base_moof pipe:1";

            // Determine if it's an MKV file by extension
            $isMkv = stripos($streamUrl, '.mkv') !== false;

            // Build the FFmpeg command
            $cmd = escapeshellcmd($ffmpegPath) . ' ';
            $cmd .= $hwaccelInitArgs;
            $cmd .= $hwaccelArgs;

            // Better error handling
            $cmd .= '-err_detect ignore_err -ignore_unknown ';

            // Pre-input HTTP options:
            $cmd .= "-user_agent " . $userAgent . " -referer " . escapeshellarg("MyComputer") . " " .
                '-multiple_requests 1 -reconnect_on_network_error 1 ' .
                '-reconnect_on_http_error 5xx,4xx -reconnect_streamed 1 ' .
                '-reconnect_delay_max 5';

            if ($isMkv) {
                $cmd .= ' -analyzeduration 10M -probesize 10M';
            }
            $cmd .= ' -noautorotate ';

            // User defined general options:
            $cmd .= $userArgs;

            // Input:
            $cmd .= '-i ' . escapeshellarg($streamUrl) . ' ';

            // Video Filter arguments:
            $cmd .= $videoFilterArgs;

            // Output options from $output variable:
            $cmd .= $output;
        } else {
            // Custom command template is provided
            $cmd = $customCommandTemplate;

            // Prepare placeholder values
            $hwaccelInitArgsValue = '';
            $hwaccelArgsValue = '';
            $videoFilterArgsValue = '';

            // QSV options
            $qsvEncoderOptionsValue = $settings['ffmpeg_qsv_encoder_options'] ? escapeshellarg($settings['ffmpeg_qsv_encoder_options']) : '';
            $qsvAdditionalArgsValue = $settings['ffmpeg_qsv_additional_args'] ? escapeshellarg($settings['ffmpeg_qsv_additional_args']) : '';

            // Determine codec type
            $isVaapiCodec = str_contains($videoCodec, '_vaapi');
            $isQsvCodec = str_contains($videoCodec, '_qsv');

            if ($settings['ffmpeg_vaapi_enabled'] ?? false) {
                $videoCodec = $isVaapiCodec ? $videoCodec : 'h264_vaapi'; // Default to h264_vaapi if not already set
                if (!empty($settings['ffmpeg_vaapi_device'])) {
                    $hwaccelInitArgsValue = "-init_hw_device vaapi=va_device:" . escapeshellarg($settings['ffmpeg_vaapi_device']) . " -filter_hw_device va_device ";
                    $hwaccelArgsValue = "-hwaccel vaapi -hwaccel_device va_device -hwaccel_output_format vaapi ";
                }
                if (!empty($settings['ffmpeg_vaapi_video_filter'])) {
                    $videoFilterArgsValue = "-vf " . escapeshellarg(trim($settings['ffmpeg_vaapi_video_filter'], "'\",")) . " ";
                }
            } else if ($settings['ffmpeg_qsv_enabled'] ?? false) {
                $videoCodec = $isQsvCodec ? $videoCodec : 'h264_qsv'; // Default to h264_qsv if not already set
                if (!empty($settings['ffmpeg_qsv_device'])) {
                    $hwaccelInitArgsValue = "-init_hw_device qsv=qsv_hw:" . escapeshellarg($settings['ffmpeg_qsv_device']) . " ";
                    $hwaccelArgsValue = '-hwaccel qsv -hwaccel_device qsv_hw -hwaccel_output_format qsv ';
                }
                if (!empty($settings['ffmpeg_qsv_video_filter'])) {
                    $videoFilterArgsValue = "-vf " . escapeshellarg(trim($settings['ffmpeg_qsv_video_filter'], "'\",")) . " ";
                }

                // Additional QSV specific options
                $codecSpecificArgs = $settings['ffmpeg_qsv_encoder_options'] ? escapeshellarg($settings['ffmpeg_qsv_encoder_options']) : '';
                if (!empty($settings['ffmpeg_qsv_additional_args'])) {
                    $userArgs = trim($settings['ffmpeg_qsv_additional_args']) . ($userArgs ? " " . $userArgs : "");
                }
            }

            $videoCodecForTemplate = $settings['ffmpeg_codec_video'] ?: 'copy';
            $audioCodecForTemplate = (config('proxy.ffmpeg_codec_audio') ?: ($settings['ffmpeg_codec_audio'] ?? null)) ?: 'copy';
            $subtitleCodecForTemplate = (config('proxy.ffmpeg_codec_subtitles') ?: ($settings['ffmpeg_codec_subtitles'] ?? null)) ?: 'copy';

            $outputCommandSegment = $format === 'ts'
                ? "-c:v {$videoCodecForTemplate} -c:a {$audioCodecForTemplate} -c:s {$subtitleCodecForTemplate} -f mpegts pipe:1"
                : "-c:v {$videoCodecForTemplate} -ac 2 -f mp4 -movflags frag_keyframe+empty_moov+default_base_moof pipe:1";

            $videoCodecArgs = "-c:v {$videoCodecForTemplate}";
            $audioCodecArgs = "-c:a {$audioCodecForTemplate}";
            $subtitleCodecArgs = "-c:s {$subtitleCodecForTemplate}";

            // Perform replacements
            $cmd = str_replace('{FFMPEG_PATH}', escapeshellcmd($ffmpegPath), $cmd);
            $cmd = str_replace('{INPUT_URL}', escapeshellarg($streamUrl), $cmd);
            $cmd = str_replace('{OUTPUT_OPTIONS}', $outputCommandSegment, $cmd);
            $cmd = str_replace('{USER_AGENT}', $userAgent, $cmd); // $userAgent is already escaped
            $cmd = str_replace('{REFERER}', escapeshellarg("MyComputer"), $cmd);
            $cmd = str_replace('{HWACCEL_INIT_ARGS}', $hwaccelInitArgsValue, $cmd);
            $cmd = str_replace('{HWACCEL_ARGS}', $hwaccelArgsValue, $cmd);
            $cmd = str_replace('{VIDEO_FILTER_ARGS}', $videoFilterArgsValue, $cmd);
            $cmd = str_replace('{VIDEO_CODEC_ARGS}', $videoCodecArgs, $cmd);
            $cmd = str_replace('{AUDIO_CODEC_ARGS}', $audioCodecArgs, $cmd);
            $cmd = str_replace('{SUBTITLE_CODEC_ARGS}', $subtitleCodecArgs, $cmd);
            $cmd = str_replace('{QSV_ENCODER_OPTIONS}', $qsvEncoderOptionsValue, $cmd);
            $cmd = str_replace('{QSV_ADDITIONAL_ARGS}', $qsvAdditionalArgsValue, $cmd);
            $cmd = str_replace('{ADDITIONAL_ARGS}', $userArgs, $cmd); // If user wants to include general additional args
        }

        // ... rest of the options and command suffix ...
        $cmd .= ($settings['ffmpeg_debug'] ? ' -loglevel verbose' : ' -hide_banner -loglevel error');

        return $cmd;
    }
}
