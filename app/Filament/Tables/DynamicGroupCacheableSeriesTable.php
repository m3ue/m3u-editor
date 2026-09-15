<?php

namespace App\Filament\Tables;

use App\Models\DynamicGroup;
use App\Models\Series;
use App\Services\DynamicGroupCacheDispatchService;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Backing table for the Phase 4 "Select Content" picker on a Series-type
 * Dynamic Group rule (modal table select in the Playlist form's
 * `dynamic_groups_config` Repeater). Scoped to a single DynamicGroup's
 * existing series membership — NOT a playlist-wide browse.
 *
 * Series-level selection only: a picked series caches ALL of its episodes
 * (same as `all`/`recent` do today). Episode-level picking is intentionally
 * not offered — there's no per-episode picker anywhere in this app today
 * and the user-facing value vs. complexity isn't there yet.
 *
 * The query is driven off `$table->getArguments()` because the picker
 * lives inside a Repeater item and only knows its rule's `name`; the
 * `dynamic_group_id` argument is resolved up-front by the picker closure
 * via
 * {@see DynamicGroupCacheDispatchService::resolveGroupForRule()}.
 *
 * If the playlist hasn't been synced yet (no `DynamicGroup` row exists
 * for this rule), no rows render — "you can't pick what isn't there yet"
 * is a clearer empty state than "here's everything in your playlist."
 */
class DynamicGroupCacheableSeriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->query(function () use ($table) {
                $arguments = $table->getArguments();
                $groupId = $arguments['dynamic_group_id'] ?? null;

                if (! $groupId) {
                    return Series::query()->whereRaw('1 = 0');
                }

                $group = DynamicGroup::find($groupId);

                if (! $group) {
                    return Series::query()->whereRaw('1 = 0');
                }

                return $group->series()->getQuery();
            })
            ->defaultSort('name', 'asc')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Series Name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('release')
                    ->label(__('Release Year'))
                    ->getStateUsing(fn (Series $record): ?string => $record->info['release_date'] ?? null)
                    ->placeholder('—'),
            ])
            ->paginated([15, 25, 50, 100])
            ->defaultPaginationPageOption(15);
    }
}
