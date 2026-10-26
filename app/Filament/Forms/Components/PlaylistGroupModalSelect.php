<?php

namespace App\Filament\Forms\Components;

use App\Filament\Tables\PlaylistCategoriesTable;
use App\Filament\Tables\PlaylistGroupsTable;
use App\Models\Category;
use App\Models\Group;
use App\Traits\HasGroupSelectionModalLabels;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\ModalTableSelect;
use Filament\Schemas\Components\Utilities\Get;

/**
 * Standard-playlist picker for the playlist's own groups / categories - what its
 * clients see, custom and genre groups included - for alias filters and bouquets.
 *
 * Unlike the provider-group pickers, the state is stored as it is picked: the
 * group / category ids, which stay the same across syncs and renames. Reads the
 * playlist from the sibling `playlist_id` form field.
 *
 * $type: 'live' | 'vod' | 'categories'.
 */
class PlaylistGroupModalSelect
{
    use HasGroupSelectionModalLabels;

    /**
     * @param  Closure(Get): array<int>|null  $bouquetIds  ids the assigned bouquets already cover, ticked in the picker
     */
    public static function make(string $statePath, string $type, ?Closure $bouquetIds = null): ModalTableSelect
    {
        $isCategories = $type === 'categories';

        return ModalTableSelect::make($statePath)
            ->tableConfiguration($isCategories ? PlaylistCategoriesTable::class : PlaylistGroupsTable::class)
            ->label($isCategories ? __('Your series categories') : __('Your groups'))
            ->columnSpanFull()
            ->visible(fn (Get $get): bool => self::isStandardPlaylistForm($get))
            ->multiple()
            ->tableArguments(fn (Get $get): array => [
                'playlist_id' => (int) $get('playlist_id'),
                'type' => $isCategories ? null : $type,
                'selected' => $get($statePath) ?? [],
                'bouquet_group_ids' => $bouquetIds ? $bouquetIds($get) : [],
            ])
            ->selectAction(
                fn (Action $action) => $action
                    ->label($isCategories ? __('Select your categories') : __('Select your groups'))
                    ->modalHeading(self::modalHeadingFor($type))
                    ->modalDescription($isCategories
                        ? __('The categories your clients see, genre categories included.')
                        : __('The groups your clients see, custom and genre groups included. A channel matches the group it sits in, wherever it came from.'))
                    ->modalSubmitActionLabel(__('Confirm selection'))
                    ->button(),
            )
            ->hintAction(self::clearSelectionAction('clear_', $statePath))
            ->getOptionLabelFromRecordUsing(fn ($record): string => $record->name)
            ->getOptionLabelsUsing(function (array $values, Get $get) use ($isCategories): array {
                $ids = array_values(array_filter($values, 'is_numeric'));
                $query = $isCategories ? Category::query() : Group::query();

                return $query->where('playlist_id', (int) $get('playlist_id'))->whereIn('id', $ids)->pluck('name', 'id')->toArray();
            })
            ->dehydrateStateUsing(fn ($state): array => is_array($state)
                ? array_values(array_unique(array_map('intval', array_filter($state, 'is_numeric'))))
                : []);
    }

    /**
     * Whether the form targets a standard playlist, the only target whose own groups
     * can be picked by id.
     */
    public static function isStandardPlaylistForm(Get $get): bool
    {
        return (bool) $get('playlist_id') && ! $get('custom_playlist_id') && ! $get('merged_playlist_id');
    }
}
