<?php

namespace App\Http\Controllers;

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
     *
     *
     * @queryParam limit integer Maximum number of channels to return (1-1000). Defaults to 50. Example: 100
     * @queryParam offset integer Number of channels to skip for pagination. Defaults to 0. Example: 0
     *
     * @response 200 {
     *   "success": true,
     *   "data": [
     *     {
     *       "id": 1,
     *       "title": "ESPN",
     *       "name": "ESPN HD",
     *       "logo": "https://example.com/logo.png",
     *       "url": "https://example.com/stream.m3u8"
     *     }
     *   ],
     *   "meta": {
     *     "total": 100,
     *     "limit": 50,
     *     "offset": 0
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
        ]);

        // Get pagination parameters
        $limit = (int) $request->get('limit', 50);
        $offset = (int) $request->get('offset', 0);

        // Validate pagination parameters
        $limit = min(max($limit, 1), 1000); // Between 1 and 1000
        $offset = max($offset, 0); // No negative offsets

        // Get total count
        $totalQuery = Channel::where('user_id', $user->id);
        $total = $totalQuery->count();

        // Get channels with limit and offset
        $channels = Channel::where('user_id', $user->id)
            ->orderBy('id')
            ->limit($limit)
            ->offset($offset)
            ->get()
            ->map(function ($channel) {
                return [
                    'id' => $channel->id,
                    'title' => $channel->title_custom ?? $channel->title,
                    'name' => $channel->name_custom ?? $channel->name,
                    'logo' => $channel->logo ?? $channel->logo_internal,
                    'url' => $channel->url_custom ?? $channel->url,
                    'stream_id' => $channel->stream_id_custom ?? $channel->stream_id,
                    'enabled'=> $channel->enabled,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $channels,
            'meta' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
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
                'enabled'=> $channel->enabled,
            ],
        ]);
    }
}
