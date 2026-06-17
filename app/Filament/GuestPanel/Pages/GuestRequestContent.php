<?php

namespace App\Filament\GuestPanel\Pages;

use App\Facades\PlaylistFacade;
use App\Filament\GuestPanel\Pages\Concerns\HasGuestAuth;
use App\Models\ArrIntegration;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;

class GuestRequestContent extends Page
{
    use HasGuestAuth;

    protected string $view = 'filament.guest-panel.pages.request-content';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-magnifying-glass-circle';

    protected static ?int $navigationSort = 7;

    public static function getNavigationLabel(): string
    {
        return __('Request Content');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Request Content');
    }

    protected static ?string $slug = 'request-content';

    /**
     * Only show the page if the guest is authenticated AND their playlist has at
     * least one enabled Sonarr/Radarr integration with guest_enabled = true.
     */
    public static function canAccess(): bool
    {
        if (! static::isSessionAuthenticated()) {
            return false;
        }

        $uuid = static::getCurrentUuid();
        if (! $uuid) {
            return false;
        }

        $playlist = PlaylistFacade::resolvePlaylistByUuid($uuid);
        if (! $playlist) {
            return false;
        }

        $playlistId = $playlist instanceof PlaylistAlias ? $playlist->playlist_id : $playlist->id;
        if (! $playlistId) {
            return false;
        }

        return ArrIntegration::query()
            ->where('playlist_id', $playlistId)
            ->enabled()
            ->guestEnabled()
            ->exists();
    }

    public static function getUrl(
        array $parameters = [],
        bool $isAbsolute = true,
        ?string $panel = null,
        $tenant = null,
        bool $shouldGuessMissingParameters = false,
        ?string $configuration = null
    ): string {
        $parameters['uuid'] = static::getCurrentUuid();

        return route(static::getRouteName($panel), $parameters, $isAbsolute);
    }

    /**
     * Resolve the first guest-enabled integration for the current playlist.
     */
    public function getIntegrationProperty(): ?ArrIntegration
    {
        $uuid = static::getCurrentUuid();
        if (! $uuid) {
            return null;
        }

        $playlist = PlaylistFacade::resolvePlaylistByUuid($uuid);
        $playlistId = $playlist instanceof PlaylistAlias ? $playlist->playlist_id : $playlist?->id;
        if (! $playlistId) {
            return null;
        }

        return ArrIntegration::query()
            ->where('playlist_id', $playlistId)
            ->enabled()
            ->guestEnabled()
            ->orderBy('name')
            ->first();
    }

    /**
     * All guest-enabled integrations for the playlist (when more than one exists,
     * a tab/select UI can be added in the future).
     *
     * @return Collection<int, ArrIntegration>
     */
    public function getIntegrationsProperty()
    {
        $uuid = static::getCurrentUuid();
        if (! $uuid) {
            return collect();
        }

        $playlist = PlaylistFacade::resolvePlaylistByUuid($uuid);
        $playlistId = $playlist instanceof PlaylistAlias ? $playlist->playlist_id : $playlist?->id;
        if (! $playlistId) {
            return collect();
        }

        return ArrIntegration::query()
            ->where('playlist_id', $playlistId)
            ->enabled()
            ->guestEnabled()
            ->orderBy('name')
            ->get();
    }
}
