<?php

namespace App\Http\Controllers;

use Exception;
use App\Models\Channel;
use App\Models\Episode;
use App\Services\ProxyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Process as SymphonyProcess;

class StreamController extends Controller
{
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
        $title = $channel->title_custom ?? $channel->title;
        $title = strip_tags($title);

        // Check if playlist is specified
        $playlist = $channel->playlist;

        // Setup streams array
        $streamUrl = $channel->url_custom ?? $channel->url;

        // Determine the output format
        $ip = $request->ip();
        $streamId = uniqid();
        $channelId = $channel->id;
        $contentType = $format === 'ts' ? 'video/MP2T' : 'video/mp4';

        // Start the stream
        return $this->startStream(
            type: 'channel',
            modelId: $channelId,
            streamUrl: $streamUrl,
            title: $title,
            format: $format,
            ip: $ip,
            streamId: $streamId,
            contentType: $contentType,
            userAgent: $playlist->user_agent ?? null
        );
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
        $playlist = $episode->playlist;

        // Setup streams array
        $streamUrl = $episode->url;

        // Determine the output format
        $ip = $request->ip();
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
            userAgent: $playlist->user_agent ?? null
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
        $userAgent
    ) {
        // Prevent timeouts, etc.
        ini_set('max_execution_time', 0);
        ini_set('output_buffering', 'off');
        ini_set('implicit_flush', 1);

        // Get user preferences
        $settings = ProxyService::getStreamSettings();

        // Get user agent
        $userAgent = escapeshellarg($userAgent) ?: escapeshellarg($settings['ffmpeg_user_agent']);

        // Set the content type based on the format
        return new StreamedResponse(function () use ($modelId, $type, $streamUrl, $title, $settings, $format, $ip, $streamId, $userAgent) {
            // Set unique client key (order is used for stats output)
            $clientKey = "{$ip}::{$modelId}::{$streamId}::{$type}";

            // Make sure PHP doesn't ignore user aborts
            ignore_user_abort(false);

            // Register a shutdown function that ALWAYS runs when the script dies
            register_shutdown_function(function () use ($clientKey, $type, $title) {
                Redis::srem('mpts:active_ids', $clientKey);
                Log::channel('ffmpeg')->info("Streaming stopped for {$type} {$title}");
            });

            // Mark as active
            Redis::sadd('mpts:active_ids', $clientKey);

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
            Log::channel('ffmpeg')->info("Streaming {$type} {$title} with command: {$cmd}");

            // Continue trying until the client disconnects, or max retries are reached
            $retries = 0;
            while (!connection_aborted()) {
                // Start the streaming process!
                $process = SymphonyProcess::fromShellCommandline($cmd);
                $process->setTimeout(null);
                try {
                    $streamType = $type;
                    $process->run(function ($type, $buffer) use ($modelId, $format, $streamType) {
                        if (connection_aborted()) {
                            throw new \Exception("Connection aborted by client.");
                        }
                        if ($type === SymphonyProcess::OUT) {
                            echo $buffer;
                            flush();
                            usleep(10000); // Reduce CPU usage
                        }
                        if ($type === SymphonyProcess::ERR) {
                            // split out each line
                            $lines = preg_split('/\r?\n/', trim($buffer));
                            foreach ($lines as $line) {
                                // Use below, along with `-progress pipe:2`, to enable stream progress tracking...
                                /*
                                        // "progress" lines are always KEY=VALUE
                                        if (strpos($line, '=') !== false) {
                                            list($key, $value) = explode('=', $line, 2);
                                            if (in_array($key, ['bitrate', 'fps', 'out_time_ms'])) {
                                                // push the metric value onto a Redis list and trim to last 20 points
                                                $listKey = "mpts:{$streamType}_hist:{$modelId}:{$key}";
                                                $timeKey = "mpts:{$streamType}_hist:{$modelId}:timestamps";

                                                // push the timestamp into a parallel list (once per loop)
                                                Redis::rpush($timeKey, now()->format('H:i:s'));
                                                Redis::ltrim($timeKey, -20, -1);

                                                // push the metric value
                                                Redis::rpush($listKey, $value);
                                                Redis::ltrim($listKey, -20, -1);
                                            }
                                        } elseif ($line !== '') {
                                            // anything else is a true ffmpeg log/error
                                            Log::channel('ffmpeg')->error($line);
                                        }
                                    */

                                if (preg_match('/speed=\s*([0-9\.]+x)/', $line, $matches)) {
                                    $speed = $matches[1];
                                    Log::channel('ffmpeg')->info("FFmpeg speed: {$speed}");
                                    // In a future step, this $speed value will be used for failover decisions.
                                } elseif (!empty(trim($line))) { // Avoid logging empty lines
                                    // It's not a speed update, log as original error/info based on context
                                    // For now, continue logging as error if it's not an empty line from stderr
                                    Log::channel('ffmpeg')->error($line);
                                }
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
            $audioCodec = config('proxy.ffmpeg_codec_audio') ?: $settings['ffmpeg_codec_audio'];
            $subtitleCodec = config('proxy.ffmpeg_codec_subtitles') ?: $settings['ffmpeg_codec_subtitles'];

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
                    // Simplified filter chain for QSV
                    $videoFilterArgs = "-vf 'format=nv12,hwupload=extra_hw_frames=64' ";
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

            // Codec specific additional arguments (e.g. QSV specific):
            $cmd .= $codecSpecificArgs;

            // Input:
            if ($format === 'ts') {
                // For TS format, we use -re to read the input at its native frame rate
                $cmd .= '-re -i ' . escapeshellarg($streamUrl) . ' ';
            } else {
                // For MP4 format, we can read it normally
                $cmd .= '-i ' . escapeshellarg($streamUrl) . ' ';
            }

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
            $audioCodecForTemplate = $settings['ffmpeg_codec_audio'] ?: 'copy';
            $subtitleCodecForTemplate = $settings['ffmpeg_codec_subtitles'] ?: 'copy';

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
