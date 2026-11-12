<?php

namespace App\Services;

use App\Facades\PlaylistFacade;
use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\PlaylistAuth;

/**
 * Resolves Xtream credentials to the one playlist they unlock.
 *
 * /player_api.php and the plugin API both authenticate through this class, so
 * the rules (PlaylistAuth expiry and enabled state, alias expiry, owner default
 * login, disabled Xtream output) cannot drift between the two surfaces.
 */
class XtreamLoginAuthenticator
{
    /**
     * Authenticate exactly as /player_api.php does.
     *
     * @return array|bool The PlaylistService::authenticate() result, with the playlist set to null
     *                    when its Xtream output is disabled, or false when the credentials are blank.
     */
    public function authenticate(mixed $username, mixed $password): array|bool
    {
        if (! is_string($username) || ! is_string($password)) {
            return false;
        }

        $result = PlaylistFacade::authenticate($username, $password);

        if (is_array($result) && $result[0] !== null && ! $result[0]->xapi_enabled) {
            $result[0] = null;
        }

        return $result;
    }

    /**
     * The enabled, unexpired PlaylistAuth behind a 'playlist_auth' login.
     */
    public function activePlaylistAuth(string $username, string $password): ?PlaylistAuth
    {
        return PlaylistAuth::where('username', $username)
            ->where('password', $password)
            ->where('enabled', true)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->first();
    }

    /**
     * Resolve a login to its playlist scope, or null when the login is rejected.
     *
     * @return array{playlist: Playlist|CustomPlaylist|MergedPlaylist|PlaylistAlias, auth_method: string, playlist_auth: ?PlaylistAuth}|null
     */
    public function login(mixed $username, mixed $password): ?array
    {
        $result = $this->authenticate($username, $password);
        if (! is_array($result) || $result[0] === null || $result[1] === 'none') {
            return null;
        }

        $playlistAuth = null;
        if ($result[1] === 'playlist_auth') {
            $playlistAuth = $this->activePlaylistAuth($username, $password);
            if (! $playlistAuth) {
                return null;
            }
        }

        return [
            'playlist' => $result[0],
            'auth_method' => $result[1],
            'playlist_auth' => $playlistAuth,
        ];
    }
}
