<?php

namespace App\Http\Controllers;

use App\Facades\PlaylistFacade;
use App\Jobs\AddItemsToCustomPlaylist;
use App\Jobs\DetachItemsFromCustomPlaylist;
use App\Models\CustomPlaylist;
use App\Services\PlaylistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Tags\Tag;

/**
 * @tags Custom Playlists
 */
class CustomPlaylistController extends Controller
{
    /**
     * List the channels attached to a Custom Playlist.
     *
     * Returns the channels currently attached, including their per-playlist
     * `channel_number`/`sort` pivot values and their custom group tag, if any.
     *
     * @response 200 {
     *   "success": true,
     *   "data": [
     *     {
     *       "id": 123,
     *       "title": "ESPN HD",
     *       "enabled": true,
     *       "is_vod": false,
     *       "group": "Sports",
     *       "channel_number": 101,
     *       "sort": 1
     *     }
     *   ],
     *   "meta": {"current_page": 1, "per_page": 50, "total": 1, "last_page": 1}
     * }
     * @response 404 {
     *   "success": false,
     *   "message": "Custom playlist not found"
     * }
     */
    public function channels(Request $request, string $uuid): JsonResponse
    {
        [$playlist, $error] = $this->resolveOwnedCustomPlaylist($request, $uuid);
        if ($error) {
            return $error;
        }

        $validated = $request->validate([
            'per_page' => 'sometimes|integer|min:1|max:200',
        ]);

        $channels = $playlist->channels()
            ->with(['tags' => fn ($query) => $query->where('type', $playlist->uuid)])
            ->paginate($validated['per_page'] ?? 50);

        return response()->json([
            'success' => true,
            'data' => $channels->getCollection()->map(fn ($channel) => [
                'id' => $channel->id,
                'title' => $channel->title_custom ?: $channel->title,
                'enabled' => (bool) $channel->enabled,
                'is_vod' => (bool) $channel->is_vod,
                'group' => $channel->tags->first()?->getAttributeValue('name'),
                'channel_number' => $channel->pivot->channel_number,
                'sort' => $channel->pivot->sort,
            ])->values(),
            'meta' => [
                'current_page' => $channels->currentPage(),
                'per_page' => $channels->perPage(),
                'total' => $channels->total(),
                'last_page' => $channels->lastPage(),
            ],
        ]);
    }

