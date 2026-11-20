<?php

namespace App\Filament\Concerns;

use App\Enums\Status;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;

/**
 * Keeps a list page in step with syncs that run in the background (scheduled
 * auto syncs, queued job chains) without polling the full table query.
 *
 * A hidden poller in the page footer asks for a cheap signature of the page's
 * records (row count, active count and latest `updated_at`). The page only
 * re-renders, and so only re-queries the table, when that signature changes.
 * It polls quickly while any record is syncing and slowly otherwise, so a sync
 * started elsewhere is picked up, and a brief status flip between job stages
 * (e.g. EPG import completing before cache generation starts) can't stop it.
 *
 * Intended for Filament `ListRecords` pages.
 *
 * @method static class-string<\Filament\Resources\Resource> getResource()
 */
trait RefreshesOnSyncActivity
{
    #[Locked]
    public ?string $syncActivitySignature = null;

    #[Locked]
    public bool $hasActiveSyncs = false;

    public function mountRefreshesOnSyncActivity(): void
    {
        $this->syncActivitySignature = $this->computeSyncActivitySignature();
    }

    /**
     * Polled from the page footer. Skips the render (and the table query)
     * unless something about the page's records has changed.
     */
    public function checkSyncActivity(): void
    {
        $signature = $this->computeSyncActivitySignature();

        if ($signature === $this->syncActivitySignature) {
            $this->skipRender();

            return;
        }

        $this->syncActivitySignature = $signature;
    }

    public function getSyncActivityPollInterval(): string
    {
        return $this->hasActiveSyncs ? '3s' : '10s';
    }

    public function getFooter(): ?View
    {
        return view('filament.partials.sync-activity-poller', [
            'interval' => $this->getSyncActivityPollInterval(),
        ]);
    }

    /**
     * The records whose sync activity this page tracks. Kept separate from the
     * table query so the poll never pays for the table's counts and eager loads.
     */
    protected function getSyncActivityQuery(): Builder
    {
        return static::getResource()::getEloquentQuery()
            ->where('user_id', auth()->id());
    }

    protected function scopeActiveSyncs(Builder $query): Builder
    {
        return $query->whereIn('status', [Status::Processing, Status::Pending]);
    }

    protected function computeSyncActivitySignature(): string
    {
        $query = $this->getSyncActivityQuery()->reorder();
        $updatedAtColumn = $query->getModel()->getQualifiedUpdatedAtColumn();

        $this->hasActiveSyncs = $this->scopeActiveSyncs(clone $query)->exists();

        return implode('|', [
            (clone $query)->count(),
            (clone $query)->max($updatedAtColumn) ?? '',
            (int) $this->hasActiveSyncs,
        ]);
    }
}
