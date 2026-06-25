<?php

namespace App\Filament\Pages;

use App\Services\TmdbService;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

class ActorFilmography extends Page
{
    protected string $view = 'filament.pages.actor-filmography';

    public int $personId = 0;

    public string $name = '';

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

        $service = app(TmdbService::class);

        if ($this->personId <= 0 && $this->name !== '') {
            $this->personId = (int) $service->searchPersonIdByName($this->name);
        }

        if ($this->personId <= 0) {
            return;
        }

        $this->person = $service->getPersonDetails($this->personId);
        $this->filmography = $service->getPersonCombinedCredits($this->personId);
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

        $title = null;
        foreach ($this->filmography as $item) {
            if ((int) ($item['tmdb_id'] ?? 0) === $tmdbId) {
                $title = $item['title'] ?? null;
                break;
            }
        }

        $this->dispatch('request-from-discover', tmdbId: $tmdbId, mediaType: $mediaType, title: $title);
    }
}
