<?php

namespace App\Filament\Resources\Playlists\Widgets;

use App\Filament\Resources\SyncRuns\SyncRunResource;
use App\Models\Playlist;
use App\Models\SyncRun;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;

class LatestSyncRun extends Widget
{
    protected string $view = 'filament.resources.playlist-resource.widgets.latest-sync-run';

    public ?Model $record = null;

    public function getColumnSpan(): int|string|array
    {
        return 'full';
    }

    /**
     * Polling interval; null disables polling when no active run exists.
     */
    public function getPollingInterval(): ?string
    {
        return $this->getLatestRun()?->isFinished() === false ? '2s' : null;
    }

    public function getLatestRun(): ?SyncRun
    {
        if (! $this->record instanceof Playlist) {
            return null;
        }

        return $this->record->syncRuns()->latest('id')->first();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getTimeline(): array
    {
        $run = $this->getLatestRun();
        if (! $run) {
            return [];
        }

        return SyncRunResource::buildPhaseTimeline($run);
    }

    public function getViewRunUrl(): ?string
    {
        $run = $this->getLatestRun();

        return $run ? SyncRunResource::getUrl('view', ['record' => $run]) : null;
    }
}
