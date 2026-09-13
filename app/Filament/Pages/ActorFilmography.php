<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\FiltersFilmographyByPlaylist;
use App\Filament\Resources\Series\SeriesResource;
use App\Filament\Resources\Vods\VodResource;
use App\Models\Playlist;
use App\Services\TmdbService;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

class ActorFilmography extends Page
{
    use FiltersFilmographyByPlaylist;

    protected string $view = 'filament.pages.actor-filmography';

    protected static bool $shouldRegisterNavigation = false;

    public int $personId = 0;

    public string $name = '';

    /** When > 0, only show filmography items that exist as Series/Vod in this playlist (after ownership check). */
    public int $playlistId = 0;

    public ?array $person = null;

    public array $filmography = [];

    public static function getNavigationLabel(): string
    {
        return __('Actor Filmography');
    }

    public function getTitle(): string|Htmlable
    {
        return $this->name !== '' ? $this->name : __('Actor Filmography');
    }

    public function mount(): void
    {
        // Filament's getUrl() generates query-string params, but they aren't
        // auto-bound to public properties on plain Pages. Read them explicitly.
        $this->personId = (int) (request()->query('personId', $this->personId));
        $this->name = (string) (request()->query('name', $this->name));
        $this->playlistId = (int) (request()->query('playlistId', $this->playlistId));

        $service = app(TmdbService::class);

        if ($this->personId <= 0 && $this->name !== '') {
            $this->personId = (int) $service->searchPersonIdByName($this->name);
        }

        if ($this->personId <= 0) {
            return;
        }

        $this->person = $service->getPersonDetails($this->personId);
        $this->filmography = $service->getPersonCombinedCredits($this->personId);
        $this->filmography = $this->filterFilmographyToPlaylist($this->filmography);
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public function openFilmographyItem(int $tmdbId, string $mediaType): void
    {
        if ($tmdbId <= 0) {
            return;
        }

        $url = $this->resolveLocalItemUrl($tmdbId, $mediaType);
        if ($url) {
            $this->redirect($url);

            return;
        }
        // No local match (or no playlist scope) — fall through to existing ArrSearch
        // dispatch so the item is still reachable via the ArrIntegration request flow.
        $title = null;
        foreach ($this->filmography as $item) {
            if ((int) ($item['tmdb_id'] ?? 0) === $tmdbId) {
                $title = $item['title'] ?? null;
                break;
            }
        }

        $this->dispatch('request-from-discover', tmdbId: $tmdbId, mediaType: $mediaType, title: $title);
    }

    /**
     * Validate the playlist_id param against the authenticated user. Admins
     * can scope to any playlist; non-admins only to their own. Returns 0 when
     * ownership fails or the playlist doesn't exist — in which case the trait
     * falls back to global TMDB filmography (the safe default).
     */
    protected function filmographyPlaylistId(): int
    {
        if ($this->playlistId <= 0) {
            return 0;
        }

        $playlist = Playlist::find($this->playlistId);
        if (! $playlist) {
            return 0;
        }

        $user = auth()->user();
        if (! $user) {
            return 0;
        }

        $isAdmin = method_exists($user, 'isAdmin') && $user->isAdmin();

        return $isAdmin || (int) $playlist->user_id === (int) $user->id
            ? $this->playlistId
            : 0;
    }

    protected function filmographySeriesResource(): string
    {
        return SeriesResource::class;
    }

    protected function filmographyVodResource(): string
    {
        return VodResource::class;
    }
}
