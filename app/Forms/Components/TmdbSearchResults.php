<?php

namespace App\Forms\Components;

use Closure;
use Filament\Forms\Components\Field;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TmdbSearchResults extends Field
{
    protected string $view = 'forms.components.tmdb-search-results';

    protected string|Closure $type = 'tv';

    /**
     * Sibling field that a clicked result's TMDB id is written into. When
     * unset, clicking a result calls the host's applyTmdbSelection() instead.
     */
    protected ?string $selectInto = null;

    public function selectInto(string $field): static
    {
        $this->selectInto = $field;

        return $this;
    }

    public function getSelectStatePath(): ?string
    {
        if (! $this->selectInto) {
            return null;
        }

        return Str::contains($this->getStatePath(), '.')
            ? Str::beforeLast($this->getStatePath(), '.').'.'.$this->selectInto
            : $this->selectInto;
    }

    public function getSelectedId(): ?int
    {
        $statePath = $this->getSelectStatePath();
        $selected = $statePath ? data_get($this->getLivewire(), $statePath) : null;

        return filled($selected) ? (int) $selected : null;
    }

    public function type(string|Closure $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getType(): string
    {
        return $this->evaluate($this->type);
    }

    public function getResults(): array
    {
        return $this->getState() ?? [];
    }

    public function getRecordId(): ?int
    {
        try {
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

            // Fallback: try getting from Livewire component's mounted action data
            $livewire = $this->getLivewire();
            $mountedActions = $livewire->mountedActions ?? [];
            $lastActionIndex = array_key_last($mountedActions);
            if ($lastActionIndex !== null) {
                $actionData = $mountedActions[$lastActionIndex]['data'] ?? [];
                if (! empty($actionData['series_id'])) {
                    return (int) $actionData['series_id'];
                }
                if (! empty($actionData['vod_id'])) {
                    return (int) $actionData['vod_id'];
                }
            }

            Log::warning('TmdbSearchResults: Could not find record ID', [
                'container_key' => $container->getKey(),
                'type' => $this->type,
            ]);
        } catch (\Throwable $e) {
            Log::error('TmdbSearchResults: Error getting record ID', [
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }
}
