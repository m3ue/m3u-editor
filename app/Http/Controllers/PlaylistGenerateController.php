<?php

namespace App\Http\Controllers;

use App\Enums\ChannelLogoType;
use App\Enums\PlaylistChannelId;
use App\Facades\PlaylistFacade;
use App\Facades\ProxyFacade;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Services\PlaylistUrlService;
use Illuminate\Http\Request;

class PlaylistGenerateController extends Controller
{
    public function __invoke(Request $request, string $uuid)
    {
        // Fetch the playlist
        $playlist = PlaylistFacade::resolvePlaylistByUuid($uuid);
        if (! $playlist) {
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

            if (! $authenticated) {
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

        // Build the channel query
        $channels = self::getChannelQuery($playlist);
        $cursor = $channels->cursor();

        // Get all active channels
        return response()->stream(
            function () use ($cursor, $baseUrl, $playlist, $proxyEnabled, $logoProxyEnabled, $type, $usedAuth) {
                // Set the auth details
                if ($usedAuth) {
                    $username = urlencode($usedAuth->username);
                    $password = urlencode($usedAuth->password);
                } else {
                    $username = urlencode($playlist->user->name);
                    $password = urlencode($playlist->uuid);
                }

                // Output the enabled channels
                $epgUrl = route('epg.generate', ['uuid' => $playlist->uuid]);
                echo "#EXTM3U x-tvg-url=\"$epgUrl\" \n";
                $channelNumber = $playlist->auto_channel_increment ? $playlist->channel_start - 1 : 0;
                $idChannelBy = $playlist->id_channel_by;
                foreach ($cursor as $channel) {
                    // Get the title and name
                    $title = $channel->title_custom ?? $channel->title;
                    $name = $channel->name_custom ?? $channel->name;
                    $url = PlaylistUrlService::getChannelUrl($channel, $playlist);
                    // Use selected EPG fields (avoids N+1 query for epgChannel relation)
                    $epgIcon = $channel->epg_icon ?? null;
                    $epgIconCustom = $channel->epg_icon_custom ?? null;
                    $channelNo = $channel->channel;
                    $timeshift = $channel->shift ?? 0;
                    $stationId = $channel->station_id ?? '';
                    $epgShift = $channel->tvg_shift ?? 0;
                    $group = $channel->group ?? '';
                    if (! $channelNo && $playlist->auto_channel_increment) {
                        $channelNo = ++$channelNumber;
                    }
                    if ($type === 'custom') {
                        // We selected the custom tag name as `custom_group_name` when building the query
                        // It's a JSON field with translations, so decode and extract the 'en' locale
                        if (! empty($channel->custom_group_name)) {
                            $groupName = json_decode($channel->custom_group_name, true);
                            $group = $groupName['en'] ?? $groupName[array_key_first($groupName)] ?? '';
                        }
                    }

                    // Get the TVG ID
                    switch ($idChannelBy) {
                        case PlaylistChannelId::ChannelId:
                            $tvgId = $channel->id;
                            break;
                        case PlaylistChannelId::Name:
                            $tvgId = $channel->name_custom ?? $channel->name;
                            break;
                        case PlaylistChannelId::Title:
                            $tvgId = $channel->title_custom ?? $channel->title;
                            break;
                        default:
                            $tvgId = $channel->source_id ?? $channel->stream_id_custom ?? $channel->stream_id;
                            break;
                    }

                    // If no TVG ID still, fallback to the channel source ID or internal ID as a last resort
                    if (empty($tvgId)) {
                        $tvgId = $channel->source_id ?? $channel->id;
                    }

                    // Get the icon
                    $icon = '';
                    if ($channel->logo) {
                        // Logo override takes precedence
                        $icon = $channel->logo;
                    } elseif ($channel->logo_type === ChannelLogoType::Epg && ($epgIconCustom || $epgIcon)) {
                        $icon = $epgIconCustom ?? $epgIcon ?? '';
                    } elseif ($channel->logo_type === ChannelLogoType::Channel) {
                        $icon = $channel->logo ?? $channel->logo_internal ?? '';
                    }
                    if (empty($icon)) {
                        $icon = $baseUrl.'/placeholder.png';
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
                    if (! (config('app.disable_m3u_xtream_format') ?? false)) {
                        $urlPath = 'live';
                        if ($channel->is_vod) {
                            $urlPath = 'movie';
                            $extension = $channel->container_extension ?? 'mkv';
                        }
                        $url = $baseUrl."/{$urlPath}/{$username}/{$password}/".$channel->id.'.'.$extension;
                    }
                    $url = rtrim($url, '.');

                    // Make sure TVG ID only contains characters and numbers
                    $tvgId = preg_replace(config('dev.tvgid.regex'), '', $tvgId);

                    // Output the channel
                    $extInf = '#EXTINF:-1';
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
                    echo "$extInf,".$title."\n";
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
                    echo $url."\n";
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
                            },
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
                            if (! (config('app.disable_m3u_xtream_format') ?? false)) {
                                $containerExtension = $episode->container_extension ?? 'mp4';
                                $url = $baseUrl."/series/{$username}/{$password}/".$episode->id.".{$containerExtension}";
                            } elseif ($proxyEnabled) {
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
                                    $tvgId = $episode->id;
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
                            echo "$extInf,".$title."\n";
                            echo $url."\n";
                        }
                    }
                }
            },
            200,
            [
                'Access-Control-Allow-Origin' => '*',
                'Content-Type' => 'audio/x-mpegurl',
                'Cache-Control' => 'no-cache, must-revalidate',
                'Pragma' => 'no-cache',
            ]
        );
    }

    public function hdhr(string $uuid)
    {
        // Fetch the playlist so we can send a 404 if not found
        $playlist = PlaylistFacade::resolvePlaylistByUuid($uuid);
        if (! $playlist) {
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
        if (! $playlist) {
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

            if (! $authenticated) {
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
        if (! $playlist) {
            return response()->json(['Error' => 'Playlist Not Found'], 404);
        }

        // Return the HDHR device info
        return $this->getDeviceInfo($playlist);
    }

    public function hdhrLineup(string $uuid)
    {
        // Fetch the playlist
        $playlist = PlaylistFacade::resolvePlaylistByUuid($uuid);
        if (! $playlist) {
            return response()->json(['Error' => 'Playlist Not Found'], 404);
        }

        // Build the channel query
        $channels = self::getChannelQuery($playlist);

        // Set the auth details
        $username = $playlist->user->name;
        $password = $playlist->uuid;

        // Check if proxy enabled
        $idChannelBy = $playlist->id_channel_by;
        $autoIncrement = $playlist->auto_channel_increment;
        $channelNumber = $autoIncrement ? $playlist->channel_start - 1 : 0;

        // Stream the JSON response to avoid loading all channels into memory.
        $cursor = $channels->cursor();
        $headers = [
            'Content-Type' => 'application/json',
        ];

        return response()->stream(function () use ($cursor, $username, $password, $idChannelBy, $autoIncrement, &$channelNumber) {
            $first = true;
            echo '[';
            foreach ($cursor as $channel) {
                $sourceUrl = $channel->url_custom ?? $channel->url;
                $baseUrl = ProxyFacade::getBaseUrl();
                $extension = pathinfo($sourceUrl, PATHINFO_EXTENSION);
                $urlPath = 'live';
                if ($channel->is_vod) {
                    $urlPath = 'movie';
                    $extension = $channel->container_extension ?? 'mkv';
                }
                $url = rtrim($baseUrl."/{$urlPath}/{$username}/{$password}/".$channel->id.'.'.$extension, '.');
                $channelNo = $channel->channel;
                if (! $channelNo && $autoIncrement) {
                    $channelNo = ++$channelNumber;
                }

                // Get the TVG ID
                switch ($idChannelBy) {
                    case PlaylistChannelId::ChannelId:
                        $tvgId = $channel->id;
                        break;
                    case PlaylistChannelId::Name:
                        $tvgId = $channel->name_custom ?? $channel->name;
                        break;
                    case PlaylistChannelId::Title:
                        $tvgId = $channel->title_custom ?? $channel->title;
                        break;
                    default:
                        $tvgId = $channel->source_id ?? $channel->stream_id_custom ?? $channel->stream_id;
                        break;
                }

                if (empty($tvgId)) {
                    $tvgId = $channel->source_id ?? $channel->id;
                }
                $tvgId = preg_replace(config('dev.tvgid.regex'), '', $tvgId);

                $item = [
                    'GuideNumber' => (string) $tvgId,
                    'GuideName' => $channel->title_custom ?? $channel->title,
                    'URL' => $url,
                ];

                if (! $first) {
                    echo ',';
                }
                echo json_encode($item);
                $first = false;
            }
            echo ']';
        }, 200, $headers);
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
        $tunerCount = (int) $playlist->streams === 0
            ? ($xtreamStatus['user_info']['max_connections'] ?? $playlist->streams ?? 1)
            : $playlist->streams;
        $tunerCount = max($tunerCount, 1); // Ensure at least 1 tuner
        $deviceId = substr($uuid, 0, 8);
        $baseUrl = ProxyFacade::getBaseUrl();
        $baseUrl = $baseUrl."/{$uuid}/hdhr";

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

    /**
     * Build the base query for channels for a playlist.
     *
     * @param  Playlist  $playlist
     */
    public static function getChannelQuery($playlist): mixed
    {
        // Build the base query for channels. We'll use cursor() to stream
        // results rather than loading all channels into memory.
        $playlistUuid = $playlist->uuid;
        $query = $playlist->channels()
            ->leftJoin('groups', 'channels.group_id', '=', 'groups.id')
            ->where('channels.enabled', true)
            ->when(! $playlist->include_vod_in_m3u, function ($q) {
                $q->where('channels.is_vod', false);
            })
            // Select the channel columns and also pull through group name and (for custom)
            // the custom tag name/order so we can order in SQL and avoid a PHP-side resort.
            ->selectRaw('channels.*')
            ->selectRaw('groups.name as group_name')
            ->selectRaw('groups.sort_order as group_sort_order');

        // Join EPG channel data to avoid N+1 queries and select common fields
        $query->leftJoin('epg_channels', 'channels.epg_channel_id', '=', 'epg_channels.id')
            ->selectRaw('epg_channels.icon as epg_icon')
            ->selectRaw('epg_channels.icon_custom as epg_icon_custom')
            // Alias the external EPG channel identifier to avoid clobbering the FK attribute
            ->selectRaw('epg_channels.channel_id as epg_channel_key');

        // If custom playlist, left join tags through the taggables polymorphic table
        if ($playlist instanceof CustomPlaylist) {
            $query->leftJoin('taggables', function ($join) {
                $join->on('channels.id', '=', 'taggables.taggable_id')
                    ->where('taggables.taggable_type', '=', Channel::class);
            });

            $query->leftJoin('tags as custom_tags', function ($join) use ($playlistUuid) {
                $join->on('taggables.tag_id', '=', 'custom_tags.id')
                    ->where('custom_tags.type', '=', $playlistUuid);
            });

            // Order by custom tag order when present, otherwise fall back to group sort_order
            $query->orderByRaw('COALESCE(custom_tags.order_column, groups.sort_order)')
                ->orderBy('channels.sort')
                ->orderBy('channels.channel')
                ->orderBy('channels.title');

            // Include the custom tag name/order in the selected columns
            // Note: custom_tags.name is a JSON field with translations like {"en":"Name"}
            // We'll decode it in PHP to extract the locale-specific value
            $query->selectRaw('custom_tags.name as custom_group_name')
                ->selectRaw('custom_tags.order_column as custom_order');
        } else {
            // Standard ordering for non-custom playlists
            $query->orderBy('groups.sort_order')
                ->orderBy('channels.sort')
                ->orderBy('channels.channel')
                ->orderBy('channels.title');
        }

        return $query;
    }
}
