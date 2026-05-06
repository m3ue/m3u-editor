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

    /**
     * Per-request memoized lookup so a single render cycle (which calls
     * `getPollingInterval()`, `getTimeline()`, and `getViewRunUrl()` in turn)
     * only hits the database once instead of three times.
     */
    private ?SyncRun $cachedLatestRun = null;

    private bool $latestRunLoaded = false;

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
        if ($this->latestRunLoaded) {
            return $this->cachedLatestRun;
        }

        $this->latestRunLoaded = true;

        if (! $this->record instanceof Playlist) {
            return $this->cachedLatestRun = null;
        }

        return $this->cachedLatestRun = $this->record->syncRuns()->latest('id')->first();
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
