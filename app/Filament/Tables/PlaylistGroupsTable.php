<?php

namespace App\Filament\Tables;

use App\Filament\Tables\Traits\FiltersBySelection;
use App\Models\Group;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Picker table for a standard playlist's own groups - the groups its clients see,
 * custom and genre groups included - keyed by group id, for alias and bouquet
 * selections that match channels by the group they sit in. Lists only groups with
 * enabled channels unless the filter is cleared.
 */
class PlaylistGroupsTable
{
    use FiltersBySelection;

    public static function configure(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Group::query())
            ->modifyQueryUsing(function (Builder $query) use ($table): Builder {
                $arguments = $table->getArguments();

                $query->where('groups.playlist_id', (int) ($arguments['playlist_id'] ?? 0));
                if ($type = $arguments['type'] ?? null) {
                    $query->where('groups.type', $type);
                }

                return $query->withCount(['channels as enabled_channels_count' => fn (Builder $query) => $query->where('enabled', true)]);
            })
            ->defaultSort('sort_order', 'asc')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Group Name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('custom')
                    ->label(__('Type'))
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? __('Custom') : __('Provider'))
                    ->color(fn (bool $state): string => $state ? 'info' : 'gray'),
                TextColumn::make('enabled_channels_count')
                    ->label(__('Enabled channels'))
                    ->sortable(),
                IconColumn::make('in_bouquet')
                    ->label(__('In bouquet'))
                    ->visible(fn (): bool => ! empty($table->getArguments()['bouquet_group_ids'] ?? []))
                    ->state(fn (Group $record): bool => in_array($record->id, $table->getArguments()['bouquet_group_ids'] ?? [], false))
                    ->boolean(),
            ])
            ->filters([
                TernaryFilter::make('selected')
                    ->label(__('Groups'))
                    ->placeholder(__('All groups'))
                    ->trueLabel(__('Selected only'))
                    ->falseLabel(__('Unselected only'))
                    ->queries(
                        true: fn (Builder $query): Builder => self::whereSelected($query, $table->getArguments()['selected'] ?? [], selected: true),
                        false: fn (Builder $query): Builder => self::whereSelected($query, $table->getArguments()['selected'] ?? [], selected: false),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                // Clients never see a group with nothing enabled in it, e.g. a provider
                // group whose channels were all moved into custom groups.
                TernaryFilter::make('has_enabled_channels')
                    ->label(__('Enabled channels'))
                    ->placeholder(__('All groups'))
                    ->trueLabel(__('Groups with enabled channels'))
                    ->falseLabel(__('Groups without enabled channels'))
                    ->default(true)
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereHas('channels', fn (Builder $query) => $query->where('enabled', true)),
                        false: fn (Builder $query): Builder => $query->whereDoesntHave('channels', fn (Builder $query) => $query->where('enabled', true)),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                TernaryFilter::make('custom')
                    ->label(__('Type'))
                    ->placeholder(__('All groups'))
                    ->trueLabel(__('Custom groups only'))
                    ->falseLabel(__('Provider groups only')),
            ])
            ->paginated([15, 25, 50, 100])
            ->defaultPaginationPageOption(15);
    }
}
