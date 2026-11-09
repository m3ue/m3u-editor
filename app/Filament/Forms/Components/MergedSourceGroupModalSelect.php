<?php

namespace App\Filament\Forms\Components;

use App\Filament\Resources\PlaylistAliases\PlaylistAliasResource;
use App\Filament\Tables\SourceCategoriesTable;
use App\Filament\Tables\SourceGroupsTable;
use App\Models\PlaylistAlias;
use App\Models\SourceCategory;
use App\Models\SourceGroup;
use App\Traits\HasGroupSelectionModalLabels;
use Filament\Actions\Action;
use Filament\Forms\Components\ModalTableSelect;
use Filament\Schemas\Components\Utilities\Get;
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
    use HasGroupSelectionModalLabels;

    public static function make(string $statePath, string $type): ModalTableSelect
    {
        $isCategories = $type === 'categories';
        $selectionKey = substr($statePath, strrpos($statePath, '.') + 1);
        $contentType = $isCategories ? 'series' : $type;

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
                    ->label(self::selectLabelFor($type))
                    ->modalHeading(self::modalHeadingFor($type))
                    ->modalSubmitActionLabel(__('Confirm selection'))
                    ->button(),
            )
            ->hintAction(self::clearSelectionAction('clear_merged_', $statePath))
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
            ->afterStateHydrated(function ($component, $state, $record, Get $get) use ($scopedQuery, $selectionKey, $playlistIdsFor): void {
                // Hidden twin components are still hydrated; bail unless this is a
                // merged-target record whose stored pairs need resolving to ids. A
                // non-empty $state means they already were (a re-fill) - leave it.
                if (! $record?->merged_playlist_id || ! empty($state)) {
                    return;
                }
                // The pairs are read from the record rather than $state: Filament's
                // option state cast normalises multi-select state to scalars before
                // this hook runs, which drops every {playlist_id, name} pair and
                // would leave the picker empty (and the selections lost on save).
                $pairs = PlaylistAlias::selectionPairs($record->group_selections[$selectionKey] ?? []);
                if (empty($pairs)) {
                    return;
                }
                $playlistIds = $playlistIdsFor($get, $record);
                if (empty($playlistIds)) {
                    return;
                }
                $component->state(self::pairsToSourceIds($scopedQuery($playlistIds), $pairs));
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
                    $resolvedTokens = array_map(PlaylistAlias::selectionToken(...), $pairs);
                    $stale = array_filter($stored, function (array $pair) use ($resolvedTokens, $playlistIds, $scopedQuery): bool {
                        if (in_array(PlaylistAlias::selectionToken($pair), $resolvedTokens, true)) {
                            return false;
                        }

                        // A pair whose source is currently out of the picker's scope (its
                        // Include Live/VOD Channels toggle was switched off, or it was
                        // dropped from the merged playlist) can't be resolved here at all
                        // right now - that is not the same thing as the provider having
                        // deleted/renamed the group, so it must be kept as stale rather
                        // than checked against (and wrongly found in) the source's table
                        // regardless of whether it is actually in scope.
                        if (! in_array($pair['playlist_id'], $playlistIds, true)) {
                            return true;
                        }

                        return ! self::pairResolves($scopedQuery([$pair['playlist_id']]), $pair);
                    });

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
}
