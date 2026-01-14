<?php

namespace App\Http\Controllers;

use App\Facades\PlaylistFacade;
use App\Facades\ProxyFacade;
use App\Models\Channel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @tags Channels
 */
class ChannelController extends Controller
{
    /**
     * List channels
     *
     * Retrieve a paginated list of channels for the authenticated user.
     * Supports filtering by various criteria and sorting.
     *
     *
     * @queryParam limit integer Maximum number of channels to return (1-1000). Defaults to 50. Example: 100
     * @queryParam offset integer Number of channels to skip for pagination. Defaults to 0. Example: 0
     * @queryParam enabled boolean Filter channels by enabled status. When set to true, only enabled channels are returned. When set to false, only disabled channels are returned. When omitted, all channels are returned. Example: true
     * @queryParam playlist_uuid string Filter by playlist UUID. Only returns channels from this playlist. Example: abc-123-def
     * @queryParam group_id integer Filter by group ID. Only returns channels from this group. Example: 5
     * @queryParam is_vod boolean Filter by VOD status. true = only VOD/movies, false = only live channels. Example: false
     * @queryParam search string Search in title, name, and stream_id fields. Example: ESPN
     * @queryParam sort string Field to sort by. Allowed: id, title, name, channel, created_at. Defaults to id. Example: title
     * @queryParam order string Sort order. Allowed: asc, desc. Defaults to asc. Example: asc
     *
     * @response 200 {
     *   "success": true,
     *   "data": [
     *     {
     *       "id": 1,
     *       "title": "ESPN",
     *       "name": "ESPN HD",
     *       "logo": "https://example.com/logo.png",
     *       "url": "https://example.com/stream.m3u8",
     *       "stream_id": "12345",
     *       "enabled": true,
     *       "is_vod": false,
     *       "channel_number": 1,
     *       "group": {"id": 5, "name": "Sports"},
     *       "proxy_url": "https://example.com/api/m3u-proxy/channel/1",
     *       "playlist": {
     *         "id": 1,
     *         "name": "My IPTV Provider",
     *         "uuid": "abc-123-def",
     *         "proxy_enabled": true
     *       }
     *     }
     *   ],
     *   "meta": {
     *     "total": 100,
     *     "limit": 50,
     *     "offset": 0,
     *     "filters": {
     *       "enabled": true,
     *       "playlist_uuid": "abc-123-def"
     *     },
     *     "sort": "title",
     *     "order": "asc"
     *   }
     * }
     * @response 401 {
     *   "message": "Unauthenticated."
     * }
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'limit' => 'sometimes|integer|min:1|max:1000',
            'offset' => 'sometimes|integer|min:0',
            'enabled' => 'sometimes',
            'playlist_uuid' => 'sometimes|string',
            'group_id' => 'sometimes|integer',
            'is_vod' => 'sometimes',
            'search' => 'sometimes|string|max:255',
            'sort' => 'sometimes|string|in:id,title,name,channel,created_at',
            'order' => 'sometimes|string|in:asc,desc',
        ]);

        // Get pagination parameters
        $limit = (int) $request->get('limit', 50);
        $offset = (int) $request->get('offset', 0);

        // Validate pagination parameters
        $limit = min(max($limit, 1), 1000); // Between 1 and 1000
        $offset = max($offset, 0); // No negative offsets

        // Get sort parameters
        $sortField = $request->get('sort', 'id');
        $sortOrder = $request->get('order', 'asc');

        // Build base query with relationships eager loaded
        $baseQuery = Channel::where('user_id', $user->id)
            ->with(['playlist', 'customPlaylist', 'group']);

        // Track applied filters for meta response
        $appliedFilters = [];

        // Apply enabled filter if provided
        if ($request->has('enabled')) {
            $enabledFilter = filter_var($request->get('enabled'), FILTER_VALIDATE_BOOLEAN);
            $baseQuery->where('enabled', $enabledFilter);
            $appliedFilters['enabled'] = $enabledFilter;
        }

        // Apply playlist filter if provided
        if ($request->has('playlist_uuid')) {
            $playlistUuid = $request->get('playlist_uuid');
            $playlist = PlaylistFacade::resolvePlaylistByUuid($playlistUuid);
            if ($playlist) {
                $baseQuery->where('playlist_id', $playlist->id);
                $appliedFilters['playlist_uuid'] = $playlistUuid;
            }
        }

        // Apply group filter if provided
        if ($request->has('group_id')) {
            $groupId = (int) $request->get('group_id');
            $baseQuery->where('group_id', $groupId);
            $appliedFilters['group_id'] = $groupId;
        }

        // Apply VOD filter if provided
        if ($request->has('is_vod')) {
            $isVod = filter_var($request->get('is_vod'), FILTER_VALIDATE_BOOLEAN);
            $baseQuery->where('is_vod', $isVod);
            $appliedFilters['is_vod'] = $isVod;
        }

        // Apply search filter if provided
        if ($request->has('search')) {
            $search = $request->get('search');
            $baseQuery->where(function ($query) use ($search) {
                $query->where('title', 'LIKE', "%{$search}%")
                    ->orWhere('title_custom', 'LIKE', "%{$search}%")
                    ->orWhere('name', 'LIKE', "%{$search}%")
                    ->orWhere('name_custom', 'LIKE', "%{$search}%")
                    ->orWhere('stream_id', 'LIKE', "%{$search}%")
                    ->orWhere('stream_id_custom', 'LIKE', "%{$search}%");
            });
            $appliedFilters['search'] = $search;
        }

        // Get total count
        $total = (clone $baseQuery)->count();

        // Apply sorting
        // Handle sorting for custom fields
        if ($sortField === 'title') {
            $baseQuery->orderByRaw("COALESCE(title_custom, title) {$sortOrder}");
        } elseif ($sortField === 'name') {
            $baseQuery->orderByRaw("COALESCE(name_custom, name) {$sortOrder}");
        } else {
            $baseQuery->orderBy($sortField, $sortOrder);
        }

        // Get channels with limit and offset
        $channels = (clone $baseQuery)
            ->limit($limit)
            ->offset($offset)
            ->get()
            ->map(function ($channel) {
                // Get the effective playlist (regular or custom)
                $playlist = $channel->getEffectivePlaylist();

                // Build playlist info
                $playlistInfo = null;
                if ($playlist) {
                    $playlistInfo = [
                        'id' => $playlist->id,
                        'name' => $playlist->name,
                        'uuid' => $playlist->uuid,
                        'proxy_enabled' => (bool) ($playlist->enable_proxy ?? false),
                    ];
                }

                // Build group info
                $groupInfo = null;
                if ($channel->group) {
                    $groupInfo = [
                        'id' => $channel->group->id,
                        'name' => $channel->group->name,
                    ];
                }

                // Generate proxy URL
                $proxyUrl = ProxyFacade::getProxyUrlForChannel($channel->id, $playlist?->uuid);

                return [
                    'id' => $channel->id,
                    'title' => $channel->title_custom ?? $channel->title,
                    'name' => $channel->name_custom ?? $channel->name,
                    'logo' => $channel->logo ?? $channel->logo_internal,
                    'url' => $channel->url_custom ?? $channel->url,
                    'stream_id' => $channel->stream_id_custom ?? $channel->stream_id,
                    'enabled' => $channel->enabled,
                    'is_vod' => $channel->is_vod,
                    'channel_number' => $channel->channel,
                    'group' => $groupInfo,
                    'proxy_url' => $proxyUrl,
                    'playlist' => $playlistInfo,
                ];
            });

        $meta = [
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'sort' => $sortField,
            'order' => $sortOrder,
        ];

        // Include filters in meta if any were applied
        if (! empty($appliedFilters)) {
            $meta['filters'] = $appliedFilters;
        }

        return response()->json([
            'success' => true,
            'data' => $channels,
            'meta' => $meta,
        ]);
    }

    /**
     * Health check for a specific channel
     *
     * Retrieve stream statistics for a specific channel by ID.
     *
     *
     * @response 200 {
     *   "success": true,
     *   "data": {
     *     "channel_id": 1,
     *     "title": "ESPN HD",
     *     "url": "https://example.com/stream.m3u8",
     *     "stream_stats": [
     *       {
     *         "stream": {
     *           "codec_type": "video",
     *           "codec_name": "h264",
     *           "width": 1920,
     *           "height": 1080
     *         }
     *       }
     *     ]
     *   }
     * }
     * @response 404 {
     *   "success": false,
     *   "message": "Channel not found"
     * }
     * @response 403 {
     *   "success": false,
     *   "message": "You do not have permission to access this channel"
     * }
     */
    public function healthcheck(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        // Find the channel
        $channel = Channel::find($id);

        if (! $channel) {
            return response()->json([
                'success' => false,
                'message' => 'Channel not found',
            ], 404);
        }

        // Check if the user owns the channel
        if ($channel->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to access this channel',
            ], 403);
        }

        // Get stream stats
        $streamStats = [];
        try {
            $streamStats = $channel->stream_stats;
        } catch (\Exception $e) {
            $streamStats = [
                'error' => 'Unable to retrieve stream statistics',
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'channel_id' => $channel->id,
                'title' => $channel->title_custom ?? $channel->title,
                'name' => $channel->name_custom ?? $channel->name,
                'url' => $channel->url_custom ?? $channel->url,
                'stream_stats' => $streamStats,
            ],
        ]);
    }

    /**
     * Health check for channels by playlist search
     *
     * Search for channels in a playlist and retrieve stream statistics.
     * Searches across title, name, and stream_id fields.
     *
     *
     * @response 200 {
     *   "success": true,
     *   "data": [
     *     {
     *       "channel_id": 1,
     *       "title": "ESPN HD",
     *       "url": "https://example.com/stream.m3u8",
     *       "stream_stats": [
     *         {
     *           "stream": {
     *             "codec_type": "video",
     *             "codec_name": "h264",
     *             "width": 1920,
     *             "height": 1080
     *           }
     *         }
     *       ]
     *     }
     *   ],
     *   "meta": {
     *     "total": 1,
     *     "search": "ESPN"
     *   }
     * }
     * @response 404 {
     *   "success": false,
     *   "message": "Playlist not found"
     * }
     * @response 403 {
     *   "success": false,
     *   "message": "You do not have permission to access this playlist"
     * }
     */
    public function healthcheckByPlaylist(Request $request, string $uuid, string $search): JsonResponse
    {
        $user = $request->user();

        // Find the playlist by UUID
        $playlist = PlaylistFacade::resolvePlaylistByUuid($uuid);

        if (! $playlist) {
            return response()->json([
                'success' => false,
                'message' => 'Playlist not found',
            ], 404);
        }

        // Check if the user owns the playlist
        if ($playlist->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to access this playlist',
            ], 403);
        }

        // Search for channels in the playlist
        // Search across title, name, and stream_id fields (both original and custom)
        $channels = Channel::where('user_id', $user->id)
            ->where('playlist_id', $playlist->id)
            ->where(function ($query) use ($search) {
                $query->where('title', 'LIKE', "%{$search}%")
                    ->orWhere('title_custom', 'LIKE', "%{$search}%")
                    ->orWhere('name', 'LIKE', "%{$search}%")
                    ->orWhere('name_custom', 'LIKE', "%{$search}%")
                    ->orWhere('stream_id', 'LIKE', "%{$search}%")
                    ->orWhere('stream_id_custom', 'LIKE', "%{$search}%");
            })
            ->get();

        // Get stream stats for each channel
        $results = $channels->map(function ($channel) {
            $stats = [];
            try {
                $stats = $channel->stream_stats;
            } catch (\Exception $e) {
                $stats = [
                    'error' => 'Unable to retrieve stream statistics',
                ];
            }

            return [
                'channel_id' => $channel->id,
                'title' => $channel->title_custom ?? $channel->title,
                'name' => $channel->name_custom ?? $channel->name,
                'url' => $channel->url_custom ?? $channel->url,
                'stream_id' => $channel->stream_id_custom ?? $channel->stream_id,
                'stream_stats' => $stats,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $results,
            'meta' => [
                'total' => $results->count(),
                'search' => $search,
                'playlist_uuid' => $uuid,
            ],
        ]);
    }

    /**
     * Update a channel
     *
     * Update specific fields of a channel. Only the provided fields will be updated.
     * Updated values are stored in custom fields (e.g., `title_custom`, `name_custom`).
     *
     *
     * @bodyParam title string The channel title.
     * @bodyParam name string The channel name (tvg-name).
     * @bodyParam logo string The channel logo URL.
     * @bodyParam url string The custom stream URL.
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Channel updated successfully",
     *   "data": {
     *     "id": 1,
     *     "title": "ESPN",
     *     "name": "ESPN HD",
     *     "logo": "https://example.com/logo.png",
     *     "url": "https://example.com/stream.m3u8"
     *   }
     * }
     * @response 404 {
     *   "success": false,
     *   "message": "Channel not found"
     * }
     * @response 403 {
     *   "success": false,
     *   "message": "You do not have permission to update this channel"
     * }
     * @response 422 {
     *   "message": "The given data was invalid.",
     *   "errors": {
     *     "url": [
     *       "The url must be a valid URL."
     *     ]
     *   }
     * }
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        // Find the channel
        $channel = Channel::find($id);

        if (! $channel) {
            return response()->json([
                'success' => false,
                'message' => 'Channel not found',
            ], 404);
        }

        // Check if the user owns the channel
        if ($channel->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to update this channel',
            ], 403);
        }

        // Validate the request
        $validated = $request->validate([
            'title' => 'sometimes|string|max:500',
            'name' => 'sometimes|string|max:500',
            'logo' => 'sometimes|nullable|string|max:2500',
            'url' => 'sometimes|nullable|url|max:2500',
            'stream_id' => 'sometimes|string|max:500',
            'enabled' => 'sometimes|boolean',
        ]);

        // Update the channel fields
        // Similar to ChannelFindAndReplace, we update the custom fields
        $updated = false;

        if (array_key_exists('title', $validated)) {
            $channel->title_custom = $validated['title'];
            $updated = true;
        }

        if (array_key_exists('name', $validated)) {
            $channel->name_custom = $validated['name'];
            $updated = true;
        }

        if (array_key_exists('logo', $validated)) {
            // Logo is the special case - update the `logo` field directly
            $channel->logo = $validated['logo'];
            $updated = true;
        }

        if (array_key_exists('url', $validated)) {
            $channel->url_custom = $validated['url'];
            $updated = true;
        }

        if (array_key_exists('stream_id', $validated)) {
            $channel->stream_id_custom = $validated['stream_id'];
            $updated = true;
        }

        if (array_key_exists('enabled', $validated)) {
            $channel->enabled = $validated['enabled'];
            $updated = true;
        }

        // Save if any updates were made
        if ($updated) {
            $channel->save();
        }

        return response()->json([
            'success' => true,
            'message' => 'Channel updated successfully',
            'data' => [
                'id' => $channel->id,
                'title' => $channel->title_custom ?? $channel->title,
                'name' => $channel->name_custom ?? $channel->name,
                'logo' => $channel->logo ?? $channel->logo_internal,
                'url' => $channel->url_custom ?? $channel->url,
                'stream_id' => $channel->stream_id_custom ?? $channel->stream_id,
                'enabled' => $channel->enabled,
            ],
        ]);
    }

    /**
     * Get a single channel
     *
     * Retrieve detailed information about a specific channel by ID.
     * Returns comprehensive channel data including EPG mapping, group info, failovers, and metadata.
     *
     *
     * @response 200 {
     *   "success": true,
     *   "data": {
     *     "id": 1,
     *     "title": "ESPN HD",
     *     "title_original": "ESPN",
     *     "name": "ESPN HD",
     *     "name_original": "espn-hd",
     *     "logo": "https://example.com/logo.png",
     *     "logo_internal": "https://example.com/internal-logo.png",
     *     "url": "https://example.com/stream.m3u8",
     *     "url_original": "https://provider.com/stream.m3u8",
     *     "stream_id": "12345",
     *     "stream_id_original": "12345",
     *     "enabled": true,
     *     "is_vod": false,
     *     "channel_number": 1,
     *     "catchup": true,
     *     "shift": 24,
     *     "proxy_url": "https://example.com/api/m3u-proxy/channel/1",
     *     "epg": {
     *       "channel_id": 100,
     *       "epg_id": "espn.us",
     *       "name": "ESPN US"
     *     },
     *     "group": {
     *       "id": 5,
     *       "name": "Sports"
     *     },
     *     "playlist": {
     *       "id": 1,
     *       "name": "My Provider",
     *       "uuid": "abc-123",
     *       "proxy_enabled": true
     *     },
     *     "failovers": [
     *       {"id": 2, "title": "ESPN Backup", "priority": 1}
     *     ],
     *     "metadata": {
     *       "year": "2024",
     *       "rating": "8.5",
     *       "has_info": true
     *     },
     *     "created_at": "2025-01-01T00:00:00Z",
     *     "updated_at": "2026-01-14T12:00:00Z"
     *   }
     * }
     * @response 404 {
     *   "success": false,
     *   "message": "Channel not found"
     * }
     * @response 403 {
     *   "success": false,
     *   "message": "You do not have permission to access this channel"
     * }
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        // Find the channel with relationships
        $channel = Channel::with(['playlist', 'customPlaylist', 'group', 'epgChannel', 'failoverChannels'])
            ->find($id);

        if (! $channel) {
            return response()->json([
                'success' => false,
                'message' => 'Channel not found',
            ], 404);
        }

        // Check if the user owns the channel
        if ($channel->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to access this channel',
            ], 403);
        }

        // Get effective playlist
        $playlist = $channel->getEffectivePlaylist();

        // Build EPG info
        $epgInfo = null;
        if ($channel->epgChannel) {
            $epgInfo = [
                'channel_id' => $channel->epgChannel->id,
                'epg_id' => $channel->epgChannel->channel_id,
                'name' => $channel->epgChannel->name,
            ];
        }

        // Build group info
        $groupInfo = null;
        if ($channel->group) {
            $groupInfo = [
                'id' => $channel->group->id,
                'name' => $channel->group->name,
            ];
        }

        // Build playlist info
        $playlistInfo = null;
        if ($playlist) {
            $playlistInfo = [
                'id' => $playlist->id,
                'name' => $playlist->name,
                'uuid' => $playlist->uuid,
                'proxy_enabled' => (bool) ($playlist->enable_proxy ?? false),
            ];
        }

        // Build failovers info
        $failovers = $channel->failoverChannels->map(function ($failover) {
            return [
                'id' => $failover->id,
                'title' => $failover->title_custom ?? $failover->title,
                'priority' => $failover->pivot->sort ?? 0,
            ];
        })->toArray();

        // Generate proxy URL
        $proxyUrl = ProxyFacade::getProxyUrlForChannel($channel->id, $playlist?->uuid);

        // Build metadata info
        $metadata = [
            'year' => $channel->year,
            'rating' => $channel->rating,
            'rating_5based' => $channel->rating_5based,
            'has_info' => $channel->has_metadata,
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $channel->id,
                'title' => $channel->title_custom ?? $channel->title,
                'title_original' => $channel->title,
                'name' => $channel->name_custom ?? $channel->name,
                'name_original' => $channel->name,
                'logo' => $channel->logo ?? $channel->logo_internal,
                'logo_internal' => $channel->logo_internal,
                'url' => $channel->url_custom ?? $channel->url,
                'url_original' => $channel->url,
                'stream_id' => $channel->stream_id_custom ?? $channel->stream_id,
                'stream_id_original' => $channel->stream_id,
                'enabled' => $channel->enabled,
                'is_vod' => $channel->is_vod,
                'channel_number' => $channel->channel,
                'catchup' => $channel->catchup ?? false,
                'shift' => $channel->shift ?? 0,
                'proxy_url' => $proxyUrl,
                'epg' => $epgInfo,
                'group' => $groupInfo,
                'playlist' => $playlistInfo,
                'failovers' => $failovers,
                'metadata' => $metadata,
                'created_at' => $channel->created_at?->toIso8601String(),
                'updated_at' => $channel->updated_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Toggle channel(s) enabled status
     *
     * Enable or disable one or multiple channels at once.
     * Provide either a single channel ID or an array of IDs.
     *
     *
     * @bodyParam ids array required Array of channel IDs to toggle. Example: [1, 2, 3]
     * @bodyParam enabled boolean required The enabled status to set. Example: true
     *
     * @response 200 {
     *   "success": true,
     *   "message": "3 channel(s) updated successfully",
     *   "data": {
     *     "updated_count": 3,
     *     "enabled": true,
     *     "channel_ids": [1, 2, 3]
     *   }
     * }
     * @response 422 {
     *   "message": "The given data was invalid.",
     *   "errors": {
     *     "ids": ["The ids field is required."]
     *   }
     * }
     */
    public function toggle(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:channels,id',
            'enabled' => 'required|boolean',
        ]);

        $ids = $validated['ids'];
        $enabled = $validated['enabled'];

        // Update only channels that belong to the user
        $updatedCount = Channel::where('user_id', $user->id)
            ->whereIn('id', $ids)
            ->update(['enabled' => $enabled]);

        return response()->json([
            'success' => true,
            'message' => "{$updatedCount} channel(s) updated successfully",
            'data' => [
                'updated_count' => $updatedCount,
                'enabled' => $enabled,
                'channel_ids' => $ids,
            ],
        ]);
    }

    /**
     * Bulk update channels
     *
     * Update multiple channels at once. Supports updating by explicit IDs or by filter criteria.
     * When using filters, all matching channels will be updated.
     *
     *
     * @bodyParam ids array Array of channel IDs to update. Either ids or filter is required. Example: [1, 2, 3]
     * @bodyParam filter object Filter criteria to select channels. Either ids or filter is required.
     * @bodyParam filter.playlist_uuid string Filter by playlist UUID. Example: abc-123
     * @bodyParam filter.group_id integer Filter by group ID. Example: 5
     * @bodyParam filter.enabled boolean Filter by current enabled status. Example: true
     * @bodyParam filter.is_vod boolean Filter by VOD status. Example: false
     * @bodyParam updates object required The updates to apply.
     * @bodyParam updates.enabled boolean Set enabled status. Example: true
     * @bodyParam updates.group_id integer Move to group. Example: 10
     * @bodyParam updates.logo string Set logo URL. Example: https://example.com/logo.png
     *
     * @response 200 {
     *   "success": true,
     *   "message": "5 channel(s) updated successfully",
     *   "data": {
     *     "updated_count": 5,
     *     "updates_applied": {"enabled": true}
     *   }
     * }
     * @response 422 {
     *   "message": "The given data was invalid.",
     *   "errors": {
     *     "updates": ["The updates field is required."]
     *   }
     * }
     */
    public function bulkUpdate(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'ids' => 'sometimes|array|min:1',
            'ids.*' => 'integer|exists:channels,id',
            'filter' => 'sometimes|array',
            'filter.playlist_uuid' => 'sometimes|string',
            'filter.group_id' => 'sometimes|integer',
            'filter.enabled' => 'sometimes|boolean',
            'filter.is_vod' => 'sometimes|boolean',
            'updates' => 'required|array|min:1',
            'updates.enabled' => 'sometimes|boolean',
            'updates.group_id' => 'sometimes|integer|exists:groups,id',
            'updates.logo' => 'sometimes|nullable|string|max:2500',
        ]);

        // Either ids or filter must be provided
        if (! isset($validated['ids']) && ! isset($validated['filter'])) {
            return response()->json([
                'success' => false,
                'message' => 'Either ids or filter must be provided',
            ], 422);
        }

        // Build query
        $query = Channel::where('user_id', $user->id);

        // Apply IDs filter
        if (isset($validated['ids'])) {
            $query->whereIn('id', $validated['ids']);
        }

        // Apply filter criteria
        if (isset($validated['filter'])) {
            $filter = $validated['filter'];

            if (isset($filter['playlist_uuid'])) {
                $playlist = PlaylistFacade::resolvePlaylistByUuid($filter['playlist_uuid']);
                if ($playlist) {
                    $query->where('playlist_id', $playlist->id);
                }
            }

            if (isset($filter['group_id'])) {
                $query->where('group_id', $filter['group_id']);
            }

            if (isset($filter['enabled'])) {
                $query->where('enabled', $filter['enabled']);
            }

            if (isset($filter['is_vod'])) {
                $query->where('is_vod', $filter['is_vod']);
            }
        }

        // Build update array
        $updates = [];
        $appliedUpdates = [];

        if (isset($validated['updates']['enabled'])) {
            $updates['enabled'] = $validated['updates']['enabled'];
            $appliedUpdates['enabled'] = $validated['updates']['enabled'];
        }

        if (isset($validated['updates']['group_id'])) {
            $updates['group_id'] = $validated['updates']['group_id'];
            $appliedUpdates['group_id'] = $validated['updates']['group_id'];
        }

        if (array_key_exists('logo', $validated['updates'])) {
            $updates['logo'] = $validated['updates']['logo'];
            $appliedUpdates['logo'] = $validated['updates']['logo'];
        }

        // Execute update
        $updatedCount = $query->update($updates);

        return response()->json([
            'success' => true,
            'message' => "{$updatedCount} channel(s) updated successfully",
            'data' => [
                'updated_count' => $updatedCount,
                'updates_applied' => $appliedUpdates,
            ],
        ]);
    }

    /**
     * Check channel availability
     *
     * Performs a lightweight HTTP HEAD request to check if the stream URL is reachable.
     * Does NOT open the stream or consume connection slots.
     *
     * @response 200 {
     *   "success": true,
     *   "data": {
     *     "channel_id": 1,
     *     "title": "ESPN HD",
     *     "url": "https://example.com/stream.m3u8",
     *     "available": true,
     *     "status": "online",
     *     "response_time_ms": 245,
     *     "http_status": 200
     *   }
     * }
     * @response 404 {
     *   "success": false,
     *   "message": "Channel not found"
     * }
     */
    public function checkAvailability(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $channel = Channel::find($id);

        if (! $channel) {
            return response()->json([
                'success' => false,
                'message' => 'Channel not found',
            ], 404);
        }

        if ($channel->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to access this channel',
            ], 403);
        }

        $url = $channel->url_custom ?? $channel->url;
        $startTime = microtime(true);

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(5)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (m3u-editor availability check)',
                ])
                ->head($url);

            $responseTime = round((microtime(true) - $startTime) * 1000);
            $httpStatus = $response->status();

            $available = $httpStatus >= 200 && $httpStatus < 400;
            $status = $available ? 'online' : 'offline';

            return response()->json([
                'success' => true,
                'data' => [
                    'channel_id' => $channel->id,
                    'title' => $channel->title_custom ?? $channel->title,
                    'url' => $url,
                    'available' => $available,
                    'status' => $status,
                    'response_time_ms' => $responseTime,
                    'http_status' => $httpStatus,
                ],
            ]);
        } catch (\Exception $e) {
            $responseTime = round((microtime(true) - $startTime) * 1000);

            return response()->json([
                'success' => true,
                'data' => [
                    'channel_id' => $channel->id,
                    'title' => $channel->title_custom ?? $channel->title,
                    'url' => $url,
                    'available' => false,
                    'status' => 'offline',
                    'response_time_ms' => $responseTime,
                    'error' => $e->getMessage(),
                ],
            ]);
        }
    }

    /**
     * Batch check channel availability
     *
     * Checks multiple channels at once with HTTP HEAD requests.
     * Does NOT open streams or consume connection slots.
     *
     * @bodyParam channel_ids array required Array of channel IDs to check. Example: [1, 2, 3]
     *
     * @response 200 {
     *   "success": true,
     *   "data": {
     *     "total_checked": 3,
     *     "online": 2,
     *     "offline": 1,
     *     "channels": [
     *       {
     *         "channel_id": 1,
     *         "title": "ESPN HD",
     *         "available": true,
     *         "status": "online",
     *         "response_time_ms": 245
     *       }
     *     ]
     *   }
     * }
     */
    public function batchCheckAvailability(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'channel_ids' => 'required|array|min:1|max:50',
            'channel_ids.*' => 'integer|exists:channels,id',
        ]);

        $channelIds = $validated['channel_ids'];
        $channels = Channel::whereIn('id', $channelIds)
            ->where('user_id', $user->id)
            ->get();

        $results = [];
        $onlineCount = 0;
        $offlineCount = 0;

        foreach ($channels as $channel) {
            $url = $channel->url_custom ?? $channel->url;
            $startTime = microtime(true);

            try {
                $response = \Illuminate\Support\Facades\Http::timeout(5)
                    ->withHeaders([
                        'User-Agent' => 'Mozilla/5.0 (m3u-editor availability check)',
                    ])
                    ->head($url);

                $responseTime = round((microtime(true) - $startTime) * 1000);
                $httpStatus = $response->status();
                $available = $httpStatus >= 200 && $httpStatus < 400;

                if ($available) {
                    $onlineCount++;
                } else {
                    $offlineCount++;
                }

                $results[] = [
                    'channel_id' => $channel->id,
                    'title' => $channel->title_custom ?? $channel->title,
                    'url' => $url,
                    'available' => $available,
                    'status' => $available ? 'online' : 'offline',
                    'response_time_ms' => $responseTime,
                    'http_status' => $httpStatus,
                ];
            } catch (\Exception $e) {
                $responseTime = round((microtime(true) - $startTime) * 1000);
                $offlineCount++;

                $results[] = [
                    'channel_id' => $channel->id,
                    'title' => $channel->title_custom ?? $channel->title,
                    'url' => $url,
                    'available' => false,
                    'status' => 'offline',
                    'response_time_ms' => $responseTime,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'total_checked' => count($results),
                'online' => $onlineCount,
                'offline' => $offlineCount,
                'channels' => $results,
            ],
        ]);
    }

    /**
     * Test channel stability over time
     *
     * Opens the stream and uses FFprobe to count frames over multiple intervals.
     * This test DOES consume a connection slot while running.
     *
     * @bodyParam duration integer Seconds to check per interval. Default: 5. Example: 5
     * @bodyParam checks integer Number of checks to perform. Default: 3. Example: 3
     * @bodyParam pause_between integer Pause in seconds between checks. Default: 1. Example: 1
     *
     * @response 200 {
     *   "success": true,
     *   "data": {
     *     "channel_id": 1,
     *     "title": "ESPN HD",
     *     "url": "https://example.com/stream.m3u8",
     *     "live": true,
     *     "stable": true,
     *     "quality": "✅ Online",
     *     "connect_time_ms": 245,
     *     "checks_passed": 3,
     *     "checks_failed": 0,
     *     "frame_counts": [125, 123, 126],
     *     "avg_frames_per_check": 124.6,
     *     "total_test_duration_ms": 18500
     *   }
     * }
     */
    public function stabilityTest(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $channel = Channel::find($id);

        if (! $channel) {
            return response()->json([
                'success' => false,
                'message' => 'Channel not found',
            ], 404);
        }

        if ($channel->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to access this channel',
            ], 403);
        }

        $validated = $request->validate([
            'duration' => 'sometimes|integer|min:1|max:30',
            'checks' => 'sometimes|integer|min:1|max:10',
            'pause_between' => 'sometimes|integer|min:0|max:10',
        ]);

        $duration = $validated['duration'] ?? 5;
        $numChecks = $validated['checks'] ?? 3;
        $pauseBetween = $validated['pause_between'] ?? 1;

        $url = $channel->url_custom ?? $channel->url;

        // Measure connect time
        $connectStart = microtime(true);
        try {
            $connectResponse = \Illuminate\Support\Facades\Http::timeout(5)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (m3u-editor stability test)'])
                ->head($url);
            $connectTime = round((microtime(true) - $connectStart) * 1000);
        } catch (\Exception $e) {
            return response()->json([
                'success' => true,
                'data' => [
                    'channel_id' => $channel->id,
                    'title' => $channel->title_custom ?? $channel->title,
                    'url' => $url,
                    'live' => false,
                    'reason' => 'connection_failed',
                    'error' => $e->getMessage(),
                ],
            ]);
        }

        $testStart = microtime(true);
        $frameCounts = [];
        $stableChecks = 0;
        $failedChecks = 0;

        for ($i = 0; $i < $numChecks; $i++) {
            try {
                $command = sprintf(
                    'ffprobe -v error -rw_timeout 5000000 -user_agent "%s" -read_intervals %%+%d -select_streams v:0 -count_frames -show_entries stream=nb_read_frames -of default=nw=1:nk=1 "%s" 2>&1',
                    'Mozilla/5.0 (m3u-editor stability test)',
                    $duration,
                    $url
                );

                $process = \Symfony\Component\Process\Process::fromShellCommandline($command);
                $process->setTimeout($duration + 10);
                $process->run();

                $output = trim($process->getOutput());
                $lines = explode("\n", $output);
                $frameCount = null;

                foreach ($lines as $line) {
                    $line = trim($line);
                    if (preg_match('/^[0-9]+$/', $line)) {
                        $frameCount = (int) $line;
                        break;
                    }
                }

                if ($frameCount !== null && $frameCount > 0) {
                    $frameCounts[] = $frameCount;
                    $stableChecks++;
                } else {
                    $failedChecks++;
                }
            } catch (\Exception $e) {
                $failedChecks++;
            }

            if ($i < $numChecks - 1) {
                sleep($pauseBetween);
            }
        }

        $totalDuration = round((microtime(true) - $testStart) * 1000);
        $avgFrames = count($frameCounts) > 0 ? round(array_sum($frameCounts) / count($frameCounts), 1) : 0;

        $live = $stableChecks > 0;
        $stable = $failedChecks === 0;
        $quality = '❌ Offline';

        if ($live) {
            $quality = $stable ? '✅ Online' : '⚠️ Instabil';
        }

        return response()->json([
            'success' => true,
            'data' => [
                'channel_id' => $channel->id,
                'title' => $channel->title_custom ?? $channel->title,
                'url' => $url,
                'live' => $live,
                'stable' => $stable,
                'quality' => $quality,
                'connect_time_ms' => $connectTime,
                'checks_passed' => $stableChecks,
                'checks_failed' => $failedChecks,
                'frame_counts' => $frameCounts,
                'avg_frames_per_check' => $avgFrames,
                'total_test_duration_ms' => $totalDuration,
            ],
        ]);
    }
}
