<?php

namespace App\Http\Controllers;

use App\Enums\ChannelLogoType;
use App\Facades\PlaylistFacade;
use App\Models\Channel;
use App\Models\ChannelFailover;
use App\Models\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Symfony\Component\Process\Process;

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

                // Build group info (use relationLoaded to avoid conflict with 'group' column)
                $groupInfo = null;
                if ($channel->relationLoaded('group') && $channel->getRelation('group')) {
                    $groupInfo = [
                        'id' => $channel->getRelation('group')->id,
                        'name' => $channel->getRelation('group')->name,
                    ];
                }

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
                    'proxy_url' => $channel->getProxyUrl(), // Include proxy URL in the response
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

        // Perform live stream probe
        $streamStats = [];
        try {
            $streamStats = $channel->probeStreamStats();
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

        // Perform live stream probe for each channel
        $results = $channels->map(function ($channel) {
            $stats = [];
            try {
                $stats = $channel->probeStreamStats();
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
            'title' => 'sometimes|nullable|string|max:500',
            'name' => 'sometimes|nullable|string|max:500',
            'logo' => 'sometimes|nullable|string|max:2500',
            'url' => 'sometimes|nullable|url|max:2500',
            'stream_id' => 'sometimes|nullable|string|max:500',
            'enabled' => 'sometimes|boolean',
            'group' => 'sometimes|nullable|string|max:500',
            'group_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('groups', 'id')->where('user_id', $user->id),
            ],
            'epg_channel_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('epg_channels', 'id')->where('user_id', $user->id),
            ],
            'channel_number' => 'sometimes|nullable|integer|min:0',
            'sort_order' => 'sometimes|nullable|numeric|min:0',
            'logo_type' => 'sometimes|string|in:channel,epg',
            'use_epg_logo' => 'sometimes|boolean',
            'epg_map_enabled' => 'sometimes|boolean',
            'tvg_shift' => 'sometimes|nullable|numeric',
        ]);

        // Build and apply normalized column updates from API input.
        $group = $this->resolveUserGroup($validated, $user->id);
        $resolvedUpdateData = $this->buildChannelUpdates($validated, $group);

        foreach ($resolvedUpdateData['updates'] as $column => $value) {
            $channel->{$column} = $value;
        }

        // Persist only when at least one column value actually changed.
        if ($channel->isDirty()) {
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
                'channel_number' => $channel->channel,
                'sort_order' => $channel->sort,
                'group' => $channel->group,
                'group_id' => $channel->group_id,
                'epg_channel_id' => $channel->epg_channel_id,
                'logo_type' => $channel->logo_type?->value,
                'use_epg_logo' => $channel->logo_type === ChannelLogoType::Epg,
                'epg_map_enabled' => $channel->epg_map_enabled ?? true,
                'tvg_shift' => $channel->tvg_shift,
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

        // Build group info (use relationLoaded to avoid conflict with 'group' column)
        $groupInfo = null;
        if ($channel->relationLoaded('group') && $channel->getRelation('group')) {
            $groupInfo = [
                'id' => $channel->getRelation('group')->id,
                'name' => $channel->getRelation('group')->name,
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
                'sort_order' => $channel->sort,
                'catchup' => $channel->catchup ?? false,
                'shift' => $channel->shift ?? 0,
                'tvg_shift' => $channel->tvg_shift,
                'logo_type' => $channel->logo_type?->value,
                'use_epg_logo' => $channel->logo_type === ChannelLogoType::Epg,
                'epg_map_enabled' => $channel->epg_map_enabled ?? true,
                'proxy_url' => $channel->getProxyUrl(),
                'epg' => $epgInfo,
                'epg_channel_id' => $channel->epg_channel_id,
                'group_title' => $channel->group,
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
            'ids.*' => ['integer', Rule::exists('channels', 'id')->where('user_id', $user->id)],
            'enabled' => 'required|boolean',
        ]);

        $ids = $validated['ids'];
        $enabled = $validated['enabled'];

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
            'ids.*' => ['integer', Rule::exists('channels', 'id')->where('user_id', $user->id)],
            'filter' => 'sometimes|array',
            'filter.playlist_uuid' => 'sometimes|string',
            'filter.group_id' => 'sometimes|integer',
            'filter.enabled' => 'sometimes|boolean',
            'filter.is_vod' => 'sometimes|boolean',
            'updates' => 'required|array|min:1',
            'updates.title' => 'sometimes|nullable|string|max:500',
            'updates.name' => 'sometimes|nullable|string|max:500',
            'updates.url' => 'sometimes|nullable|url|max:2500',
            'updates.stream_id' => 'sometimes|nullable|string|max:500',
            'updates.enabled' => 'sometimes|boolean',
            'updates.group' => 'sometimes|nullable|string|max:500',
            'updates.group_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('groups', 'id')->where('user_id', $user->id),
            ],
            'updates.logo' => 'sometimes|nullable|string|max:2500',
            'updates.epg_channel_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('epg_channels', 'id')->where('user_id', $user->id),
            ],
            'updates.channel' => 'sometimes|nullable|integer|min:0',
            'updates.channel_number' => 'sometimes|nullable|integer|min:0',
            'updates.sort' => 'sometimes|nullable|numeric|min:0',
            'updates.sort_order' => 'sometimes|nullable|numeric|min:0',
            'updates.logo_type' => 'sometimes|string|in:channel,epg',
            'updates.use_epg_logo' => 'sometimes|boolean',
            'updates.epg_map_enabled' => 'sometimes|boolean',
            'updates.tvg_shift' => 'sometimes|nullable|numeric',
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
        $updatesInput = $validated['updates'];
        $group = $this->resolveUserGroup($updatesInput, $user->id);
        $resolvedUpdateData = $this->buildChannelUpdates($updatesInput, $group);
        $updates = $resolvedUpdateData['updates'];
        $appliedUpdates = $resolvedUpdateData['applied'];

        if (empty($updates)) {
            return response()->json([
                'success' => false,
                'message' => 'No valid updates were provided',
            ], 422);
        }

        // Only update rows where at least one target column value is different.
        $query->where(function ($differenceQuery) use ($updates) {
            foreach ($updates as $column => $value) {
                if ($value === null) {
                    $differenceQuery->orWhereNotNull($column);

                    continue;
                }

                $differenceQuery->orWhere(function ($columnQuery) use ($column, $value) {
                    $columnQuery->whereNull($column)
                        ->orWhere($column, '!=', $value);
                });
            }
        });

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
            $response = Http::timeout(5)
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
            'channel_ids.*' => ['integer', Rule::exists('channels', 'id')->where('user_id', $user->id)],
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
                $response = Http::timeout(5)
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
     * Resolve the selected group for channel update payloads.
     *
     * @param  array<string, mixed>  $input
     */
    private function resolveUserGroup(array $input, int $userId): ?Group
    {
        if (! array_key_exists('group_id', $input) || $input['group_id'] === null) {
            return null;
        }

        return Group::query()
            ->select(['id', 'name'])
            ->where('user_id', $userId)
            ->find($input['group_id']);
    }

    /**
     * Normalize API input into channel update columns and response metadata.
     *
     * @param  array<string, mixed>  $input
     * @return array{updates: array<string, mixed>, applied: array<string, mixed>}
     */
    private function buildChannelUpdates(array $input, ?Group $group): array
    {
        $updates = [];
        $applied = [];

        if (array_key_exists('title', $input)) {
            $updates['title_custom'] = $input['title'];
            $applied['title'] = $input['title'];
        }

        if (array_key_exists('name', $input)) {
            $updates['name_custom'] = $input['name'];
            $applied['name'] = $input['name'];
        }

        if (array_key_exists('logo', $input)) {
            $updates['logo'] = $input['logo'];
            $applied['logo'] = $input['logo'];
        }

        if (array_key_exists('url', $input)) {
            $updates['url_custom'] = $input['url'];
            $applied['url'] = $input['url'];
        }

        if (array_key_exists('stream_id', $input)) {
            $updates['stream_id_custom'] = $input['stream_id'];
            $applied['stream_id'] = $input['stream_id'];
        }

        if (array_key_exists('enabled', $input)) {
            $updates['enabled'] = $input['enabled'];
            $applied['enabled'] = $input['enabled'];
        }

        if (array_key_exists('group_id', $input)) {
            $updates['group_id'] = $input['group_id'];
            $applied['group_id'] = $input['group_id'];

            if ($input['group_id'] === null) {
                // Keep existing group title unless an explicit override was provided.
                if (array_key_exists('group', $input)) {
                    $updates['group'] = $input['group'];
                    $applied['group'] = $input['group'];
                }
            } elseif ($group) {
                $updates['group'] = $group->name;
                $applied['group'] = $group->name;
            }
        } elseif (array_key_exists('group', $input)) {
            $updates['group'] = $input['group'];
            $applied['group'] = $input['group'];
        }

        if (array_key_exists('epg_channel_id', $input)) {
            $updates['epg_channel_id'] = $input['epg_channel_id'];
            $applied['epg_channel_id'] = $input['epg_channel_id'];
        }

        if (array_key_exists('channel_number', $input)) {
            $channelNumber = $input['channel_number'];

            $updates['channel'] = $channelNumber;
            $applied['channel_number'] = $channelNumber;
        }

        if (array_key_exists('sort_order', $input)) {
            $sortOrder = $input['sort_order'];

            $updates['sort'] = $sortOrder;
            $applied['sort_order'] = $sortOrder;
        }

        if (array_key_exists('logo_type', $input) || array_key_exists('use_epg_logo', $input)) {
            $logoType = $input['logo_type'] ?? null;
            if ($logoType === null && array_key_exists('use_epg_logo', $input)) {
                $logoType = $input['use_epg_logo']
                    ? ChannelLogoType::Epg->value
                    : ChannelLogoType::Channel->value;
            }

            if ($logoType !== null) {
                $updates['logo_type'] = $logoType;
                $applied['logo_type'] = $logoType;
                $applied['use_epg_logo'] = $logoType === ChannelLogoType::Epg->value;
            }
        }

        if (array_key_exists('epg_map_enabled', $input)) {
            $updates['epg_map_enabled'] = $input['epg_map_enabled'];
            $applied['epg_map_enabled'] = $input['epg_map_enabled'];
        }

        if (array_key_exists('tvg_shift', $input)) {
            $updates['tvg_shift'] = $input['tvg_shift'];
            $applied['tvg_shift'] = $input['tvg_shift'];
        }

        return [
            'updates' => $updates,
            'applied' => $applied,
        ];
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
     *     "quality": "online",
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
            $connectResponse = Http::timeout(5)
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
                $process = new Process([
                    'ffprobe', '-v', 'error',
                    '-rw_timeout', '5000000',
                    '-user_agent', 'Mozilla/5.0 (m3u-editor stability test)',
                    '-read_intervals', "%+{$duration}",
                    '-select_streams', 'v:0',
                    '-count_frames',
                    '-show_entries', 'stream=nb_read_frames',
                    '-of', 'default=nw=1:nk=1',
                    $url,
                ]);
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
        $quality = 'offline';

        if ($live) {
            $quality = $stable ? 'online' : 'unstable';
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

    /**
     * Set failovers for a channel
     *
     * Replace all failover associations for the given primary channel.
     * The order of the failover_channel_ids array determines priority (index 0 = highest priority).
     *
     * @bodyParam failover_channel_ids integer[] required Ordered array of channel IDs to use as failovers. Example: [101, 102, 103]
     * @bodyParam deactivate_failover_channels boolean When true, failover channels will be disabled (enabled=false). Defaults to false. Example: true
     * @bodyParam metadata object Optional metadata to attach to each failover entry. Example: {"source": "epg-sync"}
     *
     * @response 200 {
     *   "success": true,
     *   "message": "3 failover(s) set for channel 100",
     *   "data": {
     *     "channel_id": 100,
     *     "failovers": [
     *       {"id": 101, "title": "Channel HD", "priority": 0},
     *       {"id": 102, "title": "Channel SD", "priority": 1},
     *       {"id": 103, "title": "Channel RAW", "priority": 2}
     *     ],
     *     "deactivated_count": 3
     *   }
     * }
     * @response 404 {
     *   "success": false,
     *   "message": "Channel not found"
     * }
     * @response 422 {
     *   "message": "The given data was invalid.",
     *   "errors": {}
     * }
     */
    public function setFailovers(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $channel = Channel::where('user_id', $user->id)->find($id);

        if (! $channel) {
            return response()->json([
                'success' => false,
                'message' => 'Channel not found',
            ], 404);
        }

        $validated = $request->validate([
            'failover_channel_ids' => 'required|array|min:1',
            'failover_channel_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('channels', 'id')->where('user_id', $user->id),
            ],
            'deactivate_failover_channels' => 'sometimes|boolean',
            'metadata' => 'sometimes|nullable|array',
        ]);

        $failoverIds = $validated['failover_channel_ids'];
        $deactivateFailovers = (bool) ($validated['deactivate_failover_channels'] ?? false);
        $metadata = $validated['metadata'] ?? null;

        if (in_array($id, $failoverIds, true)) {
            return response()->json([
                'success' => false,
                'message' => 'A channel cannot be its own failover',
            ], 422);
        }

        $deactivatedCount = 0;

        DB::transaction(function () use ($channel, $user, $failoverIds, $deactivateFailovers, $metadata, &$deactivatedCount) {
            ChannelFailover::where('channel_id', $channel->id)
                ->where('user_id', $user->id)
                ->delete();

            $records = [];
            foreach ($failoverIds as $sort => $failoverChannelId) {
                $records[] = [
                    'user_id' => $user->id,
                    'channel_id' => $channel->id,
                    'channel_failover_id' => $failoverChannelId,
                    'sort' => $sort,
                    'metadata' => $metadata ? json_encode($metadata) : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            ChannelFailover::insert($records);

            if ($deactivateFailovers) {
                $deactivatedCount = Channel::where('user_id', $user->id)
                    ->whereIn('id', $failoverIds)
                    ->where('enabled', true)
                    ->update(['enabled' => false]);
            }
        });

        $channel->load('failoverChannels');

        $failovers = $channel->failoverChannels->map(fn (Channel $failover) => [
            'id' => $failover->id,
            'title' => $failover->title_custom ?? $failover->title,
            'priority' => $failover->pivot->sort ?? 0,
        ])->toArray();

        return response()->json([
            'success' => true,
            'message' => count($failovers).' failover(s) set for channel '.$channel->id,
            'data' => [
                'channel_id' => $channel->id,
                'failovers' => $failovers,
                'deactivated_count' => $deactivatedCount,
            ],
        ]);
    }

    /**
     * Clear failovers for a channel
     *
     * Remove all failover associations from the given primary channel. The failover channels themselves are not deleted.
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Failovers cleared for channel 100",
     *   "data": {
     *     "channel_id": 100,
     *     "removed_count": 3
     *   }
     * }
     * @response 404 {
     *   "success": false,
     *   "message": "Channel not found"
     * }
     */
    public function clearFailovers(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $channel = Channel::where('user_id', $user->id)->find($id);

        if (! $channel) {
            return response()->json([
                'success' => false,
                'message' => 'Channel not found',
            ], 404);
        }

        $removedCount = ChannelFailover::where('channel_id', $channel->id)
            ->where('user_id', $user->id)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'Failovers cleared for channel '.$channel->id,
            'data' => [
                'channel_id' => $channel->id,
                'removed_count' => $removedCount,
            ],
        ]);
    }

    /**
     * Bulk set failovers for multiple channels
     *
     * Replace failover channels for multiple primary channels in a single request.
     * Each mapping specifies a primary channel and its ordered list of failover channel IDs.
     * The order of failover_channel_ids determines priority (index 0 = highest priority).
     *
     * @bodyParam mappings array required Array of primary-to-failover mappings. Example: [{"primary_channel_id": 100, "failover_channel_ids": [101, 102]}]
     * @bodyParam mappings[].primary_channel_id integer required The primary channel ID. Example: 100
     * @bodyParam mappings[].failover_channel_ids integer[] required Ordered failover channel IDs. Example: [101, 102, 103]
     * @bodyParam mappings[].metadata object Optional metadata for each failover entry. Example: {"source": "epg-sync"}
     * @bodyParam deactivate_failover_channels boolean When true, failover channels will be disabled (enabled=false). Defaults to false. Example: true
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Failovers set for 5 channel(s)",
     *   "data": {
     *     "mappings_applied": 5,
     *     "total_failovers_created": 15,
     *     "deactivated_count": 10
     *   }
     * }
     * @response 422 {
     *   "message": "The given data was invalid.",
     *   "errors": {}
     * }
     */
    public function bulkSetFailovers(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'mappings' => 'required|array|min:1',
            'mappings.*.primary_channel_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('channels', 'id')->where('user_id', $user->id),
            ],
            'mappings.*.failover_channel_ids' => 'required|array|min:1',
            'mappings.*.failover_channel_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('channels', 'id')->where('user_id', $user->id),
            ],
            'mappings.*.metadata' => 'sometimes|nullable|array',
            'deactivate_failover_channels' => 'sometimes|boolean',
        ]);

        $mappings = $validated['mappings'];
        $deactivateFailovers = (bool) ($validated['deactivate_failover_channels'] ?? false);

        foreach ($mappings as $index => $mapping) {
            if (in_array($mapping['primary_channel_id'], $mapping['failover_channel_ids'], true)) {
                return response()->json([
                    'success' => false,
                    'message' => "Mapping at index {$index}: a channel cannot be its own failover",
                ], 422);
            }
        }

        $primaryIds = array_column($mappings, 'primary_channel_id');

        $totalFailoversCreated = 0;
        $deactivatedCount = 0;

        DB::transaction(function () use ($mappings, $primaryIds, $user, $deactivateFailovers, &$totalFailoversCreated, &$deactivatedCount) {
            ChannelFailover::whereIn('channel_id', $primaryIds)
                ->where('user_id', $user->id)
                ->delete();

            $allFailoverIds = [];
            $records = [];
            foreach ($mappings as $mapping) {
                $metadata = $mapping['metadata'] ?? null;
                $encodedMetadata = $metadata ? json_encode($metadata) : null;

                foreach ($mapping['failover_channel_ids'] as $sort => $failoverChannelId) {
                    $allFailoverIds[] = $failoverChannelId;
                    $records[] = [
                        'user_id' => $user->id,
                        'channel_id' => $mapping['primary_channel_id'],
                        'channel_failover_id' => $failoverChannelId,
                        'sort' => $sort,
                        'metadata' => $encodedMetadata,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }

            $totalFailoversCreated = count($records);

            foreach (array_chunk($records, 500) as $chunk) {
                ChannelFailover::insert($chunk);
            }

            if ($deactivateFailovers) {
                $deactivatedCount = Channel::where('user_id', $user->id)
                    ->whereIn('id', array_unique($allFailoverIds))
                    ->where('enabled', true)
                    ->update(['enabled' => false]);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Failovers set for '.count($mappings).' channel(s)',
            'data' => [
                'mappings_applied' => count($mappings),
                'total_failovers_created' => $totalFailoversCreated,
                'deactivated_count' => $deactivatedCount,
            ],
        ]);
    }
}
