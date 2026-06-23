<?php

namespace App\Filament\GuestPanel\Pages;

use App\Filament\GuestPanel\Pages\Concerns\HasGuestAuth;
use App\Services\TmdbService;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

class GuestActorFilmography extends Page
{
    use HasGuestAuth;

    protected string $view = 'filament.guest-panel.pages.actor-filmography';

    public int $personId = 0;

    public string $name = '';

    public ?array $person = null;

    public array $filmography = [];

    public function getTitle(): string|Htmlable
    {
        return $this->name !== '' ? $this->name : __('Actor Filmography');
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

        return parent::getUrl($parameters, $isAbsolute, $panel, $tenant, $shouldGuessMissingParameters, $configuration);
    }

    public static function canAccess(): bool
    {
        if (! static::isSessionAuthenticated()) {
            return false;
        }

        return static::getCurrentUuid() !== null;
    }

    public function mount(): void
    {
        if ($this->personId <= 0) {
            return;
        }

        $service = app(TmdbService::class);

        $this->person = $service->getPersonDetails($this->personId);
        $this->filmography = $service->getPersonCombinedCredits($this->personId);
    }
}
