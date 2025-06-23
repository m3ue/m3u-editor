<?php

namespace App\Http\Controllers;

use App\Enums\ChannelLogoType;
use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

class XtreamApiController extends Controller
{
    /**
     * Xtream API request handler.
     * 
     * This endpoint serves as the primary interface for Xtream API interactions.
     * It requires authentication via username and password query parameters.
     * The `action` query parameter dictates the specific operation to perform and the structure of the response.
     * 
     * The `username` and `password` parameters are mandatory for all actions, and will default to your m3u editor login credentials (default is admin/admin).
     * 
     * If the Playlist has a Playlist Auth assigned, it will check that first for authentication, and then fall back to the User's credentials.
     * 
     * ## Supported Actions:
     * 
     * ### panel (default)
     * Returns user authentication info and server details.
     * 
     * ### get_live_streams
     * Returns a JSON array of live stream objects. Only enabled, non-VOD channels are included.
     * Supports optional category filtering via `category_id` parameter.
     * Each stream object contains: `num`, `name`, `stream_type`, `stream_id`, `stream_icon`, `epg_channel_id`, 
     * `added`, `category_id`, `tv_archive`, `direct_source`, `tv_archive_duration`.
     * 
     * ### get_vod_streams
     * Returns a JSON array of VOD stream objects (series and VOD channels). Only enabled content is included.
     * Supports optional category filtering via `category_id` parameter.
     * Each object contains: `num`, `name`, `series_id`, `cover`, `plot`, `cast`, `director`, `genre`, `releaseDate`, 
     * `last_modified`, `rating`, `rating_5based`, `backdrop_path`, `youtube_trailer`, `episode_run_time`, `category_id`.
     * 
     * ### get_live_categories
     * Returns a JSON array of live stream categories/groups. Only groups with enabled, non-VOD channels are included.
     * Each category contains: `category_id`, `category_name`, `parent_id`.
     * 
     * ### get_vod_categories
     * Returns a JSON array of VOD categories. Includes categories from series and groups with VOD channels.
     * Each category contains: `category_id`, `category_name`, `parent_id`.
     *
     * @param string $uuid The UUID of the playlist (required path parameter)
     * @param \Illuminate\Http\Request $request The HTTP request containing query parameters:
     *   - username (string, required): User's Xtream API username
     *   - password (string, required): User's Xtream API password  
     *   - action (string, optional): Defaults to 'panel'. Determines the API action
     *   - category_id (string, optional): Filter results by category ID (only valid for get_live_streams and get_vod_streams actions)
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
     *     "category_name": "Drama Series",
     *     "parent_id": 0
     *   },
     *   {
     *     "category_id": "2",
     *     "category_name": "Action Movies",
     *     "parent_id": 0
     *   }
     * ]
     *
     * @response 400 scenario="Bad Request" {"error": "Invalid action"}
     * @response 401 scenario="Unauthorized - Missing Credentials" {"error": "Unauthorized - Missing credentials"}
     * @response 401 scenario="Unauthorized - Invalid Credentials" {"error": "Unauthorized"}
     * @response 404 scenario="Not Found (e.g., playlist not found)" {"error": "Playlist not found"}
     * 
     * @unauthenticated
     */
    public function handle(Request $request, string $uuid)
    {
        $playlist = null;
        // $playlistModelType = null; // Not strictly needed here anymore

        try {
            $playlist = Playlist::with([
                'playlistAuths',
                'user',
                'channels' => fn($q) => $q->where('enabled', true)->with(['group', 'epgChannel']),
                'series' => fn($q) => $q->where('enabled', true)->with(['seasons.episodes', 'category'])
            ])->where('uuid', $uuid)->firstOrFail();
            // $playlistModelType = 'Playlist';
        } catch (ModelNotFoundException $e) {
            try {
                $playlist = MergedPlaylist::with([
                    'playlistAuths',
                    'user',
                    'channels' => fn($q) => $q->where('enabled', true)->with(['group', 'epgChannel'])
                ])->where('uuid', $uuid)->firstOrFail();
                // $playlistModelType = 'MergedPlaylist';
                if (method_exists($playlist, 'series')) {
                    $playlist->load(['series' => fn($q) => $q->where('enabled', true)->with(['seasons.episodes', 'category'])]);
                }
            } catch (ModelNotFoundException $e) {
                try {
                    $playlist = CustomPlaylist::with([
                        'playlistAuths',
                        'user',
                        'channels' => fn($q) => $q->where('enabled', true)->with(['group', 'epgChannel'])
                    ])->where('uuid', $uuid)->firstOrFail();
                    // $playlistModelType = 'CustomPlaylist';
                    if (method_exists($playlist, 'series')) {
                        $playlist->load(['series' => fn($q) => $q->where('enabled', true)->with(['seasons.episodes', 'category'])]);
                    }
                } catch (ModelNotFoundException $e) {
                    return response()->json(['error' => 'Playlist not found'], 404);
                }
            }
        }

        $username = $request->input('username');
        $password = $request->input('password');
        $authenticated = false;

        if (empty($username) || empty($password)) {
            return response()->json(['error' => 'Unauthorized - Missing credentials'], 401);
        }

        // Check for PlaylistAuth authentication
        $enabledAuth = $playlist->playlistAuths->where('enabled', true)->first();
        if ($enabledAuth && $enabledAuth->username === $username && $enabledAuth->password === $password) {
            $authenticated = true;
        }

        if (!$authenticated) {
            if ($playlist->user->name === $username && $playlist->user && Hash::check($password, $playlist->user->password)) {
                $authenticated = true;
            }
        }

        if (!$authenticated) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $action = $request->input('action', 'panel');

        if ($action === 'panel' || empty($request->input('action'))) {
            $now = Carbon::now();
            $userInfo = [
                'username' => $username,
                'password' => $password,
                'message' => '',
                'auth' => 1,
                'status' => 'Active',
                'exp_date' => (string)$now->copy()->addYears(10)->timestamp,
                'is_trial' => '0',
                'active_cons' => 1,
                'created_at' => (string)($playlist->user ? $playlist->user->created_at->timestamp : $now->timestamp),
                'max_connections' => (string)($playlist->streams ?? 1),
                'allowed_output_formats' => ['m3u8', 'ts'],
            ];

            $scheme = $request->getScheme();
            $host = $request->getHost();
            $currentPort = $request->getPort();
            $baseUrl = $scheme . '://' . $host;
            $httpsPort = ($scheme === 'https') ? (string)$currentPort : "443";

            $serverInfo = [
                'url' => $baseUrl,
                'port' => (string)$currentPort,
                'https_port' => $httpsPort,
                'rtmp_port' => null, // RTMP not available currently
                'server_protocol' => $scheme,
                'timezone' => Config::get('app.timezone', 'UTC'),
                'server_software' => config('app.name') . ' Xtream API',
                'timestamp_now' => (string)$now->timestamp,
                'time_now' => $now->toDateTimeString(),
            ];

            return response()->json([
                'user_info' => $userInfo,
                'server_info' => $serverInfo
            ]);
        }
        else if ($action === 'get_live_streams') {
            $categoryId = $request->input('category_id');
            
            $channelsQuery = $playlist->channels()
                ->where('enabled', true)
                ->where('is_vod', false);
            
            // Apply category filtering if category_id is provided
            if ($categoryId && $categoryId !== 'all') {
                $channelsQuery->where('group_id', $categoryId);
            }
            
            $enabledChannels = $channelsQuery->get();
            $liveStreams = [];
            if ($enabledChannels instanceof \Illuminate\Database\Eloquent\Collection) {
                foreach ($enabledChannels as $index => $channel) {
                    $streamIcon = url('/placeholder.png');
                    if ($channel->logo_type === ChannelLogoType::Epg && $channel->epgChannel && $channel->epgChannel->icon) {
                        $streamIcon = $channel->epgChannel->icon;
                    } elseif ($channel->logo_type === ChannelLogoType::Channel && $channel->logo) {
                        $streamIcon = filter_var($channel->logo, FILTER_VALIDATE_URL) ? $channel->logo : url($channel->logo);
                    }

                    $channelCategoryId = 'all';
                    if ($channel->group_id) {
                        $channelCategoryId = (string)$channel->group_id;
                    } elseif ($channel->group && $channel->group->id) {
                        $channelCategoryId = (string)$channel->group->id;
                    }
                    // It's better to ensure category_id exists in a predefined list of categories if strict adherence is needed.
                    // For now, defaulting to 'all' if not found or being more robust based on actual category data available.

                    $streamId = rtrim(base64_encode($channel->id), '=');
                    $liveStreams[] = [
                        'num' => $channel->channel ?? null,
                        'name' => $channel->title_custom ?? $channel->title,
                        'stream_type' => 'live',
                        'stream_id' => $streamId,
                        'stream_icon' => $streamIcon,
                        'epg_channel_id' => $channel->epgChannel->epg_channel_id ?? $channel->stream_id_custom ?? $channel->stream_id ?? (string)$channel->id,
                        'added' => (string)$channel->created_at->timestamp,
                        'category_id' => $channelCategoryId, // Ensure this category_id is valid based on your categories logic
                        'tv_archive' => !empty($channel->catchup) ? 1 : 0, // Based on catchup field availability
                        'direct_source' => url("xtream/{$uuid}/live/{$username}/{$password}/" . $streamId . ".ts"),
                        'tv_archive_duration' => !empty($channel->catchup) ? 24 : 0, // Default 24 hours if catchup available
                    ];
                }
            }
            return response()->json($liveStreams);
        }
        else if ($action === 'get_vod_streams') {
            $categoryId = $request->input('category_id');
            
            // Get series with optional category filtering
            $seriesQuery = $playlist->series()->where('enabled', true);
            if ($categoryId && $categoryId !== 'all') {
                $seriesQuery->where('category_id', $categoryId);
            }
            $enabledSeriesCollection = $seriesQuery->get();
            
            // Get VOD channels with optional category filtering
            $vodChannelsQuery = $playlist->channels()
                ->where('enabled', true)
                ->where('is_vod', true);
            if ($categoryId && $categoryId !== 'all') {
                $vodChannelsQuery->where('group_id', $categoryId);
            }
            $enabledVodChannels = $vodChannelsQuery->get();
            
            $vodSeries = [];
            $now = Carbon::now(); // Ensure $now is available

            // First, add series/movies from the series table
            if ($enabledSeriesCollection instanceof \Illuminate\Database\Eloquent\Collection) {
                foreach ($enabledSeriesCollection as $index => $seriesItem) {
                    $seriesCategoryId = 'all'; // Default category_id
                    if ($seriesItem->category_id) {
                        $seriesCategoryId = (string)$seriesItem->category_id;
                    } elseif ($seriesItem->category && $seriesItem->category->id) {
                        $seriesCategoryId = (string)$seriesItem->category->id;
                    }
                    // Consider validating $seriesCategoryId against available categories if strictness is required.

                    $vodSeries[] = [
                        'num' => $index + 1,
                        'name' => $seriesItem->name,
                        'series_id' => (int)$seriesItem->id,
                        'cover' => $seriesItem->cover ? (filter_var($seriesItem->cover, FILTER_VALIDATE_URL) ? $seriesItem->cover : url($seriesItem->cover)) : url('/placeholder.png'),
                        'plot' => $seriesItem->plot ?? '',
                        'cast' => $seriesItem->cast ?? '',
                        'director' => $seriesItem->director ?? '',
                        'genre' => $seriesItem->genre ?? '',
                        'releaseDate' => $seriesItem->release_date ? Carbon::parse($seriesItem->release_date)->format('Y-m-d') : '',
                        'last_modified' => (string)($seriesItem->updated_at ? $seriesItem->updated_at->timestamp : $now->timestamp),
                        'rating' => (string)($seriesItem->rating ?? 0),
                        'rating_5based' => round((floatval($seriesItem->rating ?? 0)) / 2, 1),
                        'backdrop_path' => $seriesItem->backdrop_path ?? [], // Assuming backdrop_path is an array or can be defaulted to []
                        'youtube_trailer' => $seriesItem->youtube_trailer ?? '',
                        'episode_run_time' => (string)($seriesItem->episode_run_time ?? 0),
                        'category_id' => $seriesCategoryId,
                    ];
                }
            }

            // Then, add VOD channels (movies/content marked as VOD in channels table)
            if ($enabledVodChannels instanceof \Illuminate\Database\Eloquent\Collection) {
                $seriesCount = count($vodSeries);
                foreach ($enabledVodChannels as $index => $vodChannel) {
                    $channelCategoryId = 'all'; // Default category_id
                    if ($vodChannel->group_id) {
                        $channelCategoryId = (string)$vodChannel->group_id;
                    } elseif ($vodChannel->group && $vodChannel->group->id) {
                        $channelCategoryId = (string)$vodChannel->group->id;
                    }

                    $streamIcon = url('/placeholder.png');
                    if ($vodChannel->logo_type === ChannelLogoType::Epg && $vodChannel->epgChannel && $vodChannel->epgChannel->icon) {
                        $streamIcon = $vodChannel->epgChannel->icon;
                    } elseif ($vodChannel->logo_type === ChannelLogoType::Channel && $vodChannel->logo) {
                        $streamIcon = filter_var($vodChannel->logo, FILTER_VALIDATE_URL) ? $vodChannel->logo : url($vodChannel->logo);
                    }

                    $vodSeries[] = [
                        'num' => $seriesCount + $index + 1,
                        'name' => $vodChannel->title_custom ?? $vodChannel->title,
                        'series_id' => (int)$vodChannel->id,
                        'cover' => $streamIcon,
                        'plot' => '', // VOD channels don't typically have plot info
                        'cast' => '',
                        'director' => '',
                        'genre' => $vodChannel->group ?? '',
                        'releaseDate' => $vodChannel->created_at ? $vodChannel->created_at->format('Y-m-d') : '',
                        'last_modified' => (string)($vodChannel->updated_at ? $vodChannel->updated_at->timestamp : $now->timestamp),
                        'rating' => '0',
                        'rating_5based' => 0.0,
                        'backdrop_path' => [],
                        'youtube_trailer' => '',
                        'episode_run_time' => '0',
                        'category_id' => $channelCategoryId,
                    ];
                }
            }
            return response()->json($vodSeries);
        }
        else if ($action === 'get_live_categories') {
            $liveCategories = [];
            
            // Get all groups that have live channels (non-VOD channels)
            $groups = $playlist->groups()
                ->whereHas('channels', function($query) {
                    $query->where('enabled', true)
                          ->where('is_vod', false);
                })
                ->get();

            foreach ($groups as $group) {
                $liveCategories[] = [
                    'category_id' => (string)$group->id,
                    'category_name' => $group->name,
                    'parent_id' => 0, // Flat structure for now
                ];
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
        }
        else if ($action === 'get_vod_categories') {
            $vodCategories = [];
            
            // Get categories from series
            $seriesCategories = $playlist->series()
                ->where('enabled', true)
                ->with('category')
                ->get()
                ->pluck('category')
                ->filter()
                ->unique('id');

            foreach ($seriesCategories as $category) {
                $vodCategories[] = [
                    'category_id' => (string)$category->id,
                    'category_name' => $category->name,
                    'parent_id' => 0,
                ];
            }

            // Get groups from VOD channels
            $vodGroups = $playlist->groups()
                ->whereHas('channels', function($query) {
                    $query->where('enabled', true)
                          ->where('is_vod', true);
                })
                ->get();

            foreach ($vodGroups as $group) {
                // Check if this group is not already added from series categories
                $exists = collect($vodCategories)->contains(function($cat) use ($group) {
                    return $cat['category_id'] === (string)$group->id;
                });

                if (!$exists) {
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
        } else {
            return response()->json(['error' => "Action '{$action}' not implemented"]);
        }
    }
}
