<?php

namespace App\Filament\Pages;

use App\Models\Channel;
use App\Models\Playlist;
use App\Services\ProxyService;
use Carbon\Carbon;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class StreamingChannelStats extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-video-camera';
    protected static ?string $navigationLabel = 'Streaming Stats';
    protected static ?string $title = 'Active Streaming Channel Statistics';
    protected static ?string $navigationGroup = 'Tools';
    protected static ?int $navigationSort = 10; // Adjust as needed

    protected static string $view = 'filament.pages.streaming-channel-stats';

    // We can add properties to hold data later
    public $statsData = [];

    public function mount(): void
    {
        // Data fetching logic will go here in a later step
        $this->statsData = $this->getStatsData();
    }

    protected function getStatsData(): array
    {
        $stats = [];
        // Fetch active channel stream IDs from Redis
        $activeChannelIds = Redis::smembers('hls:active_channel_ids');
        Log::info('Active HLS Channel IDs from Redis: ', $activeChannelIds ?: []);

        foreach ($activeChannelIds as $originalChannelId) {
            $actualStreamingChannelId = Cache::get("hls:stream_mapping:channel:{$originalChannelId}") ?: $originalChannelId;
            $channel = Channel::find($actualStreamingChannelId);

            if (!$channel) {
                Log::warning("StreamingChannelStats: Channel not found for ID {$actualStreamingChannelId} (original ID: {$originalChannelId})");
                continue;
            }

            // Get last seen for original channel ID
            $lastSeenTimestamp = Redis::get("hls:channel_last_seen:{$originalChannelId}");
            $lastSeenDisplay = $lastSeenTimestamp ? Carbon::createFromTimestamp($lastSeenTimestamp)->diffForHumans() : 'N/A';

            $playlist = $channel->playlist;
            $isBadSource = false; // Default

            if (!$playlist) {
                Log::warning("StreamingChannelStats: Playlist not found for Channel ID {$channel->id}");
                $stats[] = [
                    'channelName' => $channel->title_custom ?? $channel->title,
                    'playlistName' => 'N/A (Playlist missing)',
                    'activeStreams' => 'N/A',
                    'maxStreams' => 'N/A',
                    'codec' => 'N/A',
                    'hwAccel' => 'N/A',
                    'resolution' => 'N/A',
                    'lastSeen' => $lastSeenDisplay,
                    'isBadSource' => $isBadSource, // Will be false as playlist is missing
                ];
                continue;
            }

            // Check bad source using actual streaming channel ID and its playlist
            $badSourceCacheKey = "mfp:bad_source:{$channel->id}:{$playlist->id}";
            $isBadSource = Redis::exists($badSourceCacheKey);

            $activeStreamsOnPlaylist = Redis::get("active_streams:{$playlist->id}") ?? 0;
            $maxStreamsOnPlaylist = $playlist->available_streams ?? 'N/A';

            // Format maxStreams
            $maxStreamsDisplay = ($maxStreamsOnPlaylist == 0 && is_numeric($maxStreamsOnPlaylist)) ? '∞' : $maxStreamsOnPlaylist;
            if ($maxStreamsOnPlaylist === 'N/A') {
                $maxStreamsDisplay = 'N/A';
            }

            $settings = ProxyService::getStreamSettings();
            $hwAccelMethod = $settings['hardware_acceleration_method'] ?? 'none';
            $finalVideoCodec = ProxyService::determineVideoCodec(
                config('proxy.ffmpeg_codec_video', null),
                $settings['ffmpeg_codec_video'] ?? 'copy'
            );
            $outputVideoCodec = $finalVideoCodec;
            $isVaapiCodec = str_contains($finalVideoCodec, '_vaapi');
            $isQsvCodec = str_contains($finalVideoCodec, '_qsv');

            if ($hwAccelMethod === 'vaapi' || $isVaapiCodec) {
                $outputVideoCodec = $isVaapiCodec ? $finalVideoCodec : 'h264_vaapi';
            } elseif ($hwAccelMethod === 'qsv' || $isQsvCodec) {
                $outputVideoCodec = $isQsvCodec ? $finalVideoCodec : 'h264_qsv';
            }

            $codecDisplay = ($outputVideoCodec !== 'copy') ? $outputVideoCodec : 'copy (source)';

            $stats[] = [
                'channelName' => $channel->title_custom ?? $channel->title,
                'playlistName' => $playlist->name,
                'activeStreams' => $activeStreamsOnPlaylist,
                'maxStreams' => $maxStreamsDisplay, // Use the formatted value
                'codec' => $codecDisplay,
                'hwAccel' => ucfirst($hwAccelMethod ?? 'none'),
                'resolution' => 'N/A', // Still deferred
                'lastSeen' => $lastSeenDisplay, // Updated
                'isBadSource' => $isBadSource, // Updated
            ];
        }
        Log::info('Processed statsData: ', $stats);
        return $stats;
    }

    // Add the new logic inside the loop, after $playlist and $channel are confirmed
    // This is a conceptual placement, the actual diff will integrate it correctly.
    // For reference, the new logic snippet to be integrated:
    /*
            $lastSeenTimestamp = Redis::get("hls:channel_last_seen:{$originalChannelId}");
            $lastSeenDisplay = $lastSeenTimestamp ? Carbon::createFromTimestamp($lastSeenTimestamp)->diffForHumans() : 'N/A';

            // Ensure $channel and $playlist are not null before using their IDs
            $isBadSource = false; // Default to false
            if ($channel && $playlist) {
                $badSourceCacheKey = "mfp:bad_source:{$channel->id}:{$playlist->id}";
                $isBadSource = Redis::exists($badSourceCacheKey);
            } elseif ($channel) {
                // If playlist is null, we might not be able to determine bad source for that specific playlist context.
                // However, a channel itself could be globally marked bad, but that's not what mfp:bad_source usually means.
                // For now, if playlist is missing, we can't check the specific key.
                Log::warning("StreamingChannelStats: Cannot check bad source status for channel ID {$channel->id} due to missing playlist.");
            }
    */
}
