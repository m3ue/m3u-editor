<?php

namespace App\Forms\Components;

use Filament\Forms\Components\Field;

class TmdbSearchResults extends Field
{
    protected string $view = 'forms.components.tmdb-search-results';

    protected string $type = 'tv';

    public function type(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getResults(): array
    {
        return $this->getState() ?? [];
    }

    public function getRecordId(): ?int
    {
        // Get the ID from the sibling hidden field via the container's state
        $container = $this->getContainer();

        // Try series_id first, then vod_id
        $seriesId = $container->getComponent('series_id')?->getState();
        if ($seriesId) {
            return (int) $seriesId;
        }

        $vodId = $container->getComponent('vod_id')?->getState();
        if ($vodId) {
            return (int) $vodId;
        }

        return null;
    }
}
