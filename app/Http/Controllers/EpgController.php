<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessEpgImport;
use App\Models\Epg;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EpgController extends Controller
{
    /**
     * Sync the selected EPG.
     *
     * Use the `uuid` parameter to select the EPG to refresh.
     * You can find the EPG UUID by using the `User > Get your EPGs` endpoint.
     *
     *
     * @return JsonResponse
     *
     * @unauthenticated
     *
     * @response array{message: "EPG is currently being synced..."}
     */
    public function refreshEpg(Request $request, string $uuid)
    {
        $request->validate([
            // If true, will force a refresh of the EPG, ignoring any scheduling. Default is true.
            'force' => 'boolean',
        ]);

        // Fetch the EPG
        $epg = Epg::where('uuid', $uuid)->firstOrFail();

        // Refresh the EPG
        // Refresh the playlist
        dispatch(new ProcessEpgImport($epg, $request->force ?? true));

        return response()->json([
            'message' => "EPG \"{$epg->name}\" is currently being synced...",
        ]);
    }
}
