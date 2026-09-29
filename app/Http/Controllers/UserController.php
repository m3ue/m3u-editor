<?php

namespace App\Http\Controllers;

use App\Http\Resources\User\EpgSummaryResource;
use App\Http\Resources\User\PlaylistSummaryResource;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('User', 'Look up the UUIDs of your playlists and EPGs, which the other endpoints take.', weight: 10)]
class UserController extends Controller
{
    /**
     * Get your Playlists.
     *
     * Returns an array of your Playlists, Custom Playlists, Merged Playlists, and Playlist
     * Aliases with detailed information including channel counts and proxy settings. This is
     * useful for calling the Playlist/Custom Playlist/Merged Playlist/Playlist Alias endpoints
     * as a UUID is required. Use `type` (`playlist`, `custom_playlist`, `merged_playlist`, or
     * `playlist_alias`) to tell them apart. `last_sync`/`status`/`source_type` are only
     * meaningful for standard playlists and are always `null` on the other types, since they
     * don't sync from a source of their own.
     *
     * @response PlaylistSummaryResource[]
     */
    public function playlists(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return abort(401, 'Unauthorized'); // Return 401 if user is not authenticated
        }

        $playlists = $user->playlists()
            ->withCount($this->channelCountScopes('groups'))
            ->get();

        $customPlaylists = $user->customPlaylists()
            ->withCount($this->channelCountScopes('groupTags as groups_count'))
            ->get();

        $mergedPlaylists = $user->mergedPlaylists()
            ->withCount($this->channelCountScopes('groups'))
            ->get();

        // Playlist Aliases resolve their channels/groups relations dynamically based on which
        // source playlist type they point to (Playlist, CustomPlaylist, or MergedPlaylist), so
        // withCount() can't build a single subquery for the whole collection here the way it
        // can for the other playlist types above; PlaylistSummaryResource queries each alias's
        // counts individually.
        $playlistAliases = $user->playlistAliases()->get();

        return response()->json(PlaylistSummaryResource::collection(
            $playlists->toBase()
                ->concat($customPlaylists)
                ->concat($mergedPlaylists)
                ->concat($playlistAliases)
                ->values()
        ));
    }

    /**
     * Build the withCount() relation scopes shared by Playlists, Custom Playlists, and Merged
     * Playlists. Only the groups relation/alias differs between them.
     *
     * @return array<int|string, string|\Closure>
     */
    private function channelCountScopes(string $groupsScope): array
    {
        return [
            'channels',
            'channels as enabled_channels_count' => function ($query) {
                $query->where('enabled', true);
            },
            'channels as live_channels_count' => function ($query) {
                $query->where('is_vod', false);
            },
            'channels as vod_channels_count' => function ($query) {
                $query->where('is_vod', true);
            },
            $groupsScope,
        ];
    }

    /**
     * Get your EPGs.
     *
     * Returns an array of your EPGs with detailed information including channel counts
     * and sync status. This is useful for calling the EPG endpoints as a UUID is required.
     *
     * @response EpgSummaryResource[]
     */
    public function epgs(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user) {
            return response()->json(EpgSummaryResource::collection(
                $user->epgs()->withCount('channels')->get()
            ));
        }

        return abort(401, 'Unauthorized'); // Return 401 if user is not authenticated
    }
}