    /**
     * Attach channels to a Custom Playlist.
     *
     * Queues the same background job the UI's Attach and Add to custom group actions use, so
     * large selections don't block the request. `group` assigns (creating if needed) a custom
     * group tag to every attached channel, matching the AddGroupsToCustomPlaylist/attach action
     * semantics. `channel_number` is only accepted when attaching a single channel, and is
     * applied synchronously since it requires the pivot row to exist immediately.
     *
     * @bodyParam ids integer[] required The channel IDs to attach. Example: [123, 456]
     * @bodyParam group string The custom group tag to assign (created if it doesn't exist). Example: Sports
     * @bodyParam channel_number integer The per-playlist channel number. Only valid with a single id. Example: 101
     *
     * @response 202 {
     *   "success": true,
     *   "message": "Attach job for 2 channel(s) has been queued",
     *   "data": {
     *     "custom_playlist_uuid": "0eff7923-cbd1-4868-9fed-2e3748ac1100",
     *     "queued_channel_ids": [123, 456],
     *     "group": "Sports",
     *     "channel_number_applied": null
     *   }
     * }
     * @response 404 {
     *   "success": false,
     *   "message": "Custom playlist not found"
     * }
     * @response 422 {
     *   "success": false,
     *   "message": "channel_number can only be set when attaching a single channel"
     * }
     */
    public function attachChannels(Request $request, string $uuid): JsonResponse
    {
        [$playlist, $error] = $this->resolveOwnedCustomPlaylist($request, $uuid);
        if ($error) {
            return $error;
        }

        $user = $request->user();

        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => ['integer', Rule::exists('channels', 'id')->where('user_id', $user->id)],
            'group' => 'sometimes|nullable|string|max:255',
            'channel_number' => 'sometimes|nullable|integer|min:0',
        ]);

        $ids = $validated['ids'];
        $group = $validated['group'] ?? null;
        $channelNumber = $validated['channel_number'] ?? null;

        if ($channelNumber !== null && count($ids) !== 1) {
            return response()->json([
                'success' => false,
                'message' => 'channel_number can only be set when attaching a single channel',
            ], 422);
        }

        if ($channelNumber !== null) {
            // Ensure the pivot row exists synchronously so channel_number can be applied
            // immediately, rather than racing the queued attach job below.
            $playlist->channels()->syncWithoutDetaching($ids);
            $playlist->channels()->updateExistingPivot($ids[0], ['channel_number' => $channelNumber]);
        }

        AddItemsToCustomPlaylist::dispatch(
            userId: $user->id,
            itemIds: $ids,
            customPlaylistId: $playlist->id,
            data: $group !== null ? ['mode' => 'select', 'category' => $group] : ['mode' => 'select'],
            type: 'channel',
        );

        return response()->json([
            'success' => true,
            'message' => 'Attach job for '.count($ids).' channel(s) has been queued',
            'data' => [
                'custom_playlist_uuid' => $playlist->uuid,
                'queued_channel_ids' => $ids,
                'group' => $group,
                'channel_number_applied' => $channelNumber,
            ],
        ], 202);
    }

    /**
     * Detach channels from a Custom Playlist.
     *
     * Queues the same background job the UI's Detach Selected bulk action uses: removes the
     * pivot row and strips the custom group tag for every given channel.
     *
     * @bodyParam ids integer[] required The channel IDs to detach. Example: [123, 456]
     *
     * @response 202 {
     *   "success": true,
     *   "message": "Detach job for 2 channel(s) has been queued",
     *   "data": {
     *     "custom_playlist_uuid": "0eff7923-cbd1-4868-9fed-2e3748ac1100",
     *     "queued_channel_ids": [123, 456]
     *   }
     * }
     * @response 404 {
     *   "success": false,
     *   "message": "Custom playlist not found"
     * }
     */
    public function detachChannels(Request $request, string $uuid): JsonResponse
    {
        [$playlist, $error] = $this->resolveOwnedCustomPlaylist($request, $uuid);
        if ($error) {
            return $error;
        }

        $user = $request->user();

        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => ['integer', Rule::exists('channels', 'id')->where('user_id', $user->id)],
        ]);

        DetachItemsFromCustomPlaylist::dispatch(
            userId: $user->id,
            itemIds: $validated['ids'],
            customPlaylistId: $playlist->id,
            type: 'channel',
        );

        return response()->json([
            'success' => true,
            'message' => 'Detach job for '.count($validated['ids']).' channel(s) has been queued',
            'data' => [
                'custom_playlist_uuid' => $playlist->uuid,
                'queued_channel_ids' => $validated['ids'],
            ],
        ], 202);
    }

    /**
     * Update a channel's group, channel number, or sort order within a Custom Playlist.
     *
     * Applies synchronously, matching the UI's per-record pivot editing (channel number/sort
     * columns and the detach action's tag handling). The channel must already be attached to
     * the custom playlist.
     *
     * @bodyParam group string The custom group tag to assign, or null to remove the channel's group tag. Example: Sports
     * @bodyParam channel_number integer The per-playlist channel number. Can be set to null to clear it. Example: 101
     * @bodyParam sort number The per-playlist sort order. Can be set to null to clear it. Example: 1
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Channel updated successfully",
     *   "data": {
     *     "id": 123,
     *     "group": "Sports",
     *     "channel_number": 101,
     *     "sort": 1
     *   }
     * }
     * @response 404 {
     *   "success": false,
     *   "message": "Channel is not attached to this custom playlist"
     * }
     */
    public function updateChannelPivot(Request $request, string $uuid, int $id): JsonResponse
    {
        [$playlist, $error] = $this->resolveOwnedCustomPlaylist($request, $uuid);
        if ($error) {
            return $error;
        }

        $user = $request->user();

        $channel = $playlist->channels()
            ->where('channels.user_id', $user->id)
            ->where('channels.id', $id)
            ->first();

        if (! $channel) {
            return response()->json([
                'success' => false,
                'message' => 'Channel is not attached to this custom playlist',
            ], 404);
        }

        $validated = $request->validate([
            'group' => 'sometimes|nullable|string|max:255',
            'channel_number' => 'sometimes|nullable|integer|min:0',
            'sort' => 'sometimes|nullable|numeric|min:0',
        ]);

        if ($validated === []) {
            return response()->json([
                'success' => false,
                'message' => 'No changes provided',
            ], 422);
        }

        $pivotUpdates = array_intersect_key($validated, array_flip(['channel_number', 'sort']));
        if ($pivotUpdates !== []) {
            $playlist->channels()->updateExistingPivot($channel->id, $pivotUpdates);
        }

        if (array_key_exists('group', $validated)) {
            $meta = PlaylistService::resolveCustomPlaylistRelationMeta($playlist, 'channel');
            $groupName = $validated['group'];

            if ($groupName === null) {
                $channel->detachTags($playlist->groupTags()->get());
            } else {
                $tag = Tag::findOrCreate($groupName, $meta['tagType']);
                $playlist->attachTag($tag);
                $playlistTagIds = $playlist->groupTags()->pluck('tags.id')->all();
                PlaylistService::retagItems($meta, $playlistTagIds, $tag, [$channel->id]);
            }
        }

        $channel = $playlist->channels()
            ->with(['tags' => fn ($query) => $query->where('type', $playlist->uuid)])
            ->where('channels.id', $channel->id)
            ->first();

        return response()->json([
            'success' => true,
            'message' => 'Channel updated successfully',
            'data' => [
                'id' => $channel->id,
                'group' => $channel->tags->first()?->getAttributeValue('name'),
                'channel_number' => $channel->pivot->channel_number,
                'sort' => $channel->pivot->sort === null ? null : (float) $channel->pivot->sort,
            ],
        ]);
    }

    /**
     * List the group tags for a Custom Playlist.
     *
     * @response 200 {
     *   "success": true,
     *   "data": [
     *     {"id": 1, "name": "Sports", "order_column": 1}
     *   ]
     * }
     */
    public function groups(Request $request, string $uuid): JsonResponse
    {
        [$playlist, $error] = $this->resolveOwnedCustomPlaylist($request, $uuid);
        if ($error) {
            return $error;
        }

        $groups = $playlist->groupTags()
            ->orderBy('order_column')
            ->get()
            ->map(fn ($tag) => [
                'id' => $tag->id,
                'name' => $tag->getAttributeValue('name'),
                'order_column' => $tag->order_column,
            ])
            ->values();

        return response()->json([
            'success' => true,
            'data' => $groups,
        ]);
    }

    /**
     * Create a group tag for a Custom Playlist.
     *
     * @bodyParam name string required The group name. Example: Sports
     *
     * @response 201 {
     *   "success": true,
     *   "message": "Group created successfully",
     *   "data": {"id": 1, "name": "Sports", "order_column": 1}
     * }
     */
    public function createGroup(Request $request, string $uuid): JsonResponse
    {
        [$playlist, $error] = $this->resolveOwnedCustomPlaylist($request, $uuid);
        if ($error) {
            return $error;
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $tag = Tag::findOrCreate($validated['name'], $playlist->uuid);
        $playlist->attachTag($tag);

        return response()->json([
            'success' => true,
            'message' => 'Group created successfully',
            'data' => [
                'id' => $tag->id,
                'name' => $tag->getAttributeValue('name'),
                'order_column' => $tag->order_column,
            ],
        ], 201);
    }

    /**
     * Rename or reorder a Custom Playlist group tag.
     *
     * @bodyParam name string The new group name. Example: Sports HD
     * @bodyParam order_column integer The new sort position, matching the UI's drag-to-reorder. Example: 2
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Group updated successfully",
     *   "data": {"id": 1, "name": "Sports", "order_column": 2}
     * }
     * @response 404 {
     *   "success": false,
     *   "message": "Group not found"
     * }
     */
    public function updateGroup(Request $request, string $uuid, int $id): JsonResponse
    {
        [$playlist, $error] = $this->resolveOwnedCustomPlaylist($request, $uuid);
        if ($error) {
            return $error;
        }

        $tag = $playlist->groupTags()->where('tags.id', $id)->first();
        if (! $tag) {
            return response()->json([
                'success' => false,
                'message' => 'Group not found',
            ], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'order_column' => 'sometimes|integer|min:0',
        ]);

        if ($validated === []) {
            return response()->json([
                'success' => false,
                'message' => 'No changes provided',
            ], 422);
        }

        if (array_key_exists('name', $validated)) {
            $tag->name = $validated['name'];
        }

        if (array_key_exists('order_column', $validated)) {
            $tag->order_column = $validated['order_column'];
        }

        $tag->save();

        return response()->json([
            'success' => true,
            'message' => 'Group updated successfully',
            'data' => [
                'id' => $tag->id,
                'name' => $tag->getAttributeValue('name'),
                'order_column' => $tag->order_column,
            ],
        ]);
    }

    /**
     * Resolve a Custom Playlist by UUID, scoped to the authenticated user.
     *
     * @return array{0: ?CustomPlaylist, 1: ?JsonResponse}
     */
    private function resolveOwnedCustomPlaylist(Request $request, string $uuid): array
    {
        $playlist = PlaylistFacade::resolvePlaylistByUuid($uuid);

        if (! $playlist || ! $playlist instanceof CustomPlaylist) {
            return [null, response()->json([
                'success' => false,
                'message' => 'Custom playlist not found',
            ], 404)];
        }

        if ($playlist->user_id !== $request->user()->id) {
            return [null, response()->json([
                'success' => false,
                'message' => 'You do not have permission to manage this custom playlist',
            ], 403)];
        }

        return [$playlist, null];
    }
}
