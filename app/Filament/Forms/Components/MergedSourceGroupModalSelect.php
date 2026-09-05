<?php

namespace App\Filament\Forms\Components;

use App\Filament\Resources\PlaylistAliases\PlaylistAliasResource;
use App\Filament\Tables\SourceCategoriesTable;
use App\Filament\Tables\SourceGroupsTable;
use App\Models\PlaylistAlias;
use App\Models\SourceCategory;
use App\Models\SourceGroup;
use Filament\Actions\Action;
use Filament\Forms\Components\ModalTableSelect;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Merged-playlist group/category picker for bouquet selections.
 *
 * A merged-target bouquet stores {playlist_id, name} pairs (issue #1483's shape)
 * so a group can be allowed from one source without also allowing a same-named
 * group from another. The picker is scoped to every source playlist of the
 * bouquet's merged playlist that contributes the content type - #1483's
 * SourceGroupsTable / SourceCategoriesTable add a "Source Playlist" column once
 * more than one is in scope. Source ids are resolved only through merged
 * playlists the current user owns.
 *
 * $type: 'live' | 'vod' | 'categories'.
 */
class MergedSourceGroupModalSelect
{
    public static function make(string $statePath, string $type): ModalTableSelect
    {
        $isCategories = $type === 'categories';
        $selectionKey = substr($statePath, strrpos($statePath, '.') + 1);
        $contentType = $isCategories ? 'series' : $type;

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

        $playlistIdsFor = function (Get $get, $record) use ($contentType): array {
            $mergedId = (int) ($get('merged_playlist_id') ?: $record?->merged_playlist_id);

            return $mergedId ? PlaylistAliasResource::mergedSourcePlaylistIds($mergedId, $contentType) : [];
        };

        $scopedQuery = function (array $playlistIds) use ($isCategories, $type): Builder {
            return $isCategories
                ? SourceCategory::query()->whereIn('playlist_id', $playlistIds)
                : SourceGroup::query()->whereIn('playlist_id', $playlistIds)->where('type', $type);
        };

        return ModalTableSelect::make($statePath)
            ->tableConfiguration($isCategories ? SourceCategoriesTable::class : SourceGroupsTable::class)
            ->columnSpanFull()
            ->visible(fn (Get $get): bool => (bool) $get('merged_playlist_id'))
            ->multiple()
            ->tableArguments(function (Get $get, $record) use ($isCategories, $type, $playlistIdsFor): array {
                $arguments = ['playlist_ids' => $playlistIdsFor($get, $record)];
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
                Action::make('clear_merged_'.str_replace('.', '_', $statePath))
                    ->label(__('Clear all'))
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->action(fn (Set $set) => $set($statePath, []))
                    ->requiresConfirmation()
                    ->modalHeading(__('Clear selection'))
                    ->modalSubmitActionLabel(__('Clear'))
            )
            ->getOptionLabelFromRecordUsing(fn ($record) => $record->display_name ?? $record->name)
            ->getOptionLabelsUsing(function (array $values, $record, Get $get) use ($isCategories, $type, $playlistIdsFor): array {
                $playlistIds = $playlistIdsFor($get, $record);
                if (empty($playlistIds)) {
                    return [];
                }
                $ids = array_filter($values, fn ($value): bool => is_numeric($value));

                return $isCategories
                    ? SourceCategory::displayLabelsForIds($playlistIds, $ids, includePlaylistName: count($playlistIds) > 1)
                    : SourceGroup::displayLabelsForIds($playlistIds, $type, $ids, includePlaylistName: count($playlistIds) > 1);
            })
            ->afterStateHydrated(function ($component, $state, $record, Get $get) use ($scopedQuery, $playlistIdsFor): void {
                // Hidden twin components are still hydrated; bail unless this is a
                // merged-target record whose stored pairs need resolving to ids.
                if (! $record?->merged_playlist_id || ! is_array($state) || empty($state)) {
                    return;
                }
                if (! is_array($state[0] ?? null)) {
                    return;
                }
                $playlistIds = $playlistIdsFor($get, $record);
                if (empty($playlistIds)) {
                    return;
                }
                $component->state(self::pairsToSourceIds($scopedQuery($playlistIds), $state));
            })
            ->dehydrateStateUsing(function ($state, $record, Get $get) use ($scopedQuery, $selectionKey, $playlistIdsFor): array {
                $playlistIds = $playlistIdsFor($get, $record);
                $ids = is_array($state) ? array_values(array_filter($state, 'is_numeric')) : [];

                $pairs = ($ids === [] || empty($playlistIds))
                    ? []
                    : self::sourceIdsToPairs($scopedQuery($playlistIds)->whereIn('id', $ids)->get(['playlist_id', 'name']));

                // Never-silently-shrink: stored pairs the picker could not resolve
                // (provider churn, or a source dropped from the merged playlist) are
                // kept, unless the user deliberately cleared a still-valid selection.
                $stored = PlaylistAlias::selectionPairs($record?->group_selections[$selectionKey] ?? []);
                if (! empty($stored)) {
                    $resolvedTokens = self::tokens($pairs);
                    $stale = array_filter($stored, fn (array $pair) => ! in_array(self::token($pair), $resolvedTokens, true)
                        && ! self::pairResolves($scopedQuery([$pair['playlist_id']]), $pair));

                    if (! empty($stale) && (! empty($ids) || count($stale) === count($stored))) {
                        $pairs = array_merge($pairs, array_values($stale));
                    }
                }

                return PlaylistAlias::selectionPairs($pairs);
            });
    }

    /**
     * @param  array{playlist_id: int, name: string}  $pair
     */
    private static function pairResolves(Builder $query, array $pair): bool
    {
        return $query->where('playlist_id', $pair['playlist_id'])->where('name', $pair['name'])->exists();
    }

    /**
     * @param  array<int, array{playlist_id: int, name: string}>  $pairs
     * @return array<int>
     */
    private static function pairsToSourceIds(Builder $query, array $pairs): array
    {
        $pairs = PlaylistAlias::selectionPairs($pairs);
        if (empty($pairs)) {
            return [];
        }

        return $query->where(function (Builder $query) use ($pairs): void {
            foreach ($pairs as $pair) {
                $query->orWhere(fn (Builder $query) => $query
                    ->where('playlist_id', $pair['playlist_id'])
                    ->where('name', $pair['name']));
            }
        })->pluck('id')->unique()->values()->all();
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return array<int, array{playlist_id: int, name: string}>
     */
    private static function sourceIdsToPairs($rows): array
    {
        return PlaylistAlias::selectionPairs($rows
            ->map(fn ($row): array => ['playlist_id' => (int) $row->playlist_id, 'name' => $row->name])
            ->all());
    }

    /**
     * @param  array{playlist_id: int, name: string}  $pair
     */
    private static function token(array $pair): string
    {
        return $pair['playlist_id'].':'.$pair['name'];
    }

    /**
     * @param  array<int, array{playlist_id: int, name: string}>  $pairs
     * @return array<string>
     */
    private static function tokens(array $pairs): array
    {
        return array_map([self::class, 'token'], $pairs);
    }
}
