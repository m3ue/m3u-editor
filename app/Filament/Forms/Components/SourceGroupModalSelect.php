<?php

namespace App\Filament\Forms\Components;

use App\Filament\Tables\SourceCategoriesTable;
use App\Filament\Tables\SourceGroupsTable;
use App\Models\SourceCategory;
use App\Models\SourceGroup;
use Filament\Actions\Action;
use Filament\Forms\Components\ModalTableSelect;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * Standard-playlist group/category picker for bouquet selections.
 *
 * Wraps the ModalTableSelect ID<->name round-trip that group_filter-style name
 * columns need: the backing tables are keyed by SourceGroup/SourceCategory IDs
 * (unstable across syncs), while the persisted state is provider-stable names.
 * Reads the playlist from the sibling `playlist_id` form field / record
 * attribute, and reads/writes the record's `group_selections` for the
 * never-silently-shrink merge - i.e. this builder is bouquet-form-scoped;
 * parameterize the stored-selection lookup before reusing it on the alias form.
 *
 * $type: 'live' | 'vod' | 'categories'.
 */
class SourceGroupModalSelect
{
    public static function make(string $statePath, string $type): ModalTableSelect
    {
        $isCategories = $type === 'categories';
        $selectionKey = substr($statePath, strrpos($statePath, '.') + 1);

        $selectLabel = match ($type) {
            'live' => __('Select live groups'),
            'vod' => __('Select VOD groups'),
            default => __('Select series categories'),
        };
        $modalHeading = match ($type) {
            'live' => __('Search live groups'),
            'vod' => __('Search VOD groups'),
            default => __('Search series categories'),
        };

        $sourceQuery = function (int $playlistId) use ($isCategories, $type) {
            return $isCategories
                ? SourceCategory::where('playlist_id', $playlistId)
                : SourceGroup::where('playlist_id', $playlistId)->where('type', $type);
        };

        return ModalTableSelect::make($statePath)
            ->tableConfiguration($isCategories ? SourceCategoriesTable::class : SourceGroupsTable::class)
            ->columnSpanFull()
            ->visible(fn (Get $get): bool => (bool) $get('playlist_id'))
            ->multiple()
            ->tableArguments(function (Get $get) use ($isCategories, $type, $statePath): array {
                $arguments = [
                    'playlist_id' => (int) $get('playlist_id'),
                    'selected' => $get($statePath) ?? [],
                ];
                if (! $isCategories) {
                    $arguments['type'] = $type;
                }

                return $arguments;
            })
            ->selectAction(
                fn (Action $action) => $action
                    ->label($selectLabel)
                    ->modalHeading($modalHeading)
                    ->modalSubmitActionLabel(__('Confirm selection'))
                    ->button(),
            )
            ->hintAction(
                Action::make('clear_'.str_replace('.', '_', $statePath))
                    ->label(__('Clear all'))
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->action(fn (Set $set) => $set($statePath, []))
                    ->requiresConfirmation()
                    ->modalHeading(__('Clear selection'))
                    ->modalSubmitActionLabel(__('Clear'))
            )
            ->getOptionLabelFromRecordUsing(fn ($record) => $record->display_name ?? $record->name)
            ->getOptionLabelsUsing(function (array $values, $record, Get $get) use ($isCategories, $type): array {
                $playlistId = $record?->playlist_id ?? (int) $get('playlist_id');
                if (! $playlistId) {
                    return [];
                }
                $ids = array_filter($values, fn ($value): bool => is_numeric($value));

                return $isCategories
                    ? SourceCategory::where('playlist_id', $playlistId)->whereIn('id', $ids)->pluck('name', 'id')->toArray()
                    : SourceGroup::displayLabelsForIds($playlistId, $type, $ids);
            })
            ->afterStateHydrated(function ($component, $state, $record) use ($sourceQuery): void {
                // Hidden twin components are still hydrated; bail out unless this is
                // a standard-target record with stored names to resolve.
                if (! $record?->playlist_id || ! is_array($state) || empty($state)) {
                    return;
                }
                if (is_string($state[0] ?? null)) {
                    $component->state(
                        $sourceQuery($record->playlist_id)->whereIn('name', $state)
                            ->pluck('id')->unique()->values()->toArray()
                    );
                }
            })
            ->dehydrateStateUsing(function ($state, $record, Get $get) use ($sourceQuery, $selectionKey): array {
                $playlistId = $record?->playlist_id ?? (int) $get('playlist_id');
                $ids = is_array($state) ? array_values(array_filter($state, 'is_numeric')) : [];

                $names = ($ids === [] || ! $playlistId)
                    ? []
                    : $sourceQuery($playlistId)->whereIn('id', $ids)->pluck('name')->unique()->values()->all();

                // Never-silently-shrink: previously-stored names the hydrator could
                // not resolve (provider churn) are merged back in, unless the user
                // deliberately cleared a selection that still had live entries.
                $stored = $record?->group_selections[$selectionKey] ?? [];
                if (! empty($stored) && $playlistId) {
                    $resolvable = $sourceQuery($playlistId)->whereIn('name', $stored)->pluck('name')->all();
                    $stale = array_values(array_diff($stored, $resolvable));
                    if (! empty($stale) && (! empty($ids) || empty($resolvable))) {
                        $names = array_values(array_unique(array_merge($names, $stale)));
                    }
                }

                return $names;
            });
    }
}
