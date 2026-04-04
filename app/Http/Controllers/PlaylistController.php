<?php

namespace App\Http\Controllers;

use App\Facades\PlaylistFacade;
use App\Jobs\MergeChannels;
use App\Jobs\ProcessM3uImport;
use App\Models\Playlist;
use App\Services\M3uProxyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlaylistController extends Controller
{
    /**
     * Sync the selected Playlist.
     *
     * Use the `uuid` parameter to select the playlist to refresh.
     * You can find the playlist UUID by using the `User > Get your Playlists` endpoint.
     *
     *
     * @return JsonResponse
     *
     * @unauthenticated
     *
     * @response array{message: "Playlist is currently being synced..."}
     */
    public function refreshPlaylist(Request $request, string $uuid)
    {
        $request->validate([
            // If true, will force a refresh of the EPG, ignoring any scheduling. Default is true.
            'force' => 'boolean',
        ]);

        // Fetch the playlist
        $playlist = PlaylistFacade::resolvePlaylistByUuid($uuid);
        if (! $playlist) {
            return response()->json(['Error' => 'Playlist Not Found'], 404);
        }

        // Refresh the playlist
        dispatch(new ProcessM3uImport($playlist, $request->force ?? true));

        return response()->json([
            'message' => "Playlist \"{$playlist->name}\" is currently being synced...",
        ]);
    }

    /**
     * Get playlist statistics.
     *
     * Retrieve comprehensive statistics for a specific playlist including channel counts,
     * group information, sync status, and proxy details.
     *
     *
     * @response 200 {
     *   "success": true,
     *   "data": {
     *     "uuid": "abc-123-def",
     *     "name": "My Provider",
     *     "channels": {
     *       "total": 500,
     *       "enabled": 450,
     *       "disabled": 50,
     *       "live": 400,
     *       "live_enabled": 380,
     *       "vod": 100,
     *       "vod_enabled": 70
     *     },
     *     "groups": {
     *       "total": 25,
     *       "live": 20,
     *       "vod": 5
     *     },
     *     "series": {
     *       "total": 50,
     *       "enabled": 45
     *     },
     *     "sync": {
     *       "last_sync": "2026-01-14T10:00:00+00:00",
     *       "sync_time_seconds": 45.5,
     *       "is_processing": false,
     *       "status": "Active"
     *     },
     *     "proxy": {
     *       "enabled": true,
     *       "active_streams": 3,
     *       "max_connections": 5
     *     },
     *     "source": {
     *       "type": "xtream",
     *       "url": "https://provider.com"
     *     }
     *   }
     * }
     * @response 404 {
     *   "success": false,
     *   "message": "Playlist not found"
     * }
     */
    public function stats(Request $request, string $uuid): JsonResponse
    {
        // Fetch the playlist
        $playlist = PlaylistFacade::resolvePlaylistByUuid($uuid);
        if (! $playlist) {
            return response()->json([
                'success' => false,
                'message' => 'Playlist not found',
            ], 404);
        }

        // Get channel counts using a single aggregated query
        $channelCounts = $playlist->channels()
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN enabled = TRUE THEN 1 ELSE 0 END) as enabled,
                SUM(CASE WHEN is_vod = FALSE THEN 1 ELSE 0 END) as live,
                SUM(CASE WHEN is_vod = FALSE AND enabled = TRUE THEN 1 ELSE 0 END) as live_enabled,
                SUM(CASE WHEN is_vod = TRUE THEN 1 ELSE 0 END) as vod,
                SUM(CASE WHEN is_vod = TRUE AND enabled = TRUE THEN 1 ELSE 0 END) as vod_enabled
            ')
            ->first();

        $totalChannels = (int) ($channelCounts->total ?? 0);
        $enabledChannels = (int) ($channelCounts->enabled ?? 0);
        $liveChannels = (int) ($channelCounts->live ?? 0);
        $liveEnabledChannels = (int) ($channelCounts->live_enabled ?? 0);
        $vodChannels = (int) ($channelCounts->vod ?? 0);
        $vodEnabledChannels = (int) ($channelCounts->vod_enabled ?? 0);

        // Get group counts using a single aggregated query
        $groupCounts = $playlist->groups()
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN type = 'live' THEN 1 ELSE 0 END) as live,
                SUM(CASE WHEN type = 'vod' THEN 1 ELSE 0 END) as vod
            ")
            ->first();

        $totalGroups = (int) ($groupCounts->total ?? 0);
        $liveGroups = (int) ($groupCounts->live ?? 0);
        $vodGroups = (int) ($groupCounts->vod ?? 0);

        // Get series counts if available
        $totalSeries = 0;
        $enabledSeries = 0;
        if (method_exists($playlist, 'series')) {
            $totalSeries = $playlist->series()->count();
            $enabledSeries = $playlist->series()->where('enabled', true)->count();
        }

        // Get active streams if proxy is enabled
        $activeStreams = 0;
        if ($playlist->enable_proxy) {
            $activeStreams = M3uProxyService::getCachedPlaylistActiveStreamsCount($playlist, 5);
        }

        // Build response
        $data = [
            'uuid' => $playlist->uuid,
            'name' => $playlist->name,
            'channels' => [
                'total' => $totalChannels,
                'enabled' => $enabledChannels,
                'disabled' => $totalChannels - $enabledChannels,
                'live' => $liveChannels,
                'live_enabled' => $liveEnabledChannels,
                'vod' => $vodChannels,
                'vod_enabled' => $vodEnabledChannels,
            ],
            'groups' => [
                'total' => $totalGroups,
                'live' => $liveGroups,
                'vod' => $vodGroups,
            ],
            'series' => [
                'total' => $totalSeries,
                'enabled' => $enabledSeries,
            ],
            'sync' => [
                'last_sync' => $playlist->synced?->toIso8601String(),
                'sync_time_seconds' => $playlist->sync_time,
                'is_processing' => $playlist->isProcessing(),
                'status' => $playlist->status?->value ?? 'Unknown',
            ],
            'proxy' => [
                'enabled' => (bool) $playlist->enable_proxy,
                'active_streams' => $activeStreams,
                'max_connections' => $playlist->streams ?? 1,
            ],
            'source' => [
                'type' => $playlist->source_type?->value ?? 'unknown',
                'url' => $playlist->url ? parse_url($playlist->url, PHP_URL_HOST) : null,
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Trigger channel merge for a playlist.
     *
     * Dispatches the same MergeChannels job used by the UI.
     * Defaults to playlist auto-merge configuration, with optional request overrides.
     */
    public function mergeChannels(Request $request, string $uuid): JsonResponse
    {
        $user = $request->user();

        $playlist = PlaylistFacade::resolvePlaylistByUuid($uuid);
        if (! $playlist) {
            return response()->json([
                'success' => false,
                'message' => 'Playlist not found',
            ], 404);
        }

        if (! $playlist instanceof Playlist) {
            return response()->json([
                'success' => false,
                'message' => 'Only standard playlists support this merge endpoint',
            ], 422);
        }

        if ($playlist->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to merge this playlist',
            ], 403);
        }

        $this->normalizeMergePayload($request);

        $validated = $request->validate([
            'preferred_playlist_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('playlists', 'id')->where('user_id', $user->id),
            ],
            'playlist_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('playlists', 'id')->where('user_id', $user->id),
            ],
            'failover_playlists' => 'sometimes|array',
            'group_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('groups', 'id')
                    ->where('user_id', $user->id)
                    ->where('playlist_id', $playlist->id),
            ],
            'check_resolution' => 'sometimes|boolean',
            'by_resolution' => 'sometimes|boolean',
            'deactivate_failover_channels' => 'sometimes|boolean',
            'force_complete_remerge' => 'sometimes|boolean',
            'prefer_catchup_as_primary' => 'sometimes|boolean',
            'new_channels_only' => 'sometimes|boolean',
            'prefer_codec' => 'sometimes|nullable|string|in:hevc,h264',
            'priority_keywords' => 'sometimes|array',
            'priority_keywords.*' => 'string|max:255',
            'exclude_disabled_groups' => 'sometimes|boolean',
            'group_priorities' => 'sometimes|array',
            'group_priorities.*.group_id' => [
                'required_with:group_priorities',
                'integer',
                Rule::exists('groups', 'id')->where('user_id', $user->id),
            ],
            'group_priorities.*.weight' => 'required_with:group_priorities|integer|min:1|max:1000',
            'priority_attributes' => 'sometimes|array',
            'priority_attributes.*' => 'string|in:playlist_priority,group_priority,catchup_support,resolution,codec,keyword_match',
        ]);

        $config = $playlist->auto_merge_config ?? [];
        if (isset($config['priority_attributes']) && is_array($config['priority_attributes'])) {
            $config['priority_attributes'] = $this->normalizePriorityAttributes($config['priority_attributes']);
        }

        // Merge optional override values into the playlist's stored merge configuration.
        foreach ([
            'force_complete_remerge',
            'prefer_catchup_as_primary',
            'new_channels_only',
            'prefer_codec',
            'priority_keywords',
            'exclude_disabled_groups',
            'group_priorities',
            'priority_attributes',
        ] as $key) {
            if (array_key_exists($key, $validated)) {
                $config[$key] = $validated[$key];
            }
        }

        if (array_key_exists('check_resolution', $validated)) {
            $config['check_resolution'] = $validated['check_resolution'];
        } elseif (array_key_exists('by_resolution', $validated)) {
            $config['check_resolution'] = $validated['by_resolution'];
        }

        $preferredPlaylistId = null;
        $preferredFromRequest = false;
        if (array_key_exists('preferred_playlist_id', $validated)) {
            $preferredPlaylistId = $validated['preferred_playlist_id'];
            $preferredFromRequest = true;
        } elseif (array_key_exists('playlist_id', $validated)) {
            $preferredPlaylistId = $validated['playlist_id'];
            $preferredFromRequest = true;
        } else {
            $preferredPlaylistId = $config['preferred_playlist_id'] ?? null;
        }

        if ($preferredPlaylistId) {
            $resolvedId = Playlist::query()
                ->where('user_id', $user->id)
                ->where('id', (int) $preferredPlaylistId)
                ->value('id');

            if (! $resolvedId && $preferredFromRequest) {
                return response()->json([
                    'success' => false,
                    'message' => 'The preferred_playlist_id is invalid or does not belong to your account',
                ], 422);
            }

            $preferredPlaylistId = $resolvedId;
        }

        $effectivePreferredPlaylistId = $preferredPlaylistId ? (int) $preferredPlaylistId : $playlist->id;

        if (array_key_exists('failover_playlists', $validated)) {
            $requestedFailoverPlaylistIds = $this->normalizeFailoverPlaylistIds($validated['failover_playlists']);

            $validRequestedIds = Playlist::query()
                ->where('user_id', $user->id)
                ->whereIn('id', $requestedFailoverPlaylistIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->toArray();

            $invalidRequestedIds = array_values(array_diff($requestedFailoverPlaylistIds, $validRequestedIds));
            if (! empty($invalidRequestedIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'One or more failover playlist IDs are invalid for this user',
                    'data' => [
                        'invalid_failover_playlist_ids' => $invalidRequestedIds,
                    ],
                ], 422);
            }

            $config['failover_playlists'] = $validRequestedIds;
        }

        $configFailoverIds = $this->normalizeFailoverPlaylistIds($config['failover_playlists'] ?? []);
        if (! empty($configFailoverIds)) {
            $configFailoverIds = Playlist::query()
                ->where('user_id', $user->id)
                ->whereIn('id', $configFailoverIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->toArray();
        }

        // Build merge source playlists, always including the current playlist first.
        $playlists = collect([['playlist_failover_id' => $playlist->id]]);
        foreach ($configFailoverIds as $failoverId) {
            if ($failoverId !== $playlist->id) {
                $playlists->push(['playlist_failover_id' => $failoverId]);
            }
        }

        $checkResolution = (bool) ($config['check_resolution'] ?? false);
        $deactivateFailoverChannels = array_key_exists('deactivate_failover_channels', $validated)
            ? (bool) $validated['deactivate_failover_channels']
            : (bool) $playlist->auto_merge_deactivate_failover;
        $forceCompleteRemerge = (bool) ($config['force_complete_remerge'] ?? false);
        $preferCatchupAsPrimary = (bool) ($config['prefer_catchup_as_primary'] ?? false);
        $newChannelsOnly = (bool) ($config['new_channels_only'] ?? true);
        $groupId = array_key_exists('group_id', $validated) ? $validated['group_id'] : null;
        $weightedConfig = $this->buildMergeWeightedConfig($config);

        dispatch(new MergeChannels(
            user: $user,
            playlists: $playlists,
            playlistId: $effectivePreferredPlaylistId,
            checkResolution: $checkResolution,
            deactivateFailoverChannels: $deactivateFailoverChannels,
            forceCompleteRemerge: $forceCompleteRemerge,
            preferCatchupAsPrimary: $preferCatchupAsPrimary,
            groupId: $groupId,
            weightedConfig: $weightedConfig,
            newChannelsOnly: $newChannelsOnly,
        ));

        return response()->json([
            'success' => true,
            'message' => 'Channel merge job has been queued',
            'data' => [
                'playlist_uuid' => $playlist->uuid,
                'preferred_playlist_id' => $effectivePreferredPlaylistId,
                'failover_playlist_ids' => $configFailoverIds,
                'group_id' => $groupId,
                'check_resolution' => $checkResolution,
                'deactivate_failover_channels' => $deactivateFailoverChannels,
                'force_complete_remerge' => $forceCompleteRemerge,
                'prefer_catchup_as_primary' => $preferCatchupAsPrimary,
                'new_channels_only' => $newChannelsOnly,
                'weighted_config_enabled' => $weightedConfig !== null,
            ],
        ]);
    }

    /**
     * Normalize failover playlist payload values into integer playlist IDs.
     *
     * Accepts either integer IDs or objects with `playlist_failover_id`.
     *
     * @param  array<int, mixed>  $items
     * @return array<int, int>
     */
    private function normalizeFailoverPlaylistIds(array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            $candidate = is_array($item) ? ($item['playlist_failover_id'] ?? null) : $item;
            if (! is_numeric($candidate)) {
                continue;
            }

            $id = (int) $candidate;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Build weighted merge config if weighted priority options are present.
     */
    private function buildMergeWeightedConfig(array $config): ?array
    {
        $priorityAttributes = $this->normalizePriorityAttributes($config['priority_attributes'] ?? []);
        $priorityKeywords = collect($config['priority_keywords'] ?? [])
            ->filter(fn ($keyword) => is_string($keyword) && trim($keyword) !== '')
            ->values()
            ->toArray();
        $groupPriorities = collect($config['group_priorities'] ?? [])
            ->filter(function ($item) {
                return is_array($item)
                    && isset($item['group_id'], $item['weight'])
                    && is_numeric($item['group_id'])
                    && is_numeric($item['weight']);
            })
            ->map(fn ($item) => [
                'group_id' => (int) $item['group_id'],
                'weight' => (int) $item['weight'],
            ])
            ->values()
            ->toArray();

        $preferCodec = in_array(($config['prefer_codec'] ?? null), ['hevc', 'h264'], true)
            ? $config['prefer_codec']
            : null;

        $hasWeightedOptions = ! empty($priorityAttributes)
            || ! empty($groupPriorities)
            || ! empty($priorityKeywords)
            || $preferCodec !== null
            || ($config['exclude_disabled_groups'] ?? false);

        if (! $hasWeightedOptions) {
            return null;
        }

        return [
            'priority_attributes' => $priorityAttributes,
            'group_priorities' => $groupPriorities,
            'priority_keywords' => $priorityKeywords,
            'prefer_codec' => $preferCodec,
            'exclude_disabled_groups' => $config['exclude_disabled_groups'] ?? false,
        ];
    }

    /**
     * Normalize merge payload before validation.
     */
    private function normalizeMergePayload(Request $request): void
    {
        $payload = $request->all();
        if (! isset($payload['priority_attributes']) || ! is_array($payload['priority_attributes'])) {
            return;
        }

        $request->merge([
            'priority_attributes' => $this->normalizePriorityAttributes($payload['priority_attributes']),
        ]);
    }

    /**
     * Normalize priority attribute input to string array.
     *
     * @param  array<int, mixed>  $items
     * @return array<int, string>
     */
    private function normalizePriorityAttributes(array $items): array
    {
        $allowed = [
            'playlist_priority',
            'group_priority',
            'catchup_support',
            'resolution',
            'codec',
            'keyword_match',
        ];
        $allowedLookup = array_flip($allowed);

        $normalized = [];
        foreach ($items as $item) {
            $candidate = is_array($item) ? ($item['attribute'] ?? null) : $item;
            if (! is_string($candidate)) {
                continue;
            }

            $candidate = trim($candidate);
            if ($candidate !== '' && isset($allowedLookup[$candidate])) {
                $normalized[] = $candidate;
            }
        }

        return array_values(array_unique($normalized));
    }
}
