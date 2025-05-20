<?php

namespace App\Http\Controllers;

use Exception;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Settings\GeneralSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Process as SymphonyProcess;

class ChannelStreamController extends Controller
{
    /**
     * Stream an IPTV channel.
     *
     * @param Request $request
     * @param int|string $encodedId
     * @param string|null $playlist
     * @param string $format
     *
     * @return StreamedResponse
     */
    public function __invoke(
        Request $request,
        $encodedId,
        $format = 'mp2t',
        $playlist = null
    ) {
        // Validate the format
        if (!in_array($format, ['mp2t', 'mp4'])) {
            abort(400, 'Invalid format specified.');
        }

        // Prevent timeouts, etc.
        ini_set('max_execution_time', 0);
        ini_set('output_buffering', 'off');
        ini_set('implicit_flush', 1);

        // Find the channel by ID
        if (strpos($encodedId, '==') === false) {
            $encodedId .= '=='; // right pad to ensure proper decoding
        }
        $channel = Channel::findOrFail(base64_decode($encodedId));
        $title = $channel->title_custom ?? $channel->title;
        $title = strip_tags($title);

        // Check if playlist is specified
        $playlistType = null;
        if ($playlist) {
            $playlistType = 'playlist';
            $playlist = Playlist::find($playlist);
            if (!$playlist) {
                $playlistType = 'merged';
                $playlist = MergedPlaylist::find($playlist);
            }
            if (!$playlist) {
                $playlistType = 'custom';
                $playlist = CustomPlaylist::findOrFail($playlist);
            }
        }

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
            'ffmpeg_codec_video' => 'copy',
            'ffmpeg_codec_audio' => 'copy',
            'ffmpeg_codec_subtitles' => 'copy',
            'ffmpeg_path' => 'jellyfin-ffmpeg',
        ];

        try {
            $settings = [
                'ffmpeg_debug' => $userPreferences->ffmpeg_debug ?? $settings['ffmpeg_debug'],
                'ffmpeg_max_tries' => $userPreferences->ffmpeg_max_tries ?? $settings['ffmpeg_max_tries'],
                'ffmpeg_user_agent' => $userPreferences->ffmpeg_user_agent ?? $settings['ffmpeg_user_agent'],
                'ffmpeg_codec_video' => $userPreferences->ffmpeg_codec_video ?? $settings['ffmpeg_codec_video'],
                'ffmpeg_codec_audio' => $userPreferences->ffmpeg_codec_audio ?? $settings['ffmpeg_codec_audio'],
                'ffmpeg_codec_subtitles' => $userPreferences->ffmpeg_codec_subtitles ?? $settings['ffmpeg_codec_subtitles'],
                'ffmpeg_path' => $userPreferences->ffmpeg_path ?? $settings['ffmpeg_path'],
            ];
        } catch (Exception $e) {
            // Ignore
        }
        $extension = $format === 'mp2t'
            ? 'ts'
            : $format;
        return new StreamedResponse(function () use ($streamUrls, $title, $settings, $format, $playlist) {
            while (ob_get_level()) {
                ob_end_flush();
            }
            flush();

            // Disable output buffering to ensure real-time streaming
            ini_set('zlib.output_compression', 0);

            // Get user agent
            $userAgent = escapeshellarg($settings['ffmpeg_user_agent']);
            if ($playlist) {
                $userAgent = escapeshellarg($playlist->user_agent ?? $userAgent);
            }
            $maxRetries = $settings['ffmpeg_max_tries'];

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

            // Get ffmpeg output codec formats
            $videoCodec = config('proxy.ffmpeg_codec_video') ?: $settings['ffmpeg_codec_video'];
            $audioCodec = config('proxy.ffmpeg_codec_audio') ?: $settings['ffmpeg_codec_audio'];
            $subtitleCodec = config('proxy.ffmpeg_codec_subtitles') ?: $settings['ffmpeg_codec_subtitles'];

            // Loop through available streams...
            $output = $format === 'mp2t'
                ? "-c:v $videoCodec -c:a $audioCodec -c:s $subtitleCodec -f mpegts pipe:1"
                : "-c:v $videoCodec -c:a $audioCodec -bsf:a aac_adtstoasc -c:s $subtitleCodec -f mp4 -movflags frag_keyframe+empty_moov+default_base_moof pipe:1";
            foreach ($streamUrls as $streamUrl) {
                $cmd = sprintf(
                    $ffmpegPath . ' ' .
                        // Pre-input HTTP options:
                        '-user_agent "%s" -referer "MyComputer" ' .
                        '-multiple_requests 1 -reconnect_on_network_error 1 ' .
                        '-reconnect_on_http_error 5xx,4xx -reconnect_streamed 1 ' .
                        '-reconnect_delay_max 5 -noautorotate ' .

                        // User defined options:
                        '%s' .

                        // Input:
                        '-re -i "%s" ' .

                        // Output:
                        '%s ' .

                        // Logging:
                        '%s',
                    $userAgent,                   // for -user_agent
                    $userArgs,                    // user defined options
                    $streamUrl,                   // input URL
                    $output,                      // for -f
                    $settings['ffmpeg_debug'] ? '' : '-hide_banner -nostats -loglevel error'
                );

                Log::channel('ffmpeg')->info("Streaming channel {$title} with command: {$cmd}");

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
}
