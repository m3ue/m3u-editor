<?php

namespace App\Livewire\EasyEditor\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Drag-reorder that only rewrites the rows currently on screen.
 *
 * Filament's stock reorder loads *every* matching row when you enter reorder
 * mode (see HasRecords::getTableRecords) and renumbers the whole set 1..N. For a
 * group with a thousand-plus channels that renders thousands of interactive
 * cells at once and hangs / OOMs the browser tab.
 *
 * Paired with `->paginatedWhileReordering()`, this override keeps reordering to
 * the visible page: it reuses the sort values those rows already hold as fixed
 * "slots" and reassigns them in the new visual order, so a drag on page 5 never
 * disturbs the ordering of pages 1-4. Cross-page moves are done with the inline
 * sort-number column or the "Sort A-Z" action instead.
 */
trait ReordersVisiblePage
{
    /**
     * @param  array<int|string>  $order  Record keys in their new visual order.
     */
    public function reorderTable(array $order, int|string|null $draggedRecordKey = null): void
    {
        if (! $this->getTable()->isReorderable()) {
            return;
        }

        $orderColumn = (string) str($this->getTable()->getReorderColumn())->afterLast('.');
        $keys = array_values($order);

        if ($keys === []) {
            return;
        }

        $this->getTable()->callBeforeReordering($order);

        DB::transaction(function () use ($keys, $orderColumn): void {
            // Sort values the dragged rows currently occupy, ascending. Scoped
            // through the table's own query so tampered keys outside this
            // user / playlist / group can't be touched.
            $slots = (clone $this->getTable()->getQuery())
                ->whereKey($keys)
                ->reorder()
                ->orderBy($orderColumn)
                ->pluck($orderColumn)
                ->map(fn ($value): float => (float) $value)
                ->values();

            while ($slots->count() < count($keys)) {
                $slots->push($slots->isNotEmpty() ? $slots->last() + 1 : (float) ($slots->count() + 1));
            }

            foreach ($keys as $index => $recordKey) {
                (clone $this->getTable()->getQuery())
                    ->whereKey($recordKey)
                    ->update([$orderColumn => $slots->get($index, $index + 1)]);
            }
        });

        $this->getTable()->callAfterReordering($order);
    }
}
