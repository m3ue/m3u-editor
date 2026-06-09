<?php

namespace App\Filament\Resources\CustomPlaylists\RelationManagers\Concerns;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Query\Expression;
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

        $this->getTable()->callBeforeReordering($order);

        $orderColumn = (string) str($this->getTable()->getReorderColumn())->afterLast('.');

        DB::transaction(function () use ($order, $orderColumn): void {
            $relationship = $this->getTable()->getRelationship();

            if (
                ($relationship instanceof BelongsToMany) &&
                in_array($orderColumn, $relationship->getPivotColumns(), true)
            ) {
                if ($this->isCustomPlaylistPivotSortReorder($relationship, $orderColumn)) {
                    $slots = $this->getCustomPlaylistPivotSortSlots($order, $orderColumn);

                    foreach (array_values($order) as $index => $recordKey) {
                        $this->getTableRecord($recordKey)
                            ->getRelationValue($relationship->getPivotAccessor())
                            ->update([
                                $orderColumn => $slots->get($index, $index + 1),
                            ]);
                    }

                    return;
                }

                foreach ($order as $index => $recordKey) {
                    $this->getTableRecord($recordKey)->getRelationValue($relationship->getPivotAccessor())->update([
                        $orderColumn => $index + 1,
                    ]);
                }

                return;
            }

            $model = app($this->getTable()->getModel());
            $modelKeyName = $model->getKeyName();
            $wrappedModelKeyName = $model->getConnection()?->getQueryGrammar()?->wrap($modelKeyName) ?? $modelKeyName;

            $this->getTable()
                ->getQuery()
                ->whereIn($modelKeyName, array_values($order))
                ->update([
                    $orderColumn => new Expression(
                        'case '.collect($order)
                            ->when($this->getTable()->getReorderDirection() === 'desc', fn (Collection $order) => $order->reverse()->values())
                            ->map(fn ($recordKey, int $recordIndex): string => 'when '.$wrappedModelKeyName.' = '.DB::getPdo()->quote($recordKey).' then '.($recordIndex + 1))
                            ->implode(' ').' end'
                    ),
                ]);
        });

        $this->getTable()->callAfterReordering($order);
    }

    private function isCustomPlaylistPivotSortReorder(BelongsToMany $relationship, string $orderColumn): bool
    {
        return $orderColumn === 'sort'
            && $relationship->getTable() === 'channel_custom_playlist';
    }

    /**
     * @param  array<int|string>  $order
     * @return Collection<int, float|int>
     */
    private function getCustomPlaylistPivotSortSlots(array $order, string $orderColumn): Collection
    {
        $recordKeys = array_values($order);

        $records = $this->ownerRecord
            ->channels()
            ->whereIn('channels.id', $recordKeys)
            ->get(['channels.id', 'channels.sort'])
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
            return collect($recordKeys)
                ->keys()
                ->map(fn (int $index): int => $index + 1);
        }

        $nextSlot = (float) $slots->last();
        while ($slots->count() < count($recordKeys)) {
            $nextSlot++;
            $slots->push($nextSlot);
        }

        return $slots;
    }
}
