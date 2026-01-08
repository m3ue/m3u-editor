<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * Get your Playlists.
     *
     * Returns an array of your Playlists, including their names and UUIDs.
     * This is useful for calling the Playlist endpoints as a UUID is required.
     *
     * @return []|\Illuminate\Http\Response
     *
     * @response array{name: "My Playlist", "uuid": "0eff7923-cbd1-4868-9fed-2e3748ac1100"}
     */
    public function playlists(Request $request)
    {
        $user = $request->user();
        if ($user) {
            return $user->playlists()->get(['name', 'uuid'])->map(function ($playlist) {
                return [
                    'name' => $playlist->name,
                    'uuid' => $playlist->uuid,
                ];
            })->toArray();
        }

        return abort(401, 'Unauthorized'); // Return 401 if user is not authenticated
    }

    /**
     * Get your EPGs.
     *
     * Returns an array of your EPGs, including their names and UUIDs.
     * This is useful for calling the EPG endpoints as a UUID is required.
     *
     * @return []|\Illuminate\Http\Response
     *
     * @response array{name: "My EPG", "uuid": "0eff7923-cbd1-4868-9fed-2e3748ac1100"}
     */
    public function epgs(Request $request)
    {
        $user = $request->user();
        if ($user) {
            return $user->epgs()->get(['name', 'uuid'])->map(function ($playlist) {
                return [
                    'name' => $playlist->name,
                    'uuid' => $playlist->uuid,
                ];
            })->toArray();
        }

        return abort(401, 'Unauthorized'); // Return 401 if user is not authenticated
    }
}
