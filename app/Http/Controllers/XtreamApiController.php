<?php

namespace App\Http\Controllers;

use App\Models\PlaylistAuth;
use Illuminate\Http\Response;
use Illuminate\Http\RedirectResponse;
use App\Enums\ChannelLogoType;
use App\Enums\PlaylistChannelId;
use App\Facades\PlaylistFacade;
use App\Facades\ProxyFacade;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Epg;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\Series;
use App\Models\SharedStream;
use App\Services\EpgCacheService;
use App\Services\M3uProxyService;
use App\Services\XtreamService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Redirect;
use Spatie\Tags\Tag;

class XtreamApiController extends Controller
{
    /**
     * Xtream API request handler.
     *
     * This endpoint serves as the primary interface for Xtream API interactions.
     * It requires authentication via username and password provided as query parameters.
     * The `action` query parameter dictates the specific operation to perform and the structure of the response.
     *
     * The `username` and `password` parameters are mandatory for all actions.
     *
     * You will use your m3u editor login username (default is admin), and the password will be your playlist unique identifier for the playlist you would like to access via the Xtream API.
     *
     * ## Supported Actions:
     *
     * ### panel (default)
     * Returns user authentication info and server details. This is the default action if none is specified. Returns the same response as: `get_user_info`, `get_account_info` and `get_server_info`.
     *
     * ### get_live_streams
     * Returns a JSON array of live stream objects. Only enabled, non-VOD channels are included.
     * Supports optional category filtering via `category_id` parameter.
     * Each stream object contains: `num`, `name`, `stream_type`, `stream_id`, `stream_icon`, `epg_channel_id`,
     * `added`, `category_id`, `tv_archive`, `direct_source`, `tv_archive_duration`.
     *
     * ### get_vod_streams
     * Returns a JSON array of VOD channel objects (movies marked as VOD). Only enabled VOD channels are included.
     * Supports optional category filtering via `category_id` parameter.
     * Each object contains: `num`, `name`, `title`, `year`, `stream_type` (always "movie"), `stream_id`, `stream_icon`,
     * `rating`, `rating_5based`, `added`, `category_id`, `category_ids`, `container_extension`, `custom_sid`, `direct_source`.
     *
     * ### get_series
     * Returns a JSON array of series objects. Only enabled series are included.
     * Supports optional category filtering via `category_id` parameter.
     * Each object contains: `num`, `name`, `series_id`, `cover`, `plot`, `cast`, `director`, `genre`, `releaseDate`,
     * `last_modified`, `rating`, `rating_5based`, `backdrop_path`, `youtube_trailer`, `episode_run_time`, `category_id`.
     *
     * ### get_live_categories
     * Returns a JSON array of live stream categories/groups. Only groups with enabled, non-VOD channels are included.
     * Each category contains: `category_id`, `category_name`, `parent_id`.
     *
     * ### get_vod_categories
     * Returns a JSON array of VOD categories/groups. Only groups with enabled VOD channels are included.
     * Each category contains: `category_id`, `category_name`, `parent_id`.
     *
     * ### get_series_categories
     * Returns a JSON array of series categories. Only categories with enabled series are included.
     * Each category contains: `category_id`, `category_name`, `parent_id`.
     *
     * ### get_series_info
     * Returns detailed information for a specific series, including its seasons and episodes.
     * Requires `series_id` parameter to specify which series to retrieve.
     * Returns series info, seasons, and episode details.
     *
     * ### get_vod_info
     * Returns detailed information for a specific VOD/movie stream.
     * Requires `vod_id` parameter to specify which VOD stream to retrieve.
     * Returns movie information and metadata in a structured format.
     * Uses channel's `info` and `movie_data` fields when available, or builds data from other channel fields.
     *
     * ### get_short_epg
     * Returns a limited number of EPG programmes for a specific live stream/channel.
     * Requires `stream_id` parameter to specify which channel to retrieve EPG data for.
     * Supports optional `limit` parameter (default=4) to control the number of programmes returned.
     * Returns programmes from current time onwards, including currently playing programme if any.
     * Includes `now_playing` flag to indicate if the channel is currently streaming.
     *
     * ### get_simple_data_table
     * Returns full EPG data for a specific live stream/channel for the current date.
     * Requires `stream_id` parameter to specify which channel to retrieve EPG data for.
     * Returns all programmes for today with programme details and timing information.
     * Includes `now_playing` flag to indicate if the channel is currently streaming.
     *
     * ### m3u_plus
     * Redirects to the `m3u` method to generate an M3U playlist in the M3U Plus format.
     * `output` parameter is ignored for this action and will instead use your Playlist configuration for M3U Plus output.
     *
     * ### get_user_info
     * ### get_account_info
     * ### get_server_info
     * Returns account and server information including user details and allowed output formats.
     * This provides the same user information as the panel.
     * Contains: `username`, `password`, `message`, `auth`, `status`, `exp_date`, `is_trial`,
     * `active_cons`, `created_at`, `max_connections`, `allowed_output_formats`.
     *
     *
     * @param string $uuid The UUID of the playlist (required path parameter)
     * @param Request $request The HTTP request containing query parameters:
     *   - username (string, required): User's Xtream API username
     *   - password (string, required): User's Xtream API password 
     *   - action (string, optional): Defaults to 'panel'. Determines the API action
     *   - category_id (string, optional): Filter results by category ID (required for get_series, optional for get_live_streams and get_vod_streams)
     *   - series_id (int, optional): Series ID (required for get_series_info action)
     *   - vod_id (int, optional): VOD/Movie ID (required for get_vod_info action)
     *   - stream_id (int, optional): Channel/Stream ID (required for get_short_epg and get_simple_data_table actions)
     *   - limit (int, optional): Number of EPG programmes to return for get_short_epg (default=4)
     *
     * @response 200 scenario="Panel action response" {
     *   "user_info": {
     *     "username": "test_user",
     *     "password": "test_pass",
     *     "message": "",
     *     "auth": 1,
     *     "status": "Active",
     *     "exp_date": "1767225600",
     *     "is_trial": "0",
     *     "active_cons": 1,
     *     "created_at": "1640995200",
     *     "max_connections": "2",
     *     "allowed_output_formats": ["m3u8", "ts"]
     *   },
     *   "server_info": {
     *     "url": "https://example.com",
     *     "port": "443",
     *     "https_port": "443",
     *     "server_protocol": "https",
     *     "timezone": "UTC",
     *     "server_software": "M3U Proxy Editor Xtream API",
     *     "timestamp_now": "1719187200",
     *     "time_now": "2025-06-20 12:00:00"
     *   }
     * }
     *
     * @response 200 scenario="Live streams response" [
     *   {
     *     "num": 1,
     *     "name": "CNN HD",
     *     "stream_type": "live",
     *     "stream_id": "12345",
     *     "stream_icon": "https://example.com/logos/cnn.png",
     *     "epg_channel_id": "cnn.us",
     *     "added": "1640995200",
     *     "category_id": "1",
     *     "tv_archive": 1,
     *     "direct_source": "https://example.com/live/user/pass/12345.ts",
     *     "tv_archive_duration": 24
     *   }
     * ]
     *
     * @response 200 scenario="VOD streams response" [
     *   {
     *     "num": 1,
     *     "name": "The Matrix",
     *     "title": "The Matrix",
     *     "year": "1999",
     *     "stream_type": "movie",
     *     "stream_id": "67890",
     *     "stream_icon": "https://example.com/covers/matrix.jpg",
     *     "rating": "8.7",
     *     "rating_5based": 4.35,
     *     "added": "1640995200",
     *     "category_id": "3",
     *     "category_ids": [3],
     *     "container_extension": "mkv",
     *     "custom_sid": "",
     *     "direct_source": ""
     *   }
     * ]
     *
     * @response 200 scenario="Series response" [
     *   {
     *     "num": 1,
     *     "name": "Breaking Bad",
     *     "series_id": 101,
     *     "cover": "https://example.com/covers/breaking_bad.jpg",
     *     "plot": "A high school chemistry teacher turned meth cook...",
     *     "cast": "Bryan Cranston, Aaron Paul",
     *     "director": "Vince Gilligan",
     *     "genre": "Crime, Drama",
     *     "releaseDate": "2008-01-20",
     *     "last_modified": "1640995200",
     *     "rating": "9.5",
     *     "rating_5based": 4.75,
     *     "backdrop_path": [],
     *     "youtube_trailer": "HhesaQXLuRY",
     *     "episode_run_time": "47",
     *     "category_id": "2"
     *   }
     * ]
     *
     * @response 200 scenario="Series info response" {
     *   "info": {
     *     "name": "Breaking Bad",
     *     "cover": "https://example.com/covers/breaking_bad.jpg",
     *     "plot": "A high school chemistry teacher turned meth cook...",
     *     "cast": "Bryan Cranston, Aaron Paul",
     *     "director": "Vince Gilligan",
     *     "genre": "Crime, Drama",
     *     "releaseDate": "2008-01-20",
     *     "last_modified": "1640995200",
     *     "rating": "9.5",
     *     "rating_5based": 4.75,
     *     "backdrop_path": [],
     *     "youtube_trailer": "HhesaQXLuRY",
     *     "episode_run_time": "47",
     *     "category_id": "2"
     *   },
     *   "episodes": {
     *     "1": [
     *       {
     *         "id": "1001",
     *         "episode_num": 1,
     *         "title": "Pilot",
     *         "container_extension": "mp4",
     *         "info": {
     *             "release_date" => "2024-06-29"
     *             "plot" => "Kafka's final fate is determined as the monster within him tries to take control."
     *             "duration_secs" => 1440
     *             "duration" => "00:24:00"
     *             "movie_image" => "http://23.227.147.172:80/images/e11236b82442615bc6e44d3555dce478.jpg"
     *             "bitrate" => 0
     *             "rating" => "7.3"
     *             "season" => "1"
     *             "tmdb_id" => "5188924"
     *             "cover_big" => "http://23.227.147.172:80/images/e11236b82442615bc6e44d3555dce478.jpg"
     *         },
     *         "added": "1640995200",
     *         "season": 1,
     *         "stream_id": "1001",
     *         "direct_source": "https://example.com/xtream/uuid/series/user/pass/1001.mp4"
     *       }
     *     ]
     *   },
     *   "seasons": {
     *     "1": []
     *   }
     * }
     *
     * @response 200 scenario="Live categories response" [
     *   {
     *     "category_id": "1",
     *     "category_name": "News",
     *     "parent_id": 0
     *   },
     *   {
     *     "category_id": "2",
     *     "category_name": "Sports",
     *     "parent_id": 0
     *   }
     * ]
     *
     * @response 200 scenario="VOD categories response" [
     *   {
     *     "category_id": "1",
     *     "category_name": "Action Movies",
     *     "parent_id": 0
     *   },
     *   {
     *     "category_id": "2",
     *     "category_name": "Comedy Movies",
     *     "parent_id": 0
     *   }
     * ]
     *
     * @response 200 scenario="Series categories response" [
     *   {
     *     "category_id": "1",
     *     "category_name": "Drama Series",
     *     "parent_id": 0
     *   },
     *   {
     *     "category_id": "2",
     *     "category_name": "Comedy Series",
     *     "parent_id": 0
     *   }
     * ]
     *
     * @response 200 scenario="Short EPG response" {
     *   "epg_listings": [
     *     {
     *       "id": "8037716",
     *       "epg_id": "8",
     *       "title": "Morning News",
     *       "lang": "en",
     *       "start": "2025-08-14 07:00:00",
     *       "end": "2025-08-14 07:15:00",
     *       "description": "Latest morning news and updates",
     *       "channel_id": "cnn.us",
     *       "start_timestamp": "1755154800",
     *       "stop_timestamp": "1755155700",
     *       "now_playing": 1,
     *       "has_archive": 0
     *     },
     *     {
     *       "id": "8037717",
     *       "epg_id": "8",
     *       "title": "Business Report",
     *       "lang": "en",
     *       "start": "2025-08-14 07:15:00",
     *       "end": "2025-08-14 07:30:00",
     *       "description": "Financial market updates",
     *       "channel_id": "cnn.us",
     *       "start_timestamp": "1755155700",
     *       "stop_timestamp": "1755156600",
     *       "now_playing": 0,
     *       "has_archive": 0
     *     }
     *   ]
     * }
     *
     * @response 200 scenario="Simple date table response" {
     *   "epg_listings": [
     *     {
     *       "id": "8037716",
     *       "epg_id": "8",
     *       "title": "Morning News",
     *       "lang": "en",
     *       "start": "2025-08-14 07:00:00",
     *       "end": "2025-08-14 07:15:00",
     *       "description": "Latest morning news and updates",
     *       "channel_id": "cnn.us",
     *       "start_timestamp": "1755154800",
     *       "stop_timestamp": "1755155700",
     *       "now_playing": 1,
     *       "has_archive": 0
     *     }
     *   ]
     * }
     *
     * @response 200 scenario="Account info response" {
     *   "username": "test_user",
     *   "password": "test_pass",
     *   "message": "",
     *   "auth": 1,
     *   "status": "Active",
     *   "exp_date": "1767225600",
     *   "is_trial": "0",
     *   "active_cons": 1,
     *   "created_at": "1640995200",
     *   "max_connections": "2",
     *   "allowed_output_formats": ["m3u8", "ts"]
     * }
     *
     * @response 400 scenario="Bad Request" {"error": "Invalid action"}
     * @response 400 scenario="Missing category_id for get_series" {"error": "category_id parameter is required for get_series action"}
     * @response 400 scenario="Missing series_id for get_series_info" {"error": "series_id parameter is required for get_series_info action"}
     * @response 400 scenario="Missing stream_id for get_short_epg" {"error": "stream_id parameter is required for get_short_epg action"}
     * @response 400 scenario="Missing stream_id for get_simple_data_table" {"error": "stream_id parameter is required for get_simple_data_table action"}
     * @response 401 scenario="Unauthorized - Missing Credentials" {"error": "Unauthorized - Missing credentials"}
     * @response 401 scenario="Unauthorized - Invalid Credentials" {"error": "Unauthorized"}
     * @response 404 scenario="Not Found (e.g., playlist not found)" {"error": "Playlist not found"}
     * @response 404 scenario="Series not found" {"error": "Series not found or not enabled"}
     *
     * @unauthenticated
     */
    public function handle(Request $request)
    {
        // Authenticate the user based on the provided credentials
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);
        list($playlist, $authMethod, $username, $password) = $this->authenticate($request);

