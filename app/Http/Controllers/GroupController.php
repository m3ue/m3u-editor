<?php

namespace App\Http\Controllers;

use App\Facades\PlaylistFacade;
use App\Models\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @tags Groups
 */
class GroupController extends Controller
{
    /**
     * List groups
     *
     * Retrieve a list of channel groups for the authenticated user.
     * Supports filtering by playlist and includes channel counts.
     *
     *
     * @queryParam playlist_uuid string Filter groups by playlist UUID. Example: abc-123-def
     * @queryParam with_channels boolean Include channel count statistics. Defaults to true. Example: true
     *
     * @response 200 {
     *   "success": true,
     *   "data": [
     *     {
     *       "id": 1,
     *       "name": "Sports",
     *       "sort_order": 1,
     *       "type": "live",
     *       "total_channels": 50,
     *       "enabled_channels": 45,
     *       "playlist": {
     *         "id": 1,
     *         "name": "My Provider",
     *         "uuid": "abc-123-def"
     *       }
     *     }
     *   ],
     *   "meta": {
     *     "total": 25
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
            'playlist_uuid' => 'sometimes|string',
            'with_channels' => 'sometimes|boolean',
        ]);

        $withChannels = filter_var($request->get('with_channels', true), FILTER_VALIDATE_BOOLEAN);

        // Build base query
        $query = Group::where('user_id', $user->id)
            ->with('playlist');

        // Add channel counts if requested
        if ($withChannels) {
            $query->withCount([
                'channels',
                'channels as enabled_channels_count' => function ($q) {
                    $q->where('enabled', true);
                },
            ]);
        }

        // Filter by playlist if provided
        if ($request->has('playlist_uuid')) {
            $playlist = PlaylistFacade::resolvePlaylistByUuid($request->get('playlist_uuid'));
            if ($playlist) {
                $query->where('playlist_id', $playlist->id);
            }
        }

        // Get groups ordered by sort_order
        $groups = $query->orderBy('sort_order')->orderBy('name')->get();

        $data = $groups->map(function ($group) use ($withChannels) {
            $result = [
                'id' => $group->id,
                'name' => $group->name,
                'sort_order' => $group->sort_order,
                'type' => $group->type ?? 'live',
            ];

            if ($withChannels) {
                $result['total_channels'] = $group->channels_count ?? 0;
                $result['enabled_channels'] = $group->enabled_channels_count ?? 0;
            }

            if ($group->playlist) {
                $result['playlist'] = [
                    'id' => $group->playlist->id,
                    'name' => $group->playlist->name,
                    'uuid' => $group->playlist->uuid,
                ];
            }

            return $result;
        });

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'total' => $groups->count(),
            ],
        ]);
    }

    /**
     * Get a single group
     *
     * Retrieve detailed information about a specific group by ID.
     *
     *
     * @response 200 {
     *   "success": true,
     *   "data": {
     *     "id": 1,
     *     "name": "Sports",
     *     "sort_order": 1,
     *     "type": "live",
     *     "total_channels": 50,
     *     "enabled_channels": 45,
     *     "live_channels": 45,
     *     "vod_channels": 5,
     *     "playlist": {
     *       "id": 1,
     *       "name": "My Provider",
     *       "uuid": "abc-123-def"
     *     }
     *   }
     * }
     * @response 404 {
     *   "success": false,
     *   "message": "Group not found"
     * }
     * @response 403 {
     *   "success": false,
     *   "message": "You do not have permission to access this group"
     * }
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $group = Group::with('playlist')
            ->withCount([
                'channels',
                'channels as enabled_channels_count' => function ($q) {
                    $q->where('enabled', true);
                },
                'channels as live_channels_count' => function ($q) {
                    $q->where('is_vod', false);
                },
                'channels as vod_channels_count' => function ($q) {
                    $q->where('is_vod', true);
                },
            ])
            ->find($id);

        if (! $group) {
            return response()->json([
                'success' => false,
                'message' => 'Group not found',
            ], 404);
        }

        if ($group->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to access this group',
            ], 403);
        }

        $data = [
            'id' => $group->id,
            'name' => $group->name,
            'sort_order' => $group->sort_order,
            'type' => $group->type ?? 'live',
            'total_channels' => $group->channels_count,
            'enabled_channels' => $group->enabled_channels_count,
            'live_channels' => $group->live_channels_count,
            'vod_channels' => $group->vod_channels_count,
        ];

        if ($group->playlist) {
            $data['playlist'] = [
                'id' => $group->playlist->id,
                'name' => $group->playlist->name,
                'uuid' => $group->playlist->uuid,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }
}
