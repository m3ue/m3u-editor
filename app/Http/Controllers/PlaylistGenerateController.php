<?php

namespace App\Http\Controllers;

use App\Enums\ChannelLogoType;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\MergedPlaylist;
use App\Models\CustomPlaylist;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PlaylistGenerateController extends Controller
{
    public function __invoke(string $uuid)
    {
        // Fetch the playlist
        $playlist = Playlist::where('uuid', $uuid)->first();
        if (!$playlist) {
            $playlist = MergedPlaylist::where('uuid', $uuid)->first();
        }
        if (!$playlist) {
            $playlist = CustomPlaylist::where('uuid', $uuid)->firstOrFail();
        }

        // Generate a filename
        $filename = Str::slug($playlist->name) . '.m3u';

        // Check if proxy enabled
        $proxyEnabled = $playlist->enable_proxy;

        // Get ll active channels
        return response()->stream(
            function () use ($playlist, $proxyEnabled) {
                // Get all active channels
                $channels = $playlist->channels()
                    ->where('enabled', true)
                    ->with('epgChannel')
                    ->orderBy('sort')
                    ->orderBy('channel')
                    ->orderBy('title')
                    ->get();

                // Output the enabled channels
                echo "#EXTM3U\n";
                $channelNumber = $playlist->auto_channel_increment ? $playlist->channel_start - 1 : 0;
                foreach ($channels as $channel) {
                    // Get the title and name
                    $title = $channel->title_custom ?? $channel->title;
                    $name = $channel->name_custom ?? $channel->name;
                    $tvgId = $channel->stream_id_custom ?? $channel->stream_id;
                    $url = $channel->url_custom ?? $channel->url;
                    $epgData = $channel->epgChannel ?? null;
                    $channelNo = $channel->channel;
                    if (!$channelNo && $playlist->auto_channel_increment) {
                        $channelNo = ++$channelNumber;
                    }
                    if ($proxyEnabled) {
                        $url = route('stream', base64_encode((string)$channel->id));
                    }

                    // Get the icon
                    $icon = '';
                    if ($channel->logo_type === ChannelLogoType::Epg && $epgData) {
                        $icon = $epgData->icon ?? '';
                    } elseif ($channel->logo_type === ChannelLogoType::Channel) {
                        $icon = $channel->logo ?? '';
                    }

                    // Output the channel
                    echo "#EXTINF:-1 tvg-chno=\"$channelNo\" tvg-id=\"$tvgId\" tvg-name=\"$name\" tvg-logo=\"$icon\" group-title=\"$channel->group\"," . $title . "\n";
                    if ($channel->extvlcopt) {
                        foreach ($channel->extvlcopt as $extvlcopt) {
                            echo "#EXTVLCOPT:{$extvlcopt['key']}={$extvlcopt['value']}\n";
                        }
                    }
                    echo $url . "\n";
                }
            },
            200,
            [
                'Access-Control-Allow-Origin' => '*',
                'Content-Disposition' => "attachment; filename=$filename",
                'Content-Type' => 'application/vnd.apple.mpegurl'
            ]
        );
    }

    public function hdhr(string $uuid)
    {
        // Setup the HDHR device info
        $deviceInfo = $this->getDeviceInfo($uuid);
        $deviceInfoXml = collect($deviceInfo)->map(function ($value, $key) {
            return "<$key>$value</$key>";
        })->implode('');
        $xmlResponse = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><root>$deviceInfoXml</root>";

        // Return the XML response to mimic the HDHR device
        return response($xmlResponse)->header('Content-Type', 'application/xml');
    }

    public function hdhrDiscover(string $uuid)
    {
        // Fetch the playlist so we can send a 404 if not found
        $playlist = Playlist::where('uuid', $uuid)->first();
        if (!$playlist) {
            $playlist = MergedPlaylist::where('uuid', $uuid)->first();
        }
        if (!$playlist) {
            $playlist = CustomPlaylist::where('uuid', $uuid)->first();
        }

        // Check if playlist exists
        if (!$playlist) {
            return response()->json(['Error' => 'Playlist Not Found'], 404);
        }

        // Return the HDHR device info
        return $this->getDeviceInfo($uuid);
    }

    public function hdhrLineup(string $uuid)
    {
        // Fetch the playlist
        $playlist = Playlist::where('uuid', $uuid)->first();
        if (!$playlist) {
            $playlist = MergedPlaylist::where('uuid', $uuid)->first();
        }
        if (!$playlist) {
            $playlist = CustomPlaylist::where('uuid', $uuid)->firstOrFail();
        }

        // Get all active channels
        $channels = $playlist->channels()
            ->where('enabled', true)
            ->orderBy('sort')
            ->orderBy('channel')
            ->orderBy('title')
            ->get();

        // Check if proxy enabled
        $proxyEnabled = $playlist->enable_proxy;
        return response()->json($channels->transform(function (Channel $channel) use ($proxyEnabled) {
            $url = $channel->url_custom ?? $channel->url;
            if ($proxyEnabled) {
                $url = route('stream', base64_encode((string)$channel->id));
            }
            $streamId = $channel->stream_id_custom ?? $channel->stream_id;
            return [
                'GuideNumber' => $streamId,
                'GuideName' => $channel->title_custom ?? $channel->title,
                'URL' => $url,
            ];
        }));
    }

    public function hdhrLineupStatus(string $uuid)
    {
        // No need to fetch, status is same for all...
        return response()->json([
            'ScanInProgress' => 0,
            'ScanPossible' => 1,
            'Source' => 'Cable',
            'SourceList' => ['Cable'],
        ]);
    }

    private function getDeviceInfo($uuid)
    {
        // Return the HDHR device info
        return [
            'DeviceID' => '12345678',
            'FriendlyName' => 'm3u editor HDHomeRun',
            'ModelNumber' => 'HDTC-2US',
            'FirmwareName' => 'hdhomerun3_atsc',
            'FirmwareVersion' => '20200101',
            'DeviceAuth' => 'test_auth_token',
            'BaseURL' => url("/$uuid/hdhr"),
            'LineupURL' => route('playlist.hdhr.lineup', $uuid),
        ];
    }
}
