<?php

namespace App\Http\Controllers;

use App\Facades\PlaylistFacade;
use App\Models\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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
            ->withCount($this->groupCountRelations())
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

        return response()->json([
            'success' => true,
            'data' => $this->serializeGroup($group),
        ]);
    }

    /**
     * Create a group
     *
     * Create a new group for the authenticated user.
     * API-created groups are always custom groups.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'name_internal' => 'sometimes|nullable|string|max:255',
            'playlist_uuid' => 'required_without:playlist_id|string',
            'playlist_id' => [
                'required_without:playlist_uuid',
                'integer',
                Rule::exists('playlists', 'id')->where('user_id', $user->id),
            ],
            'type' => 'sometimes|string|in:live,vod',
            'sort_order' => 'sometimes|nullable|numeric|min:0',
            'enabled' => 'sometimes|boolean',
        ]);

        $playlistId = $this->resolvePlaylistId($validated, $user->id);
        if (! $playlistId) {
            return response()->json([
                'success' => false,
                'message' => 'Playlist not found or you do not have permission to use it',
            ], 422);
        }

        $group = new Group;
        $group->name = $validated['name'];
        $group->name_internal = array_key_exists('name_internal', $validated)
            ? $validated['name_internal']
            : $validated['name'];
        $group->user_id = $user->id;
        $group->playlist_id = $playlistId;
        $group->type = $validated['type'] ?? 'live';
        $group->sort_order = $validated['sort_order'] ?? 9999;
        $group->enabled = $validated['enabled'] ?? true;
        $group->custom = true;
        $group->new = true;
        $group->save();

        $this->loadGroupMeta($group);

        return response()->json([
            'success' => true,
            'message' => 'Group created successfully',
            'data' => $this->serializeGroup($group),
        ], 201);
    }

    /**
     * Update a group
     *
     * Update specific fields of a group. Only the provided fields will be updated. Updated values are stored in custom fields (e.g., `name_internal`).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $group = Group::where('user_id', $user->id)->find($id);

        if (! $group) {
            return response()->json([
                'success' => false,
                'message' => 'Group not found',
            ], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|nullable|string|max:255',
            'sort_order' => 'sometimes|numeric|min:0',
            'enabled' => 'sometimes|boolean',
            'type' => 'sometimes|string|in:live,vod',
        ]);

        if (array_key_exists('name', $validated)) {
            $group->name_internal = $validated['name'];
        }
        if (array_key_exists('sort_order', $validated)) {
            $group->sort_order = $validated['sort_order'];
        }
        if (array_key_exists('enabled', $validated)) {
            $group->enabled = $validated['enabled'];
        }

        if (array_key_exists('type', $validated) && $validated['type'] !== $group->type) {
            $hasOppositeTypeChannels = $group->channels()
                ->where('is_vod', $validated['type'] === 'live')
                ->exists();

            if ($hasOppositeTypeChannels) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot change group type while it contains channels of the opposite type',
                ], 422);
            }

            $group->type = $validated['type'];
        }

        if ($group->isDirty()) {
            $group->save();
        }

        $this->loadGroupMeta($group);

        return response()->json([
            'success' => true,
            'message' => 'Group updated successfully',
            'data' => $this->serializeGroup($group),
        ]);
    }

    /**
     * Move all channels from one group to another group.
     */
    public function moveChannels(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $group = Group::where('user_id', $user->id)->find($id);
        if (! $group) {
            return response()->json([
                'success' => false,
                'message' => 'Group not found',
            ], 404);
        }

        $validated = $request->validate([
            'target_group_id' => [
                'required',
                'integer',
                Rule::exists('groups', 'id')->where('user_id', $user->id),
            ],
        ]);

        $targetGroupId = (int) $validated['target_group_id'];
        if ($targetGroupId === $group->id) {
            return response()->json([
                'success' => false,
                'message' => 'Target group must be different from the source group',
            ], 422);
        }

        $targetGroup = Group::where('user_id', $user->id)->find($targetGroupId);
        if (! $targetGroup) {
            return response()->json([
                'success' => false,
                'message' => 'Target group not found',
            ], 422);
        }

        if ($targetGroup->playlist_id !== $group->playlist_id) {
            return response()->json([
                'success' => false,
                'message' => 'Target group must belong to the same playlist',
            ], 422);
        }

        if (($targetGroup->type ?? 'live') !== ($group->type ?? 'live')) {
            return response()->json([
                'success' => false,
                'message' => 'Target group must be the same type as the source group',
            ], 422);
        }

        $updatedCount = $group->channels()->update([
            'group' => $targetGroup->name,
            'group_id' => $targetGroup->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Channels moved successfully',
            'data' => [
                'source_group_id' => $group->id,
                'target_group_id' => $targetGroup->id,
                'moved_channels' => $updatedCount,
            ],
        ]);
    }

    /**
     * Delete a group
     *
     * For safety, only custom groups can be deleted through the API.
     * If channels exist, provide `target_group_id` to move channels first, or `force=true`
     * to allow deleting the group. Any group channels will be orphaned (without a group).
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $group = Group::where('user_id', $user->id)->find($id);
        if (! $group) {
            return response()->json([
                'success' => false,
                'message' => 'Group not found',
            ], 404);
        }

        if (! $group->custom) {
            return response()->json([
                'success' => false,
                'message' => 'Only custom groups can be deleted through the API',
            ], 403);
        }

        $validated = $request->validate([
            'target_group_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('groups', 'id')->where('user_id', $user->id),
            ],
            'force' => 'sometimes|boolean',
        ]);

        $channelCount = $group->channels()->count();
        $movedCount = 0;

        $targetGroup = null;
        $targetGroupId = $validated['target_group_id'] ?? null;
        if ($targetGroupId !== null) {
            if ((int) $targetGroupId === $group->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Target group must be different from the group being deleted',
                ], 422);
            }

            $targetGroup = Group::where('user_id', $user->id)->find((int) $targetGroupId);
            if (! $targetGroup) {
                return response()->json([
                    'success' => false,
                    'message' => 'Target group not found',
                ], 422);
            }

            if ($targetGroup->playlist_id !== $group->playlist_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Target group must belong to the same playlist',
                ], 422);
            }

            if (($targetGroup->type ?? 'live') !== ($group->type ?? 'live')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Target group must be the same type as the source group',
                ], 422);
            }
        } elseif ($channelCount > 0) {
            $force = (bool) ($validated['force'] ?? false);
            if (! $force) {
                return response()->json([
                    'success' => false,
                    'message' => 'Group contains channels. Provide target_group_id to move channels first, or force=true to delete channels with the group',
                    'data' => [
                        'channels_in_group' => $channelCount,
                    ],
                ], 409);
            }
        }

        $groupId = $group->id;
        $groupName = $group->name;

        DB::transaction(function () use ($group, $targetGroupId, $targetGroup, $channelCount, &$movedCount, &$orphanedChannels) {
            if ($targetGroupId !== null && isset($targetGroup)) {
                $movedCount = $group->channels()->update([
                    'group' => $targetGroup->name,
                    'group_id' => $targetGroup->id,
                ]);
            } elseif ($channelCount > 0) {
                $orphanedChannels = $channelCount;
            }

            $group->delete();
        });

        return response()->json([
            'success' => true,
            'message' => 'Group deleted successfully',
            'data' => [
                'id' => $groupId,
                'name' => $groupName,
                'moved_channels' => $movedCount,
                'orphaned_channels' => $orphanedChannels, // Channels that were orphaned because the group was deleted and no target group was provided
            ],
        ]);
    }

    /**
     * Resolve playlist ID from playlist_id or playlist_uuid payload.
     *
     * @param  array<string, mixed>  $validated
     */
    private function resolvePlaylistId(array $validated, int $userId): ?int
    {
        if (array_key_exists('playlist_id', $validated) && $validated['playlist_id'] !== null) {
            return (int) $validated['playlist_id'];
        }

        if (! array_key_exists('playlist_uuid', $validated) || $validated['playlist_uuid'] === null) {
            return null;
        }

        $playlist = PlaylistFacade::resolvePlaylistByUuid($validated['playlist_uuid']);
        if (! $playlist || $playlist->user_id !== $userId) {
            return null;
        }

        return (int) $playlist->id;
    }

    /**
     * @return array<string, mixed>
     */
    private function groupCountRelations(): array
    {
        return [
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
        ];
    }

    private function loadGroupMeta(Group $group): Group
    {
        return $group->load('playlist')
            ->loadCount($this->groupCountRelations());
    }

    private function serializeGroup(Group $group): array
    {
        $data = [
            'id' => $group->id,
            'name' => $group->name_internal ?? $group->name,
            'sort_order' => $group->sort_order,
            'type' => $group->type ?? 'live',
            'enabled' => (bool) $group->enabled,
            'custom' => (bool) $group->custom,
            'total_channels' => $group->channels_count ?? 0,
            'enabled_channels' => $group->enabled_channels_count ?? 0,
            'live_channels' => $group->live_channels_count ?? 0,
            'vod_channels' => $group->vod_channels_count ?? 0,
            'playlist' => $group->playlist ? [
                'id' => $group->playlist->id,
                'name' => $group->playlist->name,
                'uuid' => $group->playlist->uuid,
            ] : null,
        ];

        return $data;
    }
}
