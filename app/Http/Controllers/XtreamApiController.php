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
    public function handle(Request $request, string $uuid)
    {
        $playlist = null;
        // $playlistModelType = null; // Not strictly needed here anymore

        try {
            $playlist = Playlist::with([
                'playlistAuth', 'user',
                'channels' => fn($q) => $q->where('enabled', true)->with(['group', 'epgChannel']),
                'series' => fn($q) => $q->where('enabled', true)->with(['seasons.episodes', 'category'])
            ])->findOrFail($uuid);
            // $playlistModelType = 'Playlist';
        } catch (ModelNotFoundException $e) {
            try {
                $playlist = MergedPlaylist::with([
                    'playlistAuth', 'user',
                    'channels' => fn($q) => $q->where('enabled', true)->with(['group', 'epgChannel'])
                ])->findOrFail($uuid);
                // $playlistModelType = 'MergedPlaylist';
                if (method_exists($playlist, 'series')) {
                    $playlist->load(['series' => fn($q) => $q->where('enabled', true)->with(['seasons.episodes', 'category'])]);
                }
            } catch (ModelNotFoundException $e) {
                try {
                    $playlist = CustomPlaylist::with([
                        'playlistAuth', 'user',
                        'channels' => fn($q) => $q->where('enabled', true)->with(['group', 'epgChannel'])
                    ])->findOrFail($uuid);
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

        if ($playlist->playlistAuth && $playlist->playlistAuth->is_enabled) {
            if ($playlist->playlistAuth->username === $username && $playlist->playlistAuth->password === $password) {
                $authenticated = true;
            }
        }

        if (!$authenticated && $username === 'm3ue') {
            if ($playlist->user && Hash::check($password, $playlist->user->password)) {
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

            $responseCategories = [];
            $categoryMap = []; // Used to ensure categories for channels/series exist in the list

            $enabledChannels = $playlist->channels ?? new Collection();
            if ($enabledChannels->isNotEmpty()) {
                $liveGroups = $enabledChannels->pluck('group')->filter()->unique('id');
                foreach ($liveGroups as $group) {
                    if ($group && !isset($categoryMap[$group->id])) {
                        $responseCategories[] = [
                            'category_id' => (string)$group->id,
                            'category_name' => $group->name,
                            'parent_id' => 0,
                        ];
                        $categoryMap[(string)$group->id] = true;
                    }
                }
            }

            $enabledSeriesCollection = $playlist->series ?? new Collection();
            if ($enabledSeriesCollection->isNotEmpty()) {
                 $vodCategories = $enabledSeriesCollection->pluck('category')->filter()->unique('id');
                 foreach ($vodCategories as $category) {
                     if ($category && !isset($categoryMap[$category->id])) {
                         $responseCategories[] = [
                             'category_id' => (string)$category->id,
                             'category_name' => $category->name,
                             'parent_id' => 0,
                         ];
                         $categoryMap[(string)$category->id] = true;
                     }
                 }
            }

            if (empty($responseCategories)) {
                $responseCategories[] = [
                    'category_id' => 'all',
                    'category_name' => 'All',
                    'parent_id' => 0,
                ];
                if (!isset($categoryMap['all'])) $categoryMap['all'] = true;
            }

            $liveStreams = [];
            foreach ($enabledChannels as $index => $channel) {
                $streamIcon = URL::asset('/placeholder.png');
                if ($channel->logo_type === ChannelLogoType::Epg && $channel->epgChannel && $channel->epgChannel->icon) {
                    $streamIcon = $channel->epgChannel->icon;
                } elseif ($channel->logo_type === ChannelLogoType::Channel && $channel->logo) {
                    $streamIcon = $channel->logo;
                }

                $channelCategoryId = 'all';
                if ($channel->group_id) {
                    $channelCategoryId = (string)$channel->group_id;
                } elseif ($channel->group && $channel->group->id) {
                    $channelCategoryId = (string)$channel->group->id;
                }
                if (!isset($categoryMap[$channelCategoryId])) {
                    $channelCategoryId = 'all';
                }

                $liveStreams[] = [
                    'num' => $index + 1,
                    'name' => $channel->title_custom ?? $channel->title,
                    'stream_type' => 'live',
                    'stream_id' => (int)$channel->id,
                    'stream_icon' => $streamIcon,
                    'epg_channel_id' => $channel->epgChannel->epg_channel_id ?? $channel->stream_id_custom ?? $channel->stream_id ?? (string)$channel->id,
                    'added' => (string)$channel->created_at->timestamp,
                    'category_id' => $channelCategoryId,
                    'tv_archive' => 0,
                    'direct_source' => '',
                    'tv_archive_duration' => 0,
                ];
            }

            $vodSeries = [];
            foreach ($enabledSeriesCollection as $index => $seriesItem) {
                $seriesCategoryId = 'all';
                if ($seriesItem->category_id) {
                    $seriesCategoryId = (string)$seriesItem->category_id;
                } elseif ($seriesItem->category && $seriesItem->category->id) {
                    $seriesCategoryId = (string)$seriesItem->category->id;
                }
                 if (!isset($categoryMap[$seriesCategoryId])) {
                    $seriesCategoryId = 'all';
                }

                $vodSeries[] = [
                    'num' => $index + 1,
                    'name' => $seriesItem->name,
                    'series_id' => (int)$seriesItem->id,
                    // Basic fields as per simplified requirement for this subtask
                    'cover' => $seriesItem->cover_image ?? URL::asset('/placeholder.png'), // Add cover with placeholder
                    'plot' => $seriesItem->plot_summary ?? '', // Add plot
                    'cast' => $seriesItem->cast ?? '', // Add cast
                    'director' => $seriesItem->director ?? '', // Add director
                    'genre' => $seriesItem->genre ?? '', // Add genre
                    'releaseDate' => $seriesItem->release_date ? Carbon::parse($seriesItem->release_date)->format('Y-m-d') : '', // Format date
                    'last_modified' => (string)($seriesItem->updated_at ? $seriesItem->updated_at->timestamp : $now->timestamp),
                    'rating' => (string)($seriesItem->rating ?? 0), // Default rating
                    'rating_5based' => round(($seriesItem->rating ?? 0) / 2, 1), // Convert 10-based to 5-based
                    'backdrop_path' => [], // Empty for now
                    'youtube_trailer' => $seriesItem->youtube_trailer ?? '',
                    'episode_run_time' => (string)($seriesItem->episode_run_time ?? 0),
                    'category_id' => $seriesCategoryId,
                ];
            }

            $scheme = $request->getScheme();
            $host = $request->getHost();
            $currentPort = $request->getPort();
            $baseUrl = $scheme . '://' . $host;
            $httpsPort = ($scheme === 'https') ? (string)$currentPort : "443";

            $serverInfo = [
                'url' => $baseUrl,
                'port' => (string)$currentPort,
                'https_port' => $httpsPort,
                'rtmp_port' => "1935",
                'server_protocol' => $scheme,
                'timezone' => Config::get('app.timezone', 'UTC'),
                'server_software' => 'MediaFlow Xtream API',
                'timestamp_now' => (string)$now->timestamp,
                'time_now' => $now->toDateTimeString(),
            ];

            return response()->json([
                'user_info' => $userInfo,
                'server_info' => $serverInfo,
                'available_channels' => $liveStreams,
                'series' => $vodSeries, // Populate this
                'categories' => $responseCategories,
            ]);
        } else if ($action === 'get_live_streams') {
            $enabledChannels = $playlist->channels ?? new Collection();
            $liveStreams = [];
            foreach ($enabledChannels as $index => $channel) {
                $streamIcon = URL::asset('/placeholder.png');
                if ($channel->logo_type === ChannelLogoType::Epg && $channel->epgChannel && $channel->epgChannel->icon) {
                    $streamIcon = $channel->epgChannel->icon;
                } elseif ($channel->logo_type === ChannelLogoType::Channel && $channel->logo) {
                    $streamIcon = $channel->logo;
                }

                $channelCategoryId = 'all';
                if ($channel->group_id) {
                    $channelCategoryId = (string)$channel->group_id;
                } elseif ($channel->group && $channel->group->id) {
                    $channelCategoryId = (string)$channel->group->id;
                }
                // It's better to ensure category_id exists in a predefined list of categories if strict adherence is needed.
                // For now, defaulting to 'all' if not found or being more robust based on actual category data available.

                $liveStreams[] = [
                    'num' => $index + 1,
                    'name' => $channel->title_custom ?? $channel->title,
                    'stream_type' => 'live',
                    'stream_id' => (int)$channel->id,
                    'stream_icon' => $streamIcon,
                    'epg_channel_id' => $channel->epgChannel->epg_channel_id ?? $channel->stream_id_custom ?? $channel->stream_id ?? (string)$channel->id,
                    'added' => (string)$channel->created_at->timestamp,
                    'category_id' => $channelCategoryId, // Ensure this category_id is valid based on your categories logic
                    'tv_archive' => $channel->tv_archive_enabled ?? 0, // Assuming a model attribute or default
                    'direct_source' => $channel->direct_source_url ?? '', // Assuming a model attribute or default
                    'tv_archive_duration' => $channel->tv_archive_duration ?? 0, // Assuming a model attribute or default
                ];
            }
            return response()->json($liveStreams);
        } else if ($action === 'get_vod_streams') {
            $enabledSeriesCollection = $playlist->series ?? new Collection();
            $vodSeries = [];
            $now = Carbon::now(); // Ensure $now is available

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
                    'cover' => $seriesItem->cover_image ?? URL::asset('/placeholder.png'),
                    'plot' => $seriesItem->plot_summary ?? '',
                    'cast' => $seriesItem->cast ?? '',
                    'director' => $seriesItem->director ?? '',
                    'genre' => $seriesItem->genre ?? '',
                    'releaseDate' => $seriesItem->release_date ? Carbon::parse($seriesItem->release_date)->format('Y-m-d') : '',
                    'last_modified' => (string)($seriesItem->updated_at ? $seriesItem->updated_at->timestamp : $now->timestamp),
                    'rating' => (string)($seriesItem->rating ?? 0),
                    'rating_5based' => round(($seriesItem->rating ?? 0) / 2, 1),
                    'backdrop_path' => $seriesItem->backdrop_path ?? [], // Assuming backdrop_path is an array or can be defaulted to []
                    'youtube_trailer' => $seriesItem->youtube_trailer ?? '',
                    'episode_run_time' => (string)($seriesItem->episode_run_time ?? 0),
                    'category_id' => $seriesCategoryId,
                ];
            }
            return response()->json($vodSeries);
        } else if ($action === 'get_vod_info') {
            $vodId = $request->input('vod_id');
            if (!$vodId) {
                return response()->json(['error' => 'Missing vod_id parameter'], 400);
            }

            $seriesItem = null;
            if ($playlist->series && $playlist->series->isNotEmpty()) {
                $seriesItem = $playlist->series->firstWhere('id', $vodId);
            }

            if (!$seriesItem) {
                return response()->json(['error' => 'VOD not found or not enabled'], 404);
            }

            $now = Carbon::now();
            $seriesInfo = [
                'name' => $seriesItem->name,
                'cover' => $seriesItem->cover_image ?? URL::asset('/placeholder.png'),
                'plot' => $seriesItem->plot_summary ?? '',
                'cast' => $seriesItem->cast ?? '',
                'director' => $seriesItem->director ?? '',
                'genre' => $seriesItem->genre ?? '',
                'releaseDate' => $seriesItem->release_date ? Carbon::parse($seriesItem->release_date)->format('Y-m-d') : '',
                'last_modified' => (string)($seriesItem->updated_at ? $seriesItem->updated_at->timestamp : $now->timestamp),
                'rating' => (string)($seriesItem->rating ?? 0),
                'rating_5based' => round(($seriesItem->rating ?? 0) / 2, 1),
                'backdrop_path' => $seriesItem->backdrop_path ?? [],
                'youtube_trailer' => $seriesItem->youtube_trailer ?? '',
                'episode_run_time' => (string)($seriesItem->episode_run_time ?? '0'), // Ensure string for consistency
                'category_id' => (string)($seriesItem->category_id ?? ($seriesItem->category ? $seriesItem->category->id : 'all')),
            ];

            $episodesBySeason = [];
            if ($seriesItem->seasons && $seriesItem->seasons->isNotEmpty()) {
                foreach ($seriesItem->seasons as $season) {
                    $seasonNumber = $season->season_number;
                    $seasonEpisodes = [];
                    if ($season->episodes && $season->episodes->isNotEmpty()) {
                        foreach ($season->episodes as $episode) {
                            // Construct stream URL - ensure username/password are available from the main handle method scope
                            $containerExtension = $episode->container_extension ?? 'mp4'; // Default or from model
                            $streamUrlPath = "/series/{$uuid}/{$username}/{$password}/{$seriesItem->id}-{$episode->id}.{$containerExtension}";

                            $seasonEpisodes[] = [
                                'id' => (string)$episode->id,
                                'episode_num' => $episode->episode_number,
                                'title' => $episode->title ?? "Episode {$episode->episode_number}",
                                'container_extension' => $containerExtension,
                                'info' => [ // Basic info, can be expanded
                                    'movie_image' => $episode->thumbnail_url ?? $seriesItem->cover_image ?? URL::asset('/placeholder.png'),
                                    'plot' => $episode->plot_summary ?? '',
                                    'duration_secs' => $episode->duration_seconds ?? 0,
                                    'duration' => gmdate("H:i:s", $episode->duration_seconds ?? 0),
                                    'video' => [], // Placeholder for video details if any
                                    'audio' => [], // Placeholder for audio details if any
                                    'bitrate' => $episode->bitrate ?? 0,
                                    'rating' => (string)($episode->rating ?? 0),
                                ],
                                'added' => (string)($episode->created_at ? $episode->created_at->timestamp : $now->timestamp),
                                'season' => $seasonNumber,
                                // The key for the stream URL is usually the series_id for older API, but episode ID is more specific
                                // Xtream Codes typically uses the episode ID as the stream ID in this context.
                                'stream_id' => (int)$episode->id, // Or series_id if that's the convention followed
                                'direct_source' => url($streamUrlPath) // Generate full URL
                            ];
                        }
                    }
                    if (!empty($seasonEpisodes)) {
                        $episodesBySeason[$seasonNumber] = $seasonEpisodes;
                    }
                }
            }

            // If no seasons/episodes, or to handle series that are like single movies (no seasons)
            // This part might need adjustment based on how single VOD items (non-series) are structured
            if (empty($episodesBySeason) && $seriesItem->is_movie) { // Assuming an is_movie flag or similar logic
                 $containerExtension = $seriesItem->container_extension ?? 'mp4';
                 $streamUrlPath = "/series/{$uuid}/{$username}/{$password}/{$seriesItem->id}.{$containerExtension}";
                 $episodesBySeason[1] = [ // Default to season 1 for movies
                    [
                        'id' => (string)$seriesItem->id, // Use series ID as episode ID for movie
                        'episode_num' => 1,
                        'title' => $seriesItem->name,
                        'container_extension' => $containerExtension,
                        'info' => [
                            'movie_image' => $seriesItem->cover_image ?? URL::asset('/placeholder.png'),
                            'plot' => $seriesItem->plot_summary ?? '',
                            'duration_secs' => $seriesItem->duration_seconds ?? 0, // Assuming duration on series for movies
                            'duration' => gmdate("H:i:s", $seriesItem->duration_seconds ?? 0),
                            'video' => [],
                            'audio' => [],
                            'bitrate' => $seriesItem->bitrate ?? 0, // Assuming bitrate on series for movies
                            'rating' => (string)($seriesItem->rating ?? 0),
                        ],
                        'added' => (string)($seriesItem->created_at ? $seriesItem->created_at->timestamp : $now->timestamp),
                        'season' => 1,
                        'stream_id' => (int)$seriesItem->id,
                        'direct_source' => url($streamUrlPath)
                    ]
                 ];
            }


            return response()->json([
                'info' => $seriesInfo,
                'episodes' => $episodesBySeason,
                // The 'movie_data' structure is often used for VOD info in Xtream Codes for player compatibility.
                // Replicating a common structure:
                'movie_data' => [
                    'stream_id' => (int)$seriesItem->id,
                    'name' => $seriesItem->name,
                    'title' => $seriesItem->name, // Often duplicated
                    'year' => $seriesItem->release_date ? Carbon::parse($seriesItem->release_date)->format('Y') : '',
                    'episode_run_time' => (string)($seriesItem->episode_run_time ?? '0'),
                    'plot' => $seriesItem->plot_summary ?? '',
                    'cast' => $seriesItem->cast ?? '',
                    'director' => $seriesItem->director ?? '',
                    'genre' => $seriesItem->genre ?? '',
                    'releaseDate' => $seriesItem->release_date ? Carbon::parse($seriesItem->release_date)->format('Y-m-d') : '',
                    'last_modified' => (string)($seriesItem->updated_at ? $seriesItem->updated_at->timestamp : $now->timestamp),
                    'rating' => (string)($seriesItem->rating ?? 0),
                    'rating_5based' => round(($seriesItem->rating ?? 0) / 2, 1),
                    'cover_big' => $seriesItem->cover_image ?? URL::asset('/placeholder.png'), // Often 'cover_big' is used
                    'youtube_trailer' => $seriesItem->youtube_trailer ?? '',
                    'backdrop_path' => $seriesItem->backdrop_path ?? [],
                    // For movies without explicit episodes, direct source might be here
                    // This example assumes episodes are always structured, even for movies (as season 1, episode 1)
                ]
            ]);

        } else {
            return response()->json(['error' => "Action '{$action}' not implemented"]);
        }
    }
}
