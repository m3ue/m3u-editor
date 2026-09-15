<?php

namespace App\Filament\Tables;

use App\Models\Channel;
use App\Models\DynamicGroup;
use App\Services\DynamicGroupCacheDispatchService;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Backing table for the Phase 4 "Select Content" picker on a VOD-type
 * Dynamic Group rule (modal table select in the Playlist form's
 * `dynamic_groups_config` Repeater). Scoped to a single DynamicGroup's
 * existing channel membership — NOT a playlist-wide browse.
 *
 * The query is intentionally driven off `$table->getArguments()` because
 * the picker lives inside a Repeater item and only knows its rule's
 * `name`; the `dynamic_group_id` argument is resolved up-front by the
 * picker closure via
 * {@see DynamicGroupCacheDispatchService::resolveGroupForRule()}.
 *
 * If the playlist hasn't been synced yet (no `DynamicGroup` row exists
 * for this rule), no rows render — "you can't pick what isn't there yet"
 * is a clearer empty state than "here's everything in your playlist."
 */
class DynamicGroupCacheableChannelsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->query(function () use ($table) {
                $arguments = $table->getArguments();
                $groupId = $arguments['dynamic_group_id'] ?? null;

                if (! $groupId) {
                    return Channel::query()->whereRaw('1 = 0');
                }

                $group = DynamicGroup::find($groupId);

                if (! $group) {
                    return Channel::query()->whereRaw('1 = 0');
                }

                return $group->channels()->getQuery();
            })
            ->defaultSort('title', 'asc')
            ->columns([
                TextColumn::make('title')
                    ->label(__('Title'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label(__('Stream Name'))
                    ->searchable()
                    ->placeholder('—'),
            ])
            ->paginated([15, 25, 50, 100])
            ->defaultPaginationPageOption(15);
    }
}
