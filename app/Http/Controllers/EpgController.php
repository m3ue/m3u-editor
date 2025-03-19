<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessEpgImport;
use App\Models\Epg;
use Illuminate\Http\Request;

class EpgController extends Controller
{
    /**
     * Sync the selected EPG.
     *
     * Use the `epg` parameter to select the EPG to refresh.
     * You can find the EPG ID by looking at the ID column when viewing the EPG table.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Models\Playlist $epg
     *
     * @return \Illuminate\Http\JsonResponse
     * @response array{message: "Response"}
     */
    public function refreshEpg(Request $request, Epg $epg)
    {
        $request->validate([
            // If true, will force a refresh of the EPG, ignoring any scheduling. Default is true.
            'force' => 'boolean',
        ]);
        if ($request->user()->id !== $epg->user_id) {
            return response()->json([
                'message' => 'Unauthorized',
            ])->setStatusCode(403);
        }

        // Refresh the EPG
        // Refresh the playlist
        dispatch(new ProcessEpgImport($epg, $request->force ?? true));

        return response()->json([
            'message' => "EPG \"{$epg->name}\" is currently being synced...",
        ]);
    }
}
