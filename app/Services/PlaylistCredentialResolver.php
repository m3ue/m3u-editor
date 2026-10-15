<?php

namespace App\Services;

use App\Enums\DefaultAuthMode;
use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\PlaylistAuth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared Xtream credential -> playlist resolver.
 *
 * Mirrors the two-step pattern used by CachedContentStreamController
 * and DvrStreamController (XtreamStreamController and
 * PlaylistService::authenticate() run their own PlaylistAuth/alias
 * passes but share step 2 via resolveDefaultLogin()):
 *
 *  1. PlaylistAuth credentials (guest credential)
 *  2. The owner's default login: username = playlist owner's name, password =
 *     the playlist UUID or custom password (per the playlist's default_auth_mode),
 *     or the private internal token the app puts in its own URLs
 *
 * The resolved playlist is one of Playlist / CustomPlaylist / MergedPlaylist /
 * PlaylistAlias; the caller decides which types apply to its endpoint.
 * Returning null means neither step matched.
 */
class PlaylistCredentialResolver
{
    /**
     * Every playlist type that accepts the owner's default login. They share
     * independent autoincrement sequences, so all four tables are searched.
     *
     * @var list<class-string<Model>>
     */
    public const PLAYLIST_TYPES = [
        Playlist::class,
        MergedPlaylist::class,
        CustomPlaylist::class,
        PlaylistAlias::class,
    ];

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

        return $this->resolveDefaultLogin($username, $password);
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
     * Locate a playlist by the owner's default login: the username must match the
     * playlist owner's name, and the password must be the playlist UUID (UUID as
     * Password mode), its custom password (Custom Password mode) or its internal
     * token (any mode). Playlists with the default login disabled only match the
     * internal token.
     *
     * The SQL narrows the candidates; the final comparison happens in PHP so a
     * case-insensitive database collation can't loosen the match.
     *
     * @param  list<class-string<Model>>  $types
     * @return Model|null Playlist | CustomPlaylist | MergedPlaylist | PlaylistAlias
     */
    public function resolveDefaultLogin(string $username, string $password, array $types = self::PLAYLIST_TYPES): ?Model
    {
        if ($username === '' || $password === '') {
            return null;
        }

        $tokenUuid = self::uuidFromInternalToken($password);

        foreach ($types as $type) {
            $playlist = $type::query()
                ->with('user')
                ->where(function (Builder $query) use ($password, $tokenUuid): void {
                    $query
                        ->where(fn (Builder $uuidLogin) => $uuidLogin
                            ->where('default_auth_mode', DefaultAuthMode::Uuid)
                            ->where('uuid', $password))
                        ->orWhere(fn (Builder $customLogin) => $customLogin
                            ->where('default_auth_mode', DefaultAuthMode::Custom)
                            ->where('default_auth_password', $password));

                    if ($tokenUuid !== null) {
                        $query->orWhere('uuid', $tokenUuid);
                    }
                })
                ->get()
                ->first(fn (Model $candidate): bool => $candidate->user?->name === $username
                    && ($candidate->getDefaultAuthPassword() === $password
                        || ($tokenUuid !== null && hash_equals($candidate->getInternalAuthToken(), $password))));

            if ($playlist) {
                return $playlist;
            }
        }

        return null;
    }

    /**
     * The private password the app puts in URLs it builds for itself when the
     * playlist's UUID login is off: "{uuid}_{signature}", where the signature is an
     * APP_KEY HMAC of the UUID and the playlist's internal_auth_secret. It can't be
     * derived from the UUID alone, and rotating the secret revokes it.
     */
    public static function internalToken(string $uuid, ?string $secret): string
    {
        return $uuid.'_'.substr(hash_hmac('sha256', 'default-auth:'.$uuid.':'.$secret, (string) config('app.key')), 0, 32);
    }

    /**
     * The playlist UUID a password in internal-token format carries, or null when
     * it isn't one. The signature is checked against the playlist's own secret.
     */
    private static function uuidFromInternalToken(string $password): ?string
    {
        return preg_match('/^(.+)_[0-9a-f]{32}$/', $password, $matches) === 1 ? $matches[1] : null;
    }
}
