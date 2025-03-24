<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Settings\GeneralSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Mockery\Exception;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChannelStreamController extends Controller
{
    /**
     * Stream an IPTV channel.
     *
     * @param Request $request
     * @param int $id
     *
     * @return StreamedResponse
     */
    public function __invoke(Request $request, $id)
    {
        // Prevent timeouts
        ini_set('max_execution_time', 0);
        ini_set('output_buffering', 'off');
        ini_set('implicit_flush', 1);

        // Find the channel by ID, else throw a 404
        $channel = Channel::findOrFail(base64_decode($id));

        // Enable debug output?
        $userPreferences = app(GeneralSettings::class);
        try {
            $enabledDebug = $userPreferences->show_proxy_debug;
        } catch (Exception $e) {
            $enabledDebug = false;
        }

        // Get the stream URL (could be multiple, allow for fallbacks)
        $streamUrl = $channel->url_custom ?? $channel->url;
        $streamUrls = [$streamUrl];

        // Stream the content directly from FFmpeg
        return new StreamedResponse(function () use ($streamUrls, $enabledDebug) {
            if (ob_get_level() > 0) {
                ob_end_clean();
                while (@ob_end_flush());
            }
            ini_set('zlib.output_compression', 0);

            foreach ($streamUrls as $streamUrl) {
                $cmd = "ffmpeg -re -i \"$streamUrl\" -c copy -f mpegts pipe:1 -reconnect 1 -reconnect_streamed 1 -reconnect_delay_max 5 -timeout 100000000 -http_persistent 1";
                if (config('dev.ffmpeg.debug')) {
                    $cmd .= " 2> " . storage_path('logs/' . config('dev.ffmpeg.file'));
                } else if (!$enabledDebug) {
                    $cmd .= " -hide_banner -nostats -loglevel quiet 2>/dev/null";
                }
                $process = popen($cmd, 'r');

                if ($process) {
                    while (!feof($process)) {
                        if (connection_aborted()) {
                            pclose($process);
                            return;
                        }
                        $data = fread($process, 8192); // Increased from 4096 to 8192
                        if ($data === false) {
                            break;
                        }
                        echo $data;
                        flush();
                    }
                    pclose($process);
                    return;
                }
            }
            echo "Error: No available streams.";
        }, 200, [
            'Content-Type' => 'video/mp2t',
            'Connection' => 'keep-alive',
            'Cache-Control' => 'no-store, no-transform',
            'X-Accel-Buffering' => 'no', // Prevents Nginx from buffering
        ]);
    }

    /**
     * Stream an IPTV channel using HLS.
     *
     * @param Request $request
     * @param int $id
     *
     * @return BinaryFileResponse
     */
    public function hls(Request $request, $id)
    {
        $channel = Channel::findOrFail(base64_decode($id));
        $streamUrl = $channel->url_custom ?? $channel->url;

        // Path for HLS files
        $hlsDir = storage_path("app/public/hls/{$id}");
        $hlsPlaylist = "{$hlsDir}/playlist.m3u8";

        // Ensure directory exists
        if (!file_exists($hlsDir) && !mkdir($hlsDir, 0777, true) && !is_dir($hlsDir)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $hlsDir));
        }

        // FFmpeg command to generate HLS playlist and segments
        $cmd = "ffmpeg -re -i \"$streamUrl\" -c:v libx264 -preset veryfast -b:v 800k -c:a aac -strict -2 -f hls -hls_time 5 -hls_list_size 10 -hls_flags delete_segments \"$hlsPlaylist\" > /dev/null 2>&1 &";

        exec($cmd); // Run FFmpeg in the background

        // Return HLS playlist file
        return response()->file($hlsPlaylist, [
            'Content-Type' => 'application/vnd.apple.mpegurl',
            'Cache-Control' => 'no-cache, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }
}
