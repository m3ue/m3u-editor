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
use App\Models\TvHomeScreen;
use App\Support\TvClientSettings;
use App\Support\TvHomeScreenLayout;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

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
     * The Home screen of the playlist the login resolved to. Every login to
     * that playlist gets it: the owner's, aliases' (an Alias is its own
     * playlist) and Playlist Auths assigned to it.
     */
    public function resolveHomeScreen(mixed $playlist): ?TvHomeScreen
    {
        return $playlist instanceof Model ? TvHomeScreen::forPlaylist($playlist) : null;
    }

    /**
     * [$rowKinds] are the Home row kinds the app can draw (its `row_kinds`
     * parameter); rows of other kinds are left out.
     *
     * @param  list<string>  $rowKinds
     * @return array{api_version: int, profile: string|null, settings: array<string, array{mode: string, value: string|int|bool}>|object, home_screen: array{mode: string, sections: list<array<string, mixed>>}|null, revision: string}
     */
    public function payload(
        ?TvClientProfile $profile,
        ?TvHomeScreen $homeScreen = null,
        array $rowKinds = TvHomeScreenLayout::BASELINE_ROW_KINDS,
    ): array {
        $settings = $profile?->managedSettings() ?? [];
        $homeScreenPayload = $homeScreen === null ? null : [
            'mode' => $homeScreen->mode,
            'sections' => TvHomeScreenLayout::forRowKinds($homeScreen->layout(), $rowKinds),
        ];

        return [
            'api_version' => TvClientSettings::API_VERSION,
            'profile' => $profile?->name,
            // An empty map still encodes as a JSON object.
            'settings' => $settings === [] ? (object) [] : $settings,
            'home_screen' => $homeScreenPayload,
            'revision' => $this->revision($settings, $homeScreen),
        ];
    }

    /**
     * The `row_kinds` an app sent, always including the built-in row.
     *
     * @return list<string>
     */
    public function rowKindsFrom(?string $rowKinds): array
    {
        $kinds = array_filter(array_map('trim', explode(',', (string) $rowKinds)));

        return array_values(array_unique([...TvHomeScreenLayout::BASELINE_ROW_KINDS, ...$kinds]));
    }

    /**
     * The `m3u_editor.client_config` capability block for the handshake. The
     * app compares `revision` with its cached copy and only fetches the full
     * config through `action` when it changed.
     *
     * @return array{api_version: int, revision: string, action: string}
     */
    public function capability(?TvClientProfile $profile, ?TvHomeScreen $homeScreen = null): array
    {
        return [
            'api_version' => TvClientSettings::API_VERSION,
            'revision' => $this->revision($profile?->managedSettings() ?? [], $homeScreen),
            'action' => self::ACTION,
        ];
    }

    /**
     * Covers the settings and, when there is one, the whole Home screen (all
     * row kinds), so any change to either reaches the app.
     *
     * @param  array<string, array{mode: string, value: string|int|bool}>  $settings
     */
    public function revision(array $settings, ?TvHomeScreen $homeScreen = null): string
    {
        ksort($settings);

        // Without a Home screen this hashes exactly what earlier versions did,
        // so existing installs don't refetch an unchanged config.
        $content = $homeScreen === null ? $settings : [
            'settings' => $settings,
            'home_screen' => ['mode' => $homeScreen->mode, 'sections' => $homeScreen->layout()],
        ];

        return substr(hash('sha256', json_encode($content, JSON_THROW_ON_ERROR)), 0, 16);
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
        $this->notifyPlaylistAuths([$playlistAuth->id]);
    }

    /**
     * Tells the devices signed in with these auths to refetch their config.
     *
     * @param  array<int, int>  $playlistAuthIds
     */
    public function notifyPlaylistAuths(array $playlistAuthIds): void
    {
        if ($playlistAuthIds === []) {
            return;
        }

        $this->broadcastTo(
            TvDevice::query()
                ->whereNull('revoked_at')
                ->whereIn('playlist_auth_id', $playlistAuthIds)
        );
    }

    /**
     * Tells every device signed in to these playlists, with any login, to
     * refetch its config.
     *
     * @param  list<array{0: class-string<Model>, 1: int}>  $playlists  [model class, id] pairs
     */
    public function notifyPlaylists(array $playlists): void
    {
        if ($playlists === []) {
            return;
        }

        $this->broadcastTo(
            TvDevice::query()
                ->whereNull('revoked_at')
                ->where(function (Builder $query) use ($playlists): void {
                    foreach ($playlists as [$model, $id]) {
                        $query->orWhere(fn (Builder $query) => $query
                            ->where('notifiable_type', (new $model)->getMorphClass())
                            ->where('notifiable_id', $id));
                    }
                })
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
