<?php

namespace App\Services;

use App\Events\TvClientConfigUpdatedEvent;
use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\PlaylistAuth;
use App\Models\TvClientProfile;
use App\Models\TvDevice;
use App\Support\TvClientSettings;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resolves which TV Client Profile applies to an Xtream login and builds the
 * `client_config` payload the M3U TV app reads.
 *
 * A Playlist Auth's own profile wins; every other login (the owner's own,
 * aliases, auths without a profile) gets the owner's default profile, if any.
 */
class TvClientConfigService
{
    public const ACTION = 'get_client_config';

    /**
     * @var array<int, class-string>
     */
    private const NOTIFIABLE_TYPES = [
        Playlist::class,
        CustomPlaylist::class,
        MergedPlaylist::class,
        PlaylistAlias::class,
    ];

    public function resolveProfile(mixed $playlist, string $authMethod, ?PlaylistAuth $playlistAuth): ?TvClientProfile
    {
        $usesPlaylistAuth = $authMethod === 'playlist_auth' && $playlistAuth !== null;

        if ($usesPlaylistAuth && $playlistAuth->tvClientProfile !== null) {
            return $playlistAuth->tvClientProfile;
        }

        $ownerId = $usesPlaylistAuth ? $playlistAuth->user_id : ($playlist->user_id ?? null);

        if ($ownerId === null) {
            return null;
        }

        return TvClientProfile::query()
            ->where('user_id', $ownerId)
            ->where('is_default', true)
            ->first();
    }

    /**
     * @return array{api_version: int, profile: string|null, settings: array<string, array{mode: string, value: string|int|bool}>|object, revision: string}
     */
    public function payload(?TvClientProfile $profile): array
    {
        $settings = $profile?->managedSettings() ?? [];

        return [
            'api_version' => TvClientSettings::API_VERSION,
            'profile' => $profile?->name,
            // An empty map still encodes as a JSON object.
            'settings' => $settings === [] ? (object) [] : $settings,
            'revision' => $this->revision($settings),
        ];
    }

    /**
     * The `m3u_editor.client_config` capability block for the handshake. The
     * app compares `revision` with its cached copy and only fetches the full
     * config through `action` when it changed.
     *
     * @return array{api_version: int, revision: string, action: string}
     */
    public function capability(?TvClientProfile $profile): array
    {
        return [
            'api_version' => TvClientSettings::API_VERSION,
            'revision' => $this->revision($profile?->managedSettings() ?? []),
            'action' => self::ACTION,
        ];
    }

    /**
     * @param  array<string, array{mode: string, value: string|int|bool}>  $settings
     */
    public function revision(array $settings): string
    {
        ksort($settings);

        return substr(hash('sha256', json_encode($settings, JSON_THROW_ON_ERROR)), 0, 16);
    }

    /**
     * Tells every device the profile applies to (directly or as the owner's
     * default) to refetch its config.
     */
    public function notifyProfileChanged(TvClientProfile $profile): void
    {
        $authIds = $profile->playlistAuths()->pluck('id')->all();

        $devices = TvDevice::query()
            ->whereNull('revoked_at')
            ->where(function (Builder $query) use ($profile, $authIds): void {
                $query->whereIn('playlist_auth_id', $authIds);

                if ($profile->is_default || $profile->getOriginal('is_default')) {
                    $query->orWhere(fn (Builder $query) => $this->scopeDefaultProfileDevices($query, $profile->user_id));
                }
            });

        $this->broadcastTo($devices);
    }

    /**
     * Tells the devices signed in with this auth that their profile changed.
     */
    public function notifyPlaylistAuthChanged(PlaylistAuth $playlistAuth): void
    {
        $this->broadcastTo(
            TvDevice::query()
                ->whereNull('revoked_at')
                ->where('playlist_auth_id', $playlistAuth->id)
        );
    }

    /**
     * Devices on the owner's playlists signed in without an auth, or with an
     * auth that has no profile of its own.
     */
    private function scopeDefaultProfileDevices(Builder $query, int $ownerId): void
    {
        $query
            ->whereHasMorph('notifiable', self::NOTIFIABLE_TYPES, fn (Builder $query) => $query->where('user_id', $ownerId))
            ->where(function (Builder $query): void {
                $query->whereNull('playlist_auth_id')
                    ->orWhereHas('playlistAuth', fn (Builder $query) => $query->whereNull('tv_client_profile_id'));
            });
    }

    private function broadcastTo(Builder $devices): void
    {
        $channels = [];

        // lazy() rather than cursor(): cursor() can't eager load the notifiable.
        foreach ($devices->with('notifiable')->lazy() as $device) {
            $notifiable = $device->notifiable;

            if ($notifiable === null || empty($notifiable->uuid)) {
                continue;
            }

            $type = $notifiable->getMorphClass();
            $uuid = $notifiable->uuid;

            if ($device->playlist_auth_id !== null) {
                $channels["tv.{$type}.{$uuid}.{$device->playlist_auth_id}"] = true;
            } else {
                $channels["tv.{$type}.{$uuid}"] = true;
                $channels["tv.{$type}-admin.{$uuid}"] = true;
            }
        }

        if ($channels !== []) {
            event(new TvClientConfigUpdatedEvent(array_keys($channels)));
        }
    }
}