        // If no authentication method worked, return error
        if (!$playlist || $authMethod === 'none') {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $urlSafePass = urlencode($password);
        $urlSafeUser = urlencode($username);

        // Check if Custom Playlist (or Custom Playlist via Alias) as we handle these differently
        $isCustomPlaylist = $playlist instanceof CustomPlaylist || ($playlist instanceof PlaylistAlias && $playlist->custom_playlist_id);
        $tagUuid = $playlist->uuid; // Default to Playlist UUID
        if ($isCustomPlaylist && $playlist instanceof PlaylistAlias) {
            $playlist->load('customPlaylist');
            $tagUuid = $playlist->customPlaylist->uuid; // PlaylistAlias case, get the attached CustomPlaylist UUID
        }

        $baseUrl = ProxyFacade::getBaseUrl();
        $action = $request->input('action', 'panel');
        if (
            $action === 'panel' ||
            $action === 'get_user_info' ||
            $action === 'get_account_info' ||
            $action === 'get_server_info' ||
            empty($request->input('action'))
        ) {
            $now = Carbon::now();
            $xtreamStatus = $playlist->xtream_status ?? null;
            if ($xtreamStatus) {
                $expires = $xtreamStatus['user_info']['exp_date']
                    ? $xtreamStatus['user_info']['exp_date']
                    : $now->copy()->startOfYear()->addYears(1)->timestamp;
                $streams = (int)$playlist->streams === 0
                    ? ($xtreamStatus['user_info']['max_connections'] ?? $playlist->streams ?? 1)
                    : $playlist->streams;
                $activeConnections = (int)($xtreamStatus['user_info']['active_cons'] ?? 0);
            } else {
                $expires = $now->copy()->startOfYear()->addYears(1)->timestamp;
                $streams = $playlist->streams ?? 1;
                $activeConnections = 0;
            }
            $outputFormats = ['m3u8', 'ts'];
            if ($playlist->enable_proxy) {
                if ($playlist->xtream_config ?? false) {
                    $proxyOutput = $playlist->xtream_config['output'] ?? 'ts';
                    $outputFormats = $proxyOutput === 'hls' ? ['m3u8'] : [$proxyOutput];
                }
                $activeConnections = M3uProxyService::getPlaylistActiveStreamsCount($playlist);
            }

            $userInfo = [
                // 'playlist_id' => (string)$playlist->id, // Debugging
                'username' => $username,
                'password' => $password,
                'message' => 'Welcome to m3u editor Xtream API',
                'auth' => 1,
                'status' => 'Active',
                'exp_date' => (string)$expires,
                'is_trial' => '0',
                'active_cons' => (string)$activeConnections,
                'created_at' => (string)($playlist->user ? $playlist->user->created_at->timestamp : $now->timestamp),
                'max_connections' => (string)$streams,
                'allowed_output_formats' => $outputFormats,
            ];

            $parsedUrl = parse_url($baseUrl);
            $scheme = $parsedUrl['scheme'] ?? 'http';
            $host = $parsedUrl['host'];
            $port = isset($parsedUrl['port']) ? ':' . $parsedUrl['port'] : '';
            $httpsPort = ($scheme === 'https') ? (string)$port : "";

            $serverInfo = [
                'xui' => false, // Assuming this is not an XUI panel
                'version' => null, // Placeholder version, update as needed
                'revision' => null, // No revision info available
                'url' => $baseUrl,
                'port' => (string)$port,
                'https_port' => $httpsPort,
                'server_protocol' => $scheme,
                'rtmp_port' => "", // RTMP not available currently
                'server_software' => config('app.name') . ' Xtream API',
                'timestamp_now' => $now->timestamp,
                'time_now' => $now->toDateTimeString(),
                'timezone' => Config::get('app.timezone', 'UTC'),
            ];

            return response()->json([
                'user_info' => $userInfo,
                'server_info' => $serverInfo
            ]);
        } else if ($action === 'get_live_streams') {
            $categoryId = $request->input('category_id');

            $channelsQuery = $playlist->channels()
                ->leftJoin('groups', 'channels.group_id', '=', 'groups.id')
                ->where('channels.enabled', true)
                ->where('channels.is_vod', false)
                ->with(['epgChannel', 'tags', 'group'])
                ->select('channels.*');

            // Apply category filtering if category_id is provided
            if ($categoryId && $categoryId !== 'all') {
                if ($isCustomPlaylist) {
                    $channelsQuery->where(function ($query) use ($categoryId, $tagUuid) {
                        // Channels with custom tags matching the category ID
                        $query->whereHas('tags', function ($tagQuery) use ($categoryId, $tagUuid) {
                            $tagQuery->where('type', $tagUuid)
                                ->where('id', $categoryId);
                        })
                            // OR channels without custom tags but with matching group_id
                            ->orWhere(function ($subQuery) use ($categoryId, $tagUuid) {
                                $subQuery->whereDoesntHave('tags', function ($tagQuery) use ($tagUuid) {
                                    $tagQuery->where('type', $tagUuid);
                                })->where('group_id', $categoryId);
                            });
                    });
                } else {
                    // For regular Playlist and MergedPlaylist, filter by group_id
                    $channelsQuery->where('group_id', $categoryId);
                }
            }

            $proxyEnabled = $playlist->enable_proxy;

            $enabledChannels = $channelsQuery
                ->orderBy('groups.sort_order')
                ->orderBy('channels.sort')
                ->orderBy('channels.channel')
                ->orderBy('channels.title')
                ->get();

            // For custom playlists, re-sort by custom group order (if assigned), falling back to original group order
            if ($isCustomPlaylist && $enabledChannels->isNotEmpty()) {
                $enabledChannels = $enabledChannels->sort(function ($a, $b) use ($tagUuid) {
                    // Get custom tag order for both channels
                    $aTag = $a->tags->where('type', $tagUuid)->first();
                    $bTag = $b->tags->where('type', $tagUuid)->first();

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

            $liveStreams = [];
            if ($enabledChannels instanceof Collection) {
                $channelNumber = $playlist->auto_channel_increment ? $playlist->channel_start - 1 : 0;
                foreach ($enabledChannels as $index => $channel) {
                    $streamIcon = $baseUrl . '/placeholder.png';
                    if ($channel->logo) {
                        // Logo override takes precedence
                        $streamIcon = $channel->logo;
                    } elseif ($channel->logo_type === ChannelLogoType::Epg && $channel->epgChannel && $channel->epgChannel->icon) {
                        $streamIcon = $channel->epgChannel->icon;
                    } elseif ($channel->logo_type === ChannelLogoType::Channel && ($channel->logo || $channel->logo_internal)) {
                        $logo = $channel->logo ?? $channel->logo_internal ?? '';
                        $streamIcon = filter_var($logo, FILTER_VALIDATE_URL) ? $logo : $baseUrl . "/$logo";
                    }
                    if ($playlist->enable_logo_proxy) {
                        $streamIcon = LogoProxyController::generateProxyUrl($streamIcon);
                    }

                    // Determine category_id based on playlist type
                    $channelCategoryId = 'all';
                    if ($isCustomPlaylist) {
                        $customGroup = $channel->tags()->where('type', $tagUuid)->first();
                        if ($customGroup) {
                            $channelCategoryId = (string)$customGroup->id; // Use tag ID
                        } elseif ($channel->group_id) {
                            $channelCategoryId = (string)$channel->group_id; // Use group_id
                        }
                    } else {
                        // For regular playlists, use group_id
                        if ($channel->group_id) {
                            $channelCategoryId = (string)$channel->group_id;
                        }
                    }

                    $idChannelBy = $playlist->id_channel_by;
                    $channelNo = $channel->channel;
                    if (!$channelNo && $playlist->auto_channel_increment) {
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

                    // Get the file extension from the URL
                    $url = $channel->url_custom ?? $channel->url;
                    $extension = pathinfo($url, PATHINFO_EXTENSION);
                    if (empty($extension)) {
                        $sourcePlaylist = $channel->getEffectivePlaylist();
                        $extension = $sourcePlaylist->xtream_config['output'] ?? 'ts'; // Default to 'ts' if not set
                    }

                    $liveStreams[] = [
                        'num' => $channelNo,
                        'name' => $channel->title_custom ?? $channel->title,
                        'stream_type' => 'live',
                        'stream_id' => $channel->id,
                        'stream_icon' => $streamIcon,
                        'epg_channel_id' => $tvgId,
                        'added' => (string)$channel->created_at->timestamp,
                        'category_id' => $channelCategoryId,
                        'category_ids' => [(int) $channelCategoryId],
                        'tv_archive' => $channel->catchup ? 1 : 0,
                        'tv_archive_duration' => $channel->shift ?? 0,
                        'custom_sid' => '',
                        'thumbnail' => '',
                        'direct_source' => $baseUrl . "/live/{$urlSafeUser}/{$urlSafePass}/" . $channel->id . "." . $extension,
                    ];
                }
            }
            return response()->json($liveStreams);
        } else if ($action === 'get_vod_streams') {
            $categoryId = $request->input('category_id');

            $channelsQuery = $playlist->channels()
                ->leftJoin('groups', 'channels.group_id', '=', 'groups.id')
                ->where('channels.enabled', true)
                ->where('channels.is_vod', true)
                ->with(['epgChannel', 'tags', 'group'])
                ->select('channels.*');

            // Apply category filtering if category_id is provided
            if ($categoryId && $categoryId !== 'all') {
                if ($isCustomPlaylist) {
                    // For CustomPlaylist, filter by tag ID or group_id
                    $channelsQuery->where(function ($query) use ($categoryId, $tagUuid) {
                        // Channels with custom tags matching the category ID
                        $query->whereHas('tags', function ($tagQuery) use ($categoryId, $tagUuid) {
                            $tagQuery->where('type', $tagUuid)
                                ->where('id', $categoryId);
                        })
                            // OR channels without custom tags but with matching group_id
                            ->orWhere(function ($subQuery) use ($categoryId, $tagUuid) {
                                $subQuery->whereDoesntHave('tags', function ($tagQuery) use ($tagUuid) {
                                    $tagQuery->where('type', $tagUuid);
                                })->where('group_id', $categoryId);
                            });
                    });
                } else {
                    // For regular Playlist and MergedPlaylist, filter by group_id
                    $channelsQuery->where('group_id', $categoryId);
                }
            }

            $enabledVodChannels = $channelsQuery
                ->orderBy('groups.sort_order')
                ->orderBy('channels.sort')
                ->orderBy('channels.channel')
                ->orderBy('channels.title')
                ->get();

            // For custom playlists, re-sort by custom group order (if assigned), falling back to original group order
            if ($isCustomPlaylist && $enabledVodChannels->isNotEmpty()) {
                $enabledVodChannels = $enabledVodChannels->sort(function ($a, $b) use ($tagUuid) {
                    // Get custom tag order for both channels
                    $aTag = $a->tags->where('type', $tagUuid)->first();
                    $bTag = $b->tags->where('type', $tagUuid)->first();

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

            $vodStreams = [];
            if ($enabledVodChannels instanceof Collection) {
                foreach ($enabledVodChannels as $index => $channel) {
                    $streamIcon = $baseUrl . '/placeholder.png';
                    if ($channel->logo) {
                        // Logo override takes precedence
                        $streamIcon = $channel->logo;
                    } elseif ($channel->logo_type === ChannelLogoType::Epg && $channel->epgChannel && $channel->epgChannel->icon) {
                        $streamIcon = $channel->epgChannel->icon;
                    } elseif ($channel->logo_type === ChannelLogoType::Channel && ($channel->logo || $channel->logo_internal)) {
                        $logo = $channel->logo ?? $channel->logo_internal ?? '';
                        $streamIcon = filter_var($logo, FILTER_VALIDATE_URL) ? $logo : $baseUrl . "/$logo";
                    }
                    if ($playlist->enable_logo_proxy) {
                        $streamIcon = LogoProxyController::generateProxyUrl($streamIcon);
                    }

                    // Determine category_id based on playlist type
                    $channelCategoryId = 'all';
                    if ($isCustomPlaylist) {
                        // For CustomPlaylist, prioritize custom tags over group_id
                        $customGroup = $channel->tags()->where('type', $tagUuid)->first();
                        if ($customGroup) {
                            $channelCategoryId = (string)$customGroup->id; // Use tag ID
                        } elseif ($channel->group_id) {
                            $channelCategoryId = (string)$channel->group_id; // Use group_id
                        }
                    } else {
                        // For regular playlists, use group_id
                        if ($channel->group_id) {
                            $channelCategoryId = (string)$channel->group_id;
                        }
                    }

                    $extension = $channel->container_extension ?? 'mkv';
                    $tmdb = $channel->info['tmdb_id'] ?? $channel->movie_data['tmdb_id'] ?? 0;
                    $vodStreams[] = [
                        'num' => $index + 1,
                        'name' => $channel->title_custom ?? $channel->title,
                        'title' => $channel->title_custom ?? $channel->title,
                        'year' => $channel->year ?? '',
                        'stream_type' => 'movie',
                        'stream_id' => $channel->id,
                        'stream_icon' => $streamIcon,
                        'rating' => $channel->rating ?? '',
                        'rating_5based' => $channel->rating_5based ?? 0,
                        'added' => (string)$channel->created_at->timestamp,
                        'category_id' => $channelCategoryId,
                        'category_ids' => [(int) $channelCategoryId],
                        'tmdb' => (string)$tmdb,
                        'tmdb_id' => (int)$tmdb,
                        'container_extension' => $channel->container_extension ?? 'mkv',
                        'custom_sid' => '',
                        'direct_source' => $baseUrl . "/movie/{$urlSafeUser}/{$urlSafePass}/" . $channel->id . "." . $extension,
                    ];
                }
            }
            return response()->json($vodStreams);
        } else if ($action === 'get_series') {
            $categoryId = $request->input('category_id');

            $seriesQuery = $playlist->series()
                ->where('series.enabled', true)
                ->with(['tags', 'category']);

            // Apply category filtering if category_id is provided
            if ($categoryId && $categoryId !== 'all') {
                if ($isCustomPlaylist) {
                    // For CustomPlaylist, filter by tag ID or group_id
                    $seriesQuery->where(function ($query) use ($categoryId, $tagUuid) {
                        // Channels with custom tags matching the category ID
                        $query->whereHas('tags', function ($tagQuery) use ($categoryId, $tagUuid) {
                            $tagQuery->where('type', $tagUuid . '-category')
                                ->where('id', $categoryId);
                        })
                            // OR channels without custom tags but with matching group_id
                            ->orWhere(function ($subQuery) use ($categoryId, $tagUuid) {
                                $subQuery->whereDoesntHave('tags', function ($tagQuery) use ($tagUuid) {
                                    $tagQuery->where('type', $tagUuid . '-category');
                                })->where('category_id', $categoryId);
                            });
                    });
                } else {
                    // For regular Playlist and MergedPlaylist, filter by category_id
                    $seriesQuery->where('category_id', $categoryId);
                }
            }

            $enabledSeries = $seriesQuery->get();

            // For custom playlists, re-sort by custom category order (if assigned), falling back to original category order
            if ($isCustomPlaylist && $enabledSeries->isNotEmpty()) {
                $categoryTagType = $tagUuid . '-category';
                $enabledSeries = $enabledSeries->sort(function ($a, $b) use ($categoryTagType) {
                    // Get custom tag order for both series
                    $aTag = $a->tags->where('type', $categoryTagType)->first();
                    $bTag = $b->tags->where('type', $categoryTagType)->first();

                    $aOrder = $aTag ? ($aTag->order_column ?? 999999) : ($a->category->sort_order ?? 999999);
                    $bOrder = $bTag ? ($bTag->order_column ?? 999999) : ($b->category->sort_order ?? 999999);

                    // Primary sort by category/tag order
                    if ($aOrder !== $bOrder) {
                        return $aOrder <=> $bOrder;
                    }

                    // Secondary sort by series sort
                    $aSort = $a->sort ?? 999999;
                    $bSort = $b->sort ?? 999999;
                    if ($aSort !== $bSort) {
                        return $aSort <=> $bSort;
                    }

                    // Final sort by name
                    return ($a->name ?? '') <=> ($b->name ?? '');
                })->values();
            }

            $seriesList = [];
            if ($enabledSeries instanceof Collection) {
                foreach ($enabledSeries as $index => $seriesItem) {
                    // Determine category_id based on playlist type
                    $seriesCategoryId = 'all';
                    if ($isCustomPlaylist) {
                        // For CustomPlaylist, prioritize custom tags over category_id
                        $customCat = $seriesItem->tags->where('type', $tagUuid . '-category')->first();
                        if ($customCat) {
                            $seriesCategoryId = (string)$customCat->id; // Use tag ID
                        } elseif ($seriesItem->category_id) {
                            $seriesCategoryId = (string)$seriesItem->category_id; // Use category_id
                        }
                    } else {
                        // For regular playlists, use category_id
                        if ($seriesItem->category_id) {
                            $seriesCategoryId = (string)$seriesItem->category_id;
                        }
                    }

                    $tmdb = $seriesItem->metadata['tmdb'] ?? '';
                    $lastModified = $seriesItem->updated_at
                        ? $seriesItem->updated_at->timestamp
                        : Carbon::now()->timestamp;
                    $seriesList[] = [
                        'num' => $index + 1,
                        'name' => $seriesItem->name,
                        'series_id' => (int)$seriesItem->id,
                        'cover' => $seriesItem->cover ? (filter_var($seriesItem->cover, FILTER_VALIDATE_URL) ? $seriesItem->cover : $baseUrl . "/$seriesItem->cover") : $baseUrl . '/placeholder.png',
                        'plot' => $seriesItem->plot ?? '',
                        'cast' => $seriesItem->cast ?? '',
                        'director' => $seriesItem->director ?? '',
                        'genre' => $seriesItem->genre ?? '',
                        'releaseDate' => $seriesItem->release_date ?? '',
                        'last_modified' => (string)($lastModified),
                        'rating' => (string)($seriesItem->rating ?? 0),
                        'rating_5based' => round((floatval($seriesItem->rating ?? 0)) / 2, 1),
                        'backdrop_path' => $seriesItem->backdrop_path ?? [],
                        'tmdb' => (string)$tmdb,
                        'tmdb_id' => (int)($tmdb ?: 0),
                        'youtube_trailer' => $seriesItem->youtube_trailer ?? '',
                        'episode_run_time' => (string)($seriesItem->episode_run_time ?? 0),
                        'category_id' => $seriesCategoryId,
                    ];
                }
            }
            return response()->json($seriesList);
        } else if ($action === 'get_series_info') {
            $seriesId = $request->input('series_id');

            if (!$seriesId) {
                return response()->json(['error' => 'series_id parameter is required for get_series_info action'], 400);
            }

            $seriesItem = $playlist->series()
                ->where('enabled', true)
                ->where('series.id', $seriesId)
                ->with(['seasons.episodes', 'category'])
                ->first();

            if (!$seriesItem) {
                return response()->json(['error' => 'Series not found or not enabled'], 404);
            }

            // Check if series metadata has been fetched, and if so how recently
            if (!$seriesItem->last_metadata_fetch || $seriesItem->last_metadata_fetch < now()->subDays(1)) {
                // Either no metadata, or stale metadata
                $results = $seriesItem->fetchMetadata(sync: false);
                if ($results === false) {
                    return response()->json(['error' => 'Failed to fetch series metadata'], 500);
                }

                // Metadata fetched successfully
                $seriesItem->load('seasons.episodes', 'category');
            }

            $cover = $seriesItem->cover ? (filter_var($seriesItem->cover, FILTER_VALIDATE_URL) ? $seriesItem->cover : $baseUrl . "/$seriesItem->cover") : $baseUrl . '/placeholder.png';
            $backdropPaths = $seriesItem->backdrop_path ?? [];
            if ($playlist->enable_logo_proxy) {
                $cover = LogoProxyController::generateProxyUrl($cover);
                $backdropPaths = array_map(fn($path) => LogoProxyController::generateProxyUrl($path), $backdropPaths);
            }

            $now = Carbon::now();
            $tmdb = $seriesItem->metadata['tmdb'] ?? '';
            $lastModified = $seriesItem->updated_at
                ? $seriesItem->updated_at->timestamp
                : Carbon::now()->timestamp;
            $seriesInfo = [
                'name' => $seriesItem->name,
                'cover' => $cover,
                'plot' => $seriesItem->plot ?? '',
                'cast' => $seriesItem->cast ?? '',
                'director' => $seriesItem->director ?? '',
                'genre' => $seriesItem->genre ?? '',
                'releaseDate' => $seriesItem->release_date ?? '',
                'last_modified' => (string)($lastModified),
                'rating' => (string)($seriesItem->rating ?? 0),
                'rating_5based' => round((floatval($seriesItem->rating ?? 0)) / 2, 1),
                'backdrop_path' => $backdropPaths,
                'tmdb' => (string)$tmdb,
                'tmdb_id' => (int)($tmdb ?: 0),
                'youtube_trailer' => $seriesItem->youtube_trailer ?? '',
                'episode_run_time' => (string)($seriesItem->episode_run_time ?? 0),
                'category_id' => (string)($seriesItem->category_id ?? ($seriesItem->category ? $seriesItem->category->id : 'all')),
            ];

            $seasons = [];
            $episodesBySeason = [];
            if ($seriesItem->seasons && $seriesItem->seasons->isNotEmpty()) {
                foreach ($seriesItem->seasons as $season) {
                    $seasonNumber = $season->season_number;
                    $seasonCover = $playlist->enable_logo_proxy && ($season->cover ?? false)
                        ? LogoProxyController::generateProxyUrl($season->cover)
                        : $season->cover;
                    $tmdbCover = $playlist->enable_logo_proxy && ($seriesItem->metadata['cover_tmdb'] ?? false)
                        ? LogoProxyController::generateProxyUrl($seriesItem->metadata['cover_tmdb'])
                        : ($seriesItem->metadata['cover_tmdb'] ?? null);
                    $coverBig = $playlist->enable_logo_proxy && ($season->cover_big ?? false)
                        ? LogoProxyController::generateProxyUrl($season->cover_big)
                        : ($season->cover_big ?? null);
                    $seasons[] = [
                        'name' => $season->metadata['name'] ?? "Season {$seasonNumber}",
                        'episode_count' => $season->episode_count ?? 0,
                        'overview' => $season->metadata['overview'] ?? '',
                        'air_date' => $season->metadata['air_date'] ?? '',
                        'cover' => $seasonCover,
                        'cover_tmdb' => $tmdbCover,
                        'season_number' => (int)$seasonNumber,
                        'cover_big' => $coverBig,
                        'releaseDate' => $season->metadata['release_date'] ?? $season->metadata['releaseDate'] ?? $season->metadata['air_date'] ?? '',
                        'duration' => (string)($season->metadata['duration'] ?? 0),
                    ];
                    $seasonEpisodes = [];
                    if ($season->episodes && $season->episodes->isNotEmpty()) {
                        $orderedEpisodes = $season->episodes->sortBy('episode_num');
                        foreach ($orderedEpisodes as $episode) {
                            $containerExtension = $episode->container_extension ?? 'mp4';
                            if ($episode->info['movie_image'] ?? false) {
                                $movieImage = $playlist->enable_logo_proxy
                                    ? LogoProxyController::generateProxyUrl($episode->info['movie_image'])
                                    : $episode->info['movie_image'];
                            }
                            if ($episode->info['cover_big'] ?? false) {
                                $movieImage = $playlist->enable_logo_proxy
                                    ? LogoProxyController::generateProxyUrl($episode->info['cover_big'])
                                    : $episode->info['cover_big'];
                            }

                            $seasonEpisodes[] = [
                                'id' => (string)$episode->id,
                                'episode_num' => $episode->episode_num,
                                'title' => $episode->title ?? "Episode {$episode->episode_num}",
                                'container_extension' => $containerExtension,
                                'info' => array_merge($episode->info, [
                                    'movie_image' => $movieImage ?? null,
                                    'cover_big' => $coverBig ?? null,
                                ]),
                                'added' => $episode->added,
                                'season' => $episode->season,
                                'custom_sid' => $episode->custom_sid ?? '',
                                'stream_id' => $episode->id,
                                'direct_source' => $baseUrl . "/series/{$urlSafeUser}/{$urlSafePass}/" . $episode->id . ".{$containerExtension}"
                            ];
                        }
                    }
                    if (!empty($seasonEpisodes)) {
                        $episodesBySeason[$seasonNumber] = $seasonEpisodes;
                    }
                }
            }

            return response()->json([
                'info' => $seriesInfo,
                'episodes' => $episodesBySeason,
                'seasons' => $seasons
            ]);
        } else if ($action === 'get_live_categories') {
            $liveCategories = [];

            if ($isCustomPlaylist) {
                // For CustomPlaylist, get unique tags (groups) from channels with live content
                $channelIds = $playlist->channels()
                    ->where('enabled', true)
                    ->where('is_vod', false)
                    ->pluck('id');

                // Get custom tags assigned to channels
                $tags = $playlist->groupTags()
                    ->whereIn('id', function ($query) use ($channelIds) {
                        $query->select('tag_id')
                            ->from('taggables')
                            ->where('taggable_type', Channel::class)
                            ->whereIn('taggable_id', $channelIds);
                    })->get();

                // Sort tags by order_column
                $sortedTags = $tags->sortBy('order_column')->values();

                foreach ($sortedTags as $tag) {
                    $liveCategories[] = [
                        'category_id' => (string)$tag->id, // Use tag ID instead of name
                        'category_name' => $tag->name,
                        'parent_id' => 0,
                        'sort_order' => $tag->order_column ?? 999999,
                    ];
                }

                // Also get original groups for channels without custom tags (fallback)
                $channelsWithTags = Channel::whereIn('id', $channelIds)
                    ->whereHas('tags', function ($query) use ($tagUuid) {
                        $query->where('type', $tagUuid);
                    })
                    ->pluck('id');

                $channelsWithoutTags = $channelIds->diff($channelsWithTags);

                if ($channelsWithoutTags->isNotEmpty()) {
                    $fallbackGroups = \App\Models\Group::whereIn('id', function ($query) use ($channelsWithoutTags) {
                        $query->select('group_id')
                            ->from('channels')
                            ->whereIn('id', $channelsWithoutTags)
                            ->whereNotNull('group_id');
                    })->orderBy('sort_order')->get();

                    foreach ($fallbackGroups as $group) {
                        // Avoid duplicate category_ids
                        $existingIds = array_column($liveCategories, 'category_id');
                        if (!in_array((string)$group->id, $existingIds)) {
                            $liveCategories[] = [
                                'category_id' => (string)$group->id,
                                'category_name' => $group->name,
                                'parent_id' => 0,
                                'sort_order' => $group->sort_order ?? 999999,
                            ];
                        }
                    }
                }

                // Sort all categories by sort_order to ensure proper ordering
                usort($liveCategories, function ($a, $b) {
                    return ($a['sort_order'] ?? 999999) <=> ($b['sort_order'] ?? 999999);
                });

                // Remove sort_order from output
                $liveCategories = array_map(function ($cat) {
                    unset($cat['sort_order']);
                    return $cat;
                }, $liveCategories);
            } else {
                // For regular Playlist and MergedPlaylist, use the groups() relationship
                $groups = $playlist->groups()
                    ->orderBy('sort_order')
                    ->whereHas('channels', function ($query) {
                        $query->where('enabled', true)
                            ->where('is_vod', false);
                    })->get();

                foreach ($groups as $group) {
                    $liveCategories[] = [
                        'category_id' => (string)$group->id,
                        'category_name' => $group->name,
                        'parent_id' => 0,
                    ];
                }
            }

            // Add a default "All" category if no specific groups exist
            if (empty($liveCategories)) {
                $liveCategories[] = [
                    'category_id' => 'all',
                    'category_name' => 'All',
                    'parent_id' => 0,
                ];
            }

            return response()->json($liveCategories);
        } else if ($action === 'get_vod_categories') {
            $vodCategories = [];

            if ($isCustomPlaylist) {
                // For CustomPlaylist, get unique tags (groups) from channels with VOD content
                $channelIds = $playlist->channels()
                    ->where('enabled', true)
                    ->where('is_vod', true)
                    ->pluck('id');

                // Get custom tags assigned to channels
                $tags = $playlist->groupTags()
                    ->whereIn('id', function ($query) use ($channelIds) {
                        $query->select('tag_id')
                            ->from('taggables')
                            ->where('taggable_type', Channel::class)
                            ->whereIn('taggable_id', $channelIds);
                    })->get();

                // Sort tags by order_column
                $sortedTags = $tags->sortBy('order_column')->values();

                foreach ($sortedTags as $tag) {
                    $vodCategories[] = [
                        'category_id' => (string)$tag->id, // Use tag ID instead of name
                        'category_name' => $tag->name,
                        'parent_id' => 0,
                        'sort_order' => $tag->order_column ?? 999999,
                    ];
                }

                // Also get original groups for channels without custom tags (fallback)
                $channelsWithTags = Channel::whereIn('id', $channelIds)
                    ->whereHas('tags', function ($query) use ($tagUuid) {
                        $query->where('type', $tagUuid);
                    })
                    ->pluck('id');

                $channelsWithoutTags = $channelIds->diff($channelsWithTags);

                if ($channelsWithoutTags->isNotEmpty()) {
                    $fallbackGroups = \App\Models\Group::whereIn('id', function ($query) use ($channelsWithoutTags) {
                        $query->select('group_id')
                            ->from('channels')
                            ->whereIn('id', $channelsWithoutTags)
                            ->whereNotNull('group_id');
                    })->orderBy('sort_order')->get();

                    foreach ($fallbackGroups as $group) {
                        // Avoid duplicate category_ids
                        $existingIds = array_column($vodCategories, 'category_id');
                        if (!in_array((string)$group->id, $existingIds)) {
                            $vodCategories[] = [
                                'category_id' => (string)$group->id,
                                'category_name' => $group->name,
                                'parent_id' => 0,
                                'sort_order' => $group->sort_order ?? 999999,
                            ];
                        }
                    }
                }

                // Sort all categories by sort_order to ensure proper ordering
                usort($vodCategories, function ($a, $b) {
                    return ($a['sort_order'] ?? 999999) <=> ($b['sort_order'] ?? 999999);
                });

                // Remove sort_order from output
                $vodCategories = array_map(function ($cat) {
                    unset($cat['sort_order']);
                    return $cat;
                }, $vodCategories);
            } else {
                // For regular Playlist and MergedPlaylist, use the groups() relationship
                $vodGroups = $playlist->groups()
                    ->orderBy('sort_order')
                    ->whereHas('channels', function ($query) {
                        $query->where('enabled', true)
                            ->where('is_vod', true);
                    })
                    ->get();

                foreach ($vodGroups as $group) {
                    $vodCategories[] = [
                        'category_id' => (string)$group->id,
                        'category_name' => $group->name,
                        'parent_id' => 0,
                    ];
                }
            }

            // Add a default "All" category if no specific categories exist
            if (empty($vodCategories)) {
                $vodCategories[] = [
                    'category_id' => 'all',
                    'category_name' => 'All',
                    'parent_id' => 0,
                ];
            }

            return response()->json($vodCategories);
        } else if ($action === 'get_series_categories') {
            $seriesCategories = [];

            if ($isCustomPlaylist) {
                // For CustomPlaylist, get unique tags (categories) from series
                $seriesIds = $playlist->series()
                    ->where('enabled', true)
                    ->pluck('id');

                // Get custom tags assigned to series
                $tags = $playlist->categoryTags()
                    ->whereIn('id', function ($query) use ($seriesIds) {
                        $query->select('tag_id')
                            ->from('taggables')
                            ->where('taggable_type', Series::class)
                            ->whereIn('taggable_id', $seriesIds);
                    })->get();

                // Sort tags by order_column
                $sortedTags = $tags->sortBy('order_column')->values();

                foreach ($sortedTags as $tag) {
                    $seriesCategories[] = [
                        'category_id' => (string)$tag->id, // Use tag ID instead of name
                        'category_name' => $tag->name,
                        'parent_id' => 0,
                        'sort_order' => $tag->order_column ?? 999999,
                    ];
                }

                // Also get original categories for series without custom tags (fallback)
                $seriesWithTags = Series::whereIn('id', $seriesIds)
                    ->whereHas('tags', function ($query) use ($tagUuid) {
                        $query->where('type', $tagUuid . '-category');
                    })
                    ->pluck('id');

                $seriesWithoutTags = $seriesIds->diff($seriesWithTags);

                if ($seriesWithoutTags->isNotEmpty()) {
                    $fallbackCategories = \App\Models\Category::whereIn('id', function ($query) use ($seriesWithoutTags) {
                        $query->select('category_id')
                            ->from('series')
                            ->whereIn('id', $seriesWithoutTags)
                            ->whereNotNull('category_id');
                    })->orderBy('sort_order')->get();

                    foreach ($fallbackCategories as $category) {
                        // Avoid duplicate category_ids
                        $existingIds = array_column($seriesCategories, 'category_id');
                        if (!in_array((string)$category->id, $existingIds)) {
                            $seriesCategories[] = [
                                'category_id' => (string)$category->id,
                                'category_name' => $category->name,
                                'parent_id' => 0,
                                'sort_order' => $category->sort_order ?? 999999,
                            ];
                        }
                    }
                }

                // Sort all categories by sort_order to ensure proper ordering
                usort($seriesCategories, function ($a, $b) {
                    return ($a['sort_order'] ?? 999999) <=> ($b['sort_order'] ?? 999999);
                });

                // Remove sort_order from output
                $seriesCategories = array_map(function ($cat) {
                    unset($cat['sort_order']);
                    return $cat;
                }, $seriesCategories);
            } else {
                // Get categories from series only
                $categories = $playlist->series()
                    ->where('enabled', true)
                    ->with('category')
                    ->get()
                    ->pluck('category')
                    ->filter()
                    ->unique('id');

                foreach ($categories as $category) {
                    $seriesCategories[] = [
                        'category_id' => (string)$category->id,
                        'category_name' => $category->name,
                        'parent_id' => 0,
                    ];
                }
            }

            // Add a default "All" category if no specific categories exist
            if (empty($seriesCategories)) {
                $seriesCategories[] = [
                    'category_id' => 'all',
                    'category_name' => 'All',
                    'parent_id' => 0,
                ];
            }

            return response()->json($seriesCategories);
        } else if ($action === 'get_vod_info') {
            $channelId = $request->input('vod_id');

            // Find the channel
            $channel = $playlist->channels()
                ->where('enabled', true)
                ->where('channels.id', $channelId)
                ->where('is_vod', true)
                ->first();

            if (!$channel) {
                return response()->json(['error' => 'VOD not found'], 404);
            }

            // Check if VOD metadata has been fetched
            if (!$channel->last_metadata_fetch) {
                // No metadata, fetch it!
                $results = $channel->fetchMetadata();
                if ($results === false) {
                    return response()->json(['error' => 'Failed to fetch VOD metadata'], 500);
                }
            }

            // Build info section - use channel's info field if available, otherwise build from channel data
            $info = $channel->info ?? [];

            $cover = $info['cover_big'] ?? $channel->logo ?? $channel->logo_internal;
            $movieImage = $info['movie_image'] ?? $channel->logo ?? $channel->logo_internal;
            $backdropPaths = $info['backdrop_path'] ?? [];
            if ($playlist->enable_logo_proxy) {
                $cover = LogoProxyController::generateProxyUrl($cover);
                $movieImage = LogoProxyController::generateProxyUrl($movieImage);
                $backdropPaths = array_map(fn($path) => LogoProxyController::generateProxyUrl($path), $backdropPaths);
            }

            // Fill in missing info fields with channel data
            $defaultInfo = [
                'kinopoisk_url' => $info['kinopoisk_url'] ?? '',
                'tmdb_id' => $info['tmdb_id'] ?? 0,
                'name' => $info['name'] ?? $channel->name,
                'o_name' => $info['o_name'] ?? $channel->name,
                'cover_big' => $cover,
                'movie_image' => $movieImage,
                'release_date' => $info['release_date'] ?? $channel->year,
                'episode_run_time' => $info['episode_run_time'] ?? 0,
                'youtube_trailer' => $info['youtube_trailer'] ?? null,
                'director' => $info['director'] ?? '',
                'actors' => $info['actors'] ?? '',
                'cast' => $info['cast'] ?? '',
                'description' => $info['description'] ?? '',
                'plot' => $info['plot'] ?? '',
                'age' => $info['age'] ?? '',
                'mpaa_rating' => $info['mpaa_rating'] ?? '',
                'rating_count_kinopoisk' => $info['rating_count_kinopoisk'] ?? 0,
                'country' => $info['country'] ?? '',
                'genre' => $info['genre'] ?? '',
                'backdrop_path' => $backdropPaths,
                'duration_secs' => $info['duration_secs'] ?? 0,
                'duration' => $info['duration'] ?? '00:00:00',
                'bitrate' => $info['bitrate'] ?? 0,
                'rating' => $channel->rating ?? $info['rating'],
                'releasedate' => $info['releasedate'] ?? $channel->year,
                'subtitles' => $info['subtitles'] ?? [],
            ];

            // Build movie_data section - use channel's movie_data field if available, otherwise build from channel data
            $movieData = $channel->movie_data ?? [];

            $extension = $movieData['container_extension'] ?? $channel->container_extension ?? 'mp4';
            $defaultMovieData = [
                'stream_id' => $channel->id,
                'name' => $movieData['name'] ?? $channel->name,
                'title' => $movieData['title'] ?? $channel->name,
                'year' => $movieData['year'] ?? $channel->year,
                'added' => $movieData['added'] ?? (string)($channel->created_at ? $channel->created_at->timestamp : time()),
                'category_id' => (string)($channel->group_id ?? ''),
                'category_ids' => ($channel->group_id ? [(int) $channel->group_id] : []),
                'container_extension' => $extension,
                'custom_sid' => $movieData['custom_sid'] ?? '',
                'direct_source' => $baseUrl . "/movie/{$urlSafeUser}/{$urlSafePass}/" . $channel->id . '.' . $extension,
            ];

            return response()->json([
                'info' => $defaultInfo,
                'movie_data' => $defaultMovieData,
            ]);
        } else if ($action === 'get_short_epg') {
            $streamId = $request->input('stream_id');
            $limit = $request->input('limit');
            $limit = (int) ($limit ?? 4);

            if (!$streamId) {
                return response()->json(['error' => 'stream_id parameter is required for get_short_epg action'], 400);
            }

            // Find the channel
            $channel = $playlist->channels()
                ->where('enabled', true)
                ->where('channels.id', $streamId)
                ->with('epgChannel')
                ->first();

            if (!$channel) {
                return response()->json(['error' => 'Channel not found'], 404);
            }

            if (!$channel->epgChannel) {
                return response()->json(['epg_listings' => []]);
            }

            // Get EPG data using EpgCacheService
            $cacheService = new EpgCacheService();
            $epg = Epg::find($channel->epgChannel->epg_id);

            if (!$epg || !$epg->is_cached) {
                return response()->json(['epg_listings' => []]);
            }

            // Get programmes for today and tomorrow to ensure we have enough data
            $today = Carbon::now()->format('Y-m-d');
            $tomorrow = Carbon::now()->addDay()->format('Y-m-d');

            $todayProgrammes = $cacheService->getCachedProgrammes($epg, $today, [$channel->epgChannel->channel_id]);
            $tomorrowProgrammes = $cacheService->getCachedProgrammes($epg, $tomorrow, [$channel->epgChannel->channel_id]);

            $allProgrammes = [];
            if (isset($todayProgrammes[$channel->epgChannel->channel_id])) {
                $allProgrammes = array_merge($allProgrammes, $todayProgrammes[$channel->epgChannel->channel_id]);
            }
            if (isset($tomorrowProgrammes[$channel->epgChannel->channel_id])) {
                $allProgrammes = array_merge($allProgrammes, $tomorrowProgrammes[$channel->epgChannel->channel_id]);
            }

            // Check if channel is currently playing
            $isNowPlaying = M3uProxyService::isChannelActive($channel);
            $isNowPlaying = false;

            // Filter programmes to current time and future, then limit
            $now = Carbon::now();
            $epgListings = [];
            $count = 0;

            foreach ($allProgrammes as $programme) {
                if ($count >= $limit) break;

                $startTime = Carbon::parse($programme['start']);
                $endTime = Carbon::parse($programme['stop']);

                // Include current programme and future programmes
                if ($endTime->gt($now)) {
                    $isCurrentProgramme = $startTime->lte($now) && $endTime->gt($now);

                    $epgListings[] = [
                        'id' => $programme['id'] ?? $count,
                        'epg_id' => (string) $epg->id,
                        'title' => $programme['title'] ?? '',
                        'lang' => $programme['lang'] ?? 'en',
                        'start' => $startTime->format('Y-m-d H:i:s'),
                        'end' => $endTime->format('Y-m-d H:i:s'),
                        'description' => $programme['desc'] ?? '',
                        'channel_id' => $channel->epgChannel->channel_id,
                        'start_timestamp' => (string) $startTime->timestamp,
                        'stop_timestamp' => (string) $endTime->timestamp,
                        'now_playing' => ($isCurrentProgramme && $isNowPlaying) ? 1 : 0,
                        'has_archive' => 0
                    ];
                    $count++;
                }
            }

            return response()->json(['epg_listings' => $epgListings]);
        } else if ($action === 'get_simple_data_table') {
            $streamId = $request->input('stream_id');

            if (!$streamId) {
                return response()->json(['error' => 'stream_id parameter is required for get_simple_data_table action'], 400);
            }

            // Find the channel
            $channel = $playlist->channels()
                ->where('enabled', true)
                ->where('channels.id', $streamId)
                ->with('epgChannel')
                ->first();

            if (!$channel) {
                return response()->json(['error' => 'Channel not found'], 404);
            }

            if (!$channel->epgChannel) {
                return response()->json(['epg_listings' => []]);
            }

            // Get EPG data using EpgCacheService
            $cacheService = new EpgCacheService();
            $epg = Epg::find($channel->epgChannel->epg_id);

            if (!$epg || !$epg->is_cached) {
                return response()->json(['epg_listings' => []]);
            }

            // Get programmes for today
            $today = Carbon::now()->format('Y-m-d');
            $programmes = $cacheService->getCachedProgrammes($epg, $today, [$channel->epgChannel->channel_id]);

            $epgListings = [];
            if (isset($programmes[$channel->epgChannel->channel_id])) {
                // Check if channel is currently playing
                $isNowPlaying = M3uProxyService::isChannelActive($channel);
                $isNowPlaying = false;

                $now = Carbon::now();
                foreach ($programmes[$channel->epgChannel->channel_id] as $index => $programme) {
                    $startTime = Carbon::parse($programme['start']);
                    $endTime = Carbon::parse($programme['stop']);
                    $isCurrentProgramme = $startTime->lte($now) && $endTime->gt($now);

                    $epgListings[] = [
                        'id' => $programme['id'] ?? $index,
                        'epg_id' => (string) $epg->id,
                        'title' => $programme['title'] ?? '',
                        'lang' => $programme['lang'] ?? 'en',
                        'start' => $startTime->format('Y-m-d H:i:s'),
                        'end' => $endTime->format('Y-m-d H:i:s'),
                        'description' => $programme['desc'] ?? '',
                        'channel_id' => $channel->epgChannel->channel_id,
                        'start_timestamp' => (string) $startTime->timestamp,
                        'stop_timestamp' => (string) $endTime->timestamp,
                        'now_playing' => ($isCurrentProgramme && $isNowPlaying) ? 1 : 0,
                        'has_archive' => 0
                    ];
                }
            }

            return response()->json(['epg_listings' => $epgListings]);
        } else if ($action === 'm3u_plus') {
            // For m3u_plus, redirect to the m3u method which handles the request
            return $this->m3u($playlist);
        } else {
            return response()->json(['error' => 'Invalid action parameter'], 400);
        }
    }

    /**
     * Redirects to the M3U playlist generation route.
     *
     * This method handles the M3U playlist request by calling the PlaylistGenerateController
     * with the appropriate playlist UUID.
     *
     * @param mixed $playlist The authenticated playlist instance.
     * @return Response
     */
    public function m3u($playlist)
    {
        return app()->call('App\\Http\\Controllers\\PlaylistGenerateController@__invoke', [
            'uuid' => $playlist->uuid,
        ]);
    }

    /**
     * Redirects to the EPG generation route.
     *
     * This method handles the EPG request by authenticating the user and redirecting
     * to the appropriate EPG generation URL based on the playlist UUID.
     *
     * @param Request $request
     * @return RedirectResponse
     */
    public function epg(Request $request)
    {
        // Authenticate the user based on the provided credentials
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);
        list($playlist, $authMethod, $username, $password) = $this->authenticate($request);

        // If no authentication method worked, return error
        if (!$playlist || $authMethod === 'none') {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // If here, user is authenticated
        return redirect()->to(route('epg.generate', ['uuid' => $playlist->uuid]));
    }

    /**
     * Authenticate the user based on the provided credentials.
     *
     * This method checks for PlaylistAuth credentials first, then falls back to
     * the original authentication method using username and password.
     *
     * @param Request $request
     * @return array|bool Returns an array with playlist and auth method, or false if authentication fails.
     */
    private function authenticate(Request $request)
    {
        $username = $request->input('username');
        $password = $request->input('password');

        return PlaylistFacade::authenticate($username, $password);
    }
}
