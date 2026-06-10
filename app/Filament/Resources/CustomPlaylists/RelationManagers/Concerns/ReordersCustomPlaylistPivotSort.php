<?php

namespace App\Filament\Resources\CustomPlaylists\RelationManagers\Concerns;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

trait ReordersCustomPlaylistPivotSort
{
    /**
     * @param  array<int|string>  $order
     */
    public function reorderTable(array $order, int|string|null $draggedRecordKey = null): void
    {
        if (! $this->getTable()->isReorderable()) {
            return;
        }

        $orderColumn = (string) str($this->getTable()->getReorderColumn())->afterLast('.');
        $relationship = $this->getTable()->getRelationship();

        if (
            ($relationship instanceof BelongsToMany) &&
            in_array($orderColumn, $relationship->getPivotColumns(), true) &&
            $this->isCustomPlaylistPivotSortReorder($relationship, $orderColumn)
        ) {
            $this->getTable()->callBeforeReordering($order);

            DB::transaction(function () use ($order, $orderColumn, $relationship): void {
                $slots = $this->getCustomPlaylistPivotSortSlots($order, $orderColumn, $relationship);

                foreach (array_values($order) as $index => $recordKey) {
                    $this->getTableRecord($recordKey)
                        ->getRelationValue($relationship->getPivotAccessor())
                        ->update([
                            $orderColumn => $slots->get($index, $index + 1),
                        ]);
                }
            });

            $this->getTable()->callAfterReordering($order);

            return;
        }

        $this->filamentReorderTable($order, $draggedRecordKey);
    }

    private function isCustomPlaylistPivotSortReorder(BelongsToMany $relationship, string $orderColumn): bool
    {
        return $orderColumn === 'sort'
            && in_array($relationship->getTable(), ['channel_custom_playlist', 'series_custom_playlist'], true);
    }

    /**
     * @param  array<int|string>  $order
     * @return Collection<int, float|int>
     */
    private function getCustomPlaylistPivotSortSlots(array $order, string $orderColumn, BelongsToMany $relationship): Collection
    {
        $recordKeys = array_values($order);
        $related = $relationship->getRelated();
        $relatedTable = $related->getTable();
        $keyName = $related->getKeyName();

        $records = $relationship
            ->whereIn("{$relatedTable}.{$keyName}", $recordKeys)
            ->get(["{$relatedTable}.{$keyName}", "{$relatedTable}.sort"])
            ->keyBy(fn ($record): string => (string) $record->getKey());

        $slots = collect($recordKeys)
            ->map(function ($recordKey) use ($records, $orderColumn): mixed {
                $record = $records->get((string) $recordKey);

                return $record?->pivot?->{$orderColumn} ?? $record?->sort;
            })
            ->filter(fn ($slot): bool => $slot !== null && $slot !== '')
            ->map(fn ($slot): float => (float) $slot)
            ->sort()
            ->values();

        if ($slots->isEmpty()) {
            return collect(range(1, count($recordKeys)));
        }

        $nextSlot = (float) $slots->last();
        while ($slots->count() < count($recordKeys)) {
            $nextSlot++;
            $slots->push($nextSlot);
        }

        return $slots;
    }
}
