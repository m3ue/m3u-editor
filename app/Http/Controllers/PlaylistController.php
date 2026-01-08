<?php

namespace App\Http\Controllers;

use App\Facades\PlaylistFacade;
use App\Jobs\ProcessM3uImport;
use App\Models\Playlist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}
