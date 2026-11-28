<?php

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reorders a table by shuffling the sort values the dragged records already
 * hold, instead of Filament's default of renumbering them 1..N.
 *
 * Filament only sends the keys of the records on screen, so with
 * `paginatedWhileReordering()` its default would give page 3 the same sort
 * values as page 1. Reusing the existing slots keeps every other record (other
 * pages, other groups in the same playlist) exactly where it was.
 *
 * For direct order columns (e.g. `channels.sort`). Pivot sort columns on custom
 * playlists use ReordersCustomPlaylistPivotSort instead.
 */
trait PreservesSortSlotsOnReorder
{
    /**
     * @param  array<int|string>  $order
     */
    public function reorderTable(array $order, int|string|null $draggedRecordKey = null): void
    {
        $table = $this->getTable();

        if (! $table->isReorderable()) {
            return;
        }

        $table->callBeforeReordering($order);

        $orderColumn = (string) str($table->getReorderColumn())->afterLast('.');
        $recordKeys = array_values($order);
        $slots = $this->getReorderSortSlots($recordKeys, $orderColumn);

        // Filament treats the first key as the highest value for a descending sort.
        if ($table->getReorderDirection() === 'desc') {
            $recordKeys = array_reverse($recordKeys);
        }

        DB::transaction(function () use ($table, $recordKeys, $slots, $orderColumn): void {
            foreach ($recordKeys as $index => $recordKey) {
                $table->getQuery()
                    ->whereKey($recordKey)
                    ->update([$orderColumn => $slots->get($index)]);
            }
        });

        $this->flushCachedTableRecords();

        $table->callAfterReordering($order);
    }

    /**
     * The ascending sort values currently held by the given records. Missing or
     * duplicate values are replaced with consecutive values after the lowest
     * one, so every record still gets a distinct position.
     *
     * @param  array<int|string>  $recordKeys
     * @return Collection<int, float>
     */
    private function getReorderSortSlots(array $recordKeys, string $orderColumn): Collection
    {
        /** @var Model $model */
        $model = app($this->getTable()->getModel());

        $slots = $this->getTable()->getQuery()
            ->whereKey($recordKeys)
            ->toBase()
            ->pluck($model->qualifyColumn($orderColumn))
            ->filter(fn ($slot): bool => $slot !== null && $slot !== '')
            ->map(fn ($slot): float => (float) $slot)
            ->sort()
            ->values();

        if ($slots->count() === count($recordKeys) && $slots->unique()->count() === $slots->count()) {
            return $slots;
        }

        $firstSlot = $slots->isEmpty() ? 1.0 : (float) $slots->first();

        return collect(range(0, count($recordKeys) - 1))
            ->map(fn (int $index): float => $firstSlot + $index);
    }
}
