<?php

namespace App\Filament\GuestPanel\Resources\Vods\Pages;

use App\Filament\GuestPanel\Pages\Concerns\HasPlaylist;
use App\Filament\GuestPanel\Resources\Vods\VodResource;
use App\Support\TmdbRating;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewVod extends ViewRecord
{
    use HasPlaylist;

    protected static string $resource = VodResource::class;

    protected string $view = 'filament.resources.vods.pages.view-vod';

    public function getTitle(): string|Htmlable
    {
        return $this->record->title_custom ?? $this->record->title ?? $this->record->name;
    }

    public function getAuth(): ?array
    {
        return self::getCurrentAuth();
    }

    public function getSubheading(): string|Htmlable|null
    {
        $parts = [];

        $info = $this->record->info ?? [];
        $movieData = $this->record->movie_data ?? [];

        if ($this->record->year) {
            $parts[] = $this->record->year;
        } elseif (! empty($info['releasedate']) || ! empty($movieData['info']['releasedate'] ?? null)) {
            $releaseDate = $info['releasedate'] ?? $movieData['info']['releasedate'] ?? null;
            if ($releaseDate) {
                $parts[] = substr($releaseDate, 0, 4);
            }
        }

        if (! empty($info['genre']) || ! empty($movieData['info']['genre'] ?? null)) {
            $parts[] = $info['genre'] ?? $movieData['info']['genre'];
        }

        $subheadingRating = $info['rating'] ?? ($movieData['info']['rating'] ?? null);
        if (! empty($subheadingRating) && ! TmdbRating::isVoteCountBelowThreshold($info['vote_count'] ?? null)) {
            $parts[] = '★ '.$subheadingRating;
        }

        return implode(' • ', $parts) ?: null;
    }

    /**
     * Open the floating player for this VOD. Called from the detail view's
     * Play button - the payload is built server-side rather than inlined into
     * a `wire:click="$dispatch(...)"` expression, whose naive parser breaks on
     * parentheses/quotes inside the JSON (e.g. a movie title like "Movie (2009)").
     */
    public function playFloatingStream(): void
    {
        $auth = $this->getAuth();

        $this->dispatch('openFloatingStream', $this->record->getFloatingPlayerAttributes(
            username: $auth['username'] ?? null,
            password: $auth['password'] ?? null,
        ));
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('back')
                ->label(__('Back to VOD'))
                ->url(VodResource::getUrl('index'))
                ->icon('heroicon-s-arrow-left')
                ->color('gray')
                ->size('sm'),
        ];
    }
}
