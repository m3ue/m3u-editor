<?php

namespace App\Filament\Forms\Components;

use App\Filament\Tables\SourceCategoriesTable;
use App\Filament\Tables\SourceGroupsTable;
use App\Models\Category;
use App\Models\Group;
use App\Models\SourceCategory;
use App\Models\SourceGroup;
use App\Traits\HasGroupSelectionModalLabels;
use Filament\Actions\Action;
use Filament\Forms\Components\ModalTableSelect;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;

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
    use HasGroupSelectionModalLabels;

    /**
     * Form state path of the virtual picker holding the custom groups picked for a
     * selection key. They are saved into the same key as the provider names.
     */
    public static function customPicksPath(string $statePath): string
    {
        return 'custom_group_picks_'.substr($statePath, strrpos($statePath, '.') + 1);
    }

    /**
     * The playlist's own groups (or, for $type 'categories', categories the provider did
     * not supply), keyed by name_internal with the display name and a "(custom)" marker as
     * the label, so one can't be mistaken for a provider group of the same name. The
     * display name can be renamed, name_internal is the stable key. One whose name_internal
     * matches a provider group or category is left out: it shares the provider pick's key.
     *
     * @return array<string, string>
     */
    public static function customGroupOptions(int $playlistId, string $type): array
    {
        $query = $type === 'categories'
            ? Category::query()->nonProvider()
            : Group::query()->where('type', $type)->where('custom', true)->nonProvider();

        return $query
            ->where('playlist_id', $playlistId)
            ->whereNotNull('name_internal')
            ->orderBy('name')
            ->pluck('name', 'name_internal')
            ->map(fn (string $name): string => $name.' '.__('(custom)'))
            ->all();
    }

    /**
     * Picker for the playlist's custom groups (live and VOD only). Virtual: it is not
     * saved itself, the provider picker merges its picks into the selection key.
     */
    public static function customGroups(string $statePath, string $type): Select
    {
        $selectionKey = substr($statePath, strrpos($statePath, '.') + 1);

        return Select::make(self::customPicksPath($statePath))
            ->label($type === 'categories' ? __('Custom categories') : __('Custom groups'))
            ->multiple()
            ->searchable()
            ->dehydrated(false)
            ->columnSpanFull()
            ->visible(fn (Get $get): bool => (bool) $get('playlist_id') && self::customGroupOptions((int) $get('playlist_id'), $type) !== [])
            ->options(fn (Get $get): array => self::customGroupOptions((int) $get('playlist_id'), $type))
            ->afterStateHydrated(function (Select $component, $record) use ($selectionKey, $type): void {
                if (! $record?->playlist_id) {
                    return;
                }
                $stored = (array) ($record->group_selections[$selectionKey] ?? []);
                $component->state(array_values(array_intersect(
                    array_keys(self::customGroupOptions($record->playlist_id, $type)),
                    $stored,
                )));
            })
            ->helperText($type === 'categories'
                ? __('Categories the provider did not supply. Series sitting in them are matched, wherever they came from.')
                : __('Groups you created yourself. Channels sitting in them are matched, wherever they came from.'));
    }

    public static function make(string $statePath, string $type): ModalTableSelect
    {
        $isCategories = $type === 'categories';
        $selectionKey = substr($statePath, strrpos($statePath, '.') + 1);

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
                    ->label(self::selectLabelFor($type))
                    ->modalHeading(self::modalHeadingFor($type))
                    ->modalSubmitActionLabel(__('Confirm selection'))
                    ->button(),
            )
            ->hintAction(self::clearSelectionAction('clear_', $statePath))
            ->getOptionLabelFromRecordUsing(fn ($record) => $record->display_name ?? $record->name)
            ->getOptionLabelsUsing(function (array $values, $record, Get $get) use ($isCategories, $type): array {
                $playlistId = $record?->playlist_id ?? (int) $get('playlist_id');
                if (! $playlistId) {
                    return [];
                }

                return $isCategories
                    ? SourceCategory::displayLabelsForIds($playlistId, $values)
                    : SourceGroup::displayLabelsForIds($playlistId, $type, $values);
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
            ->dehydrateStateUsing(function ($state, $record, Get $get) use ($sourceQuery, $selectionKey, $statePath, $type): array {
                $playlistId = $record?->playlist_id ?? (int) $get('playlist_id');
                $ids = is_array($state) ? array_values(array_filter($state, 'is_numeric')) : [];

                $names = ($ids === [] || ! $playlistId)
                    ? []
                    : $sourceQuery($playlistId)->whereIn('id', $ids)->pluck('name')->unique()->values()->all();

                // Never-silently-shrink: previously-stored names the hydrator could
                // not resolve (provider churn) are merged back in, unless the user
                // deliberately cleared a selection that still had live entries.
                $customPicks = array_values((array) $get(self::customPicksPath($statePath)));
                $customNames = ! $playlistId ? [] : array_keys(self::customGroupOptions($playlistId, $type));

                $stored = array_diff($record?->group_selections[$selectionKey] ?? [], $customNames);
                if (! empty($stored) && $playlistId) {
                    $resolvable = $sourceQuery($playlistId)->whereIn('name', $stored)->pluck('name')->all();
                    $stale = array_values(array_diff($stored, $resolvable));
                    if (! empty($stale) && (! empty($ids) || empty($resolvable))) {
                        $names = array_values(array_unique(array_merge($names, $stale)));
                    }
                }

                return array_values(array_unique(array_merge($names, $customPicks)));
            });
    }
}
