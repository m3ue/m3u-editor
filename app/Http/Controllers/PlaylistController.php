<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessM3uImport;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use Illuminate\Http\Request;

class PlaylistController extends Controller
{
    /**
     * Sync the selected Playlist.
     *
     * Use the `playlist` parameter to select the playlist to refresh.
     * You can find the playlist ID by looking at the ID column when viewing the playlist table.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Models\Playlist $playlist
     *
     * @return \Illuminate\Http\JsonResponse
     * @response array{message: "Response"}
     */
    public function refreshPlaylist(Request $request, Playlist $playlist)
    {
        $request->validate([
            // If true, will force a refresh of the EPG, ignoring any scheduling. Default is true.
            'force' => 'boolean',
        ]);
        if ($request->user()->id !== $playlist->user_id) {
            return response()->json([
                'message' => 'Unauthorized',
            ])->setStatusCode(403);
        }

        // Refresh the playlist
        dispatch(new ProcessM3uImport($playlist, $request->force ?? true));

        return response()->json([
            'message' => "Playlist \"{$playlist->name}\" is currently being synced...",
        ]);
    }
}
