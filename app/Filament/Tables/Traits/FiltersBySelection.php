<?php

namespace App\Filament\Tables\Traits;

use Illuminate\Database\Eloquent\Builder;

trait FiltersBySelection
{
    private static function whereSelected(Builder $query, mixed $selectedValues, bool $selected): Builder
    {
        [$selectedIds, $selectedNames] = self::selectedIdsAndNames($selectedValues);

        if (empty($selectedIds) && empty($selectedNames)) {
            return $selected ? $query->whereRaw('1 = 0') : $query;
        }

        if (! $selected) {
            return $query
                ->when($selectedIds, fn (Builder $query): Builder => $query->whereNotIn($query->qualifyColumn('id'), $selectedIds))
                ->when($selectedNames, fn (Builder $query): Builder => $query->whereNotIn($query->qualifyColumn('name'), $selectedNames));
        }

        return $query->where(function (Builder $query) use ($selectedIds, $selectedNames): void {
            if (! empty($selectedIds)) {
                $query->whereIn($query->qualifyColumn('id'), $selectedIds);
            }

            if (! empty($selectedNames)) {
                $method = empty($selectedIds) ? 'whereIn' : 'orWhereIn';
                $query->{$method}($query->qualifyColumn('name'), $selectedNames);
            }
        });
    }

    /**
     * @return array{0: list<int>, 1: list<string>}
     */
    private static function selectedIdsAndNames(mixed $selectedValues): array
    {
        if (! is_array($selectedValues)) {
            return [[], []];
        }

        $selectedIds = [];
        $selectedNames = [];

        foreach ($selectedValues as $value) {
            if (is_numeric($value)) {
                $selectedIds[] = (int) $value;

                continue;
            }

            if (is_string($value) && $value !== '') {
                $selectedNames[] = $value;
            }
        }

        return [
            array_values(array_unique($selectedIds)),
            array_values(array_unique($selectedNames)),
        ];
    }
}