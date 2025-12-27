<?php

namespace App\Http\Controllers;

use App\Enums\ChannelLogoType;
use App\Enums\PlaylistChannelId;
use App\Facades\PlaylistFacade;
use App\Facades\ProxyFacade;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\MergedPlaylist;
use App\Models\CustomPlaylist;
use App\Models\PlaylistAlias;
use App\Services\PlaylistUrlService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PlaylistGenerateController extends Controller
{
    public function __invoke(Request $request, string $uuid)
    {
        // Fetch the playlist
        $playlist = PlaylistFacade::resolvePlaylistByUuid($uuid);
        if (!$playlist) {
            return response()->json(['Error' => 'Playlist Not Found'], 404);
        }

        switch (class_basename($playlist)) {
            case 'Playlist':
                $type = 'standard';
                break;
            case 'MergedPlaylist':
                $type = 'merged';
                break;
            case 'CustomPlaylist':
                $type = 'custom';
                break;
            case 'PlaylistAlias':
                $type = 'alias';
                break;
            default:
                return response()->json(['Error' => 'Invalid Playlist Type'], 400);
        }

        // Check auth
        if ($playlist instanceof PlaylistAlias) {
            $auth = $playlist->authObject;
            if ($auth) {
                $auths = collect([$auth]);
            } else {
                $auths = collect();
            }
        } else {
            $auths = $playlist->playlistAuths()->where('enabled', true)->get();
        }

        $usedAuth = null;
        if ($auths->isNotEmpty()) {
            $authenticated = false;
            foreach ($auths as $auth) {
                $authUsername = $auth->username;
                $authPassword = $auth->password;

                if (
                    $request->get('username') === $authUsername &&
                    $request->get('password') === $authPassword
                ) {
                    $authenticated = true;
                    $usedAuth = $auth;
                    break;
                }
            }

            if (!$authenticated) {
                return response()->json(['Error' => 'Unauthorized'], 401);
            }
        }

        // Check if proxy enabled
        if ($request->has('proxy')) {
            $proxyEnabled = $request->input('proxy') === 'true';
        } else {
            $proxyEnabled = $playlist->enable_proxy;
        }
        $logoProxyEnabled = $playlist->enable_logo_proxy;

        // Get the base URL
        $baseUrl = ProxyFacade::getBaseUrl();

        // Get all active channels
        return response()->stream(
            function () use ($baseUrl, $playlist, $proxyEnabled, $logoProxyEnabled, $type, $usedAuth) {
                // Get all active channels
                $channels = $playlist->channels()
                    ->leftJoin('groups', 'channels.group_id', '=', 'groups.id')
                    ->where('channels.enabled', true)
                    ->when(!$playlist->include_vod_in_m3u, function ($q) {
                        $q->where('channels.is_vod', false);
                    })
                    ->with(['epgChannel', 'tags', 'group'])
                    ->orderBy('groups.sort_order') // Primary sort
                    ->orderBy('channels.sort') // Secondary sort
                    ->orderBy('channels.channel')
                    ->orderBy('channels.title')
                    ->select('channels.*')
                    ->get();

                // For custom playlists, re-sort by custom group order (if assigned), falling back to original group order
                if ($type === 'custom' && $channels->isNotEmpty()) {
                    $playlistUuid = $playlist->uuid;
                    $channels = $channels->sort(function ($a, $b) use ($playlistUuid) {
                        // Get custom tag order for both channels
                        $aTag = $a->tags->where('type', $playlistUuid)->first();
                        $bTag = $b->tags->where('type', $playlistUuid)->first();

                        $aOrder = $aTag ? ($aTag->order_column ?? 999999) : ($a->group->sort_order ?? 999999);
                        $bOrder = $bTag ? ($bTag->order_column ?? 999999) : ($b->group->sort_order ?? 999999);

                        // Primary sort by group/tag order
                        if ($aOrder !== $bOrder) {
                            return $aOrder <=> $bOrder;
                        }

                        // Secondary sort by channel sort
                        $aSort = $a->sort ?? 999999;
                        $bSort = $b->sort ?? 999999;
                        if ($aSort !== $bSort) {
                            return $aSort <=> $bSort;
                        }

                        // Tertiary sort by channel number
                        $aCh = $a->channel ?? '';
                        $bCh = $b->channel ?? '';
                        if ($aCh !== $bCh) {
                            return $aCh <=> $bCh;
                        }

                        // Final sort by title
                        return ($a->title ?? '') <=> ($b->title ?? '');
                    })->values();
                }

                // Set the auth details
                if ($usedAuth) {
                    $username = urlencode($usedAuth->username);
                    $password = urlencode($usedAuth->password);
                } else {
                    $username = urlencode($playlist->user->name);
                    $password = urlencode($playlist->uuid);
                }

                // Output the enabled channels
                $epgUrl = url('/epg/' . $playlist->uuid . '/epg.xml');
                echo "#EXTM3U url-tvg=\"$epgUrl\" \n";
                $channelNumber = $playlist->auto_channel_increment ? $playlist->channel_start - 1 : 0;
                $idChannelBy = $playlist->id_channel_by;
                foreach ($channels as $channel) {
                    // Get the title and name
                    $title = $channel->title_custom ?? $channel->title;
                    $name = $channel->name_custom ?? $channel->name;
                    $url = PlaylistUrlService::getChannelUrl($channel, $playlist);
                    $epgData = $channel->epgChannel ?? null;
                    $channelNo = $channel->channel;
                    $timeshift = $channel->shift ?? 0;
                    $stationId = $channel->station_id ?? '';
                    $epgShift = $channel->tvg_shift ?? 0;
                    $group = $channel->group ?? '';
                    if (!$channelNo && $playlist->auto_channel_increment) {
                        $channelNo = ++$channelNumber;
                    }
                    if ($type === 'custom') {
                        $customGroup = $channel->tags
                            ->where('type', $playlist->uuid)
                            ->first();
                        if ($customGroup) {
                            $group = $customGroup->getAttributeValue('name');
                        }
                    }

                    // Get the TVG ID
                    switch ($idChannelBy) {
                        case PlaylistChannelId::ChannelId:
                            $tvgId = $channelNo;
                            break;
                        case PlaylistChannelId::Name:
                            $tvgId = $channel->name_custom ?? $channel->name;
                            break;
                        case PlaylistChannelId::Title:
                            $tvgId = $channel->title_custom ?? $channel->title;
                            break;
                        default:
                            $tvgId = $channel->stream_id_custom ?? $channel->stream_id;
                            break;
                    }

                    // Get the icon
                    $icon = '';
                    if ($channel->logo) {
                        // Logo override takes precedence
                        $icon = $channel->logo;
                    } elseif ($channel->logo_type === ChannelLogoType::Epg && $epgData) {
                        $icon = $epgData->icon ?? '';
                    } elseif ($channel->logo_type === ChannelLogoType::Channel) {
                        $icon = $channel->logo ?? $channel->logo_internal ?? '';
                    }
                    if (empty($icon)) {
                        $icon = $baseUrl . '/placeholder.png';
                    }

                    // Get the extension from the source URL
                    $extension = pathinfo($url, PATHINFO_EXTENSION);
                    if (empty($extension)) {
                        $sourcePlaylist = $channel->getEffectivePlaylist();
                        $extension = $sourcePlaylist->xtream_config['output'] ?? 'ts'; // Default to 'ts' if not set
                    }

                    if ($logoProxyEnabled) {
                        // Proxy the logo through the logo proxy controller
                        $icon = LogoProxyController::generateProxyUrl($icon);
                    }

                    // Format the URL in Xtream Codes format if not disabled
                    // This way we can perform additional stream analysis, check for stream limits, etc.
                    // When disabled, will return the raw URL from the channel (or the proxyfied URL if proxy enabled)
                    if (!(config('app.disable_m3u_xtream_format') ?? false)) {
                        $urlPath = 'live';
                        if ($channel->is_vod) {
                            $urlPath = 'movie';
                            $extension = $channel->container_extension ?? 'mkv';
                        }
                        $url = $baseUrl . "/{$urlPath}/{$username}/{$password}/" . $channel->id . "." . $extension;
                    } else if ($proxyEnabled) {
                        // Get the proxy URL
                        // Pass the playlist UUID for merged/custom playlists so the correct context is used
                        $url = ProxyFacade::getProxyUrlForChannel(
                            $channel->id,
                            $playlist->uuid
                        );
                    }
                    $url = rtrim($url, '.');

                    // Make sure TVG ID only contains characters and numbers
                    $tvgId = preg_replace(config('dev.tvgid.regex'), '', $tvgId);

                    // Output the channel
                    $extInf = "#EXTINF:-1";
                    if ($channel->catchup) {
                        $extInf .= " catchup=\"$channel->catchup\"";
                    }
                    if ($channel->catchup_source) {
                        $extInf .= " catchup-source=\"$channel->catchup_source\"";
                    }
                    if ($timeshift) {
                        $extInf .= " timeshift=\"$timeshift\"";
                    }
                    if ($stationId) {
                        $extInf .= " tvc-guide-stationid=\"$stationId\"";
                    }
                    if ($epgShift) {
                        $extInf .= " tvg-shift=\"$epgShift\"";
                    }
                    $extInf .= " tvg-chno=\"$channelNo\" tvg-id=\"$tvgId\" tvg-name=\"$name\" tvg-logo=\"$icon\" group-title=\"$group\"";
                    echo "$extInf," . $title . "\n";
                    if ($channel->extvlcopt) {
                        foreach ($channel->extvlcopt as $extvlcopt) {
                            echo "#EXTVLCOPT:{$extvlcopt['key']}={$extvlcopt['value']}\n";
                        }
                    }
                    if ($channel->kodidrop) {
                        foreach ($channel->kodidrop as $kodidrop) {
                            echo "#KODIPROP:{$kodidrop['key']}={$kodidrop['value']}\n";
                        }
                    }
                    echo $url . "\n";
                }

                // If the playlist includes series in M3U, include the series episodes
                if ($playlist->include_series_in_m3u) {
                    // Get the seasons
                    $series = $playlist->series()
                        ->where('series.enabled', true)
                        ->with([
                            'category',
                            'episodes' => function ($q) {
                                $q->where('episodes.enabled', true);
                            }
                        ])
                        ->orderBy('sort')
                        ->get();

                    foreach ($series as $s) {
                        // Append the episodes
                        foreach ($s->episodes as $episode) {
                            // Set channel variables
                            $channelNo = ++$channelNumber;
                            $group = $s->category->name ?? 'Seasons';
                            $name = $s->name;
                            $url = PlaylistUrlService::getEpisodeUrl($episode, $playlist);
                            $title = $episode->title;
                            $runtime = $episode->info['duration_secs'] ?? -1;
                            $icon = $episode->info['movie_image'] ?? $streamId->info['cover'] ?? '';
                            if (empty($icon)) {
                                $icon = url('/placeholder.png');
                            }

                            if ($logoProxyEnabled) {
                                $icon = LogoProxyController::generateProxyUrl($icon);
                            }
                            if (!(config('app.disable_m3u_xtream_format') ?? false)) {
                                $containerExtension = $episode->container_extension ?? 'mp4';
                                $url = $baseUrl . "/series/{$username}/{$password}/" . $episode->id . ".{$containerExtension}";
                            } else if ($proxyEnabled) {
                                // Get the proxy URL
                                // Pass the playlist UUID for merged/custom playlists so the correct context is used
                                $url = ProxyFacade::getProxyUrlForEpisode(
                                    $episode->id,
                                    $playlist->uuid,
                                );
                            }
                            $url = rtrim($url, '.');

                            // Get the TVG ID
                            switch ($idChannelBy) {
                                case PlaylistChannelId::ChannelId:
                                    $tvgId = $channelNo;
                                    break;
                                case PlaylistChannelId::Name:
                                    $tvgId = $name;
                                    break;
                                case PlaylistChannelId::Title:
                                    $tvgId = $name;
                                    break;
                                default:
                                    $tvgId = $episode->id;
                                    break;
                            }

                            $extInf = "#EXTINF:$runtime";
                            $extInf .= " tvg-chno=\"$channelNo\" tvg-id=\"$tvgId\" tvg-name=\"$name\" tvg-logo=\"$icon\" group-title=\"$group\"";
                            echo "$extInf," . $title . "\n";
                            echo $url . "\n";
                        }
                    }
                }
            },
            200,
            [
                'Access-Control-Allow-Origin' => '*',
                'Content-Type' => 'audio/x-mpegurl',
                'Cache-Control' => 'no-cache, must-revalidate',
                'Pragma' => 'no-cache'
            ]
        );
    }

    public function hdhr(string $uuid)
    {
        // Fetch the playlist so we can send a 404 if not found
        $playlist = PlaylistFacade::resolvePlaylistByUuid($uuid);
        if (!$playlist) {
            return response()->json(['Error' => 'Playlist Not Found'], 404);
        }

        // Setup the HDHR device info
        $deviceInfo = $this->getDeviceInfo($playlist);
        $deviceInfoXml = collect($deviceInfo)->map(function ($value, $key) {
            return "<$key>$value</$key>";
        })->implode('');
        $xmlResponse = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><root>$deviceInfoXml</root>";

        // Return the XML response to mimic the HDHR device
        return response($xmlResponse)->header('Content-Type', 'application/xml');
    }

    public function hdhrOverview(Request $request, string $uuid)
    {
        // Fetch the playlist so we can send a 404 if not found
        $playlist = PlaylistFacade::resolvePlaylistByUuid($uuid);
        if (!$playlist) {
            return response()->json(['Error' => 'Playlist Not Found'], 404);
        }

        // Check auth
        if ($playlist instanceof PlaylistAlias) {
            $auth = $playlist->authObject;
            if ($auth) {
                $auths = collect([$auth]);
            } else {
                $auths = collect();
            }
        } else {
            $auths = $playlist->playlistAuths()->where('enabled', true)->get();
        }

        if ($auths->isNotEmpty()) {
            $authenticated = false;
            foreach ($auths as $auth) {
                $authUsername = $auth->username;
                $authPassword = $auth->password;

                if (
                    $request->get('username') === $authUsername &&
                    $request->get('password') === $authPassword
                ) {
                    $authenticated = true;
                    $usedAuth = $auth;
                    break;
                }
            }

            if (!$authenticated) {
                return response()->json(['Error' => 'Unauthorized'], 401);
            }
        }

        return view('hdhr', [
            'playlist' => $playlist,
        ]);
    }

    public function hdhrDiscover(string $uuid)
    {
        // Fetch the playlist so we can send a 404 if not found
        $playlist = PlaylistFacade::resolvePlaylistByUuid($uuid);
        if (!$playlist) {
            return response()->json(['Error' => 'Playlist Not Found'], 404);
        }

        // Return the HDHR device info
        return $this->getDeviceInfo($playlist);
    }

    public function hdhrLineup(string $uuid)
    {
        // Fetch the playlist
        $playlist = PlaylistFacade::resolvePlaylistByUuid($uuid);
        if (!$playlist) {
            return response()->json(['Error' => 'Playlist Not Found'], 404);
        }

        // Get all active channels
        $channels = $playlist->channels()
            ->leftJoin('groups', 'channels.group_id', '=', 'groups.id')
            ->where('channels.enabled', true)
            ->when(!$playlist->include_vod_in_m3u, function ($q) {
                $q->where('channels.is_vod', false);
            })
            ->with(['epgChannel', 'tags', 'group'])
            ->orderBy('groups.sort_order') // Primary sort
            ->orderBy('channels.sort') // Secondary sort
            ->orderBy('channels.channel')
            ->orderBy('channels.title')
            ->select('channels.*')
            ->get();

        // Set the auth details
        $username = $playlist->user->name;
        $password = $playlist->uuid;

        // Check if proxy enabled
        $idChannelBy = $playlist->id_channel_by;
        $autoIncrement = $playlist->auto_channel_increment;
        $channelNumber = $autoIncrement ? $playlist->channel_start - 1 : 0;

        return response()->json($channels->transform(function (Channel $channel) use ($username, $password, $idChannelBy, $autoIncrement, &$channelNumber, $playlist) {
            $sourceUrl = $channel->url_custom ?? $channel->url;
            $baseUrl = ProxyFacade::getBaseUrl();
            $extension = pathinfo($sourceUrl, PATHINFO_EXTENSION);
            $urlPath = 'live';
            if ($channel->is_vod) {
                $urlPath = 'movie';
                $extension = $channel->container_extension ?? 'mkv';
            }
            $url = rtrim($baseUrl . "/{$urlPath}/{$username}/{$password}/" . $channel->id . "." . $extension, '.');
            $channelNo = $channel->channel;
            if (!$channelNo && $autoIncrement) {
                $channelNo = ++$channelNumber;
            }
            // Get the TVG ID
            switch ($idChannelBy) {
                case PlaylistChannelId::ChannelId:
                    $tvgId = $channelNo;
                    break;
                case PlaylistChannelId::Name:
                    $tvgId = $channel->name_custom ?? $channel->name;
                    break;
                case PlaylistChannelId::Title:
                    $tvgId = $channel->title_custom ?? $channel->title;
                    break;
                default:
                    $tvgId = $channel->stream_id_custom ?? $channel->stream_id;
                    break;
            }
            return [
                'GuideNumber' => (string)$tvgId,
                'GuideName' => $channel->title_custom ?? $channel->title,
                'URL' => $url,
            ];

            // Example of more detailed response
            //            return [
            //                'GuideNumber' => $channel->channel_number ?? $streamId, // Channel number (e.g., "100")
            //                'GuideName'   => $channel->title_custom ?? $channel->title, // Channel name
            //                'URL'         => $url, // Stream URL
            //                'HD'          => $is_hd ? 1 : 0, // HD flag
            //                'VideoCodec'  => 'H264', // Set based on your stream format
            //                'AudioCodec'  => 'AAC', // Set based on your stream format
            //                'Favorite'    => $favorite ? 1 : 0, // Favorite flag
            //                'DRM'         => 0, // Assuming no DRM
            //                'Streaming'   => 'direct', // Direct stream or transcoding
            //            ];
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

    private function getDeviceInfo($playlist)
    {
        // Return the HDHR device info
        $uuid = $playlist->uuid;
        $tunerCount = (int)$playlist->streams === 0
            ? ($xtreamStatus['user_info']['max_connections'] ?? $playlist->streams ?? 1)
            : $playlist->streams;
        $tunerCount = max($tunerCount, 1); // Ensure at least 1 tuner
        $deviceId = substr($uuid, 0, 8);
        $baseUrl = ProxyFacade::getBaseUrl();
        $baseUrl = $baseUrl . "/{$uuid}/hdhr";
        return [
            'DeviceID' => $deviceId,
            'FriendlyName' => "{$playlist->name} HDHomeRun",
            'ModelNumber' => 'HDHR5-4K',
            'FirmwareName' => 'hdhomerun5_firmware_20240425',
            'FirmwareVersion' => '20240425',
            'DeviceAuth' => 'test_auth_token',
            'BaseURL' => $baseUrl,
            'LineupURL' => "$baseUrl/lineup.json",
            'TunerCount' => $tunerCount,
        ];
    }
}
