<?php

namespace App\Services;

use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\PlaylistAuth;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Shared Xtream credential -> playlist resolver.
 *
 * Mirrors the two-step pattern used by CachedContentStreamController
 * and DvrStreamController (and a near-identical pass in
 * XtreamStreamController, which is intentionally NOT migrated here -
 * see open questions for the differences):
 *
 *  1. PlaylistAuth credentials (guest credential)
 *  2. password = playlist UUID, username = playlist owner's name
 *
 * The resolved playlist is one of Playlist / CustomPlaylist / MergedPlaylist /
 * PlaylistAlias; the caller decides which types apply to its endpoint.
 * Returning null means neither step matched.
 */
class PlaylistCredentialResolver
{
    /**
     * Resolve a playlist using Xtream credentials. Returns the playlist
     * (Playlist / CustomPlaylist / MergedPlaylist / PlaylistAlias) or null
     * when neither credential step matched.
     */
    public function resolve(string $username, string $password): ?Model
    {
        $playlistAuth = $this->resolveAuth($username, $password);

        if ($playlistAuth) {
            $playlist = $playlistAuth->getAssignedModel();
            if ($playlist) {
                return $playlist;
            }
        }

        return $this->resolveByUuid($password, $username);
    }

    /**
     * Locate the PlaylistAuth row matching the supplied credentials.
     * Returns null when no auth matches, the auth is disabled, or it
     * has expired.
     */
    public function resolveAuth(string $username, string $password): ?PlaylistAuth
    {
        $playlistAuth = PlaylistAuth::where('username', $username)
            ->where('password', $password)
            ->where('enabled', true)
            ->first();

        if ($playlistAuth && ! $playlistAuth->isExpired()) {
            return $playlistAuth;
        }

        return null;
    }

    /**
     * Locate a playlist by UUID when the password is its UUID and the
     * supplied username matches the playlist owner's name. Searches all
     * four playlist tables because they share independent autoincrement
     * sequences but the UUID is the public credential.
     *
     * @return Model|null Playlist | CustomPlaylist | MergedPlaylist | PlaylistAlias
     */
    public function resolveByUuid(string $uuid, string $username): ?Model
    {
        $playlistTypes = [
            Playlist::class,
            MergedPlaylist::class,
            CustomPlaylist::class,
            PlaylistAlias::class,
        ];

        foreach ($playlistTypes as $type) {
            try {
                $playlist = $type::with('user')->where('uuid', $uuid)->firstOrFail();

                if ($playlist->user && $playlist->user->name === $username) {
                    return $playlist;
                }
            } catch (ModelNotFoundException) {
                // try next type
            }
        }

        return null;
    }
}
